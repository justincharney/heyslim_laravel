<?php

namespace App\Console\Commands;

use App\Models\Prescription;
use App\Services\ChargebeeService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Models\Subscription;
use App\Models\Team;
use App\Notifications\SubscriptionCancelledNotification;
use App\Notifications\SubscriptionRefillAlertNotification;
use Illuminate\Support\Facades\DB;

class ValidateChargebeeSubscriptionRenewals extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = "app:validate-chargebee-subscription-renewals {--use-api : Fetch renewals directly from Chargebee API instead of local database} {--days=7 : Number of days to look ahead for renewals}";

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Check upcoming Chargebee subscription renewals and cancel those without valid prescriptions";

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Starting Chargebee subscription renewal validation...");

        $chargebeeService = app(ChargebeeService::class);
        $daysAhead = (int) $this->option("days");
        $useApi = $this->option("use-api");

        if ($useApi) {
            return $this->handleApiBasedValidation(
                $chargebeeService,
                $daysAhead,
            );
        } else {
            return $this->handleDatabaseBasedValidation(
                $chargebeeService,
                $daysAhead,
            );
        }
    }

    /**
     * Handle validation using local database records
     */
    private function handleDatabaseBasedValidation(
        ChargebeeService $chargebeeService,
        int $daysAhead,
    ) {
        $this->info("Using local database for subscription validation...");

        // Get all active local subscriptions with upcoming renewals
        $upcomingSubscriptions = Subscription::where("status", "active")
            ->whereNotNull("chargebee_subscription_id")
            ->whereNotNull("next_charge_scheduled_at")
            ->where("next_charge_scheduled_at", ">=", now())
            ->where(
                "next_charge_scheduled_at",
                "<=",
                now()->addDays($daysAhead),
            )
            ->with(["prescription", "user"])
            ->get();

        if ($upcomingSubscriptions->isEmpty()) {
            $this->info(
                "No upcoming Chargebee renewals found in the next {$daysAhead} days.",
            );
            return 0;
        }

        $this->info(
            "Found " .
                $upcomingSubscriptions->count() .
                " upcoming Chargebee renewals.",
        );

        $stats = $this->processSubscriptions(
            $upcomingSubscriptions,
            $chargebeeService,
        );

        $this->info(
            "Database validation complete. Valid: {$stats["valid"]}. Cancelled: {$stats["cancelled"]}. Refill alerts sent: {$stats["alerts"]}.",
        );

        return 0;
    }

    /**
     * Handle validation using Chargebee API directly
     */
    private function handleApiBasedValidation(
        ChargebeeService $chargebeeService,
        int $daysAhead,
    ) {
        $this->info("Using Chargebee API for subscription validation...");

        // Fetch upcoming renewals from Chargebee API
        $upcomingRenewals = $chargebeeService->getUpcomingRenewals($daysAhead);

        if (empty($upcomingRenewals)) {
            $this->info(
                "No upcoming Chargebee renewals found in the next {$daysAhead} days.",
            );
            return 0;
        }

        $this->info(
            "Found " .
                count($upcomingRenewals) .
                " upcoming Chargebee renewals from API.",
        );

        $cancelCount = 0;
        $validCount = 0;
        $alertCount = 0;
        $notFoundCount = 0;

        foreach ($upcomingRenewals as $renewal) {
            $chargebeeSubscriptionId = $renewal["subscription"]["id"] ?? null;

            if (!$chargebeeSubscriptionId) {
                $this->warn(
                    "Missing Chargebee subscription ID in API response.",
                );
                continue;
            }

            // Find the local subscription
            $subscription = Subscription::where(
                "chargebee_subscription_id",
                $chargebeeSubscriptionId,
            )->first();

            if (!$subscription) {
                $this->warn(
                    "No local subscription found for Chargebee ID: {$chargebeeSubscriptionId}",
                );
                $notFoundCount++;
                continue;
            }

            // Load relationships if not already loaded
            $subscription->load(["prescription", "user"]);

            $stats = $this->processSubscriptions(
                collect([$subscription]),
                $chargebeeService,
            );
            $cancelCount += $stats["cancelled"];
            $validCount += $stats["valid"];
            $alertCount += $stats["alerts"];
        }

        $this->info(
            "API validation complete. Valid: {$validCount}. Cancelled: {$cancelCount}. Refill alerts sent: {$alertCount}. Not found locally: {$notFoundCount}.",
        );

        return 0;
    }

    /**
     * Process a collection of subscriptions and return statistics
     */
    private function processSubscriptions(
        $subscriptions,
        ChargebeeService $chargebeeService,
    ): array {
        $cancelCount = 0;
        $validCount = 0;
        $alertCount = 0;

        foreach ($subscriptions as $subscription) {
            $chargebeeSubscriptionId = $subscription->chargebee_subscription_id;

            if (!$chargebeeSubscriptionId) {
                $this->warn(
                    "Missing Chargebee subscription ID for local subscription {$subscription->id}.",
                );
                continue;
            }

            $prescription = $subscription->prescription;

            if (!$prescription) {
                $this->info(
                    "Subscription {$subscription->id} (Chargebee ID: {$chargebeeSubscriptionId}) has no associated prescription. Cancelling subscription.",
                );

                if (
                    $this->cancelSubscription(
                        $subscription,
                        $chargebeeService,
                        "No associated prescription",
                    )
                ) {
                    $cancelCount++;
                }
                continue;
            }

            // Check for subscriptions needing refill alerts
            if ($this->shouldSendRefillAlert($prescription, $subscription)) {
                if ($this->sendRefillAlert($subscription)) {
                    $alertCount++;
                }
            }

            // Validate prescription
            $validationResult = $this->validatePrescription($prescription);

            if (!$validationResult["valid"]) {
                // Check if the renewal is within the 48-hour cancellation window
                if ($this->isWithinCancellationWindow($subscription)) {
                    $this->info(
                        "Subscription {$subscription->id} (Chargebee ID: {$chargebeeSubscriptionId}) has an invalid prescription and is renewing in the next 48 hours: {$validationResult["reason"]}. Cancelling subscription.",
                    );

                    if (
                        $this->cancelSubscription(
                            $subscription,
                            $chargebeeService,
                            $validationResult["reason"],
                        )
                    ) {
                        $cancelCount++;
                    }
                } else {
                    // It's invalid but not yet time to cancel, just log it.
                    $this->info(
                        "Subscription {$subscription->id} is invalid ({$validationResult["reason"]}) but renewal is not within 48 hours. No action taken yet.",
                    );
                }
            } else {
                // It's valid
                $validCount++;
                $this->info(
                    "Subscription {$subscription->id} (Chargebee ID: {$chargebeeSubscriptionId}) has a valid prescription with {$prescription->refills} refills remaining. Subscription valid.",
                );
            }
        }

        return [
            "cancelled" => $cancelCount,
            "valid" => $validCount,
            "alerts" => $alertCount,
        ];
    }

    /**
     * Validate a prescription and return validation result
     */
    private function validatePrescription(Prescription $prescription): array
    {
        if ($prescription->status !== "active") {
            return [
                "valid" => false,
                "reason" => "Prescription is not active (status: {$prescription->status})",
            ];
        }

        if (Carbon::parse($prescription->end_date)->isPast()) {
            return [
                "valid" => false,
                "reason" => "Prescription has expired",
            ];
        }

        if ($prescription->refills <= 0) {
            return [
                "valid" => false,
                "reason" => "No refills remaining",
            ];
        }

        return ["valid" => true, "reason" => null];
    }

    /**
     * Check if subscription is within cancellation window
     */
    private function isWithinCancellationWindow(
        Subscription $subscription,
    ): bool {
        return Carbon::parse($subscription->next_charge_scheduled_at)->isBefore(
            now()->addDays(2),
        );
    }

    /**
     * Check if should send refill alert
     */
    private function shouldSendRefillAlert(
        Prescription $prescription,
        Subscription $subscription,
    ): bool {
        return $prescription->status === "active" &&
            $prescription->refills <= 0 &&
            $subscription->next_charge_scheduled_at &&
            Carbon::parse($subscription->next_charge_scheduled_at)->isBetween(
                now(),
                now()->addDays(7),
            );
    }

    /**
     * Send refill alert notification
     */
    private function sendRefillAlert(Subscription $subscription): bool
    {
        $this->info(
            "Subscription {$subscription->id} (Chargebee ID: {$subscription->chargebee_subscription_id}) needs a refill. Notifying provider team.",
        );

        try {
            $patient = $subscription->user;
            if ($patient && $patient->current_team_id) {
                $team = Team::find($patient->current_team_id);
                if ($team) {
                    $team->notify(
                        new SubscriptionRefillAlertNotification($subscription),
                    );
                    Log::info(
                        "Dispatched SubscriptionRefillAlertNotification for subscription {$subscription->id}",
                    );
                    return true;
                }
            }
        } catch (\Exception $e) {
            Log::error("Failed to send SubscriptionRefillAlertNotification", [
                "subscription_id" => $subscription->id,
                "error" => $e->getMessage(),
            ]);
        }

        return false;
    }

    /**
     * Cancel a subscription in Chargebee and update local records
     */
    private function cancelSubscription(
        Subscription $subscription,
        ChargebeeService $chargebeeService,
        string $reason,
    ): bool {
        $cancelledInChargebee = $chargebeeService->cancelSubscription(
            $subscription->chargebee_subscription_id,
            $reason,
        );

        if ($cancelledInChargebee) {
            DB::transaction(function () use ($subscription) {
                $subscription->status = "cancelled";
                $subscription->save();

                $prescription = $subscription->prescription;
                if ($prescription) {
                    $prescription->status = "cancelled";
                    $prescription->save();

                    // Also mark the clinical plan as completed
                    if ($prescription->clinicalPlan) {
                        $prescription->clinicalPlan->status = "completed";
                        $prescription->clinicalPlan->save();
                    }
                }
            });

            Log::info(
                "Cancelled Chargebee subscription due to invalid prescription",
                [
                    "chargebee_subscription_id" =>
                        $subscription->chargebee_subscription_id,
                    "local_subscription_id" => $subscription->id,
                    "prescription_id" => $subscription->prescription?->id,
                    "reason" => $reason,
                ],
            );

            // Notify patient if cancelled
            $this->notifyPatientOfCancellation($subscription);
            return true;
        } else {
            $this->error(
                "Failed to cancel Chargebee subscription {$subscription->chargebee_subscription_id}",
            );
            return false;
        }
    }

    /**
     * Notify patient of subscription cancellation
     */
    private function notifyPatientOfCancellation(
        Subscription $subscription,
    ): void {
        try {
            $patient = $subscription->user;
            if ($patient) {
                $patient->notify(
                    new SubscriptionCancelledNotification(
                        $subscription,
                        $subscription->prescription,
                    ),
                );
                Log::info(
                    "Sent SubscriptionCancelledNotification to user {$patient->id} for subscription {$subscription->id}",
                );
            } else {
                Log::warning(
                    "Could not find user to notify for subscription {$subscription->id}",
                );
            }
        } catch (\Exception $e) {
            Log::error("Failed to send SubscriptionCancelledNotification", [
                "subscription_id" => $subscription->id,
                "error" => $e->getMessage(),
            ]);
        }
    }
}
