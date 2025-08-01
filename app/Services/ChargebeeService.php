<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\User;
use App\Models\Prescription;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ChargebeeService
{
    protected string $apiKey;
    protected string $siteName;
    protected string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config("services.chargebee.api_key");
        $this->siteName = config("services.chargebee.site");
        $this->baseUrl = "https://{$this->siteName}.chargebee.com/api/v2";
    }

    /**
     * Create a hosted checkout for a subscription
     *
     * @param array $params Checkout parameters
     * @return array|null Hosted page details or null on failure
     */
    public function createHostedCheckout(array $params): ?array
    {
        // Log::info("Creating Chargebee hosted checkout", [
        //     "params" => $params,
        //     "base_url" => $this->baseUrl,
        //     "site_name" => $this->siteName,
        // ]);

        // Use the modern Items API approach
        $itemsApiParams = $this->convertToItemsApiFormat($params);
        if ($itemsApiParams !== null) {
            return $this->createHostedCheckoutWithItemsApi($itemsApiParams);
        }

        return null;
    }

    private function createHostedCheckoutWithItemsApi(array $params): ?array
    {
        $endpoint = "{$this->baseUrl}/hosted_pages/checkout_new_for_items";

        try {
            $response = Http::withBasicAuth($this->apiKey, "")
                ->asForm()
                ->post($endpoint, $params);

            // Log::debug("Items API response", [
            //     "status" => $response->status(),
            //     "headers" => $response->headers(),
            //     "body" => $response->body(),
            // ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data["hosted_page"] ?? null;
            }

            return null;
        } catch (\Exception $e) {
            Log::warning("Items API checkout exception", [
                "error" => $e->getMessage(),
                "endpoint" => $endpoint,
                "trace" => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    private function convertToItemsApiFormat(array $params): ?array
    {
        // Check if this looks like legacy format with subscription[plan_id]
        if (!isset($params["subscription[plan_id]"])) {
            Log::debug(
                "No subscription[plan_id] found, skipping Items API conversion",
            );
            return null;
        }

        $planId = $params["subscription[plan_id]"];
        $quantity = $params["subscription[plan_quantity]"] ?? 1;

        // Log::debug("Converting to Items API format", [
        //     "plan_id" => $planId,
        //     "quantity" => $quantity,
        // ]);

        // Build Items API format parameters
        $itemsParams = [
            "subscription_items[item_price_id][0]" => $planId,
            "subscription_items[quantity][0]" => $quantity,
        ];

        // Copy relevant parameters
        $parametersToCopy = [
            "customer[" => true, // Copy all customer parameters
            "subscription[cf_" => true, // Copy subscription-level custom fields
            "redirect_url" => false, // Copy single parameter
            "cancel_url" => false, // Copy single parameter
        ];

        foreach ($params as $key => $value) {
            foreach ($parametersToCopy as $pattern => $isPrefix) {
                if ($isPrefix && strpos($key, $pattern) === 0) {
                    $itemsParams[$key] = $value;
                    break;
                } elseif (!$isPrefix && $key === $pattern) {
                    $itemsParams[$key] = $value;
                    break;
                }
            }
        }

        // Log::debug("Converted params for Items API", [
        //     "converted" => $itemsParams,
        // ]);
        return $itemsParams;
    }

    /**
     * Create a checkout for prescription with dose schedule
     *
     * @param Prescription $prescription
     * @param User $customer
     * @param array $additionalParams
     * @return array|null
     */
    public function createPrescriptionCheckout(
        Prescription $prescription,
        User $customer,
        array $additionalParams = [],
    ): ?array {
        $doseSchedule = $prescription->dose_schedule;
        if (!is_array($doseSchedule) || empty($doseSchedule)) {
            Log::error("Invalid dose schedule for prescription checkout", [
                "prescription_id" => $prescription->id,
                "dose_schedule" => $doseSchedule,
            ]);
            return null;
        }

        // Get the first dose for initial subscription
        $initialDose = $doseSchedule[0];

        $params = [
            "subscription[plan_id]" =>
                $initialDose["chargebee_plan_id"] ?? null,
            "subscription[plan_quantity]" => 1,
            "customer[id]" => $this->getChargebeeCustomerId($customer),
            "customer[first_name]" => $this->getFirstName($customer->name),
            "customer[last_name]" => $this->getLastName($customer->name),
            "customer[email]" => $customer->email,
            "customer[phone]" => $customer->phone_number,
            "subscription[cf_prescription_id]" => $prescription->id,
            "subscription[cf_patient_id]" => $prescription->patient_id,
            "redirect_url" =>
                config("app.frontend_url") . "/prescription-success",
            "cancel_url" =>
                config("app.frontend_url") . "/prescription-cancelled",
        ];

        // Add custom fields for tracking
        if (
            $prescription->clinicalPlan &&
            $prescription->clinicalPlan->questionnaire_submission_id
        ) {
            $params["subscription[cf_questionnaire_submission_id]"] =
                $prescription->clinicalPlan->questionnaire_submission_id;
        }

        // Merge any additional parameters
        $params = array_merge($params, $additionalParams);

        return $this->createHostedCheckout($params);
    }

    /**
     * Create a GLP1 checkout
     *
     * @param User $customer
     * @param string $planId
     * @param array $additionalParams
     * @return array|null
     */
    public function createGLP1Checkout(
        User $customer,
        string $planId,
        array $additionalParams = [],
    ): ?array {
        $submissionId =
            $additionalParams["subscription[cf_questionnaire_submission_id]"] ??
            null;

        $params = [
            "subscription[plan_id]" => $planId,
            "subscription[plan_quantity]" => 1,
            "customer[id]" => $this->getChargebeeCustomerId($customer),
            "customer[first_name]" => $this->getFirstName($customer->name),
            "customer[last_name]" => $this->getLastName($customer->name),
            "customer[email]" => $customer->email,
            "customer[phone]" => $customer->phone_number,
            "subscription[cf_consultation]" => "true",
            "cancel_url" => config("app.frontend_url") . "/dashboard",
        ];

        $params = array_merge($params, $additionalParams);

        return $this->createHostedCheckout($params);
    }

    /**
     * Create a consultation checkout for one-time charge
     *
     * @param User $customer
     * @param string $priceId
     * @param array $additionalParams
     * @return array|null
     */
    public function createConsultationCheckout(
        User $customer,
        string $priceId,
        array $additionalParams = [],
    ): ?array {
        return $this->createOneTimeChargeCheckout(
            $customer,
            $priceId,
            $additionalParams,
        );
    }

    /**
     * Create a one-time charge checkout for charges (not subscriptions)
     *
     * @param User $customer
     * @param string $priceId
     * @param array $additionalParams
     * @return array|null
     */
    public function createOneTimeChargeCheckout(
        User $customer,
        string $priceId,
        array $additionalParams = [],
    ): ?array {
        $endpoint = "{$this->baseUrl}/hosted_pages/checkout_one_time_for_items";

        $params = [
            "customer[id]" => $this->getChargebeeCustomerId($customer),
            "customer[first_name]" => $this->getFirstName($customer->name),
            "customer[last_name]" => $this->getLastName($customer->name),
            "customer[email]" => $customer->email,
            "customer[phone]" => $customer->phone_number,
            "item_prices[item_price_id][0]" => $priceId,
            "item_prices[quantity][0]" => 1,
            "redirect_url" =>
                config("app.frontend_url") . "/consultation-success",
            "cancel_url" => config("app.frontend_url") . "/treatments",
        ];

        $params = array_merge($params, $additionalParams);

        try {
            $response = Http::withBasicAuth($this->apiKey, "")
                ->asForm()
                ->post($endpoint, $params);

            if ($response->successful()) {
                $data = $response->json();
                return $data["hosted_page"] ?? null;
            }

            Log::error("Failed to create one-time charge checkout", [
                "status" => $response->status(),
                "response" => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error(
                "Error creating one-time charge checkout: " . $e->getMessage(),
            );
            throw $e;
        }
    }

    /**
     * Get subscription details from Chargebee
     *
     * @param string $subscriptionId
     * @return array|null
     */
    public function getSubscription(string $subscriptionId): ?array
    {
        try {
            $response = Http::withBasicAuth($this->apiKey, "")->get(
                "{$this->baseUrl}/subscriptions/{$subscriptionId}",
            );

            if ($response->successful()) {
                $data = $response->json();
                return $data ?? null;
            }

            Log::error("Failed to get Chargebee subscription", [
                "subscription_id" => $subscriptionId,
                "status" => $response->status(),
                "response" => $response->json(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error("Exception getting Chargebee subscription", [
                "subscription_id" => $subscriptionId,
                "error" => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Cancel a subscription
     *
     * @param string $subscriptionId
     * @param string $reason
     * @param bool $endOfTerm Whether to cancel at end of current term
     * @return bool
     */
    public function cancelSubscription(
        string $subscriptionId,
        string $reason = "customer_request",
        bool $endOfTerm = false,
    ): bool {
        try {
            $params = [
                "cancel_option" => $endOfTerm ? "end_of_term" : "immediately",
                "cancel_reason_code" => $reason,
            ];

            $response = Http::withBasicAuth($this->apiKey, "")
                ->asForm()
                ->post(
                    "{$this->baseUrl}/subscriptions/{$subscriptionId}/cancel_for_items",
                    $params,
                );

            if ($response->successful()) {
                Log::info("Successfully cancelled Chargebee subscription", [
                    "subscription_id" => $subscriptionId,
                    "reason" => $reason,
                ]);
                return true;
            }

            Log::error("Failed to cancel Chargebee subscription", [
                "subscription_id" => $subscriptionId,
                "status" => $response->status(),
                "response" => $response->json(),
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error("Exception cancelling Chargebee subscription", [
                "subscription_id" => $subscriptionId,
                "error" => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Update subscription plan (equivalent to SKU swap)
     *
     * @param string $subscriptionId
     * @param string $newPlanId
     * @param array $additionalParams
     * @return bool
     */
    public function updateSubscriptionPlan(
        string $subscriptionId,
        string $newItemPriceId,
        array $additionalParams = [],
    ): bool {
        try {
            $params = array_merge(
                [
                    "subscription_items[item_price_id][0]" => $newItemPriceId,
                    "subscription_items[quantity][0]" => 1,
                    "replace_items_list" => "true",
                    "prorate" => "false",
                    "invoice_immediately" => "false",
                ],
                $additionalParams,
            );

            $response = Http::withBasicAuth($this->apiKey, "")
                ->asForm()
                ->post(
                    "{$this->baseUrl}/subscriptions/{$subscriptionId}/update_for_items",
                    $params,
                );

            if ($response->successful()) {
                // Log::info("Successfully updated Chargebee subscription plan", [
                //     "subscription_id" => $subscriptionId,
                //     "new_item_price_id" => $newItemPriceId,
                // ]);
                return true;
            }

            Log::error("Failed to update Chargebee subscription plan", [
                "subscription_id" => $subscriptionId,
                "new_item_price_id" => $newItemPriceId,
                "status" => $response->status(),
                "response" => $response->json(),
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error("Exception updating Chargebee subscription plan", [
                "subscription_id" => $subscriptionId,
                "new_item_price_id" => $newItemPriceId,
                "error" => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get subscriptions for a customer
     *
     * @param string $customerId
     * @return array
     */
    public function getSubscriptionsForCustomer(string $customerId): array
    {
        try {
            $response = Http::withBasicAuth($this->apiKey, "")->get(
                "{$this->baseUrl}/subscriptions",
                [
                    "customer_id[is]" => $customerId,
                    "limit" => 100,
                ],
            );

            if ($response->successful()) {
                $data = $response->json();
                return $data["list"] ?? [];
            }

            Log::error("Failed to get Chargebee subscriptions for customer", [
                "customer_id" => $customerId,
                "status" => $response->status(),
                "response" => $response->json(),
            ]);

            return [];
        } catch (\Exception $e) {
            Log::error(
                "Exception getting Chargebee subscriptions for customer",
                [
                    "customer_id" => $customerId,
                    "error" => $e->getMessage(),
                ],
            );
            return [];
        }
    }

    /**
     * Change subscription term end for Product Catalog 2.0
     */
    public function changeSubscriptionTermEnd(
        string $subscriptionId,
        int $termEndsAt,
        bool $prorate = false,
        bool $invoiceImmediately = false,
    ): ?array {
        $endpoint = "{$this->baseUrl}/subscriptions/{$subscriptionId}/change_term_end";

        $params = [
            "term_ends_at" => $termEndsAt,
            "prorate" => $prorate ? "true" : "false",
            "invoice_immediately" => $invoiceImmediately ? "true" : "false",
        ];

        // Debug logging for timestamp
        // Log::info("Sending change term end request to Chargebee", [
        //     "subscription_id" => $subscriptionId,
        //     "term_ends_at_timestamp" => $termEndsAt,
        //     "term_ends_at_formatted" => date("Y-m-d H:i:s", $termEndsAt),
        //     "term_ends_at_iso" => date("c", $termEndsAt),
        //     "params" => $params,
        // ]);

        $response = Http::withBasicAuth($this->apiKey, "")
            ->asForm()
            ->post($endpoint, $params);

        if ($response->successful()) {
            $data = $response->json();
            // Log::info("Successfully changed Chargebee subscription term end", [
            //     "subscription_id" => $subscriptionId,
            //     "term_ends_at" => date("Y-m-d H:i:s", $termEndsAt),
            // ]);
            return $data;
        } else {
            Log::error("Failed to change Chargebee subscription term end", [
                "subscription_id" => $subscriptionId,
                "term_ends_at" => date("Y-m-d H:i:s", $termEndsAt),
                "status" => $response->status(),
                "body" => $response->body(),
            ]);
            return null;
        }
    }

    /**
     * Get upcoming renewals
     *
     * @param int $daysOut
     * @return array
     */
    public function getUpcomingRenewals(int $daysOut = 2): array
    {
        try {
            $startDate = Carbon::today();
            $endDate = Carbon::today()->addDays($daysOut);
            $allRenewals = [];
            $offset = null;

            do {
                $params = [
                    "status[is]" => "active",
                    "next_billing_at[after]" => $startDate->timestamp,
                    "next_billing_at[before]" => $endDate->timestamp,
                    "limit" => 100,
                ];

                if ($offset) {
                    $params["offset"] = $offset;
                }

                $response = Http::withBasicAuth($this->apiKey, "")->get(
                    "{$this->baseUrl}/subscriptions",
                    $params,
                );

                if ($response->successful()) {
                    $data = $response->json();
                    $renewals = $data["list"] ?? [];
                    $allRenewals = array_merge($allRenewals, $renewals);
                    $offset = $data["next_offset"] ?? null;
                } else {
                    Log::error("Failed to get upcoming Chargebee renewals", [
                        "status" => $response->status(),
                        "response" => $response->json(),
                    ]);
                    break;
                }
            } while ($offset);

            return $allRenewals;
        } catch (\Exception $e) {
            Log::error("Exception getting upcoming Chargebee renewals", [
                "error" => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Update subscription custom fields
     *
     * @param string $subscriptionId
     * @param array $customFields
     * @return bool
     */
    public function updateSubscriptionCustomFields(
        string $subscriptionId,
        array $customFields,
    ): bool {
        try {
            $params = [];
            foreach ($customFields as $key => $value) {
                $params["cf_{$key}"] = $value;
            }

            $response = Http::withBasicAuth($this->apiKey, "")
                ->asForm()
                ->post(
                    "{$this->baseUrl}/subscriptions/{$subscriptionId}/update",
                    $params,
                );

            if ($response->successful()) {
                return true;
            }

            Log::error(
                "Failed to update Chargebee subscription custom fields",
                [
                    "subscription_id" => $subscriptionId,
                    "status" => $response->status(),
                    "response" => $response->json(),
                ],
            );

            return false;
        } catch (\Exception $e) {
            Log::error(
                "Exception updating Chargebee subscription custom fields",
                [
                    "subscription_id" => $subscriptionId,
                    "error" => $e->getMessage(),
                ],
            );
            return false;
        }
    }

    /**
     * Retrieve a hosted page
     *
     * @param string $hostedPageId
     * @return array|null
     */
    public function retrieveHostedPage(string $hostedPageId): ?array
    {
        try {
            $response = Http::withBasicAuth($this->apiKey, "")->get(
                "{$this->baseUrl}/hosted_pages/{$hostedPageId}",
            );

            if ($response->successful()) {
                $data = $response->json();
                return $data["hosted_page"] ?? null;
            }

            return null;
        } catch (\Exception $e) {
            Log::error("Exception retrieving Chargebee hosted page", [
                "hosted_page_id" => $hostedPageId,
                "error" => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Generate a unique Chargebee customer ID
     *
     * @param User $user
     * @return string
     */
    private function getChargebeeCustomerId(User $user): string
    {
        // Use email as base for customer ID, making it Chargebee-friendly
        $customerId = preg_replace("/[^a-zA-Z0-9_-]/", "_", $user->email);
        return substr($customerId, 0, 50); // Chargebee has length limits
    }

    /**
     * Extract first name from full name
     *
     * @param string|null $fullName
     * @return string
     */
    private function getFirstName(?string $fullName): string
    {
        if (empty($fullName)) {
            return "Unknown";
        }

        $parts = explode(" ", trim($fullName));
        return $parts[0] ?? "Unknown";
    }

    /**
     * Extract last name from full name
     *
     * @param string|null $fullName
     * @return string
     */
    private function getLastName(?string $fullName): string
    {
        if (empty($fullName)) {
            return "User";
        }

        $parts = explode(" ", trim($fullName));
        if (count($parts) > 1) {
            array_shift($parts); // Remove first name
            return implode(" ", $parts);
        }

        return "User";
    }

    /**
     * Pause a subscription
     *
     * @param string $subscriptionId
     * @param int|null $pauseDate Timestamp when to pause (null for immediate)
     * @param int|null $resumeDate Timestamp when to resume (null for indefinite)
     * @return bool
     */
    public function pauseSubscription(
        string $subscriptionId,
        ?int $pauseDate = null,
        ?int $resumeDate = null,
    ): bool {
        try {
            $params = [];

            if ($pauseDate) {
                $params["pause_date"] = $pauseDate;
            }

            if ($resumeDate) {
                $params["resume_date"] = $resumeDate;
            }

            $response = Http::withBasicAuth($this->apiKey, "")
                ->asForm()
                ->post(
                    "{$this->baseUrl}/subscriptions/{$subscriptionId}/pause",
                    $params,
                );

            return $response->successful();
        } catch (\Exception $e) {
            Log::error("Exception pausing Chargebee subscription", [
                "subscription_id" => $subscriptionId,
                "error" => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Resume a paused subscription
     *
     * @param string $subscriptionId
     * @param int|null $resumeDate Timestamp when to resume (null for immediate)
     * @return bool
     */
    public function resumeSubscription(
        string $subscriptionId,
        ?int $resumeDate = null,
    ): bool {
        try {
            $params = [];

            if ($resumeDate) {
                $params["resume_date"] = $resumeDate;
            }

            $response = Http::withBasicAuth($this->apiKey, "")
                ->asForm()
                ->post(
                    "{$this->baseUrl}/subscriptions/{$subscriptionId}/resume",
                    $params,
                );

            return $response->successful();
        } catch (\Exception $e) {
            Log::error("Exception resuming Chargebee subscription", [
                "subscription_id" => $subscriptionId,
                "error" => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get shipping address from Chargebee subscription
     */
    public function getSubscriptionShippingAddress(
        string $subscriptionId,
    ): ?array {
        try {
            $response = Http::withBasicAuth($this->apiKey, "")->get(
                "{$this->baseUrl}/subscriptions/{$subscriptionId}",
            );

            if ($response->successful()) {
                $data = $response->json();

                if (isset($data["subscription"]["shipping_address"])) {
                    $shippingAddress =
                        $data["subscription"]["shipping_address"];

                    return [
                        "first_name" => $shippingAddress["first_name"] ?? "",
                        "last_name" => $shippingAddress["last_name"] ?? "",
                        "line1" => $shippingAddress["line1"] ?? "",
                        "line2" => $shippingAddress["line2"] ?? "",
                        "line3" => $shippingAddress["line3"] ?? "",
                        "city" => $shippingAddress["city"] ?? "",
                        "state" => $shippingAddress["state"] ?? "",
                        "country" => $shippingAddress["country"] ?? "",
                        "zip" => $shippingAddress["zip"] ?? "",
                        "phone" => $shippingAddress["phone"] ?? "",
                        "email" => $shippingAddress["email"] ?? "",
                        "company" => $shippingAddress["company"] ?? "",
                    ];
                }

                return null;
            }

            Log::error(
                "Failed to get shipping address from Chargebee subscription",
                [
                    "subscription_id" => $subscriptionId,
                    "status" => $response->status(),
                    "response" => $response->json(),
                ],
            );

            return null;
        } catch (\Exception $e) {
            Log::error(
                "Failed to get shipping address from Chargebee subscription",
                [
                    "subscription_id" => $subscriptionId,
                    "error" => $e->getMessage(),
                ],
            );
            return null;
        }
    }
}
