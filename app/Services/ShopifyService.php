<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopifyService
{
    protected $endpoint;
    protected $accessToken;

    public function __construct()
    {
        $this->endpoint = config("services.shopify.endpoint");
        $this->accessToken = config("services.shopify.access_token");
    }

    public function createCustomer(
        string $firstName,
        string $lastName,
        string $email
    ): ?string {
        $mutation = <<<'GRAPHQL'
mutation customerCreate($input: CustomerInput!) {
  customerCreate(input: $input) {
    customer {
      id
      email
      firstName
      lastName
    }
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;

        $shopifyCustomerInput = [
            "firstName" => $firstName,
            "lastName" => $lastName,
            "email" => $email,
            "emailMarketingConsent" => [
                "marketingState" => "SUBSCRIBED",
                "marketingOptInLevel" => "SINGLE_OPT_IN",
            ],
        ];

        $response = Http::withHeaders([
            "Content-Type" => "application/json",
            "X-Shopify-Access-Token" => $this->accessToken,
        ])->post($this->endpoint, [
            "query" => $mutation,
            "variables" => [
                "input" => $shopifyCustomerInput,
            ],
        ]);

        if ($response->successful()) {
            $data = $response->json();
            // Log::info("Shopify response", $data);

            if (
                isset($data["data"]["customerCreate"]["userErrors"]) &&
                !empty($data["data"]["customerCreate"]["userErrors"])
            ) {
                Log::error(
                    "Shopify customer creation returned errors",
                    $data["data"]["customerCreate"]["userErrors"]
                );
                return null;
            }

            if (isset($data["data"]["customerCreate"]["customer"]["id"])) {
                return $data["data"]["customerCreate"]["customer"]["id"];
            } else {
                Log::error("Shopify customer ID not found in response", $data);
            }
        } else {
            Log::error("Shopify API call failed", $response->json());
        }

        return null;
    }
}
