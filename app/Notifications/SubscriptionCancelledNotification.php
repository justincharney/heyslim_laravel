<?php

namespace App\Notifications;

use App\Models\Subscription;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionCancelledNotification extends Notification implements
    ShouldQueue
{
    use Queueable;

    protected Subscription $subscription;
    protected Prescription $prescription;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        Subscription $subscription,
        Prescription $prescription
    ) {
        $this->subscription = $subscription;
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
        $patientName = $notifiable->name;
        $medicationName = $this->prescription->medication_name;
        $dashboardUrl = config("app.frontend_url") . "/dashboard";

        return (new MailMessage())
            ->subject(
                "Your " . $medicationName . " Subscription Has Been Cancelled"
            )
            ->greeting("Hello " . $patientName . ",")
            ->line(
                "Your subscription for " .
                    $medicationName .
                    " has been automatically cancelled because you have no refills remaining on your current prescription, or your prescription is inactive."
            )
            ->line(
                "To continue your treatment, you will need a new prescription. We recommend starting a new online consultation for your provider to evaluate your options."
            )
            ->action("Go to Dashboard", $dashboardUrl)
            ->line(
                'If you have any questions, please don\'t hesitate to contact our support team.'
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
            "subscription_id" => $this->subscription->id,
            "prescription_id" => $this->prescription->id,
            "medication_name" => $this->prescription->medication_name,
            "reason" => "no_refills_remaining",
        ];
    }
}
