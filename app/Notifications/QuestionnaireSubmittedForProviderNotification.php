<?php

namespace App\Notifications;

use App\Models\QuestionnaireSubmission;
use App\Models\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\SlackMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

class QuestionnaireSubmittedForProviderNotification extends Notification implements ShouldQueue
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
        // Only attempt to send via Slack if the team has a configured webhook URL.
        // The routing will be handled by the `routeNotificationForSlack` method on the Team model.
        return !empty($notifiable->slack_webhook_url) ? ["slack"] : [];
    }

    /**
     * Get the Slack representation of the notification.
     *
     * @param  Team $notifiable The Team model instance
     * @return \Illuminate\Notifications\Messages\SlackMessage
     */
    public function toSlack(Team $notifiable): SlackMessage
    {
        $patient = $this->submission->user;
        $questionnaire = $this->submission->questionnaire;

        // Construct the URL to the submission details page in the provider portal
        // Using config() helper is safer, with a fallback.
        $providerUrl = Config::get(
            "app.provider_frontend_url",
            "http://localhost:3001"
        );
        $submissionUrl =
            rtrim($providerUrl, "/") .
            "/patients/{$patient->id}/questionnaires/{$this->submission->id}";

        return (new SlackMessage())
            ->from("HeySlim Platform", ":syringe:")
            ->content(
                "A new questionnaire has been submitted and requires review."
            ) // Fallback content for notifications that don't support blocks
            ->attachment(function ($attachment) use (
                $patient,
                $questionnaire,
                $submissionUrl
            ) {
                $attachment
                    ->title(
                        "New Questionnaire Submission: " . $questionnaire->title,
                        $submissionUrl
                    )
                    ->fields([
                        "Patient" =>
                            $patient->name . " (" . $patient->email . ")",
                        "Submitted At" => $this->submission->submitted_at
                            ? $this->submission->submitted_at->format(
                                "Y-m-d H:i T"
                            )
                            : now()->format("Y-m-d H:i T"),
                    ])
                    ->color("#3AA3E3") // A nice blue color
                    ->action("View Submission", $submissionUrl, "primary");
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
