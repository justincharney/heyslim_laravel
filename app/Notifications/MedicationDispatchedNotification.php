<?php

namespace App\Notifications;

use App\Models\Prescription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MedicationDispatchedNotification extends Notification implements
    ShouldQueue
{
    use Queueable;

    protected Prescription $prescription;

    /**
     * Create a new notification instance.
     */
    public function __construct(Prescription $prescription)
    {
        $this->prescription = $prescription;
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
        $medicationName = $this->prescription->medication_name;
        $videoUrl = "https://www.youtube.com/watch?v=jnELVSRSWXs";

        return new MailMessage()
            ->subject("Your medication is on the way!")
            ->greeting("Hi {$notifiable->name},")
            ->line(
                "Great news, your prescription for {$medicationName} has been dispatched and is on its way to you. It should arrive within 1–2 working days at your chosen address.",
            )
            ->line(
                "We’re excited to support you on your weight loss journey and can’t wait to see your progress.",
            )
            ->line(
                "In the meantime, here’s a quick guide on how to store and use {$medicationName} safely:",
            )
            ->action(
                "Watch: How to use and store {$medicationName} video",
                $videoUrl,
            )
            ->line(
                "If you have any questions, feel free to get in touch via email/chat or message your doctor directly through your patient portal.",
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
            "prescription_id" => $this->prescription->id,
            "medication_name" => $this->prescription->medication_name,
        ];
    }
}
