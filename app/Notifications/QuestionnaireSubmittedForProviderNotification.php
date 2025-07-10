<?php

namespace App\Notifications;

use App\Models\QuestionnaireSubmission;
use App\Models\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\BlockKit\Blocks\ActionsBlock;
use Illuminate\Notifications\Slack\BlockKit\Blocks\ContextBlock;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage;

class QuestionnaireSubmittedForProviderNotification
    extends Notification
    implements ShouldQueue
{
    use Queueable;

    protected QuestionnaireSubmission $submission;

    /**
     * Create a new notification instance.
     *
     * @param QuestionnaireSubmission $submission
     */
    public function __construct(QuestionnaireSubmission $submission)
    {
        // Eager load relationships to prevent N+1 issues in the queue
        $this->submission = $submission->load(["user", "questionnaire"]);
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  Team $notifiable The Team model instance
     * @return array
     */
    public function via(Team $notifiable): array
    {
        // Only attempt to send via Slack if the team has a configured channel name.
        // The routing will be handled by the `routeNotificationForSlack` method on the Team model.
        return !empty($notifiable->slack_notification_channel) ? ["slack"] : [];
    }

    /**
     * Get the Slack representation of the notification.
     *
     * @param  Team $notifiable The Team model instance
     * @return \Illuminate\Notifications\Slack\SlackMessage
     */
    public function toSlack(Team $notifiable): SlackMessage
    {
        $patient = $this->submission->user;
        $questionnaire = $this->submission->questionnaire;

        $providerUrl = config("app.front_end_url", "https://app.heyslim.co.uk");
        $submissionUrl =
            rtrim($providerUrl, "/") .
            "/patients/{$patient->id}/questionnaires/{$this->submission->id}";

        return (new SlackMessage())
            ->text(
                "A new questionnaire has been submitted by {$patient->name} and requires review."
            ) // This is fallback text for notifications
            ->headerBlock("New Questionnaire Submission")
            ->contextBlock(function (ContextBlock $block) {
                $block->text(
                    "Submitted at: " .
                        ($this->submission->submitted_at ?? now())->format(
                            "Y-m-d H:i T"
                        )
                );
            })
            ->sectionBlock(function (SectionBlock $block) use (
                $patient,
                $questionnaire
            ) {
                $block
                    ->field("*Patient:*\n{$patient->name} ({$patient->email})")
                    ->markdown();
                $block
                    ->field("*Questionnaire:*\n{$questionnaire->title}")
                    ->markdown();
            })
            ->actionsBlock(function (ActionsBlock $block) use ($submissionUrl) {
                $block->button("View Submission", $submissionUrl)->primary();
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
            "submission_id" => $this->submission->id,
            "patient_id" => $this->submission->user_id,
            "team_id" => $this->submission->user->current_team_id,
        ];
    }
}
