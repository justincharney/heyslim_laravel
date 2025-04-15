<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\User;

class CalendlyService
{
    protected $apiKey;
    protected $baseUrl = "https://api.calendly.com";

    public function __construct()
    {
        $this->apiKey = config("services.calendly.api_key");
    }

    /**
     * Generate a single-use scheduling link for the given event type
     *
     * @param string $eventType The Calendly event type URL
     * @return string|null The single-use scheduling link URL or null if there was an error
     */
    public function generateSingleUseLink(string $eventType): ?string
    {
        try {
            $response = Http::withHeaders([
                "Authorization" => "Bearer " . $this->apiKey,
                "Content-Type" => "application/json",
            ])->post($this->baseUrl . "/scheduling_links", [
                "max_event_count" => 1,
                "owner" => "{$eventType}",
                "owner_type" => "EventType",
            ]);

            if (!$response->successful()) {
                Log::error("Failed to generate Calendly link", [
                    "status" => $response->status(),
                    "body" => $response->body(),
                    "event_type" => $eventType,
                ]);
                return null;
            }

            $data = $response->json();
            return $data["resource"]["booking_url"] ?? null;
        } catch (\Exception $e) {
            Log::error("Exception generating Calendly link", [
                "error" => $e->getMessage(),
                "event_type" => $eventType,
            ]);
            return null;
        }
    }

    /**
     * Select a provider from the patient's team and generate a scheduling link
     *
     * @param User $patient The patient user
     * @return array|null An array containing the provider and booking URL, or null if unsuccessful
     */
    public function selectProviderAndGenerateLink(User $patient): ?array
    {
        $teamId = $patient->current_team_id;

        if (!$teamId) {
            Log::error("Patient has no team", ["patient_id" => $patient->id]);
            return null;
        }

        // Set the team context
        setPermissionsTeamId($teamId);

        // Get providers from the same team who have a Calendly event type
        $provider = User::role("provider")
            ->where("current_team_id", $teamId)
            ->whereNotNull("calendly_event_type")
            ->inRandomOrder()
            ->first();

        if (!$provider) {
            Log::error("No providers with calendly_event_type found in team", [
                "team_id" => $teamId,
                "patient_id" => $patient->id,
            ]);
            return null;
        }

        $bookingUrl = $this->generateSingleUseLink(
            $provider->calendly_event_type
        );

        if (!$bookingUrl) {
            return null;
        }

        return [
            "provider" => $provider,
            "booking_url" => $bookingUrl,
        ];
    }
}
