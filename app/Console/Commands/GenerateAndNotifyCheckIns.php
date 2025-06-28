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
            // $this->info(
            //     "Processing subscription #{$subscription->id}, next charge on {$nextChargeDate->format(
            //         "Y-m-d"
            //     )}, daysUntilCharge: {$daysUntilCharge}"
            // );

            if ($daysUntilCharge > 7) {
                // $this->info(
                //     "Skipping subscription #{$subscription->id} because it's more than 7 days away."
                // );
                continue;
            }

            // The Due-Date for the Check-In is one day before the next charge date
            $checkInDueDate = $nextChargeDate->copy()->subDays(1);

            // Check if a check-in already exists for this subscription and due date
            // $this->info(
            //     "Checking for existing check-in for subscription #{$subscription->id} with due date {$checkInDueDate->format(
            //         "Y-m-d"
            //     )}"
            // );
            $existingCheckIn = CheckIn::where(
                "subscription_id",
                $subscription->id
            )
                ->where("due_date", $checkInDueDate->format("Y-m-d"))
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
                            "How has your appetite changed since your last injection?",
                        "required" => true,
                        "options" => [
                            [
                                "value" => "Much less hunger",
                                "label" => "Much less hunger",
                            ],
                            [
                                "value" => "Slightly less hunger",
                                "label" => "Slightly less hunger",
                            ],
                            [
                                "value" => "No change",
                                "label" => "No change",
                            ],
                            [
                                "value" => "Increased hunger",
                                "label" => "Increased hunger",
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
                            ["value" => "vomitting", "label" => "Vomitting"],
                            ["value" => "diarrhoea", "label" => "Diarrhoea"],
                            [
                                "value" => "constipation",
                                "label" => "Constipation",
                            ],
                            ["value" => "bloating", "label" => "Bloating"],
                            ["value" => "headache", "label" => "Headache"],
                            ["value" => "none", "label" => "None of the above"],
                        ],
                        "response" => null,
                    ],
                    [
                        "id" => "question_3",
                        "type" => "textarea",
                        "question" =>
                            "Please describe any other symptoms you are experiencing:",
                        "required" => false,
                        "response" => null,
                    ],
                    [
                        "id" => "question_4",
                        "type" => "select",
                        "question" =>
                            "How severe are your symptoms, if you have any?",
                        "options" => [
                            [
                                "value" => "mild",
                                "label" =>
                                    "Mild – not affecting day-to-day activities",
                            ],
                            [
                                "value" => "moderate",
                                "label" =>
                                    "Moderate – noticeable but manageable",
                            ],
                            [
                                "value" => "severe",
                                "label" =>
                                    "Severe – affecting daily life or work",
                            ],
                        ],
                        "required" => false,
                        "response" => null,
                    ],
                    [
                        "id" => "question_5",
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
                        "id" => "question_6",
                        "type" => "number",
                        "question" => "What is your current weight? (kg)",
                        "required" => true,
                        "response" => null,
                        "validation" => [
                            "min" => 30,
                            "max" => 400,
                            "message" => "Weight must be between 30 and 400 kg",
                        ],
                    ],
                    [
                        "id" => "question_7",
                        "type" => "select",
                        "question" =>
                            "Would you be open to increasing your dose next week?",
                        "options" => [
                            ["value" => "Yes", "label" => "Yes – I’m ready"],
                            [
                                "value" => "No",
                                "label" =>
                                    "No – prefer to stay on current dose",
                            ],
                            [
                                "value" => "Maybe",
                                "label" => "Maybe – would like to discuss",
                            ],
                        ],
                        "required" => true,
                        "response" => null,
                    ],
                    [
                        "id" => "question_8",
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
                    "due_date" => $checkInDueDate->format("Y-m-d"),
                ]);

                $generatedCount++;
                $this->info(
                    "Generated check-in for prescription #{$subscription->prescription_id}, due on {$checkInDueDate->format(
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
