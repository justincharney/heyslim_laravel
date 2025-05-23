<?php

namespace App\Config;

class ShopifyProductMapping
{
    public static $medicationProducts = [
        "Mounjaro (£199.00)" => "gid://shopify/Product/7348307329120",
        "Wegovy (£209.00)" => "gid://shopify/Product/7348538310752",
    ];

    public static $consultationProductId = "gid://shopify/Product/7396602249312";

    public static function getProductId($optionText)
    {
        return self::$medicationProducts[$optionText] ?? null;
    }

    public static function getConsultationProductId()
    {
        return self::$consultationProductId;
    }
}
