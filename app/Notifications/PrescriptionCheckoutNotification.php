<?php

namespace App\Notifications;

use App\Models\Prescription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PrescriptionCheckoutNotification extends Notification implements
    ShouldQueue
{
    use Queueable;

    protected $prescription;
    protected $checkoutUrl;

    /**
     * Create a new notification instance.
     */
    public function __construct(Prescription $prescription, string $checkoutUrl)
    {
        $this->prescription = $prescription;
        $this->checkoutUrl = $checkoutUrl;
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
        $providerName =
            $this->prescription->prescriber->name ?? "your healthcare provider";

        return (new MailMessage())
            ->subject("Your Prescription for {$medicationName}")
            ->greeting("Hello " . $notifiable->name . ",")
            ->line(
                "Your prescription for {$medicationName} has been created by {$providerName}."
            )
            ->line(
                "To receive your first supply, please complete the subscription checkout using the link below."
            )
            ->action("Complete Checkout", $this->checkoutUrl)
            ->line(
                "Once your checkout is complete, your order will be processed by our pharmacy."
            )
            ->line(
                "If you have any questions, please use the chat feature in your patient portal."
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
            "checkout_url" => $this->checkoutUrl,
        ];
    }
}
