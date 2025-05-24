<?php

namespace App\Config;

class ShopifyProductMapping
{
    public static $medicationProducts = [
        "Mounjaro (£199.00)" => "gid://shopify/Product/7348307329120",
        "Wegovy (£209.00)" => "gid://shopify/Product/7348538310752",
    ];

    public static $medicationProductDetails = [
        "gid://shopify/Product/7348307329120" => [
            "name" => "Mounjaro",
            "variants" => [
                [
                    "dose_description" => "2.5mg",
                    "shopify_variant_gid" =>
                        "gid://shopify/ProductVariant/41902912897120",
                ],
                [
                    "dose_description" => "5mg",
                    "shopify_variant_gid" =>
                        "gid://shopify/ProductVariant/41902912929888",
                ],
                [
                    "dose_description" => "7.5mg",
                    "shopify_variant_gid" =>
                        "gid://shopify/ProductVariant/41902912962656",
                ],
                [
                    "dose_description" => "10mg",
                    "shopify_variant_gid" =>
                        "gid://shopify/ProductVariant/41902912995424",
                ],
                [
                    "dose_description" => "12.5mg",
                    "shopify_variant_gid" =>
                        "gid://shopify/ProductVariant/41902913028192",
                ],
                [
                    "dose_description" => "15mg",
                    "shopify_variant_gid" =>
                        "gid://shopify/ProductVariant/41902913060960",
                ],
            ],
        ],
        "gid://shopify/Product/7348538310752" => [
            "name" => "Wegovy",
            "variants" => [
                [
                    "dose_description" => "0.25mg",
                    "shopify_variant_gid" =>
                        "gid://shopify/ProductVariant/41891531292768",
                ],
                [
                    "dose_description" => "0.5mg",
                    "shopify_variant_gid" =>
                        "gid://shopify/ProductVariant/41891531325536",
                ],
                [
                    "dose_description" => "1.0mg",
                    "shopify_variant_gid" =>
                        "gid://shopify/ProductVariant/41891531358304",
                ],
                [
                    "dose_description" => "1.7mg",
                    "shopify_variant_gid" =>
                        "gid://shopify/ProductVariant/41891531391072",
                ],
                [
                    "dose_description" => "2.4mg",
                    "shopify_variant_gid" =>
                        "gid://shopify/ProductVariant/41891531423840",
                ],
            ],
        ],
    ];

    public static $consultationProductId = "gid://shopify/Product/7396602249312";

    public static function getProductId($optionText)
    {
        return self::$medicationProducts[$optionText] ?? null;
    }

    public static function getProductDetailsByGid($productGid)
    {
        return self::$medicationProductDetails[$productGid] ?? null;
    }

    public static function getProductVariantsByGid($productGid)
    {
        return self::$medicationProductDetails[$productGid]["variants"] ?? [];
    }

    public static function getProductDetailsByName($medicationNameKey)
    {
        $productGid = self::$medicationProducts[$medicationNameKey] ?? null;
        if ($productGid) {
            return self::$medicationProductDetails[$productGid] ?? null;
        }
        // Fallback for partial name match if needed, e.g. "Mounjaro" from "Mounjaro (£199.00)"
        foreach (self::$medicationProducts as $key => $gid) {
            if (stripos($key, $medicationNameKey) !== false) {
                return self::$medicationProductDetails[$gid] ?? null;
            }
        }
        return null;
    }

    public static function getConsultationProductId()
    {
        return self::$consultationProductId;
    }
}
