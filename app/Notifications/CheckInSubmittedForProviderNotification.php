<?php

namespace App\Notifications;

use App\Models\CheckIn;
use App\Models\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Slack\SlackMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\BlockKit\Blocks\ContextBlock;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Support\Facades\Config;

class CheckInSubmittedForProviderNotification extends Notification implements
    ShouldQueue
{
    use Queueable;

    protected CheckIn $checkIn;

    /**
     * Create a new notification instance.
     *
     * @param CheckIn $checkIn
     */
    public function __construct(CheckIn $checkIn)
    {
        // Eager load relationships to prevent N+1 issues in the queue
        $this->checkIn = $checkIn->load(["user", "prescription"]);
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
        $patient = $this->checkIn->user;
        $prescription = $this->checkIn->prescription;

        // Construct the URL to the check-in review page in the provider portal
        $providerUrl = Config::get(
            "app.front_end_url",
            "https://app.heyslim.co.uk"
        );
        $reviewUrl =
            rtrim($providerUrl, "/") .
            "provider/patients/{$this->patient->id}/check-ins/{$this->checkIn->id}";

        return (new SlackMessage())
            ->text(
                "A new check-in has been submitted by {$patient->name} and requires review."
            )
            ->headerBlock("New Patient Check-In Submitted")
            ->contextBlock(function (ContextBlock $block) {
                $block->text(
                    "Submitted at: " .
                        ($this->checkIn->completed_at ?? now())->format(
                            "Y-m-d H:i T"
                        )
                );
            })
            ->sectionBlock(function (SectionBlock $block) use (
                $patient,
                $prescription
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
            })
            ->sectionBlock(function (SectionBlock $block) use ($reviewUrl) {
                $block
                    ->text("Click here to <{$reviewUrl}|Review Check-In>.")
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
            "check_in_id" => $this->checkIn->id,
            "patient_id" => $this->checkIn->user_id,
            "team_id" => $this->checkIn->user->current_team_id,
        ];
    }
}
