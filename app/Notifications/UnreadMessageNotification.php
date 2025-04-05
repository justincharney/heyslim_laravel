<?php

namespace App\Notifications;

use App\Models\Chat;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UnreadMessageNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(Chat $chat, int $unreadCount)
    {
        $this->chat = $chat;
        $this->unreadCount = $unreadCount;
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
        $chatUrl = config("app.frontend_url") . "/chats/{$this->chat->id}";

        return (new MailMessage())
            ->subject("You have {$this->unreadCount} unread message(s)")
            ->line(
                "You have {$this->unreadCount} unread message(s) in your conversation with {$this->chat->getOtherParticipant(
                    $notifiable
                )->name}."
            )
            ->action("View Messages", $chatUrl);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
                //
            ];
    }
}
