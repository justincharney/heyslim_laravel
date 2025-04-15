<?php

namespace App\Notifications;

use App\Models\QuestionnaireSubmission;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ScheduleConsultationNotification extends Notification
{
    use Queueable;

    protected $submission;
    protected $provider;
    protected $bookingUrl;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        QuestionnaireSubmission $submission,
        User $provider,
        string $bookingUrl
    ) {
        $this->submission = $submission;
        $this->provider = $provider;
        $this->bookingUrl = $bookingUrl;
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
            ->subject("Schedule Your Consultation")
            ->greeting("Hello " . $notifiable->name)
            ->line(
                'Thank you for submitting your questionnaire for "' .
                    $questionnaire->title .
                    '".'
            )
            ->line(
                "Your submission is being reviewed by our medical team. The next step is to schedule a consultation with one of our healthcare providers."
            )
            ->line(
                "Dr. " .
                    $this->provider->name .
                    " is available to discuss your treatment plan."
            )
            ->action("Schedule Your Consultation", $this->bookingUrl)
            ->line(
                "This scheduling link is for one-time use only and will expire after booking."
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
            "provider_id" => $this->provider->id,
            "provider_name" => $this->provider->name,
            "booking_url" => $this->bookingUrl,
        ];
    }
}
