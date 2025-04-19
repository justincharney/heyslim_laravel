<?php

namespace App\Notifications;

use App\Models\QuestionnaireSubmission;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class QuestionnaireRejectedNotification extends Notification implements
    ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        QuestionnaireSubmission $submission,
        User $provider,
        string $reviewNotes
    ) {
        $this->submission = $submission;
        $this->provider = $provider;
        $this->reviewNotes = $reviewNotes;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ["mail"];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $questionnaire = $this->submission->questionnaire;

        return (new MailMessage())
            ->subject("Your Treatment Plan Request Was Rejected")
            ->greeting("Hello " . $notifiable->name)
            ->line(
                'Your submission for "' .
                    $questionnaire->title .
                    '" has been reviewed and has been rejected with the following feedback.'
            )
            ->line("Provider feedback: " . $this->reviewNotes)
            ->line(
                "Any authorized payments on your account for this treatment plan have been reversed."
            )
            ->line(
                "If you have any questions, please contact our support team."
            );
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            "submission_id" => $this->submission->id,
            "questionnaire_id" => $this->submission->questionnaire_id,
            "questionnaire_title" => $this->submission->questionnaire->title,
            "reviewer_id" => $this->provider->id,
            "reviewer_name" => $this->provider->name,
            "review_notes" => $this->reviewNotes,
        ];
    }
}
