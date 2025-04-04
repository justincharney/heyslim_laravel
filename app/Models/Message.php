<?php

namespace App\Models;

use App\Services\SupabaseService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $fillable = ["chat_id", "user_id", "message", "read"];

    protected $casts = ["read" => "boolean"];

    protected static function booted()
    {
        static::created(function ($message) {
            // Load the user relationship for broadcasting
            $message->load("user:id,name,avatar");

            // Prepare the data to broadcast
            $broadcastData = [
                "id" => $message->id,
                "chat_id" => $message->chat_id,
                "user_id" => $message->user_id,
                "message" => $message->message,
                "created_at" => $message->created_at,
                "user" => [
                    "id" => $message->user->id,
                    "name" => $message->user->name,
                    "avatar" => $message->user->avatar,
                ],
            ];

            // Broadcast to Supabase
            app(SupabaseService::class)->broadcastChatMessage(
                $message->chat_id,
                $broadcastData
            );

            // Also broadcast to the chat list channels for both users
            $chat = $message->chat;

            // For patient
            app(SupabaseService::class)->broadcastToChannel(
                "user-chats:{$chat->patient_id}",
                "new_message",
                [
                    "chat_id" => $chat->id,
                    "message" => $broadcastData,
                ]
            );

            // For provider
            app(SupabaseService::class)->broadcastToChannel(
                "user-chats:{$chat->provider_id}",
                "new_message",
                [
                    "chat_id" => $chat->id,
                    "message" => $broadcastData,
                ]
            );
        });
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
