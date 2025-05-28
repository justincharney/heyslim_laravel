<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\Prescription;
use App\Models\CheckIn;
use App\Notifications\CheckInReminderNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateAndNotifyCheckIns extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = "app:generate-and-notify-check-ins";

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Generate check-ins for active subscriptions and send notifications";

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Starting check-in generation and notification process...");

        // 1. Find active subscriptions with next charge date set
        $subscriptions = Subscription::where("status", "active")
            ->whereNotNull("next_charge_scheduled_at")
            ->whereHas("prescription", function ($query) {
                $query->where("status", "active");
            })
            ->with(["prescription", "user"])
            ->get();

        $this->info(
            "Found {$subscriptions->count()} active subscriptions with upcoming charges."
        );

        $generatedCount = 0;
        $notifiedCount = 0;
        $todayDate = Carbon::today();

        foreach ($subscriptions as $subscription) {
            $nextChargeDate = Carbon::parse(
                $subscription->next_charge_scheduled_at
            );

            // Only create check-ins when next charge is within 7 days
            $daysUntilCharge = $todayDate->diffInDays($nextChargeDate, false);
            if ($daysUntilCharge > 7) {
                continue;
            }

            // Check if a check-in already exists for this subscription and due date
            $existingCheckIn = CheckIn::where(
                "subscription_id",
                $subscription->id
            )
                ->where("due_date", $nextChargeDate->format("Y-m-d"))
                ->first();

            // Log::info(
            //     "Existing check-in: " . ($existingCheckIn ? "Yes" : "No")
            // );

            if (!$existingCheckIn) {
                $questionsAndResponses = [
                    [
                        "id" => "question_1",
                        "type" => "select",
                        "question" =>
                            "How have you been feeling on your current medication?",
                        "required" => true,
                        "options" => [
                            [
                                "value" => "better",
                                "label" => "Better than before",
                            ],
                            ["value" => "same", "label" => "About the same"],
                            [
                                "value" => "worse",
                                "label" => "Worse than before",
                            ],
                        ],
                        "response" => null,
                    ],
                    [
                        "id" => "question_2",
                        "type" => "multi-select",
                        "question" =>
                            "Have you experienced any of the following side effects?",
                        "required" => true,
                        "options" => [
                            ["value" => "nausea", "label" => "Nausea"],
                            ["value" => "headache", "label" => "Headache"],
                            ["value" => "dizziness", "label" => "Dizziness"],
                            ["value" => "fatigue", "label" => "Fatigue"],
                            ["value" => "none", "label" => "None of the above"],
                        ],
                        "response" => null,
                    ],
                    [
                        "id" => "question_3",
                        "type" => "select",
                        "question" =>
                            "Are you taking your medication as prescribed?",
                        "options" => [
                            ["value" => "yes", "label" => "Yes"],
                            ["value" => "no", "label" => "No"],
                        ],
                        "required" => true,
                        "response" => null,
                    ],
                    [
                        "id" => "question_4",
                        "type" => "textarea",
                        "question" =>
                            "Any additional information you'd like to share with your provider?",
                        "required" => false,
                        "response" => null,
                    ],
                ];

                // Create a new check-in with the due date set to one day before the next charge date
                $checkIn = CheckIn::create([
                    "user_id" => $subscription->user_id,
                    "prescription_id" => $subscription->prescription_id,
                    "subscription_id" => $subscription->id,
                    "status" => "pending",
                    "questions_and_responses" => $questionsAndResponses,
                    "due_date" => $nextChargeDate->subDays(1)->format("Y-m-d"),
                ]);

                $generatedCount++;
                $this->info(
                    "Generated check-in for prescription #{$subscription->prescription_id}, due on {$nextChargeDate->format(
                        "Y-m-d"
                    )}"
                );
            } else {
                $checkIn = $existingCheckIn;
            }

            // Send notification if notification hasn't been sent
            if (!$checkIn->notification_sent) {
                $patient = $subscription->user;

                // Send notification
                $patient->notify(new CheckInReminderNotification($checkIn));

                // Mark as notified
                $checkIn->update(["notification_sent" => true]);

                $notifiedCount++;
                $this->info(
                    "Sent notification for check-in #{$checkIn->id} to patient #{$patient->id}"
                );
            }
        }

        $this->info(
            "Process completed. Generated {$generatedCount} new check-ins, sent {$notifiedCount} notifications."
        );
    }
}
