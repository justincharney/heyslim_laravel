<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ZendeskService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Spatie\Newsletter\Facades\Newsletter;
use Illuminate\Support\Facades\Log;

class ProcessInactiveUsers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = "app:process-inactive-users";

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Adds inactive users to a Mailchimp campaign and creates a lead in Zendesk Sell.";

    protected ZendeskService $zendeskService;

    /**
     * Create a new command instance.
     *
     * @param ZendeskService $zendeskService
     */
    public function __construct(ZendeskService $zendeskService)
    {
        parent::__construct();
        $this->zendeskService = $zendeskService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $cutoff = Carbon::now()->subHours(1);

        // Find users who:
        // 1. Are patients.
        // 2. Registered more than 1 hour ago.
        // 3. Have NOT completed their profile OR have no questionnaire submissions not in draft.
        // 4. Have NOT had a Zendesk lead created yet.
        $inactiveUsers = User::where("created_at", "<", $cutoff)
            ->where(function ($query) {
                $query
                    ->where("profile_completed", false)
                    ->orWhereDoesntHave("questionnaireSubmissions", function (
                        $subQuery,
                    ) {
                        $subQuery->whereIn("status", [
                            "submitted",
                            "approved",
                            "pending_payment",
                            "rejected",
                        ]);
                    });
            })
            ->whereNull("zendesk_lead_created_at") // Don't process users already sent to Zendesk
            ->get();

        if ($inactiveUsers->isEmpty()) {
            $this->info("No new inactive users to process.");
            return 0;
        }

        $this->info(
            "Found {$inactiveUsers->count()} (POTENTIAL) new inactive users to process.",
        );
        $processedCount = 0;

        foreach ($inactiveUsers as $user) {
            // Set the team context for this user and check if they have the patient role
            if ($user->current_team_id) {
                setPermissionsTeamId($user->current_team_id);
            }

            // Skip if user is not a patient
            if (!$user->hasRole("patient")) {
                continue;
            }

            // Step 1: Create the lead in Zendesk Sell
            $leadCreated = $this->zendeskService->createLead($user);

            if ($leadCreated) {
                // Mark the user as processed to avoid creating duplicate leads
                $user->zendesk_lead_created_at = now();
                $user->save();
                $this->info(
                    "Successfully created Zendesk lead for {$user->email}.",
                );

                // Step 2: Add the user to the Mailchimp 'inactive-user' drip campaign
                if (Newsletter::isSubscribed($user->email)) {
                    continue;
                }

                $mergeFields = [];

                if (!empty($user->name)) {
                    $mergeFields["FNAME"] = $user->name;
                }

                Newsletter::subscribe(
                    $user->email,
                    $mergeFields,
                    config("newsletter.default_list_name"),
                    ["tags" => ["inactive-user"]],
                );

                if (Newsletter::lastActionSucceeded()) {
                    $this->info(
                        "Subscribed {$user->email} to the Mailchimp drip campaign.",
                    );
                } else {
                    $this->error(
                        "Failed to subscribe {$user->email} to Mailchimp: " .
                            Newsletter::getLastError(),
                    );
                    // Log this failure but don't stop the process
                    Log::warning(
                        "Mailchimp subscription failed for user after Zendesk lead was created.",
                        ["user_id" => $user->id],
                    );
                }

                $processedCount++;
            } else {
                $this->error(
                    "Failed to create Zendesk lead for {$user->email}.",
                );
            }
        }

        $this->info("Processed {$processedCount} users.");
        return 0;
    }
}
