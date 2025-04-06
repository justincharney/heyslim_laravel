<?php

namespace App\Http\Controllers;

use App\Models\QuestionnaireSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShopifyWebhookController extends Controller
{
    /**
     * Handle the order paid webhook from Shopify.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function orderPaid(Request $request)
    {
        Log::info("Webhook function called", [
            "request_method" => $request->method(),
            "request_url" => $request->fullUrl(),
            "request_ip" => $request->ip(),
            "request_headers" => $request->headers->all(),
        ]);

        // Retrieve the raw request body
        $data = $request->getContent();

        // Get the HMAC header from Shopify
        $hmacHeader = $request->header("X-Shopify-Hmac-Sha256");

        // Use Shopify webhook secret stored in configuration
        $secret = config("services.shopify.webhook_secret");

        // Calculate the HMAC on the request data using the shared secret
        $calculatedHmac = base64_encode(
            hash_hmac("sha256", $data, $secret, true)
        );

        // Verify that the calculated HMAC matches the header provided by Shopify
        $hmacMatch = hash_equals($hmacHeader, $calculatedHmac);

        if (!$hmacMatch) {
            Log::error("Shopify webhook HMAC verification failed", [
                "header" => $hmacHeader,
                "calculated" => $calculatedHmac,
            ]);
            return response()->json(
                ["message" => "Invalid webhook signature"],
                401
            );
        }

        // Decode the webhook payload
        $payload = json_decode($data, true);

        // Update the status of the questionnaire submission
        // Attempt to retrieve custom questionnaire submission id from the order's note attributes
        $submissionId = null;
        if (
            isset($payload["note_attributes"]) &&
            is_array($payload["note_attributes"])
        ) {
            foreach ($payload["note_attributes"] as $attribute) {
                if ($attribute["name"] === "questionnaire_submission_id") {
                    $submissionId = $attribute["value"];
                    break;
                }
            }
        } else {
            Log::warning("No note_attributes found in payload or not in array");
        }

        if ($submissionId) {
            $submission = QuestionnaireSubmission::find($submissionId);
            if ($submission) {
                $submission->update(["status" => "submitted"]);
            } else {
                Log::error("Submission record not found in database", [
                    "submission_id" => $submissionId,
                ]);
            }

            // Return a successful response
            return response()->json([
                "message" => "Webhook processed successfully",
            ]);
        } else {
            // This can happen for subsequent orders after their first order that was made with the questionnaire (they subscribed)
            return response("No matching submission found", 200);
        }
    }
}
