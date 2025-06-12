<?php

namespace App\Config;

class ShopifyProductMapping
{
    public static $medicationProducts = [
        "Mounjaro" => "gid://shopify/Product/7348307329120",
        "Wegovy" => "gid://shopify/Product/7348538310752",
    ];

    public static $mounjaroSellingPlanId = "gid://shopify/SellingPlan/1215725664";
    public static $wegovySellingPlanId = "gid://shopify/SellingPlan/1215791200";

    public static $medicationProductDetails = [
        "gid://shopify/Product/7348307329120" => [
            "name" => "Mounjaro",
            "variants" => [
                [
                    "dose" => "2.5mg",
                    "shopify_variant_gid" =>
                        "gid://shopify/ProductVariant/41902912897120",
                    "selling_plan_id" => "gid://shopify/SellingPlan/1215725664",
                ],
                [
                    "dose" => "5mg",
                    "shopify_variant_gid" =>
                        "gid://shopify/ProductVariant/41902912929888",
                    "selling_plan_id" => "gid://shopify/SellingPlan/1215725664",
                ],
                [
                    "dose" => "7.5mg",
                    "shopify_variant_gid" =>
                        "gid://shopify/ProductVariant/41902912962656",
                    "selling_plan_id" => "gid://shopify/SellingPlan/1215725664",
                ],
                [
                    "dose" => "10mg",
                    "shopify_variant_gid" =>
                        "gid://shopify/ProductVariant/41902912995424",
                    "selling_plan_id" => "gid://shopify/SellingPlan/1215725664",
                ],
                [
                    "dose" => "12.5mg",
                    "shopify_variant_gid" =>
                        "gid://shopify/ProductVariant/41902913028192",
                    "selling_plan_id" => "gid://shopify/SellingPlan/1215725664",
                ],
                [
                    "dose" => "15mg",
                    "shopify_variant_gid" =>
                        "gid://shopify/ProductVariant/41902913060960",
                    "selling_plan_id" => "gid://shopify/SellingPlan/1215725664",
                ],
            ],
        ],
        "gid://shopify/Product/7348538310752" => [
            "name" => "Wegovy",
            "variants" => [
                [
                    "dose" => "0.25mg",
                    "shopify_variant_gid" =>
                        "gid://shopify/ProductVariant/41891531292768",
                    "selling_plan_id" => "gid://shopify/SellingPlan/1215791200",
                ],
                [
                    "dose" => "0.5mg",
                    "shopify_variant_gid" =>
                        "gid://shopify/ProductVariant/41891531325536",
                    "selling_plan_id" => "gid://shopify/SellingPlan/1215791200",
                ],
                [
                    "dose" => "1.0mg",
                    "shopify_variant_gid" =>
                        "gid://shopify/ProductVariant/41891531358304",
                    "selling_plan_id" => "gid://shopify/SellingPlan/1215791200",
                ],
                [
                    "dose" => "1.7mg",
                    "shopify_variant_gid" =>
                        "gid://shopify/ProductVariant/41891531391072",
                    "selling_plan_id" => "gid://shopify/SellingPlan/1215791200",
                ],
                [
                    "dose" => "2.4mg",
                    "shopify_variant_gid" =>
                        "gid://shopify/ProductVariant/41891531423840",
                    "selling_plan_id" => "gid://shopify/SellingPlan/1215791200",
                ],
            ],
        ],
    ];

    public static $consultationProductId = "gid://shopify/Product/7396602249312";
    public static $consulationProductDetails = [
        "id" => "gid://shopify/Product/7396602249312",
        "type" => "consultation",
        "title" => "Clinical Consultation",
        "description" =>
            "Book a one-on-one consultation with our specialist weight loss doctors to discuss your health goals and personalised treatment options.",
    ];

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

    public static function getConsultationProductDetails()
    {
        return self::$consulationProductDetails;
    }

    public static function getSellingPlanId($productGid)
    {
        return self::$medicationProductDetails[$productGid]["variants"][0][
            "selling_plan_id"
        ] ?? null;
    }
}
