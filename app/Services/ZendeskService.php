<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ZendeskService
{
    protected ?string $apiUrl;
    protected ?string $accessToken;

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

    /**
     * Delete a lead from Zendesk Sell by user email.
     *
     * @param User $user The user whose lead should be deleted.
     * @return bool True on success, false on failure.
     */
    public function deleteLead(User $user): bool
    {
        if (empty($this->accessToken) || empty($this->apiUrl)) {
            Log::error("Zendesk Sell API credentials are not configured.");
            return false;
        }

        try {
            // First, search for the lead by email
            $searchResponse = Http::withHeaders([
                "Authorization" => "Bearer " . $this->accessToken,
                "Accept" => "application/json",
            ])->get("{$this->apiUrl}/v3/leads/search", [
                "email" => $user->email,
            ]);

            if (!$searchResponse->successful()) {
                Log::warning(
                    "Failed to search for Zendesk Sell lead for user {$user->email}.",
                    [
                        "status" => $searchResponse->status(),
                        "response" => $searchResponse->json(),
                    ],
                );
                return false;
            }

            $leads = $searchResponse->json("items", []);

            if (empty($leads)) {
                Log::info(
                    "No Zendesk Sell lead found for user {$user->email}.",
                );
                return true; // Consider this a success since there's no lead to delete
            }

            // Delete each lead found (there should typically be only one)
            $allDeleted = true;
            foreach ($leads as $lead) {
                $leadId = $lead["data"]["id"] ?? null;

                if (!$leadId) {
                    continue;
                }

                $deleteResponse = Http::withHeaders([
                    "Authorization" => "Bearer " . $this->accessToken,
                    "Accept" => "application/json",
                ])->delete("{$this->apiUrl}/v2/leads/{$leadId}");

                if ($deleteResponse->successful()) {
                    Log::info(
                        "Successfully deleted Zendesk Sell lead for user {$user->email}.",
                        ["lead_id" => $leadId],
                    );
                } else {
                    Log::error(
                        "Failed to delete Zendesk Sell lead for user {$user->email}.",
                        [
                            "lead_id" => $leadId,
                            "status" => $deleteResponse->status(),
                            "response" => $deleteResponse->json(),
                        ],
                    );
                    $allDeleted = false;
                }
            }

            return $allDeleted;
        } catch (\Exception $e) {
            Log::error(
                "Exception occurred while deleting Zendesk Sell lead for user {$user->email}.",
                [
                    "error" => $e->getMessage(),
                    "trace" => $e->getTraceAsString(),
                ],
            );
            return false;
        }
    }
}
