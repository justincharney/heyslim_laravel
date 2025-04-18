<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\CheckIn;

class CheckinReminderNotification extends Notification
{
    use Queueable;
    protected $checkIn;

    /**
     * Create a new notification instance.
     */
    public function __construct(CheckIn $checkIn)
    {
        $this->checkIn = $checkIn;
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
        $medication = $this->checkIn->prescription->medication_name;
        $dueDate = $this->checkIn->due_date->format("l, F j, Y");
        $checkInUrl =
            config("app.frontend_url") . "/check-ins/{$this->checkIn->id}";

        return (new MailMessage())
            ->subject("Treatment Check-In Required: {$medication}")
            ->greeting("Hello {$notifiable->name}")
            ->line(
                "Your next order of {$medication} is scheduled soon, and we need you to complete a brief check-in to ensure your treatment is going well."
            )
            ->line("Please complete your check-in by {$dueDate}.")
            ->action("Complete Check-In", $checkInUrl)
            ->line(
                "If you are experiencing any significant side effects, please contact your healthcare provider immediately."
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
            "check_in_id" => $this->checkIn->id,
            "prescription_id" => $this->checkIn->prescription_id,
            "due_date" => $this->checkIn->due_date->format("Y-m-d"),
            "medication" => $this->checkIn->prescription->medication_name,
        ];
    }
}
