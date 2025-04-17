<?php

namespace App\Http\Controllers;

use App\Models\Prescription;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class YousignWebhookController extends Controller
{
    /**
     * Handle the webhook callback when a procedure is completed in Yousign
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function procedureCompleted(Request $request)
    {
        Log::info("Received Yousign webhook", [
            "payload" => $request->all(),
        ]);

        // Extract data from the request
        $procedureId = $request->input("procedure.id");
        $status = $request->input("procedure.status");

        // Verify Yousign signature/webhook
        if (!$this->verifyWebhook($request)) {
            Log::error("Invalid webhook signature", [
                "procedure_id" => $procedureId,
            ]);
            return response()->json(["error" => "Invalid signature"], 401);
        }

        // Only process if procedure is completed
        if ($status !== "completed") {
            Log::info("Procedure not completed, status: $status");
            return response()->json([
                "message" => "Procedure not completed yet",
            ]);
        }

        // Retrieve the prescription
        $prescription = Prescription::where(
            "yousign_procedure_id",
            $procedureId
        )->first();

        if (!$prescription) {
            Log::error("Prescription not found for procedure", [
                "procedure_id" => $procedureId,
            ]);
            return response()->json(["error" => "Prescription not found"], 404);
        }

        DB::beginTransaction();
        try {
            // Update prescription status
            $prescription->update([
                "status" => "active",
                "signed_at" => now(),
            ]);

            // Activate associated subscription if exists
            $subscription = Subscription::where(
                "prescription_id",
                $prescription->id
            )->first();
            if ($subscription && $subscription->status !== "active") {
                $subscription->update([
                    "status" => "active",
                ]);
                Log::info("Activated subscription", [
                    "subscription_id" => $subscription->id,
                    "prescription_id" => $prescription->id,
                ]);
            }

            DB::commit();

            Log::info("Prescription signature completed and activated", [
                "prescription_id" => $prescription->id,
                "procedure_id" => $procedureId,
            ]);

            return response()->json([
                "message" => "Prescription activated successfully",
                "prescription_id" => $prescription->id,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error("Failed to process signature completion", [
                "error" => $e->getMessage(),
                "trace" => $e->getTraceAsString(),
                "prescription_id" => $prescription->id,
                "procedure_id" => $procedureId,
            ]);

            return response()->json(
                [
                    "error" => "Failed to process signature completion",
                    "message" => $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * Verify the webhook signature from Yousign
     */
    private function verifyWebhook(Request $request): bool
    {
        // In production, you would verify the request using Yousign's webhook signature
        // This is a simplified example; please implement according to Yousign's documentation

        $signature = $request->header("X-Yousign-Signature");
        $secret = config("services.yousign.webhook_secret");

        if (empty($signature) || empty($secret)) {
            // If testing or dev environment, you might want to skip verification
            if (app()->environment("local", "testing")) {
                return true;
            }
            return false;
        }

        // Verify the signature
        $payload = $request->getContent();
        $computedSignature = hash_hmac("sha256", $payload, $secret);

        return hash_equals($signature, $computedSignature);
    }
}
