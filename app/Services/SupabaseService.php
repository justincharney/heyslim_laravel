<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SupabaseService
{
    protected $apiUrl;
    protected $apiKey;

    public function __construct()
    {
        $this->apiUrl = env("SUPABASE_URL");
        $this->apiKey = env("SUPABASE_KEY");
    }

    /**
     * Broadcast a message to a Supabase channel
     */
    public function broadcastChatMessage($chatId, $messageData)
    {
        try {
            $response = Http::withHeaders([
                "apikey" => $this->apiKey,
                "Authorization" => "Bearer {$this->apiKey}",
                "Content-Type" => "application/json",
            ])->post("{$this->apiUrl}/realtime/v1/api/broadcast", [
                "messages" => [
                    [
                        "topic" => "chat:{$chatId}",
                        "event" => "message",
                        "payload" => $messageData,
                    ],
                ],
            ]);

            if (!$response->successful()) {
                Log::error("Supabase broadcast failed: " . $response->body());
            }

            return $response->successful();
        } catch (\Exception $e) {
            Log::error("Supabase broadcast error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Broadcast an event to a Supabase channel
     */
    public function broadcastToChannel($channel, $event, $payload)
    {
        try {
            $response = Http::withHeaders([
                "apikey" => $this->apiKey,
                "Authorization" => "Bearer {$this->apiKey}",
                "Content-Type" => "application/json",
            ])->post("{$this->apiUrl}/realtime/v1/api/broadcast", [
                "messages" => [
                    [
                        "topic" => $channel,
                        "event" => $event,
                        "payload" => $payload,
                    ],
                ],
            ]);

            if (!$response->successful()) {
                Log::error("Supabase broadcast failed: " . $response->body());
            }

            return $response->successful();
        } catch (\Exception $e) {
            Log::error("Supabase broadcast error: " . $e->getMessage());
            return false;
        }
    }
}
