<?php

namespace App\Notifications;

use App\Models\QuestionnaireSubmission;
use App\Models\User;
use App\Utils\StringUtils;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ScheduleConsultationNotification extends Notification implements
    ShouldQueue
{
    use Queueable;

    protected $submission;
    protected $provider;
    protected $bookingUrl;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        ?QuestionnaireSubmission $submission,
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
        $mail = new MailMessage()
            ->subject("Schedule Your Consultation")
            ->greeting("Hello " . $notifiable->name);

        if ($this->submission && $this->submission->questionnaire) {
            $mail
                ->line(
                    'Thank you for submitting your questionnaire for "' .
                        $this->submission->questionnaire->title .
                        '"'
                )
                ->line(
                    "Your submission is being reviewed by our medical team. The next step is to schedule a consultation with one of our healthcare providers."
                );
        } else {
            $mail->line(
                "You or your provider have requested a consultation. Please use the link below to schedule your appointment."
            );
        }

        $mail
            ->line(
                "Dr. " .
                    StringUtils::removeTitles($this->provider->name) .
                    " is available to discuss your health goals and treatment options."
            )
            ->action("Schedule Your Consultation", $this->bookingUrl)
            ->line(
                "This scheduling link is for one-time use only and will expire after booking."
            )
            ->line(
                "If you have any questions, please contact our support team."
            );

        return $mail;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $data = [
            "provider_id" => $this->provider->id,
            "provider_name" => $this->provider->name,
            "booking_url" => $this->bookingUrl,
        ];

        if ($this->submission) {
            $data["submission_id"] = $this->submission->id;
            if ($this->submission->questionnaire) {
                $data["questionnaire_id"] = $this->submission->questionnaire_id;
                $data["questionnaire_title"] =
                    $this->submission->questionnaire->title;
            }
        }

        return $data;
    }
}
