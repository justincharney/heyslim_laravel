<?php

namespace App\Console\Commands;

use App\Models\Chat;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use App\Notifications\UnreadMessageNotification;

class CheckUnreadMessages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = "app:check-unread-messages";

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Check for unread messages older than 30 minutes and send notifications";

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Find messages that are:
        // 1. Unread
        // 2. Created more than 30 minutes ago
        // 3. No notification has been sent yet (tracked in the messages table)
        $cutoffTime = Carbon::now()->subMinutes(30);

        $this->info("Checking for unread messages older than {$cutoffTime}");

        // Get all chats with unread messages
        $chatsWithUnreadMessages = Chat::whereHas("messages", function (
            $query
        ) use ($cutoffTime) {
            $query
                ->where("read", false)
                ->where("created_at", "<", $cutoffTime)
                ->where("notification_sent", false);
        })->get();

        $notificationCount = 0;

        foreach ($chatsWithUnreadMessages as $chat) {
            // Group unread messages by recipient
            $unreadMessagesByUser = [];

            // Get all unread messages in this chat
            $unreadMessages = $chat
                ->messages()
                ->where("read", false)
                ->where("created_at", "<", $cutoffTime)
                ->where("notification_sent", false)
                ->get();

            foreach ($unreadMessages as $message) {
                // Determine recipient (the person who should read this message)
                $recipientId =
                    $message->user_id == $chat->patient_id
                        ? $chat->provider_id
                        : $chat->patient_id;

                if (!isset($unreadMessagesByUser[$recipientId])) {
                    $unreadMessagesByUser[$recipientId] = [];
                }

                $unreadMessagesByUser[$recipientId][] = $message;
            }

            // Send notifications to each recipient
            foreach ($unreadMessagesByUser as $userId => $messages) {
                $recipient = User::find($userId);
                $unreadCount = count($messages);

                // Notify the user
                $recipient->notify(
                    new UnreadMessageNotification($chat, $unreadCount)
                );
                $notificationCount++;

                // Mark messages as notified
                foreach ($messages as $message) {
                    $message->notification_sent = true;
                    $message->save();
                }
            }
        }

        $this->info(
            "Sent {$notificationCount} notifications for unread messages"
        );
    }
}
