<?php

namespace App\Config;

class ShopifyProductMapping
{
    public static $medicationProducts = [
        "Mounjaro (£199.00)" => "gid://shopify/Product/7348307329120",
        "Wegovy (£209.00)" => "gid://shopify/Product/7348538310752",
    ];

    public static function getProductId($optionText)
    {
        return self::$medicationProducts[$optionText] ?? null;
    }
}
