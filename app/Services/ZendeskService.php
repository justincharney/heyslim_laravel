<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ZendeskService
{
    protected string $apiUrl;
    protected string $accessToken;

    /**
     * ZendeskService constructor.
     */
    public function __construct()
    {
        $this->apiUrl = config("services.zendesk.sell_api_url");
        $this->accessToken = config("services.zendesk.sell_access_token");
    }

    /**
     * Create a lead in Zendesk Sell.
     *
     * @param User $user The user to create a lead for.
     * @return bool True on success, false on failure.
     */
    public function createLead(User $user): bool
    {
        if (empty($this->accessToken) || empty($this->apiUrl)) {
            Log::error("Zendesk Sell API credentials are not configured.");
            return false;
        }

        // Handle cases where the user's name might be missing or incomplete
        if (!empty(trim($user->name))) {
            $nameParts = explode(" ", trim($user->name), 2);
            $firstName = $nameParts[0];
            $lastName = $nameParts[1] ?? "."; // Zendesk requires a last name, use a placeholder.
        } else {
            // Fallback to using the email if the name is not set
            $emailParts = explode("@", $user->email);
            $firstName = $emailParts[0];
            $lastName = "(From Email)"; // Indicate the source of the name
        }

        $payload = [
            "data" => [
                "first_name" => $firstName,
                "last_name" => $lastName,
                "email" => $user->email,
                "phone" => $user->phone_number,
                "description" =>
                    "Lead automatically generated for a user who did not complete their profile or questionnaire.",
                "tags" => ["inactive-user-drip"],
            ],
        ];

        try {
            $response = Http::withHeaders([
                "Authorization" => "Bearer " . $this->accessToken,
                "Accept" => "application/json",
                "Content-Type" => "application/json",
            ])->post("{$this->apiUrl}/v2/leads", $payload);

            if ($response->successful()) {
                $leadId = $response->json("data.id");
                Log::info(
                    "Successfully created Zendesk Sell lead for user {$user->email}.",
                    ["lead_id" => $leadId],
                );
                return true;
            } else {
                Log::error(
                    "Failed to create Zendesk Sell lead for user {$user->email}.",
                    [
                        "status" => $response->status(),
                        "response" => $response->json(),
                        "payload" => $payload,
                    ],
                );
                return false;
            }
        } catch (\Exception $e) {
            Log::error(
                "Exception occurred while creating Zendesk Sell lead for user {$user->email}.",
                [
                    "error" => $e->getMessage(),
                    "trace" => $e->getTraceAsString(),
                ],
            );
            return false;
        }
    }
}
