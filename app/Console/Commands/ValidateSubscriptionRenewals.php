<?php

namespace App\Console\Commands;

use App\Models\Prescription;
use App\Models\User;
use App\Services\RechargeService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Models\Subscription;
use App\Models\Team;
use App\Notifications\SubscriptionCancelledNotification;
use App\Notifications\SubscriptionRefillAlertNotification;
use Illuminate\Support\Facades\DB;

class ValidateSubscriptionRenewals extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = "app:validate-subscription-renewals";

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Check upcoming subscription renewals and cancel those without valid prescriptions";

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Starting subscription renewal validation...");

        $rechargeService = app(RechargeService::class);
        // Fetch all renewals in the next 7 days to handle both alerts and cancellations.
        $upcomingRenewals = $rechargeService->getUpcomingRenewals(7);

        if (empty($upcomingRenewals)) {
            $this->info("No upcoming renewals found.");
            return;
        }

        $this->info(
            "Found " . count($upcomingRenewals) . " upcoming renewals."
        );

        $cancelCount = 0;
        $validCount = 0;

        foreach ($upcomingRenewals as $renewal) {
            $rechargeSubscriptionId = $renewal["id"] ?? null; // Renamed for clarity
            $productTitle = $renewal["product_title"] ?? "Unknown Product";

            if (!$rechargeSubscriptionId) {
                $this->warn("Missing Recharge subscription ID for renewal.");
                continue;
            }

            $subscription = Subscription::where(
                "recharge_subscription_id",
                $rechargeSubscriptionId
            )->first();

            if (!$subscription) {
                $this->warn(
                    "No internal subscription record found for Recharge subscription ID: {$rechargeSubscriptionId}"
                );
                continue;
            }

            $prescription = $subscription->prescription;

            if (!$prescription) {
                $this->info(
                    "Subscription {$subscription->id} (Recharge ID: {$rechargeSubscriptionId}) has no associated prescription. Cancelling subscription."
                );

                $cancelledInRecharge = $rechargeService->cancelSubscription(
                    $rechargeSubscriptionId,
                    "No associated prescription",
                    "System automatically cancelled subscription due to no prescription found."
                );

                if ($cancelledInRecharge) {
                    DB::transaction(function () use ($subscription) {
                        $subscription->status = "cancelled";
                        $subscription->save();
                    });
                    $cancelCount++;
                    Log::info(
                        "Cancelled subscription due to no associated prescription",
                        [
                            "recharge_subscription_id" => $rechargeSubscriptionId,
                            "local_subscription_id" => $subscription->id,
                            "product_title" => $productTitle,
                        ]
                    );
                } else {
                    $this->error(
                        "Failed to cancel Recharge subscription {$rechargeSubscriptionId}"
                    );
                }
                continue;
            }

            // Check for subscriptions needing refill alerts
            if (
                $prescription->status === "active" &&
                $prescription->refills <= 0 &&
                $subscription->next_charge_scheduled_at &&
                Carbon::parse(
                    $subscription->next_charge_scheduled_at
                )->isBetween(now(), now()->addDays(7))
            ) {
                $this->info(
                    "Subscription {$subscription->id} (Recharge ID: {$rechargeSubscriptionId}) needs a refill. Notifying provider team."
                );

                try {
                    $patient = $subscription->user;
                    if ($patient && $patient->current_team_id) {
                        $team = Team::find($patient->current_team_id);
                        if ($team) {
                            $team->notify(
                                new SubscriptionRefillAlertNotification(
                                    $subscription
                                )
                            );
                            Log::info(
                                "Dispatched SubscriptionRefillAlertNotification for subscription {$subscription->id}"
                            );
                        }
                    }
                } catch (\Exception $e) {
                    Log::error(
                        "Failed to send SubscriptionRefillAlertNotification",
                        [
                            "subscription_id" => $subscription->id,
                            "error" => $e->getMessage(),
                        ]
                    );
                }
            }

            $isInvalid = false;
            $cancellationReason = "";

            if ($prescription->status !== "active") {
                $isInvalid = true;
                $cancellationReason = "Prescription is not active";
            } elseif (Carbon::parse($prescription->end_date)->isPast()) {
                $isInvalid = true;
                $cancellationReason = "Prescription has expired";
            } elseif ($prescription->refills <= 0) {
                $isInvalid = true;
                $cancellationReason = "No refills remaining";
            }

            // Handle based on validity and timing
            if ($isInvalid) {
                // Check if the renewal is within the 48-hour cancellation window
                if (
                    Carbon::parse(
                        $subscription->next_charge_scheduled_at
                    )->isBefore(now()->addDays(2))
                ) {
                    $this->info(
                        "Subscription {$subscription->id} (Recharge ID: {$rechargeSubscriptionId}) has an invalid prescription and is renewing in the next 48 hours: {$cancellationReason}. Cancelling subscription."
                    );

                    $cancelledInRecharge = $rechargeService->cancelSubscription(
                        $rechargeSubscriptionId,
                        $cancellationReason,
                        "System automatically cancelled subscription due to {$cancellationReason}."
                    );

                    if ($cancelledInRecharge) {
                        DB::transaction(function () use (
                            $subscription,
                            $prescription
                        ) {
                            $subscription->status = "cancelled";
                            $subscription->save();
                            $prescription->status = "cancelled";
                            $prescription->save();
                        });

                        $cancelCount++;
                        Log::info(
                            "Cancelled subscription due to invalid prescription",
                            [
                                "recharge_subscription_id" => $rechargeSubscriptionId,
                                "local_subscription_id" => $subscription->id,
                                "prescription_id" => $prescription->id,
                                "reason" => $cancellationReason,
                            ]
                        );

                        // Notify patient if cancelled
                        try {
                            $patient = $subscription->user;
                            if ($patient) {
                                $patient->notify(
                                    new SubscriptionCancelledNotification(
                                        $subscription,
                                        $prescription
                                    )
                                );
                                Log::info(
                                    "Sent SubscriptionCancelledNotification to user {$patient->id} for subscription {$subscription->id}"
                                );
                            } else {
                                Log::warning(
                                    "Could not find user to notify for subscription {$subscription->id}"
                                );
                            }
                        } catch (\Exception $e) {
                            Log::error(
                                "Failed to send SubscriptionCancelledNotification",
                                [
                                    "subscription_id" => $subscription->id,
                                    "error" => $e->getMessage(),
                                ]
                            );
                        }
                    } else {
                        $this->error(
                            "Failed to cancel Recharge subscription {$rechargeSubscriptionId}"
                        );
                    }
                } else {
                    // It's invalid but not yet time to cancel, just log it.
                    $this->info(
                        "Subscription {$subscription->id} is invalid ({$cancellationReason}) but renewal is not within 48 hours. No action taken yet."
                    );
                }
            } else {
                // It's valid
                $validCount++;
                $this->info(
                    "Subscription {$subscription->id} (Recharge ID: {$rechargeSubscriptionId}) has a valid prescription with {$prescription->refills} refills remaining. Subscription valid."
                );
            }
        }

        $this->info(
            "Validation complete. Valid: {$validCount}. Cancelled: {$cancelCount}."
        );
    }
}
