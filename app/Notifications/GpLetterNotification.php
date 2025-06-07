<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\Prescription;

class GpLetterNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected Prescription $prescription;
    protected string $pdfBinary;
    protected string $pdfFilename;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        Prescription $prescription,
        string $pdfBinary,
        string $pdfFilename
    ) {
        $this->prescription = $prescription;
        $this->pdfBinary = $pdfBinary;
        $this->pdfFilename = $pdfFilename;
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

        // decode the base64 content back to binary for attachment
        $pdfContent = base64_decode($this->pdfBinary);

        return (new MailMessage())
            ->subject(
                "Important: Information regarding your prescription for {$medicationName}"
            )
            ->greeting("Dear {$notifiable->name},")
            ->line("Your prescription for {$medicationName} has been created.")
            ->line(
                "Please find attached a letter intended for you to share with your General Practitioner (GP). This letter contains important details about your treatment and is provided to help ensure continuity of care."
            )
            ->line(
                "We strongly recommend you provide this letter to your GP at your earliest convenience."
            )
            ->line(
                "If you have any questions about your treatment, please use the chat feature in your patient portal to speak with your provider."
            )
            ->attachData($pdfContent, $this->pdfFilename, [
                "mime" => "application/pdf",
            ])
            ->line("Thank you,")
            ->line(config("app.name") . " Team");
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
