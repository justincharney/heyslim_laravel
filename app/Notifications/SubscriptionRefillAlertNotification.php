<?php

namespace App\Notifications;

use App\Models\Subscription;
use App\Models\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Slack\SlackMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\BlockKit\Blocks\ContextBlock;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Support\Facades\Config;
use Carbon\Carbon;

class SubscriptionRefillAlertNotification extends Notification implements
    ShouldQueue
{
    use Queueable;

    protected Subscription $subscription;

    /**
     * Create a new notification instance.
     *
     * @param Subscription $subscription
     */
    public function __construct(Subscription $subscription)
    {
        // Eager load relationships to prevent N+1 issues in the queue
        $this->subscription = $subscription->load(["user", "prescription"]);
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  Team  $notifiable The Team model instance
     * @return array
     */
    public function via(Team $notifiable): array
    {
        // Only attempt to send via Slack if the team has a configured channel name.
        return !empty($notifiable->slack_notification_channel) ? ["slack"] : [];
    }

    /**
     * Get the Slack representation of the notification.
     *
     * @param  Team  $notifiable The Team model instance
     * @return \Illuminate\Notifications\Slack\SlackMessage
     */
    public function toSlack(Team $notifiable): SlackMessage
    {
        $patient = $this->subscription->user;
        $prescription = $this->subscription->prescription;

        // Construct the URL to the patient's detail page in the provider portal
        $providerUrl = Config::get(
            "app.front_end_url",
            "http://app.heyslim.co.uk"
        );
        $patientUrl = rtrim($providerUrl, "/") . "/patients/{$patient->id}";

        $nextChargeDate = Carbon::parse(
            $this->subscription->next_charge_scheduled_at
        )->format("Y-m-d");

        return (new SlackMessage())
            ->text(
                "Subscription for {$patient->name} needs attention: 0 refills remaining and next charge is on {$nextChargeDate}."
            )
            ->headerBlock("Subscription Refill Alert")
            ->contextBlock(function (ContextBlock $block) {
                $block->text(
                    "Action may be required to ensure continuity of care."
                );
            })
            ->sectionBlock(function (SectionBlock $block) use (
                $patient,
                $prescription,
                $nextChargeDate
            ) {
                $block
                    ->field("*Patient:*\n{$patient->name} ({$patient->email})")
                    ->markdown();
                if ($prescription) {
                    $block
                        ->field(
                            "*Medication:*\n{$prescription->medication_name}"
                        )
                        ->markdown();
                }
                $block->field("*Refills Remaining:*\n0")->markdown();
                $block
                    ->field("*Next Charge Date:*\n{$nextChargeDate}")
                    ->markdown();
            })
            ->sectionBlock(function (SectionBlock $block) use ($patientUrl) {
                $block
                    ->text(
                        "Click here to <{$patientUrl}|View Patient Profile>."
                    )
                    ->markdown();
            });
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable): array
    {
        return [
            "subscription_id" => $this->subscription->id,
            "patient_id" => $this->subscription->user_id,
            "team_id" => $this->subscription->user->current_team_id,
        ];
    }
}
