<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\ZendeskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ZendeskServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ZendeskService $zendeskService;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up config values for testing
        config([
            "services.zendesk.sell_api_url" => "https://api.getbase.com",
            "services.zendesk.sell_access_token" => "test_token_123",
        ]);

        $this->zendeskService = new ZendeskService();
    }

    /** @test */
    public function it_successfully_deletes_a_lead_when_found()
    {
        $user = User::factory()->create([
            "email" => "test@example.com",
        ]);

        // Mock the search response
        Http::fake([
            "https://api.getbase.com/v3/leads/search*" => Http::response(
                [
                    "items" => [
                        [
                            "data" => [
                                "id" => 12345,
                                "email" => "test@example.com",
                                "first_name" => "Test",
                                "last_name" => "User",
                            ],
                        ],
                    ],
                ],
                200,
            ),
            "https://api.getbase.com/v2/leads/12345" => Http::response([], 204),
        ]);

        $result = $this->zendeskService->deleteLead($user);

        $this->assertTrue($result);

        // Assert the correct API calls were made
        Http::assertSent(function ($request) {
            return $request->url() ===
                "https://api.getbase.com/v3/leads/search?email=test%40example.com" &&
                $request->hasHeader("Authorization", "Bearer test_token_123");
        });

        Http::assertSent(function ($request) {
            return $request->url() ===
                "https://api.getbase.com/v2/leads/12345" &&
                $request->method() === "DELETE" &&
                $request->hasHeader("Authorization", "Bearer test_token_123");
        });
    }

    /** @test */
    public function it_returns_true_when_no_lead_is_found()
    {
        $user = User::factory()->create([
            "email" => "notfound@example.com",
        ]);

        // Mock the search response with no results
        Http::fake([
            "https://api.getbase.com/v3/leads/search*" => Http::response(
                [
                    "items" => [],
                ],
                200,
            ),
        ]);

        Log::shouldReceive("info")
            ->once()
            ->with("No Zendesk Sell lead found for user notfound@example.com.");

        $result = $this->zendeskService->deleteLead($user);

        $this->assertTrue($result);

        // Assert only the search API call was made
        Http::assertSent(function ($request) {
            return $request->url() ===
                "https://api.getbase.com/v3/leads/search?email=notfound%40example.com";
        });

        Http::assertSentCount(1);
    }

    /** @test */
    public function it_deletes_multiple_leads_if_found()
    {
        $user = User::factory()->create([
            "email" => "duplicate@example.com",
        ]);

        // Mock the search response with multiple leads
        Http::fake([
            "https://api.getbase.com/v3/leads/search*" => Http::response(
                [
                    "items" => [
                        [
                            "data" => [
                                "id" => 11111,
                                "email" => "duplicate@example.com",
                            ],
                        ],
                        [
                            "data" => [
                                "id" => 22222,
                                "email" => "duplicate@example.com",
                            ],
                        ],
                    ],
                ],
                200,
            ),
            "https://api.getbase.com/v2/leads/11111" => Http::response([], 204),
            "https://api.getbase.com/v2/leads/22222" => Http::response([], 204),
        ]);

        $result = $this->zendeskService->deleteLead($user);

        $this->assertTrue($result);

        // Assert all delete API calls were made
        Http::assertSent(function ($request) {
            return $request->url() ===
                "https://api.getbase.com/v2/leads/11111" &&
                $request->method() === "DELETE";
        });

        Http::assertSent(function ($request) {
            return $request->url() ===
                "https://api.getbase.com/v2/leads/22222" &&
                $request->method() === "DELETE";
        });
    }

    /** @test */
    public function it_returns_false_when_search_fails()
    {
        $user = User::factory()->create([
            "email" => "error@example.com",
        ]);

        // Mock a failed search response
        Http::fake([
            "https://api.getbase.com/v3/leads/search*" => Http::response(
                [
                    "error" => "Unauthorized",
                ],
                401,
            ),
        ]);

        Log::shouldReceive("warning")
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains(
                    $message,
                    "Failed to search for Zendesk Sell lead",
                ) && $context["status"] === 401;
            });

        $result = $this->zendeskService->deleteLead($user);

        $this->assertFalse($result);
    }

    /** @test */
    public function it_returns_false_when_delete_fails()
    {
        $user = User::factory()->create([
            "email" => "deletefail@example.com",
        ]);

        // Mock successful search but failed delete
        Http::fake([
            "https://api.getbase.com/v3/leads/search*" => Http::response(
                [
                    "items" => [
                        [
                            "data" => [
                                "id" => 99999,
                                "email" => "deletefail@example.com",
                            ],
                        ],
                    ],
                ],
                200,
            ),
            "https://api.getbase.com/v2/leads/99999" => Http::response(
                [
                    "error" => "Not found",
                ],
                404,
            ),
        ]);

        Log::shouldReceive("error")
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains(
                    $message,
                    "Failed to delete Zendesk Sell lead",
                ) &&
                    $context["lead_id"] === 99999 &&
                    $context["status"] === 404;
            });

        $result = $this->zendeskService->deleteLead($user);

        $this->assertFalse($result);
    }

    /** @test */
    public function it_returns_false_when_api_credentials_are_missing()
    {
        // Clear the config
        config(["services.zendesk.sell_access_token" => null]);

        $zendeskService = new ZendeskService();

        $user = User::factory()->create([
            "email" => "test@example.com",
        ]);

        Log::shouldReceive("error")
            ->once()
            ->with("Zendesk Sell API credentials are not configured.");

        $result = $zendeskService->deleteLead($user);

        $this->assertFalse($result);

        // No HTTP requests should have been made
        Http::assertNothingSent();
    }

    /** @test */
    public function it_handles_exceptions_gracefully()
    {
        $user = User::factory()->create([
            "email" => "exception@example.com",
        ]);

        // Mock an exception during the HTTP request
        Http::fake(function ($request) {
            throw new \Exception("Network error");
        });

        Log::shouldReceive("error")
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains(
                    $message,
                    "Exception occurred while deleting Zendesk Sell lead",
                ) && $context["error"] === "Network error";
            });

        $result = $this->zendeskService->deleteLead($user);

        $this->assertFalse($result);
    }

    /** @test */
    public function it_handles_partial_deletion_failure_correctly()
    {
        $user = User::factory()->create([
            "email" => "partial@example.com",
        ]);

        // Mock multiple leads where one delete succeeds and one fails
        Http::fake([
            "https://api.getbase.com/v3/leads/search*" => Http::response(
                [
                    "items" => [
                        [
                            "data" => [
                                "id" => 33333,
                                "email" => "partial@example.com",
                            ],
                        ],
                        [
                            "data" => [
                                "id" => 44444,
                                "email" => "partial@example.com",
                            ],
                        ],
                    ],
                ],
                200,
            ),
            "https://api.getbase.com/v2/leads/33333" => Http::response([], 204),
            "https://api.getbase.com/v2/leads/44444" => Http::response(
                [
                    "error" => "Internal server error",
                ],
                500,
            ),
        ]);

        Log::shouldReceive("info")
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains(
                    $message,
                    "Successfully deleted Zendesk Sell lead",
                ) && $context["lead_id"] === 33333;
            });

        Log::shouldReceive("error")
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains(
                    $message,
                    "Failed to delete Zendesk Sell lead",
                ) && $context["lead_id"] === 44444;
            });

        $result = $this->zendeskService->deleteLead($user);

        // Should return false since not all deletions succeeded
        $this->assertFalse($result);
    }

    /** @test */
    public function it_skips_leads_without_valid_id()
    {
        $user = User::factory()->create([
            "email" => "invalidid@example.com",
        ]);

        // Mock search response with invalid lead structure
        Http::fake([
            "https://api.getbase.com/v3/leads/search*" => Http::response(
                [
                    "items" => [
                        [
                            "data" => [
                                // Missing 'id' field
                                "email" => "invalidid@example.com",
                            ],
                        ],
                        [
                            "data" => [
                                "id" => 55555,
                                "email" => "invalidid@example.com",
                            ],
                        ],
                    ],
                ],
                200,
            ),
            "https://api.getbase.com/v2/leads/55555" => Http::response([], 204),
        ]);

        $result = $this->zendeskService->deleteLead($user);

        $this->assertTrue($result);

        // Assert only the valid lead was deleted
        Http::assertSent(function ($request) {
            return $request->url() ===
                "https://api.getbase.com/v2/leads/55555" &&
                $request->method() === "DELETE";
        });

        // Should be 2 requests: 1 search + 1 delete (skipping the invalid one)
        Http::assertSentCount(2);
    }
}
