<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    "postmark" => [
        "token" => env("POSTMARK_TOKEN"),
    ],

    "ses" => [
        "key" => env("AWS_ACCESS_KEY_ID"),
        "secret" => env("AWS_SECRET_ACCESS_KEY"),
        "region" => env("AWS_DEFAULT_REGION", "us-east-1"),
    ],

    "resend" => [
        "key" => env("RESEND_KEY"),
    ],

    "slack" => [
        "notifications" => [
            "bot_user_oauth_token" => env("SLACK_BOT_USER_OAUTH_TOKEN"),
            "channel" => env("SLACK_BOT_USER_DEFAULT_CHANNEL"),
        ],
    ],

    "shopify" => [
        "access_token" => env("SHOPIFY_ADMIN_API_TOKEN"),
        "endpoint" =>
            "https://" .
            env("PUBLIC_SHOPIFY_DOMAIN") .
            "/admin/api/2025-01/graphql.json",
    ],

    "workos" => [
        "client_id" => env("WORKOS_CLIENT_ID"),
        "secret" => env("WORKOS_API_KEY"),
        "redirect_url" => env("WORKOS_REDIRECT_URL"),
    ],
];
