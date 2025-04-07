<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopifyService
{
    protected $endpoint;
    protected $accessToken;
    protected $storefrontEndpoint;
    protected $storefrontAccessToken;

    public function __construct()
    {
        $this->endpoint = config("services.shopify.endpoint");
        $this->accessToken = config("services.shopify.access_token");
        $this->storefrontEndpoint = config(
            "services.shopify.storefront_endpoint"
        );
        $this->storefrontAccessToken = config(
            "services.shopify.storefront_access_token"
        );
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

    /**
     * Create a Shopify checkout for a specific product using the Cart API
     *
     * @param string $productId The Shopify product ID
     * @param int $quantity Default is 1
     * @return array|null The cart object (id and checkoutUrl) if successful, null otherwise
     */
    public function createCheckout(
        string $productId,
        int $submissionId,
        int $quantity = 1
    ): ?array {
        // Get the first variant ID for the product
        $variantId = $this->getFirstProductVariantId($productId);
        if (!$variantId) {
            Log::error("Failed to get variant ID for product", [
                "product_id" => $productId,
            ]);
            return null;
        }

        // Get the selling plan ID (subscription) for the product
        $sellingPlanId = $this->getFirstSellingPlanId($productId);
        if (!$sellingPlanId) {
            Log::error("Failed to get selling plan ID for subscription", [
                "product_id" => $productId,
            ]);
            return null;
        }

        // Create a cart and return it
        return $this->createCart(
            $variantId,
            $sellingPlanId,
            $submissionId,
            $quantity
        );
    }

    /**
     * Create a Shopify cart
     */
    private function createCart(
        string $variantId,
        string $sellingPlanId,
        int $submissionId,
        int $quantity = 1
    ): ?array {
        $mutation = <<<'GRAPHQL'
mutation cartCreate($input: CartInput!) {
  cartCreate(input: $input) {
    cart {
      id
      checkoutUrl
    }
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;

        $cartInput = [
            "lines" => [
                [
                    "merchandiseId" => $variantId,
                    "quantity" => $quantity,
                    "sellingPlanId" => $sellingPlanId, // Added for subscription checkout
                ],
            ],
        ];

        // Add custom attributes to identify the source submission
        if ($submissionId) {
            $cartInput["attributes"] = [
                [
                    "key" => "questionnaire_submission_id",
                    "value" => (string) $submissionId,
                ],
            ];
        }

        $response = Http::withHeaders([
            "Content-Type" => "application/json",
            "X-Shopify-Storefront-Access-Token" => $this->storefrontAccessToken,
        ])->post($this->storefrontEndpoint, [
            "query" => $mutation,
            "variables" => [
                "input" => $cartInput,
            ],
        ]);

        if ($response->successful()) {
            $data = $response->json();
            Log::info("Shopify cart response", $data);

            if (
                isset($data["data"]["cartCreate"]["userErrors"]) &&
                !empty($data["data"]["cartCreate"]["userErrors"])
            ) {
                Log::error(
                    "Shopify cart creation returned errors",
                    $data["data"]["cartCreate"]["userErrors"]
                );
                return null;
            }

            if (
                isset($data["data"]["cartCreate"]["cart"]["id"]) &&
                isset($data["data"]["cartCreate"]["cart"]["checkoutUrl"])
            ) {
                return $data["data"]["cartCreate"]["cart"];
            } else {
                Log::error(
                    "Shopify cart structure not found in response",
                    $data
                );
            }
        } else {
            Log::error(
                "Shopify API call to create cart failed",
                $response->json()
            );
        }

        return null;
    }

    /**
     * Get the first variant ID for a product
     */
    private function getFirstProductVariantId(string $productId): ?string
    {
        $query = <<<'GRAPHQL'
query getProductVariants($productId: ID!) {
  product(id: $productId) {
    variants(first: 1) {
      edges {
        node {
          id
        }
      }
    }
  }
}
GRAPHQL;

        $response = Http::withHeaders([
            "Content-Type" => "application/json",
            "X-Shopify-Storefront-Access-Token" => $this->storefrontAccessToken,
        ])->post($this->storefrontEndpoint, [
            "query" => $query,
            "variables" => [
                "productId" => $productId,
            ],
        ]);

        if ($response->successful()) {
            $data = $response->json();
            if (
                isset(
                    $data["data"]["product"]["variants"]["edges"][0]["node"][
                        "id"
                    ]
                )
            ) {
                return $data["data"]["product"]["variants"]["edges"][0]["node"][
                    "id"
                ];
            }
        }

        return null;
    }

    /**
     * Get the first available selling plan ID for a product (subscription)
     */
    private function getFirstSellingPlanId(string $productId): ?string
    {
        $query = <<<'GRAPHQL'
query getSellingPlans($productId: ID!) {
  product(id: $productId) {
    sellingPlanGroups(first: 1) {
      edges {
        node {
          sellingPlans(first: 1) {
            edges {
              node {
                id
                name
              }
            }
          }
        }
      }
    }
  }
}
GRAPHQL;

        $response = Http::withHeaders([
            "Content-Type" => "application/json",
            "X-Shopify-Storefront-Access-Token" => $this->storefrontAccessToken,
        ])->post($this->storefrontEndpoint, [
            "query" => $query,
            "variables" => [
                "productId" => $productId,
            ],
        ]);

        if ($response->successful()) {
            $data = $response->json();
            if (
                isset(
                    $data["data"]["product"]["sellingPlanGroups"]["edges"][0][
                        "node"
                    ]["sellingPlans"]["edges"][0]["node"]["id"]
                )
            ) {
                return $data["data"]["product"]["sellingPlanGroups"][
                    "edges"
                ][0]["node"]["sellingPlans"]["edges"][0]["node"]["id"];
            } else {
                Log::warning(
                    "No selling plans (subscriptions) found for product",
                    [
                        "product_id" => $productId,
                        "response" => $data,
                    ]
                );
            }
        } else {
            Log::error("Failed to get selling plans", $response->json());
        }

        return null;
    }
}
