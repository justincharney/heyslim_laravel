<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Prescription;

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

    /**
     * Find a Shopify customer by email using the Admin API
     *
     * @param string $email The email address to search for
     * @return string|null The customer GID if found, null otherwise
     */
    public function findCustomerByEmail(string $email): ?string
    {
        $query = <<<'GRAPHQL'
query findCustomerByEmail($emailQuery: String!) {
  customers(first: 1, query: $emailQuery) {
    edges {
      node {
        id
        email
      }
    }
  }
}
GRAPHQL;

        // Construct the query string for exact email match
        $emailQueryString = "email:'{$email}'";

        try {
            $response = Http::withHeaders([
                "Content-Type" => "application/json",
                "X-Shopify-Access-Token" => $this->accessToken,
            ])->post($this->endpoint, [
                "query" => $query,
                "variables" => [
                    "emailQuery" => $emailQueryString,
                ],
            ]);

            if (!$response->successful()) {
                Log::error("Shopify API call failed (find customer by email)", [
                    "status" => $response->status(),
                    "response" => $response->body(),
                    "email" => $email,
                ]);
                return null;
            }

            $data = $response->json();
            $edges = $data["data"]["customers"]["edges"] ?? [];

            if (!empty($edges)) {
                $customerId = $edges[0]["node"]["id"] ?? null;
                if ($customerId) {
                    Log::info("Found existing Shopify customer by email", [
                        "email" => $email,
                        "customer_id" => $customerId,
                    ]);
                    return $customerId;
                }
            }

            Log::info("No Shopify customer found for email", [
                "email" => $email,
            ]);
            return null;
        } catch (\Exception $e) {
            Log::error("Exception finding customer by email", [
                "email" => $email,
                "error" => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function createCustomer(
        string $firstName,
        string $lastName,
        string $email,
        string $password
    ): ?string {
        // Get the client's IP address
        $clientIp = request()->ip();

        $mutation = <<<'GRAPHQL'
mutation customerCreate($input: CustomerCreateInput!) {
  customerCreate(input: $input) {
    customer {
      id
      email
      firstName
      lastName
    }
    customerUserErrors {
      field
      message
    }
  }
}
GRAPHQL;

        $customerInput = [
            "firstName" => $firstName,
            "lastName" => $lastName,
            "email" => $email,
            "password" => $password,
            "acceptsMarketing" => true,
        ];

        $response = Http::withHeaders([
            "Content-Type" => "application/json",
            "Shopify-Storefront-Private-Token" => $this->storefrontAccessToken,
            "Shopify-Storefront-Buyer-IP" => $clientIp,
        ])->post($this->storefrontEndpoint, [
            "query" => $mutation,
            "variables" => [
                "input" => $customerInput,
            ],
        ]);

        if ($response->successful()) {
            $data = $response->json();
            Log::info("Shopify customer creation response", $data);

            if (
                isset($data["data"]["customerCreate"]["customerUserErrors"]) &&
                !empty($data["data"]["customerCreate"]["customerUserErrors"])
            ) {
                Log::error(
                    "Shopify customer creation returned errors",
                    $data["data"]["customerCreate"]["customerUserErrors"]
                );
                return null;
            }

            if (isset($data["data"]["customerCreate"]["customer"]["id"])) {
                return $data["data"]["customerCreate"]["customer"]["id"];
            } else {
                Log::error("Shopify customer ID not found in response", $data);
            }
        } else {
            Log::error(
                "Shopify API call failed (create customer)",
                $response->json()
            );
        }

        return null;
    }

    /**
     * Add tags to a customer using the Admin API's tagsAdd mutation
     *
     * @param string $customerId The Shopify customer ID (gid://shopify/Customer/...)
     * @param array|string $tags Tags to add to the customer
     * @return bool Whether the operation was successful
     */
    public function addTagsToCustomer(string $customerId, $tags): bool
    {
        // Convert string to array if necessary
        if (is_string($tags)) {
            $tags = [$tags];
        }

        $mutation = <<<'GRAPHQL'
mutation addTags($id: ID!, $tags: [String!]!) {
  tagsAdd(id: $id, tags: $tags) {
    node {
      id
    }
    userErrors {
      message
    }
  }
}
GRAPHQL;

        $response = Http::withHeaders([
            "Content-Type" => "application/json",
            "X-Shopify-Access-Token" => $this->accessToken,
        ])->post($this->endpoint, [
            "query" => $mutation,
            "variables" => [
                "id" => $customerId,
                "tags" => $tags,
            ],
        ]);

        if ($response->successful()) {
            $data = $response->json();

            if (
                isset($data["data"]["tagsAdd"]["userErrors"]) &&
                !empty($data["data"]["tagsAdd"]["userErrors"])
            ) {
                Log::error(
                    "Shopify tagsAdd mutation returned errors",
                    $data["data"]["tagsAdd"]["userErrors"]
                );
                return false;
            }

            if (isset($data["data"]["tagsAdd"]["node"]["id"])) {
                // Log::info("Successfully added tags to customer", [
                //     "customer_id" => $customerId,
                //     "tags" => $tags,
                // ]);
                return true;
            } else {
                Log::error(
                    "Shopify tagsAdd mutation did not return expected data",
                    $data
                );
                return false;
            }
        } else {
            Log::error(
                "Shopify API call failed (tagsAdd mutation)",
                $response->json()
            );
            return false;
        }
    }

    /**
     * Delete a Shopify customer using the Admin API
     *
     * @param string $customerId The Shopify customer ID
     * @return bool Whether the deletion was successful
     */
    public function deleteCustomer(string $customerId): bool
    {
        $mutation = <<<'GRAPHQL'
mutation customerDelete($input: CustomerDeleteInput!) {
  customerDelete(input: $input) {
    deletedCustomerId
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;

        $response = Http::withHeaders([
            "Content-Type" => "application/json",
            "X-Shopify-Access-Token" => $this->accessToken,
        ])->post($this->endpoint, [
            "query" => $mutation,
            "variables" => [
                "input" => [
                    "id" => $customerId,
                ],
            ],
        ]);

        if ($response->successful()) {
            $data = $response->json();

            if (
                isset($data["data"]["customerDelete"]["userErrors"]) &&
                !empty($data["data"]["customerDelete"]["userErrors"])
            ) {
                Log::error(
                    "Shopify customer deletion returned errors",
                    $data["data"]["customerDelete"]["userErrors"]
                );
                return false;
            }

            if (isset($data["data"]["customerDelete"]["deletedCustomerId"])) {
                Log::info("Successfully deleted Shopify customer", [
                    "customer_id" => $customerId,
                ]);
                return true;
            } else {
                Log::error(
                    "Shopify customer deletion did not return expected data",
                    $data
                );
                return false;
            }
        } else {
            Log::error(
                "Shopify API call failed (customer deletion)",
                $response->json()
            );
            return false;
        }
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
        // Get the client's IP
        $clientIp = request()->ip();

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

        // Create and return the cart
        return $this->createCart(
            $variantId,
            $sellingPlanId,
            $submissionId,
            $clientIp,
            $quantity
        );

        // $cartId = $this->createCart(
        //     $variantId,
        //     $sellingPlanId,
        //     $submissionId,
        //     $clientIp,
        //     $quantity
        // );

        // // Get the access token for the customer
        // $accessToken = $this->getCustomerAccessToken(
        //     $email,
        //     $password,
        //     $clientIp
        // );

        // Log::info("Customer access token retrieved", [
        //     "email" => $email,
        //     "token" => $accessToken,
        // ]);

        // if ($accessToken) {
        //     // Update the cart with the customer's access token
        //     $success = $this->associateCustomerWithCart(
        //         $cartId,
        //         $accessToken,
        //         $email,
        //         $clientIp
        //     );

        //     if (!$success) {
        //         Log::warning("Failed to associate customer with cart", [
        //             "email" => $email,
        //             "cart_id" => $cartId,
        //         ]);
        //     }

        //     // Get the cart and return it
        //     return $this->getCart($cartId, $clientIp);
        // } else {
        //     Log::warning("Failed to get customer access token", [
        //         "email" => $email,
        //     ]);
        // }

        // return null;
    }

    /**
     * Get customer access token (for login)
     *
     * @param string $email Customer's email
     * @param string $password Customer's password
     * @return string|null The customer access token if successful, null otherwise
     */
    public function getCustomerAccessToken(
        string $email,
        string $password,
        string $clientIp
    ): ?string {
        $mutation = <<<'GRAPHQL'
mutation customerAccessTokenCreate($input: CustomerAccessTokenCreateInput!) {
  customerAccessTokenCreate(input: $input) {
    customerAccessToken {
      accessToken
      expiresAt
    }
    customerUserErrors {
      field
      message
    }
  }
}
GRAPHQL;

        $response = Http::withHeaders([
            "Content-Type" => "application/json",
            "Shopify-Storefront-Private-Token" => $this->storefrontAccessToken,
            "Shopify-Storefront-Buyer-IP" => $clientIp,
        ])->post($this->storefrontEndpoint, [
            "query" => $mutation,
            "variables" => [
                "input" => [
                    "email" => $email,
                    "password" => $password,
                ],
            ],
        ]);

        if ($response->successful()) {
            $data = $response->json();

            if (
                isset(
                    $data["data"]["customerAccessTokenCreate"][
                        "customerUserErrors"
                    ]
                ) &&
                !empty(
                    $data["data"]["customerAccessTokenCreate"][
                        "customerUserErrors"
                    ]
                )
            ) {
                Log::error(
                    "Shopify customer access token creation returned errors",
                    $data["data"]["customerAccessTokenCreate"][
                        "customerUserErrors"
                    ]
                );
                return null;
            }

            if (
                isset(
                    $data["data"]["customerAccessTokenCreate"][
                        "customerAccessToken"
                    ]["accessToken"]
                )
            ) {
                return $data["data"]["customerAccessTokenCreate"][
                    "customerAccessToken"
                ]["accessToken"];
            } else {
                Log::error(
                    "Shopify customer access token not found in response",
                    $data
                );
            }
        } else {
            Log::error(
                "Shopify API call failed (customer access token creation)",
                $response->json()
            );
        }

        return null;
    }

    /**
     * Associate a customer with a cart
     *
     * @param string $cartId The cart ID
     * @param string $customerAccessToken The customer access token
     * @return bool Whether the association was successful
     */
    public function associateCustomerWithCart(
        string $cartId,
        string $customerAccessToken,
        string $email,
        string $customerIp
    ): bool {
        $mutation = <<<'GRAPHQL'
mutation cartBuyerIdentityUpdate($buyerIdentity: CartBuyerIdentityInput!, $cartId: ID!) {
  cartBuyerIdentityUpdate(buyerIdentity: $buyerIdentity, cartId: $cartId) {
    cart {
      id
      buyerIdentity {
          email
      }
    }
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;

        $response = Http::withHeaders([
            "Content-Type" => "application/json",
            "Shopify-Storefront-Private-Token" => $this->storefrontAccessToken,
            "Shopify-Storefront-Buyer-IP" => $customerIp,
        ])->post($this->storefrontEndpoint, [
            "query" => $mutation,
            "variables" => [
                "cartId" => $cartId,
                "buyerIdentity" => [
                    "customerAccessToken" => $customerAccessToken,
                    "countryCode" => "GB",
                    "email" => $email,
                ],
            ],
        ]);

        if ($response->successful()) {
            $data = $response->json();

            if (
                isset($data["data"]["cartBuyerIdentityUpdate"]["userErrors"]) &&
                !empty($data["data"]["cartBuyerIdentityUpdate"]["userErrors"])
            ) {
                Log::error(
                    "Shopify cart buyer identity update returned errors",
                    $data["data"]["cartBuyerIdentityUpdate"]["userErrors"]
                );
                return false;
            }

            if (
                isset(
                    $data["data"]["cartBuyerIdentityUpdate"]["cart"][
                        "buyerIdentity"
                    ]["email"]
                )
            ) {
                // Return true
                return true;
            } else {
                Log::error("Email not found in response", $data);
            }
        } else {
            Log::error(
                "Shopify API call failed (cart buyer identity update)",
                $response->json()
            );
        }

        return false;
    }

    /**
     * Create a Shopify cart
     */
    private function createCart(
        string $variantId,
        string $sellingPlanId,
        int $submissionId,
        string $clientIp,
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
            "Shopify-Storefront-Private-Token" => $this->storefrontAccessToken,
            "Shopify-Storefront-Buyer-IP" => $clientIp,
        ])->post($this->storefrontEndpoint, [
            "query" => $mutation,
            "variables" => [
                "input" => $cartInput,
            ],
        ]);

        if ($response->successful()) {
            $data = $response->json();
            // Log::info("Shopify cart response", $data);

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

    // Get the cart
    private function getCart(string $cartId, string $clientIp): ?array
    {
        $query = <<<'GRAPHQL'
query checkoutUrl($cartId: ID!) {
    cart(id: $cartId){
        checkoutUrl
    }
}
GRAPHQL;

        $response = Http::withHeaders([
            "Content-Type" => "application/json",
            "Shopify-Storefront-Private-Token" => $this->storefrontAccessToken,
            "Shopify-Storefront-Buyer-IP" => $clientIp,
        ])->post($this->storefrontEndpoint, [
            "query" => $query,
            "variables" => [
                "cartId" => $cartId,
            ],
        ]);

        if ($response->successful()) {
            $data = $response->json();
            if (isset($data["data"]["cart"]["checkoutUrl"])) {
                return $data["data"]["cart"];
            }
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
            "Shopify-Storefront-Private-Token" => $this->storefrontAccessToken,
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
            "Shopify-Storefront-Private-Token" => $this->storefrontAccessToken,
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

    /**
     * Cancel and refund a Shopify order
     *
     * @param string $orderId The Shopify order ID
     * @param string $reason The cancellation reason
     * @return bool Whether the cancellation was successful
     */
    public function cancelAndRefundOrder(string $orderId, string $reason): bool
    {
        // Check if we have a valid order ID
        if (empty($orderId)) {
            Log::warning("Empty Shopify order ID provided for cancellation");
            return false;
        }

        try {
            $cancelMutation = <<<'GRAPHQL'
mutation orderCancel($orderId: ID!, $reason: OrderCancelReason!, $notifyCustomer: Boolean, $refund: Boolean!, $restock: Boolean!, $staffNote: String) {
  orderCancel(
    orderId: $orderId,
    reason: $reason,
    notifyCustomer: $notifyCustomer,
    refund: $refund,
    restock: $restock,
    staffNote: $staffNote
  ) {
    job {
      id
    }
    orderCancelUserErrors {
      field
      message
    }
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;

            $cancelResponse = Http::withHeaders([
                "Content-Type" => "application/json",
                "X-Shopify-Access-Token" => $this->accessToken,
            ])->post($this->endpoint, [
                "query" => $cancelMutation,
                "variables" => [
                    "orderId" => $this->formatGid($orderId),
                    "reason" => "OTHER",
                    "notifyCustomer" => true,
                    "refund" => true,
                    "restock" => true,
                    "staffNote" => $reason,
                ],
            ]);

            if (!$cancelResponse->successful()) {
                Log::error("Failed to cancel order in Shopify", [
                    "order_id" => $orderId,
                    "response" => $cancelResponse->json(),
                ]);
                return false;
            }

            $cancelData = $cancelResponse->json();

            // Check for cancellation errors from both error fields
            if (
                (isset($cancelData["data"]["orderCancel"]["userErrors"]) &&
                    !empty($cancelData["data"]["orderCancel"]["userErrors"])) ||
                (isset(
                    $cancelData["data"]["orderCancel"]["orderCancelUserErrors"]
                ) &&
                    !empty(
                        $cancelData["data"]["orderCancel"][
                            "orderCancelUserErrors"
                        ]
                    ))
            ) {
                Log::error("Order cancellation returned errors", [
                    "order_id" => $orderId,
                    "user_errors" =>
                        $cancelData["data"]["orderCancel"]["userErrors"] ?? [],
                    "cancel_errors" =>
                        $cancelData["data"]["orderCancel"][
                            "orderCancelUserErrors"
                        ] ?? [],
                ]);
                return false;
            }

            // Log::info("Successfully cancelled order in Shopify", [
            //     "order_id" => $orderId,
            // ]);
            return true;
        } catch (\Exception $e) {
            Log::error("Exception while cancelling Shopify order", [
                "order_id" => $orderId,
                "error" => $e->getMessage(),
                "trace" => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * Generate and attach a prescription label to a Shopify order
     *
     * @param Prescription $prescription The prescription to generate a label for
     * @return bool Whether the operation was successful
     */
    public function attachPrescriptionLabelToOrder(
        Prescription $prescription,
        ?string $currentOrderId = null
    ): bool {
        // Check if this prescription is associated with a subscription that has an order ID
        $subscription = $prescription->subscription;
        if (!$subscription || !$subscription->original_shopify_order_id) {
            Log::info(
                "No Shopify order ID found for prescription #{$prescription->id}, skipping label attachment"
            );
            return false;
        }

        // Use the provided current order ID if available
        $orderId = $currentOrderId ?? $subscription->original_shopify_order_id;
        try {
            // Generate the label
            $labelService = app(PrescriptionLabelService::class);
            $labelPath = $labelService->saveLabel($prescription);

            if (!$labelPath) {
                Log::error("Failed to generate prescription label", [
                    "prescription_id" => $prescription->id,
                ]);
                return false;
            }

            // Attach the document to the Shopify order
            $success = $this->attachFileToOrder(
                $orderId,
                $labelPath,
                "prescription_label"
            );

            // Clean up the temporary file
            if (file_exists($labelPath)) {
                unlink($labelPath);
            }

            if ($success) {
                // Log::info(
                //     "Successfully attached prescription label to Shopify order",
                //     [
                //         "prescription_id" => $prescription->id,
                //         "order_id" => $orderId,
                //     ]
                // );
                return true;
            } else {
                Log::error(
                    "Failed to attach prescription label to Shopify order",
                    [
                        "prescription_id" => $prescription->id,
                        "order_id" => $orderId,
                    ]
                );
                return false;
            }
        } catch (\Exception $e) {
            Log::error(
                "Exception attaching prescription label to Shopify order",
                [
                    "prescription_id" => $prescription->id,
                    "order_id" => $orderId,
                    "error" => $e->getMessage(),
                ]
            );
            return false;
        }
    }

    /**
     * Attach a prescription document to a Shopify order
     *
     * @param string $orderId The Shopify order ID
     * @param string $filePath Path to the prescription document file
     * @param string $note Optional note to attach with the file
     * @return bool Whether the operation was successful
     */
    public function attachPrescriptionToOrder(
        string $orderId,
        string $filePath,
        string $note = ""
    ): bool {
        return $this->attachFileToOrder(
            $orderId,
            $filePath,
            "prescription",
            $note
        );
    }

    /**
     * Reusable: stage, PUT, fileCreate, then attach as a file_reference metafield
     */
    private function attachFileToOrder(
        string $orderId,
        string $filePath,
        string $metaKey,
        string $note = ""
    ): bool {
        if (empty($orderId) || !file_exists($filePath)) {
            Log::warning(
                "Invalid params for attaching file",
                compact("orderId", "filePath")
            );
            return false;
        }
        $fileBytes = file_get_contents($filePath);
        if ($fileBytes === false) {
            Log::error("Failed to read file at $filePath");
            return false;
        }

        // 1) stagedUploadsCreate → PUT
        $stage = $this->stageAndUpload($filePath, $fileBytes);
        if (!$stage["success"]) {
            return false;
        }

        // 2) fileCreate to get back a GID
        $fileGid = $this->createShopifyFile(
            $filePath,
            $stage["resourceUrl"],
            $note
        );
        if (!$fileGid) {
            return false;
        }

        // 3) orderUpdate metafield
        $formattedOrderId = $this->formatGid($orderId);
        $mutation = <<<'GRAPHQL'
mutation updateOrderMetafields($input: OrderInput!) {
  orderUpdate(input: $input) {
    userErrors { field message }
  }
}
GRAPHQL;

        $resp = Http::withHeaders([
            "Content-Type" => "application/json",
            "X-Shopify-Access-Token" => $this->accessToken,
        ])->post($this->endpoint, [
            "query" => $mutation,
            "variables" => [
                "input" => [
                    "id" => $formattedOrderId,
                    "metafields" => [
                        [
                            "namespace" => "custom",
                            "key" => $metaKey,
                            "type" => "file_reference",
                            "value" => $fileGid,
                        ],
                    ],
                ],
            ],
        ]);

        $errors = data_get($resp->json(), "data.orderUpdate.userErrors", []);
        if (!empty($errors)) {
            Log::error(
                "Shopify errors updating order metafield",
                compact("errors")
            );
            return false;
        }

        Log::info("Attached file to order {$orderId} as {$metaKey}");
        return true;
    }

    /**
     * Stage the upload and PUT the raw bytes to Shopify’s signed URL.
     *
     * @return array{success: bool, resourceUrl?: string}
     */
    private function stageAndUpload(string $filePath, string $bytes): array
    {
        $stageMutation = <<<'GRAPHQL'
mutation stagedUploadsCreate($input: [StagedUploadInput!]!) {
  stagedUploadsCreate(input: $input) {
    stagedTargets {
      url
      resourceUrl
    }
    userErrors { field message }
  }
}
GRAPHQL;

        $filename = basename($filePath);

        $stageResp = Http::withHeaders([
            "Content-Type" => "application/json",
            "X-Shopify-Access-Token" => $this->accessToken,
        ])->post($this->endpoint, [
            "query" => $stageMutation,
            "variables" => [
                "input" => [
                    [
                        "filename" => $filename,
                        "mimeType" => "application/pdf",
                        "resource" => "FILE",
                        "httpMethod" => "PUT",
                    ],
                ],
            ],
        ]);

        if (!$stageResp->successful()) {
            Log::error("stageUploadsCreate failed", [
                "status" => $stageResp->status(),
                "response" => $stageResp->json(),
            ]);
            return ["success" => false];
        }

        $stageData = $stageResp->json("data.stagedUploadsCreate");
        if (!empty($stageData["userErrors"])) {
            Log::error("stagedUploadsCreate userErrors", [
                "errors" => $stageData["userErrors"],
            ]);
            return ["success" => false];
        }

        $target = $stageData["stagedTargets"][0] ?? null;
        if (
            !$target ||
            empty($target["url"]) ||
            empty($target["resourceUrl"])
        ) {
            Log::error("Invalid staged target data", ["target" => $target]);
            return ["success" => false];
        }

        // PUT PDF bytes to the signed URL
        $uploadResp = Http::withHeaders([
            "Content-Type" => "application/pdf",
        ])
            ->withBody($bytes, "application/pdf")
            ->put($target["url"]);

        if (!$uploadResp->successful()) {
            Log::error("PUT to staged target failed", [
                "status" => $uploadResp->status(),
                "response" => $uploadResp->body(),
            ]);
            return ["success" => false];
        }

        return [
            "success" => true,
            "resourceUrl" => $target["resourceUrl"],
        ];
    }

    /**
     * Create a Shopify File object from a resourceUrl and return its GID.
     */
    private function createShopifyFile(
        string $filePath,
        string $resourceUrl,
        string $note
    ): ?string {
        $fileCreateMutation = <<<'GRAPHQL'
mutation fileCreate($files: [FileCreateInput!]!) {
  fileCreate(files: $files) {
    files { id }
    userErrors { field message }
  }
}
GRAPHQL;

        $filename = basename($filePath);

        $resp = Http::withHeaders([
            "Content-Type" => "application/json",
            "X-Shopify-Access-Token" => $this->accessToken,
        ])->post($this->endpoint, [
            "query" => $fileCreateMutation,
            "variables" => [
                "files" => [
                    [
                        "filename" => $filename,
                        "originalSource" => $resourceUrl,
                        "alt" => $note,
                    ],
                ],
            ],
        ]);

        if (!$resp->successful()) {
            Log::error("fileCreate API failed", [
                "status" => $resp->status(),
                "response" => $resp->json(),
            ]);
            return null;
        }

        $data = $resp->json("data.fileCreate");
        if (!empty($data["userErrors"])) {
            Log::error("fileCreate userErrors", [
                "errors" => $data["userErrors"],
            ]);
            return null;
        }

        return $data["files"][0]["id"] ?? null;
    }

    /**
     * Format ID to Shopify GraphQL global ID format if needed
     */
    private function formatGid(string $id): string
    {
        // If already in gid format, return as is
        if (strpos($id, "gid://") === 0) {
            return $id;
        }

        // Otherwise convert to gid format
        return "gid://shopify/Order/{$id}";
    }
}
