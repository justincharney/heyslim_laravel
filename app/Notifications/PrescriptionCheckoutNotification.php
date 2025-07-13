<?php

namespace App\Notifications;

use App\Models\Prescription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\VonageMessage;
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
        $channels = ["mail"];

        if (!empty($notifiable->phone_number)) {
            $channels[] = "vonage";
        }

        return $channels;
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
            ->subject(
                "Action Required: Complete Your {$medicationName} Prescription Checkout"
            )
            ->greeting("Hello " . $notifiable->name . ",")
            ->line(
                "Please complete your checkout to receive your {$medicationName} prescription, prescribed by {$providerName}."
            )
            ->line(
                "Your first supply is ready, but you must complete the subscription checkout using the link below."
            )
            ->action("Complete Your Checkout Now", $this->checkoutUrl)
            ->line(
                "Once your checkout is complete, your order will be processed by our pharmacy."
            )
            ->line(
                "For support, you can contact us at support@heyslim.co.uk or by live-chat on our website. If you need to speak with your provider about a medical question, please use the chat feature in your patient portal."
            )
            ->line("Thank you for using " . config("app.title") . ".");
    }

    /**
     * Get the Vonage / SMS representation of the notification.
     */
    public function toVonage(object $notifiable): VonageMessage
    {
        $message =
            "Your heySlim prescription is ready for payment. Check your email to complete your order.";

        return (new VonageMessage())->content($message);
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
