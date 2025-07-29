<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WebhookBasicAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $authHeader = $request->header("Authorization");

        if (!$authHeader || !str_starts_with($authHeader, "Basic ")) {
            return $this->unauthorizedResponse();
        }

        // Extract and decode the credentials
        $encodedCredentials = substr($authHeader, 6); // Remove "Basic " prefix
        $decodedCredentials = base64_decode($encodedCredentials);

        if (!$decodedCredentials || !str_contains($decodedCredentials, ":")) {
            return $this->unauthorizedResponse();
        }

        [$username, $password] = explode(":", $decodedCredentials, 2);

        // Get expected credentials from environment
        $expectedUsername = config("services.chargebee.webhook_username");
        $expectedPassword = config("services.chargebee.webhook_password");

        if (!$expectedUsername || !$expectedPassword) {
            \Log::error("Webhook basic auth credentials not configured");
            return $this->unauthorizedResponse();
        }

        // Use hash_equals for timing-safe comparison
        if (
            !hash_equals($expectedUsername, $username) ||
            !hash_equals($expectedPassword, $password)
        ) {
            \Log::warning("Webhook basic auth failed", [
                "ip" => $request->ip(),
                "user_agent" => $request->userAgent(),
                "provided_username" => $username,
            ]);
            return $this->unauthorizedResponse();
        }

        return $next($request);
    }

    /**
     * Return unauthorized response
     */
    private function unauthorizedResponse(): Response
    {
        return response("Unauthorized", 401, [
            "WWW-Authenticate" => 'Basic realm="Webhook Access"',
        ]);
    }
}
