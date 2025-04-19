<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessSignedPrescriptionJob;
use App\Models\Prescription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Services\ShopifyService;
use App\Services\YousignService;

class YousignWebhookController extends Controller
{
    protected $shopifyService;
    protected $yousignService;

    public function __construct(
        ShopifyService $shopifyService,
        YousignService $yousignService
    ) {
        $this->shopifyService = $shopifyService;
        $this->yousignService = $yousignService;
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

        // Log::info("Prescription #{$prescription->id} marked as signed");

        // Get the document id
        $documentId = $request->input("data.signature_request.documents.0.id");

        if (!$documentId) {
            Log::error(
                "No document ID found for signature request: $signatureRequestId"
            );
            return response()->json(["error" => "No document ID found"], 400);
        }

        // ----- PERFORM DB UPDATE -----
        try {
            DB::beginTransaction();
            $prescription->status = "active";
            $prescription->signed_at = now();
            $prescription->yousign_document_id = $documentId; // Save the document ID
            $prescription->save();
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

        // Dispatch job
        $shopifyOrderId =
            $prescription->subscription?->original_shopify_order_id;
        if (!$shopifyOrderId) {
            Log::warning(
                "Cannot dispatch job for prescription #{$prescription->id}: Missing Shopify Order ID in associated subscription."
            );
        } else {
            ProcessSignedPrescriptionJob::dispatch(
                $prescription->id,
                $signatureRequestId,
                $documentId
            );
            Log::info(
                "Dispatched ProcessSignedPrescriptionJob for prescription #{$prescription->id}."
            );
        }

        return response()->json(
            ["message" => "Webhook received, processing initiated"],
            400
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

        // Remove the 'sha256=' prefix from the received signature
        $signature = str_replace("sha256=", "", $signatureHeader);

        // Get the raw request body
        $payload = $request->getContent();

        // Calculate expected signature
        $expectedSignature = hash_hmac("sha256", $payload, $secretKey);

        // Log::debug("Received signature (cleaned): " . $signature);
        // Log::debug("Calculated signature: " . $expectedSignature);

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
