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

    protected $prescription;

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
        $dashboardUrl = config("app.front_end_url") . "/dashboard";

        return (new MailMessage())
            ->subject("Your Prescription is Active!")
            ->greeting("Hello " . $notifiable->name . ",")
            ->line(
                "Good news! Your prescription for " .
                    $medicationName .
                    " has been signed by your prescriber and is now active."
            )
            ->line(
                "Your medication will be processed by our pharmacy and shipped shortly."
            )
            ->action("View Prescription Details", $dashboardUrl)
            ->line(
                "If you have any questions, please use the chat feature associated with your prescription."
            )
            ->line("Thank you for using " . config("app.title") . ".");
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
