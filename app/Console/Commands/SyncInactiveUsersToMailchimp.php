<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Spatie\Newsletter\Facades\Newsletter;
use App\Models\User;

class SyncInactiveUsersToMailchimp extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = "app:sync-inactive-users-to-mailchimp";

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Adds users who have not completed their profile or a questionnaire to a Mailchimp drip campaign.";

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Define the cutoff time (e.g., users who registered more than 1 hour ago)
        $cutoff = Carbon::now()->subHours(1);

        // Find users who registered before the cutoff and haven't completed their profile OR haven't submitted a questionnaire
        $inactiveUsers = User::where("created_at", "<=", $cutoff)
            ->where(function ($query) {
                $query
                    ->where("profile_completed", false)
                    ->orWhereDoesntHave("questionnaireSubmissions");
            })
            ->get();

        if ($inactiveUsers->isEmpty()) {
            $this->info("No inactive users to sync.");
            return 0;
        }

        $this->info("Found {$inactiveUsers->count()} inactive users to sync.");
        $syncedCount = 0;

        foreach ($inactiveUsers as $user) {
            if (Newsletter::isSubscribed($user->email)) {
                continue;
            }

            $mergeFields = [];
            if (!empty($user->name)) {
                $mergeFields["FNAME"] = $user->name;
            }

            // Subscribe the user to our Mailchimp list
            Newsletter::subscribe(
                $user->email,
                $mergeFields,
                config("newsletter.default_list_name"),
            );

            if (Newsletter::lastActionSucceeded()) {
                $this->info("Subscribed {$user->email} to the drip campaign");
                $syncedCount++;
            } else {
                $this->error("Failed to subscribe {$user->email}.");
                $this->error("Error: " . Newsletter::getLastError());
            }
        }

        $this->info(
            "Sync complete. {$syncedCount} users were added to the drip campaign.",
        );
        return 0;
    }
}
