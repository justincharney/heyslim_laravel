<?php

namespace App\Notifications;

use App\Models\Prescription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PrescriptionSignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected Prescription $prescription;

    /**
     * Create a new notification instance.
     *
     * @param Prescription $prescription
     */
    public function __construct(Prescription $prescription)
    {
        $this->prescription = $prescription;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable): array
    {
        return ["mail"];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable): MailMessage
    {
        $dashboardUrl = config("app.frontend_url") . "/dashboard";

        return new MailMessage()
            ->subject("Your heySlim Treatment Has Been Approved")
            ->greeting("Hello " . $notifiable->name . ",")
            ->line(
                "Good news! Your prescription for " .
                    $this->prescription->medication_name .
                    " has been approved by your provider.",
            )
            ->line('Here\'s what to expect next:')
            ->line(
                "1. **GP Letter:** You will receive a separate email with a letter for your GP. We recommend sharing this with them to keep them informed about your treatment.",
            )
            ->line(
                "2. **ID Verification:** You will also receive an email with instructions for ID verification. This is a required step before our pharmacy can dispense your medication.",
            )
            ->line(
                '3. **Order Processing:** Once your ID is verified, your first order will be processed and shipped. You\'ll receive tracking information once it leaves the pharmacy.',
            )
            ->action(
                "Go to Your Dashboard",
                $dashboardUrl,
            )->line('If you need support, you can reach us at support@heyslim.co.uk or via the live chat on our
            website. For medical questions, please use the chat feature in your patient portal to speak with your provider.');
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
            "prescription_id" => $this->prescription->id,
            "medication_name" => $this->prescription->medication_name,
        ];
    }
}
