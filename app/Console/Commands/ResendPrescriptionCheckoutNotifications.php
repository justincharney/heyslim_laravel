<?php

namespace App\Console\Commands;

use App\Models\Prescription;
use App\Notifications\PrescriptionCheckoutNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use App\Services\ShopifyService;

class ResendPrescriptionCheckoutNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = "app:resend-prescription-checkout-notifications {--days=1 : The number of days a prescription has been in pending_payment to trigger a reminder}";

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Send checkout reminder notifications for prescriptions in 'pending_payment' status older than the specified number of days.";

    protected ShopifyService $shopifyService;

    /**
     * Create a new command instance.
     *
     * @param ShopifyService $shopifyService
     */
    public function __construct(ShopifyService $shopifyService)
    {
        // Modified constructor
        parent::__construct();
        $this->shopifyService = $shopifyService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $daysThreshold = (int) $this->option("days");
        if ($daysThreshold < 0) {
            $this->error("The --days option must be a positive integer.");
            return Command::FAILURE;
        }

        $thresholdDate = Carbon::now()->subDays($daysThreshold)->startOfDay(); // Compare against the start of the day

        $prescriptionsToRemind = Prescription::with("patient")
            ->where("status", "pending_payment") // Assuming 'pending_payment' is the correct status string
            ->where("created_at", "<=", $thresholdDate)
            ->get();

        if ($prescriptionsToRemind->isEmpty()) {
            $this->info(
                "No prescriptions found matching the criteria (status 'pending_payment', older than {$daysThreshold} days)."
            );
            return Command::SUCCESS;
        }

        $sentCount = 0;
        foreach ($prescriptionsToRemind as $prescription) {
            if (!$prescription->patient) {
                $this->warn(
                    "Patient not found for Prescription ID: {$prescription->id}. Skipping reminder."
                );
                Log::warning(
                    "SendPendingCheckoutRemindersCommand: Patient not found for Prescription ID: {$prescription->id}."
                );
                continue;
            }

            // Get Shopify Variant GID and Selling Plan ID from dose_schedule
            $firstDose = $prescription->dose_schedule[0] ?? null;
            if (empty($firstDose) || !is_array($firstDose)) {
                $this->warn(
                    "First dose schedule entry not found or invalid for Prescription ID: {$prescription->id}. Skipping."
                );
                Log::warning(
                    "SendPendingCheckoutRemindersCommand: First dose schedule entry not found or invalid for Prescription ID: {$prescription->id}.",
                    ["dose_schedule" => $prescription->dose_schedule]
                );
                continue;
            }

            $shopifyVariantGid = $firstDose["shopify_variant_gid"] ?? null;
            $sellingPlanId = $firstDose["selling_plan_id"] ?? null;

            if (!$shopifyVariantGid || !$sellingPlanId) {
                $this->warn(
                    "Missing Shopify Variant GID or Selling Plan ID in dose schedule for Prescription ID: {$prescription->id}. Skipping."
                );
                Log::warning(
                    "SendPendingCheckoutRemindersCommand: Missing Shopify Variant GID or Selling Plan ID for Prescription ID: {$prescription->id}.",
                    [
                        "dose_schedule_entry" => $firstDose,
                        "prescription_id" => $prescription->id,
                    ]
                );
                continue;
            }

            $discountCode = null; //"CONSULTATION_DISCOUNT";
            try {
                $cartData = $this->shopifyService->createCheckout(
                    $shopifyVariantGid,
                    null,
                    1, // quantity
                    true, // isSubscription flag
                    $sellingPlanId,
                    $discountCode,
                    (string) $prescription->id // Cart attribute, ensuring it's a string for Shopify
                );

                if (!$cartData || !isset($cartData["checkoutUrl"])) {
                    $this->error(
                        "Failed to create Shopify subscription checkout for Prescription ID: {$prescription->id}. Response did not contain checkoutUrl."
                    );
                    Log::error(
                        "SendPendingCheckoutRemindersCommand: Failed to create Shopify subscription checkout. Missing checkoutUrl.",
                        [
                            "prescription_id" => $prescription->id,
                            "cart_data_received" => $cartData ?? "null",
                        ]
                    );
                    continue; // Skip to the next prescription
                }
                $checkoutUrl = $cartData["checkoutUrl"];

                // The PrescriptionCheckoutNotification is queued by default (implements ShouldQueue)
                $prescription->patient->notify(
                    new PrescriptionCheckoutNotification(
                        $prescription,
                        $checkoutUrl
                    )
                );

                $sentCount++;
            } catch (\Exception $e) {
                $this->error(
                    "Error processing Prescription ID: {$prescription->id}. Message: {$e->getMessage()}"
                );
                Log::error(
                    "SendPendingCheckoutRemindersCommand: Exception while processing Prescription ID: {$prescription->id}.",
                    [
                        "error" => $e->getMessage(),
                        "trace" => $e->getTraceAsString(),
                        "prescription_id" => $prescription->id,
                    ]
                );
            }
        }

        $this->info("Finished processing. Queued {$sentCount} reminder(s).");
        return Command::SUCCESS;
    }
}
