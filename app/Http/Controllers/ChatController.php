<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class ChatController extends Controller
{
    use AuthorizesRequests;

    /**
     * Get chats for the authenticated user
     */
    public function index()
    {
        $user = auth()->user();

        // Get all chats where the user is either patient or provider
        $chats = Chat::where("patient_id", $user->id)
            ->orWhere("provider_id", $user->id)
            ->with([
                "prescription:id,medication_name",
                "provider:id,name",
                "patient:id,name",
                "messages" => function ($query) {
                    $query->latest()->limit(1);
                },
            ])
            ->withCount([
                "messages as unread_count" => function ($query) use ($user) {
                    $query
                        ->where("read", false)
                        ->where("user_id", "!=", $user->id);
                },
            ])
            ->latest()
            ->get();

        return response()->json([
            "chats" => $chats,
        ]);
    }

    /**
     * Get a specific chat with messages
     */
    public function show($id)
    {
        $user = auth()->user();

        // Get the chat and make sure the user is part of it
        try {
            $chat = Chat::where("id", $id)
                ->where(function ($query) use ($user) {
                    $query
                        ->where("patient_id", $user->id)
                        ->orWhere("provider_id", $user->id);
                })
                ->with(["prescription", "provider", "patient"])
                ->firstOrFail();
        } catch (\Exception $e) {
            return response()->json(
                [
                    "error" => "Chat not found",
                ],
                404
            );
        }

        // Get messages for this chat
        $messages = Message::where("chat_id", $chat->id)
            ->with("user:id,name,avatar")
            ->orderBy("created_at", "asc")
            ->get();

        // Mark all unread messages as read
        Message::where("chat_id", $chat->id)
            ->where("user_id", "!=", $user->id)
            ->where("read", false)
            ->update(["read" => true]);

        return response()->json([
            "chat" => $chat,
            "messages" => $messages,
        ]);
    }

    /**
     * Send a new message
     */
    public function sendMessage(Request $request, $chatId)
    {
        $validated = $request->validate([
            "message" => "required|string|max:1000",
        ]);

        $user = auth()->user();

        // Check if the user is part of this chat
        $chat = Chat::where("id", $chatId)
            ->where(function ($query) use ($user) {
                $query
                    ->where("patient_id", $user->id)
                    ->orWhere("provider_id", $user->id);
            })
            ->firstOrFail();

        // Check if the associated prescription is not 'active'
        if (
            $chat->prescription &&
            !in_array($chat->prescription->status, [
                "active",
                "pending_signature",
                "pending_payment",
            ])
        ) {
            return response()->json(
                [
                    "message" =>
                        "Cannot send messages in a chat for an inactive prescription.",
                    "error" => "prescription_cancelled",
                ],
                403
            );
        }

        // Just create the message - broadcasting happens via database trigger
        $message = Message::create([
            "chat_id" => $chat->id,
            "user_id" => $user->id,
            "message" => $validated["message"],
            "read" => false,
        ]);

        $message->load("user:id,name,avatar");

        return response()->json(
            [
                "message" => $message,
            ],
            201
        );
    }

    /**
     * Mark a chat as closed
     */
    public function closeChat($id)
    {
        $user = auth()->user();

        // Make sure the user is the provider for this chat
        $chat = Chat::where("id", $id)
            ->where("provider_id", $user->id)
            ->firstOrFail();

        $chat->update(["status" => "closed"]);

        return response()->json([
            "message" => "Chat closed successfully",
            "chat" => $chat,
        ]);
    }

    /**
     * Reopen a closed chat
     */
    public function reopenChat($id)
    {
        $user = auth()->user();

        // Make sure the user is the provider for this chat
        $chat = Chat::where("id", $id)
            ->where("provider_id", $user->id)
            ->firstOrFail();

        $chat->update(["status" => "active"]);

        return response()->json([
            "message" => "Chat reopened successfully",
            "chat" => $chat,
        ]);
    }

    /**
     * Mark all unread messages in a chat as read
     */
    public function markAsRead($id)
    {
        $user = auth()->user();

        // Check if the user is part of this chat
        $chat = Chat::where("id", $id)
            ->where(function ($query) use ($user) {
                $query
                    ->where("patient_id", $user->id)
                    ->orWhere("provider_id", $user->id);
            })
            ->firstOrFail();

        // Mark all unread messages from other users as read
        $updatedCount = Message::where("chat_id", $chat->id)
            ->where("user_id", "!=", $user->id)
            ->where("read", false)
            ->update(["read" => true]);

        return response()->json([
            "success" => true,
            "updated_count" => $updatedCount,
        ]);
    }
}
