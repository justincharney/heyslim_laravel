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
            "/admin/api/unstable/graphql.json",
        "webhook_secret" => env("SHOPIFY_WEBHOOK_SECRET"),
        "storefront_endpoint" =>
            "https://" .
            env("PUBLIC_SHOPIFY_DOMAIN") .
            "/api/unstable/graphql.json",
        "storefront_access_token" => env("SHOPIFY_STOREFRONT_API_TOKEN"),
    ],

    "recharge" => [
        "api_key" => env("RECHARGE_API_KEY"),
        "endpoint" => env("RECHARGE_ENDPOINT", "https://api.rechargeapps.com"),
        "client_secret" => env("RECHARGE_CLIENT_SECRET"),
    ],

    "workos" => [
        "client_id" => env("WORKOS_CLIENT_ID"),
        "secret" => env("WORKOS_API_KEY"),
        "redirect_url" => env("WORKOS_REDIRECT_URL"),
    ],

    "calendly" => [
        "api_key" => env("CALENDLY_API_KEY"),
    ],

    "yousign" => [
        "api_key" => env("YOUSIGN_API_KEY"),
        "api_url" => env("YOUSIGN_BASE_URL"),
        "webhook_secret" => env("YOUSIGN_WEBHOOK_SECRET"),
    ],

    "supabase" => [
        "url" => env("SUPABASE_STORAGE_URL"),
        "service_key" => env("SUPABASE_SERVICE_KEY"),
        "bucket" => env("SUPABASE_BUCKET"),
    ],
];
