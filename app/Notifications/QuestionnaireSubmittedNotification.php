<?php

namespace App\Notifications;

use App\Models\QuestionnaireSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class QuestionnaireSubmittedNotification extends Notification implements
    ShouldQueue
{
    use Queueable;

    protected QuestionnaireSubmission $submission;

    /**
     * Create a new notification instance.
     *
     * @param QuestionnaireSubmission $submission
     */
    public function __construct(QuestionnaireSubmission $submission)
    {
        $this->submission = $submission;
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
        // Ensure the questionnaire relationship is loaded to get the title
        $this->submission->loadMissing("questionnaire");
        $questionnaireTitle = $this->submission->questionnaire
            ? $this->submission->questionnaire->title
            : "your questionnaire";

        // Assuming $notifiable is the User model, which has a 'name' attribute
        $patientName = $this->submission->user->name ?? "there";
        $patientEmail =
            $this->submission->user->email ?? "your registered email address";

        return (new MailMessage())
            ->subject("Your Questionnaire Has Been Submitted - Next Steps")
            ->greeting("Hello " . $patientName . ",")
            ->line(
                "Thank you for submitting your questionnaire: \"{$questionnaireTitle}\". Here are the next steps in your journey with HeySlim:"
            )
            ->line(
                "1. **Provider Review:** Your health profile and submitted information will be carefully reviewed by one of our licensed healthcare providers."
            )
            ->line(
                "2. **Prescription & Medication Payment (If Approved):** Once your provider has reviewed your profile and if you are approved for treatment, you will receive another email from us. This email will contain instructions on how to complete the payment for your prescribed medication. **Important:** The checkout process will require you to log in with a Shopify account. Please ensure you use the **same email address ({$patientEmail})** for Shopify as you used for your HeySlim account. If the email addresses do not match, you will not be authorized to complete the checkout."
            )
            ->line(
                "3. **ID Verification:** After completing the order for the medication, instructions for ID verification will be emailed to you in a separate email. This ID verification must be completed before your order can be dispensed by our pharmacy."
            )
            ->line(
                "Our team is working diligently to review your information. We appreciate your patience during this process."
            )
            ->line(
                "If you have any questions in the meantime, please don't hesitate to contact our support team or use the chat feature in your patient portal to speak with your provider."
            );
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
            "questionnaire_submission_id" => $this->submission->id,
            "questionnaire_title" => $this->submission->questionnaire
                ? $this->submission->questionnaire->title
                : "N/A",
        ];
    }
}
