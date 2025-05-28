<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessSignedPrescriptionJob;
use App\Models\Prescription;
use App\Services\SupabaseStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Services\ShopifyService;
use App\Services\YousignService;
use App\Services\SupabaseStorageService;

class YousignWebhookController extends Controller
{
    protected $shopifyService;
    protected $yousignService;
    protected $supabaseService;

    public function __construct(
        ShopifyService $shopifyService,
        YousignService $yousignService,
        SupabaseStorageService $supabaseService
    ) {
        $this->shopifyService = $shopifyService;
        $this->yousignService = $yousignService;
        $this->supabaseService = $supabaseService;
    }

    /**
     * Handle Yousign webhook events
     */
    public function handleWebhook(Request $request)
    {
        // Log the webhook payload for debugging
        // Log::info("Yousign webhook received", [
        //     "event_type" => $request->input("event_type"),
        //     "payload" => $request->all(),
        // ]);

        // Verify webhook authenticity
        if (!$this->verifyWebhook($request)) {
            Log::error("Yousign webhook verification failed");
            return response()->json(["error" => "Invalid webhook"], 401);
        }

        // Get the event name
        $eventName = $request->input("event_name");

        // Handle different event types
        switch ($eventName) {
            case "signature_request.done":
                return $this->handleSignatureRequestDone($request);
            // case "signer.declined":
            // case "signature_request.declined":
            //     return $this->handleSignatureRequestDeclined($request);
            // case "signature_request.expired":
            //     return $this->handleSignatureRequestExpired($request);
            // case "signature_request.canceled":
            //     return $this->handleSignatureRequestCanceled($request);
            default:
                // Ignore other event types
                Log::info("Ignoring unhandled Yousign event: $eventName");
                return response()->json(["message" => "Event ignored"], 200);
        }
    }

    /**
     * Handle signature_request.done event - all signers have signed
     */
    private function handleSignatureRequestDone(Request $request)
    {
        // Get the signature request ID from the request
        $signatureRequestId = $request->input("data.signature_request.id");

        if (!$signatureRequestId) {
            Log::error("Missing signature request ID in Yousign webhook");
            return response()->json(
                ["error" => "Missing signature request ID"],
                400
            );
        }

        // Find the prescription with this signature request ID
        $prescription = Prescription::where(
            "yousign_signature_request_id",
            $signatureRequestId
        )->first();

        if (!$prescription) {
            Log::error(
                "No prescription found for Yousign signature request ID: $signatureRequestId"
            );
            return response()->json(["error" => "Prescription not found"], 404);
        }

        // Check if the prescription is already signed
        if (
            $prescription->status === "active" &&
            $prescription->signed_at !== null
        ) {
            Log::info(
                "Prescription #{$prescription->id} is already signed, ignoring duplicate webhook"
            );
            return response()->json(
                ["message" => "Prescription already signed"],
                200
            );
        }

        // Get the document id
        $documentId = $request->input("data.signature_request.documents.0.id");

        if (!$documentId) {
            Log::error(
                "No document ID found for signature request: $signatureRequestId"
            );
            return response()->json(["error" => "No document ID found"], 400);
        }

        // Download the signed document from YouSign
        $signedDocument = $this->yousignService->downloadSignedDocument(
            $signatureRequestId,
            $documentId
        );

        if (!$signedDocument) {
            Log::error(
                "Failed to download signed document for signature request: $signatureRequestId"
            );
            return response()->json(
                ["error" => "Failed to download signed document"],
                500
            );
        }

        // Upload to Supabase Storage
        $supabasePath = "signed_prescriptions/prescription_{$prescription->id}";
        $uploadOptions = [
            "contentType" => "application/pdf",
            "upsert" => false,
        ];

        try {
            $uploadedPath = $this->supabaseService->uploadFile(
                $supabasePath,
                $signedDocument,
                $uploadOptions
            );

            if (!$uploadedPath) {
                Log::error(
                    "Failed to upload signed prescription to Supabase for prescription #{$prescription->id}"
                );
                return response()->json(
                    ["error" => "Failed to upload signed document"],
                    500
                );
            }

            Log::info("Successfully uploaded signed prescription to Supabase", [
                "prescription_id" => $prescription->id,
                "supabase_path" => $uploadedPath,
            ]);
        } catch (\Exception $e) {
            Log::error("Exception during Supabase upload", [
                "prescription_id" => $prescription->id,
                "error" => $e->getMessage(),
            ]);
            return response()->json(
                ["error" => "Exception during document upload"],
                500
            );
        }

        // ----- PERFORM DB UPDATE -----
        try {
            DB::beginTransaction();
            $updateData = [
                "signed_at" => now(),
                "yousign_document_id" => $documentId,
                "signed_prescription_supabase_path" => $uploadedPath,
            ];

            // Only set the status to 'active' if there's a linked subscription
            if ($prescription->subscription) {
                $updateData["status"] = "active";
            } else {
                // Signature is ready, but no subscription so it's pending payment
                $updateData["status"] = "pending_payment";
            }

            $prescription->update($updateData);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error(
                "Failed to update prescription status for signature request: $signatureRequestId",
                ["error" => $e->getMessage()]
            );
            return response()->json(
                ["error" => "Failed to update prescription status"],
                500
            );
        }

        // Conditionally dispatch job based on prescription type
        $isReplacement = !is_null($prescription->replaces_prescription_id);
        $shopifyOrderId =
            $prescription->subscription?->original_shopify_order_id;

        if (!$isReplacement && $shopifyOrderId) {
            // This is an initial prescription with an associated original order
            ProcessSignedPrescriptionJob::dispatch(
                $prescription->id,
                $shopifyOrderId
            );
            Log::info(
                "Dispatched ProcessSignedPrescriptionJob for initial prescription #{$prescription->id} to original order {$shopifyOrderId}"
            );
        } elseif ($isReplacement) {
            // This is a replacement prescription - do NOT dispatch job here
            Log::info(
                "Prescription #{$prescription->id} is a replacement. Signed document uploaded but no job dispatched. RechargeWebhookController will handle attachment to future orders."
            );
        } else {
            Log::warning(
                "Cannot dispatch job for prescription #{$prescription->id}: Missing Shopify Order ID or unclear prescription type."
            );
        }

        return response()->json(
            ["message" => "Webhook received, processing completed"],
            200
        );
    }

    /**
     * Verify the webhook's authenticity using the secret key
     */
    private function verifyWebhook(Request $request): bool
    {
        $secretKey = config("services.yousign.webhook_secret");

        if (empty($secretKey)) {
            Log::warning(
                "Yousign webhook secret not configured, failing verification"
            );
            return false;
        }

        $signatureHeader = $request->header("X-Yousign-Signature-256");

        if (!$signatureHeader) {
            Log::error("Missing X-Yousign-Signature-256 header");
            return false;
        }

        Log::debug("Received signature header: " . $signatureHeader);

        // Remove the 'sha256=' prefix from the received signature
        $signature = str_replace("sha256=", "", $signatureHeader);

        // Get the raw request body
        $payload = $request->getContent();

        // Calculate expected signature
        $expectedSignature = hash_hmac("sha256", $payload, $secretKey);

        Log::debug("Received signature (cleaned): " . $signature);
        Log::debug("Calculated signature: " . $expectedSignature);

        // Compare signatures using a constant-time comparison function
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Attach the signed document to the Shopify order if applicable
     */
    private function attachDocumentToShopifyOrder(
        Prescription $prescription,
        string $documentContent
    ): bool {
        // Check if this prescription is associated with a subscription that has an order ID
        $subscription = $prescription->subscription;

        if (!$subscription || !$subscription->original_shopify_order_id) {
            Log::info(
                "No Shopify order ID found for prescription #{$prescription->id}, skipping document attachment"
            );
            return false;
        }

        $orderId = $subscription->original_shopify_order_id;

        try {
            // Store the document temporarily
            $tempPath = "prescriptions/" . $prescription->id . "_signed.pdf";
            Storage::put($tempPath, $documentContent);

            // Get the full path
            $fullPath = Storage::path($tempPath);

            // Attach the document to the Shopify order
            $success = $this->shopifyService->attachPrescriptionToOrder(
                $orderId,
                $fullPath,
                "Signed prescription #{$prescription->id}"
            );

            // Clean up the temporary file
            Storage::delete($tempPath);

            if ($success) {
                // Log::info(
                //     "Successfully attached signed prescription to Shopify order",
                //     [
                //         "prescription_id" => $prescription->id,
                //         "order_id" => $orderId,
                //     ]
                // );
                return true;
            } else {
                Log::error("Failed to attach prescription to Shopify order", [
                    "prescription_id" => $prescription->id,
                    "order_id" => $orderId,
                ]);
                return false;
            }
        } catch (\Exception $e) {
            Log::error("Exception attaching prescription to Shopify order", [
                "prescription_id" => $prescription->id,
                "order_id" => $orderId,
                "error" => $e->getMessage(),
            ]);
            return false;
        }
    }
}
