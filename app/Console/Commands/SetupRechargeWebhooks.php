<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SetupRechargeWebhooks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = "recharge:setup-webhooks {--force : Force recreation of all webhooks}";

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Set up required webhooks in Recharge";

    /**
     * The Recharge API token.
     *
     * @var string
     */
    protected $apiToken;

    /**
     * The webhook endpoint URL.
     *
     * @var string
     */
    protected $webhookBaseUrl;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->apiToken = config("services.recharge.api_key");
        $this->webhookBaseUrl = config("app.url") . "/webhooks/recharge";

        if (empty($this->apiToken)) {
            $this->error(
                "Recharge API token is not configured. Please check your .env file."
            );
            return 1;
        }

        $force = $this->option("force");

        // Define the webhooks we want to create
        $webhooks = [
            [
                "topic" => "subscription/cancelled",
                "address" => $this->webhookBaseUrl . "/subscription/cancelled",
            ],
            [
                "topic" => "order/created",
                "address" => $this->webhookBaseUrl . "/order/created",
            ],
            [
                "topic" => "subscription/created",
                "address" => $this->webhookBaseUrl . "/subscription/created",
            ],
            [
                "topic" => "subscription/updated",
                "address" => $this->webhookBaseUrl . "/subscription/updated",
            ],
        ];

        // If --force flag is used, delete all existing webhooks first
        if ($force) {
            $this->deleteExistingWebhooks();
        }

        // Get existing webhooks to avoid duplicates
        $existingWebhooks = $this->getExistingWebhooks();

        // $existingTopics = array_map(function ($webhook) {
        //     return $webhook["topic"];
        // }, $existingWebhooks);

        $createdCount = 0;
        $skippedCount = 0;

        foreach ($webhooks as $webhook) {
            // Skip if webhook already exists
            $shouldSkip = false;
            if (!$force) {
                foreach ($existingWebhooks as $existingWebhook) {
                    if (
                        isset($existingWebhook["topic"]) &&
                        $existingWebhook["topic"] === $webhook["topic"] &&
                        isset($existingWebhook["address"]) &&
                        $existingWebhook["address"] === $webhook["address"]
                    ) {
                        $this->info(
                            "Webhook for {$webhook["topic"]} already exists. Skipping."
                        );
                        $skippedCount++;
                        $shouldSkip = true;
                        break;
                    }
                }
            }

            if ($shouldSkip) {
                continue;
            }

            // Create the webhook
            $result = $this->createWebhook(
                $webhook["topic"],
                $webhook["address"]
            );

            if ($result) {
                $this->info("Created webhook for {$webhook["topic"]}");
                $createdCount++;
            } else {
                $this->error(
                    "Failed to create webhook for {$webhook["topic"]}"
                );
            }
        }

        $this->info(
            "Webhook setup complete: {$createdCount} created, {$skippedCount} skipped."
        );

        return 0;
    }

    /**
     * Get all existing webhooks from Recharge.
     *
     * @return array
     */
    private function getExistingWebhooks()
    {
        try {
            $response = Http::withHeaders([
                "X-Recharge-Access-Token" => $this->apiToken,
                "Accept" => "application/json",
                "Content-Type" => "application/json",
            ])->get("https://api.rechargeapps.com/webhooks");

            if ($response->successful()) {
                return $response->json()["webhooks"] ?? [];
            }

            $this->error(
                "Failed to retrieve existing webhooks: " . $response->body()
            );
            return [];
        } catch (\Exception $e) {
            $this->error(
                "Exception while retrieving webhooks: " . $e->getMessage()
            );
            return [];
        }
    }

    /**
     * Create a webhook in Recharge.
     *
     * @param string $topic
     * @param string $address
     * @return bool
     */
    private function createWebhook($topic, $address)
    {
        try {
            $response = Http::withHeaders([
                "X-Recharge-Access-Token" => $this->apiToken,
                "Accept" => "application/json",
                "Content-Type" => "application/json",
            ])->post("https://api.rechargeapps.com/webhooks", [
                "address" => $address,
                "topic" => $topic,
            ]);

            if ($response->successful()) {
                return true;
            }

            $this->error("Error creating webhook: " . $response->body());
            return false;
        } catch (\Exception $e) {
            $this->error(
                "Exception while creating webhook: " . $e->getMessage()
            );
            return false;
        }
    }

    /**
     * Delete all existing webhooks.
     *
     * @return void
     */
    private function deleteExistingWebhooks()
    {
        $webhooks = $this->getExistingWebhooks();

        if (empty($webhooks)) {
            $this->info("No existing webhooks to delete.");
            return;
        }

        $this->info("Deleting " . count($webhooks) . " existing webhooks...");

        foreach ($webhooks as $webhook) {
            try {
                $response = Http::withHeaders([
                    "X-Recharge-Access-Token" => $this->apiToken,
                    "Accept" => "application/json",
                    "Content-Type" => "application/json",
                ])->delete(
                    "https://api.rechargeapps.com/webhooks/" . $webhook["id"]
                );

                if ($response->successful()) {
                    $this->info("Deleted webhook: {$webhook["topic"]}");
                } else {
                    $this->error(
                        "Failed to delete webhook {$webhook["id"]}: " .
                            $response->body()
                    );
                }
            } catch (\Exception $e) {
                $this->error(
                    "Exception while deleting webhook {$webhook["id"]}: " .
                        $e->getMessage()
                );
            }
        }
    }
}
