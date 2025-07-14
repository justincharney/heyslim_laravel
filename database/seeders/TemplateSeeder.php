<?php

namespace Database\Seeders;

use App\Config\ShopifyProductMapping;
use App\Models\ClinicalPlanTemplate;
use App\Models\PrescriptionTemplate;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Set the team context
        setPermissionsTeamId(1);

        // Get an admin user to be the creator of the global templates
        $admin = User::whereHas("roles", function ($q) {
            $q->where("name", "admin");
        })->first();

        if (!$admin) {
            $this->command->error(
                "Admin user not found! Unable to create templates."
            );
            return;
        }

        // Clinical Plan Templates
        $this->createClinicalPlanTemplates($admin->id);

        // Prescription Templates
        $this->createPrescriptionTemplates($admin->id);

        $this->command->info("Templates created successfully.");
    }

    /**
     * Create global clinical plan templates
     */
    private function createClinicalPlanTemplates(int $adminId): void
    {
        $templates = [
            // [
            //     "name" => "GLP-1 Weight Management",
            //     "description" =>
            //         "Standard template for GLP-1 weight management",
            //     "condition_treated" =>
            //         "Obesity with BMI > 30 or BMI > 27 with weight-related comorbidities",
            //     "medicines_that_may_be_prescribed" =>
            //         "Semaglutide (Wegovy), Tirzepatide (Mounjaro), Liraglutide (Saxenda)",
            //     "dose_schedule" =>
            //         "Semaglutide (Wegovy): Start with 0.25mg subcutaneously once weekly for 4 weeks, then increase to 0.5mg weekly for 4 weeks. Continue with monthly dose escalation (1mg, 1.7mg, then 2.4mg) as tolerated. Maintain at 2.4mg weekly for maintenance therapy.\n\nTirzepatide (Mounjaro): Start with 2.5mg subcutaneously once weekly for 4 weeks, then increase to 5mg weekly for 4 weeks. Continue with monthly dose escalation (7.5mg, 10mg, 12.5mg, then 15mg) as tolerated.\n\nLiraglutide (Saxenda): Start with 0.6mg subcutaneously once daily for one week, then increase by 0.6mg weekly to a target dose of 3.0mg daily.",
            //     "guidelines" =>
            //         "NICE guidance [NG167] for GLP-1 mimetics in weight management. Patients should also follow lifestyle modifications including reduced caloric intake and increased physical activity.",
            //     "monitoring_frequency" =>
            //         "Monthly for 3 months, then every 3 months",
            //     "process_for_reporting_adrs" =>
            //         "Report any adverse drug reactions to the prescriber immediately. Complete yellow card reporting for serious adverse effects. Common side effects (nausea, constipation, diarrhea) should be monitored and managed symptomatically.",
            //     "is_global" => true,
            //     "created_by" => $adminId,
            // ],
            [
                "name" => "Semaglutide (Wegovy) Treatment",
                "description" =>
                    "Specific treatment plan for Wegovy in weight management",
                "condition_treated" => "BMI >= 30",
                "medicines_that_may_be_prescribed" => "Semaglutide (Wegovy)",
                "dose_schedule" =>
                    "Start with 0.25mg subcutaneously once weekly for 4 weeks\n" .
                    "Increase to 0.5mg weekly for 4 weeks\n" .
                    "Increase to 1.0mg weekly for 4 weeks\n" .
                    "Increase to 1.7mg weekly for 4 weeks\n" .
                    "Reach maintenance dose of 2.4mg weekly",
                "guidelines" =>
                    "NICE guidance [TA875] for GLP-1 mimetics. Treatment should be discontinued if less than 5% body weight reduction after 6 months of treatment.",
                "monitoring_frequency" =>
                    "Every 4 weeks during dose escalation, then every 3 months",
                "process_for_reporting_adrs" =>
                    "Monitor for pancreatitis, gallbladder disease, hypoglycemia, and renal impairment. Report serious adverse reactions through yellow card system. Common side effects include nausea, vomiting, diarrhea, abdominal pain, and constipation.",
                "is_global" => true,
                "created_by" => $adminId,
            ],
            [
                "name" => "Tirzepatide (Mounjaro/Zepbound) Treatment",
                "description" =>
                    "Treatment plan for Tirzepatide in weight management and T2DM",
                "condition_treated" => "BMI >= 30",
                "medicines_that_may_be_prescribed" =>
                    "Tirzepatide (Mounjaro/Zepbound)",
                "dose_schedule" =>
                    "Start with 2.5mg subcutaneously once weekly for 4 weeks\n" .
                    "Increase to 5mg weekly for 4 weeks\n" .
                    "Increase to 7.5mg weekly for 4 weeks\n" .
                    "Increase to 10mg weekly for 4 weeks\n" .
                    "If needed and tolerated, increase to 12.5mg weekly for 4 weeks\n" .
                    "Maximum dose of 15mg weekly",
                "guidelines" =>
                    "NICE guidelines [TA1026]. Consider dose adjustments in patients with renal impairment. Treatment should be discontinued if less than 5% body weight reduction after 6 months of treatment.",
                "monitoring_frequency" =>
                    "Every 4 weeks during dose escalation, then every 3 months",
                "process_for_reporting_adrs" =>
                    "Monitor for gastrointestinal symptoms, acute kidney injury, and pancreatitis. Report serious adverse reactions via yellow card system. Advise patients on hydration and managing GI side effects.",
                "is_global" => true,
                "created_by" => $adminId,
            ],
            // [
            //     "name" => "Liraglutide (Saxenda) Treatment",
            //     "description" =>
            //         "Treatment plan for Liraglutide in weight management",
            //     "condition_treated" => "BMI >= 30",
            //     "medicines_that_may_be_prescribed" => "Liraglutide (Saxenda)",
            //     "dose_schedule" =>
            //         "Start with 0.6mg subcutaneously once daily for one week\n" .
            //         "Increase by 0.6mg weekly to target dose of 3.0mg daily\n" .
            //         "Week 1: 0.6mg daily\n" .
            //         "Week 2: 1.2mg daily\n" .
            //         "Week 3: 1.8mg daily\n" .
            //         "Week 4: 2.4mg daily\n" .
            //         "Week 5 onwards: 3.0mg daily",
            //     "guidelines" =>
            //         "NICE guidance [TA664]. Discontinue treatment if less than 5% weight loss achieved after 12 weeks on 3.0mg daily dose.",
            //     "monitoring_frequency" =>
            //         "Weekly during dose escalation, then every 2 months",
            //     "process_for_reporting_adrs" =>
            //         "Monitor for gallbladder disease, pancreatitis, and increased heart rate. Report serious adverse reactions via yellow card system. Common side effects include nausea, constipation, headache, and injection site reactions.",
            //     "is_global" => true,
            //     "created_by" => $adminId,
            // ],
        ];

        foreach ($templates as $template) {
            ClinicalPlanTemplate::create($template);
        }
    }

    /**
     * Create global prescription templates
     */
    private function createPrescriptionTemplates(int $adminId): void
    {
        $wegovyProductGid = ShopifyProductMapping::getProductId("Wegovy");
        $mounjaroProductGid = ShopifyProductMapping::getProductId("Mounjaro");

        $wegovyVariants = ShopifyProductMapping::getProductVariantsByGid(
            $wegovyProductGid
        );
        $mounjaroVariants = ShopifyProductMapping::getProductVariantsByGid(
            $mounjaroProductGid
        );

        $wegovySellingPlanId = ShopifyProductMapping::getSellingPlanId(
            $wegovyProductGid
        );
        $mounjaroSellingPlanId = ShopifyProductMapping::getSellingPlanId(
            $mounjaroProductGid
        );

        $templates = [
            [
                "name" => "Wegovy (Semaglutide) Protocol",
                "description" =>
                    "Standard protocol for Wegovy (semaglutide) for weight management",
                "medication_name" => "Wegovy",
                "refills" => 2,
                "directions" =>
                    "Inject subcutaneously once weekly on the same day, any time; rotate injection sites between abdomen, thigh or upper arm. If using the same area (e.g., abdomen), vary the exact spot within that area to avoid irritation. If you miss a dose and your next scheduled dose is more than 2 days away, inject as soon as possible; if less than 2 days away, skip and resume your regular schedule.",
                "dose_schedule" => [
                    [
                        "refill_number" => 0,
                        "dose" => "0.25mg",
                        "shopify_variant_gid" =>
                            $wegovyVariants[0]["shopify_variant_gid"],
                        "selling_plan_id" => $wegovySellingPlanId,
                    ],
                    [
                        "refill_number" => 1,
                        "dose" => "0.5mg",
                        "shopify_variant_gid" =>
                            $wegovyVariants[1]["shopify_variant_gid"],
                        "selling_plan_id" => $wegovySellingPlanId,
                    ],
                    [
                        "refill_number" => 2,
                        "dose" => "1.0mg",
                        "shopify_variant_gid" =>
                            $wegovyVariants[2]["shopify_variant_gid"],
                        "selling_plan_id" => $wegovySellingPlanId,
                    ],
                ],
                "is_global" => true,
                "created_by" => $adminId,
            ],
            [
                "name" => "Mounjaro/Zepbound (Tirzepatide) Protocol",
                "description" =>
                    "Standard protocol for Tirzepatide for weight management",
                "medication_name" => "Mounjaro",
                "refills" => 2,
                "directions" =>
                    "Inject subcutaneously once weekly on the same day, any time; rotate injection sites between abdomen, thigh or upper arm. f using the same area (e.g., abdomen), vary the exact spot within that area to avoid irritation. If you miss a dose, give it within 3 days or skip and resume your usual schedule.",
                "dose_schedule" => [
                    [
                        "refill_number" => 0,
                        "dose" => "2.5mg",
                        "shopify_variant_gid" =>
                            $mounjaroVariants[0]["shopify_variant_gid"],
                        "selling_plan_id" => $mounjaroSellingPlanId,
                    ],
                    [
                        "refill_number" => 1,
                        "dose" => "5.0mg",
                        "shopify_variant_gid" =>
                            $mounjaroVariants[1]["shopify_variant_gid"],
                        "selling_plan_id" => $mounjaroSellingPlanId,
                    ],
                    [
                        "refill_number" => 2,
                        "dose" => "7.5mg",
                        "shopify_variant_gid" =>
                            $mounjaroVariants[2]["shopify_variant_gid"],
                        "selling_plan_id" => $mounjaroSellingPlanId,
                    ],
                ],
                "is_global" => true,
                "created_by" => $adminId,
            ],
            // [
            //     "name" => "Saxenda (Liraglutide) Protocol",
            //     "description" =>
            //         "Standard protocol for Saxenda (liraglutide) for weight management",
            //     "medication_name" => "Liraglutide (Saxenda)",
            //     "dose" => "Based on dose schedule",
            //     "schedule" => "Once daily subcutaneous injection",
            //     "refills" => 5,
            //     "directions" =>
            //         "Inject subcutaneously once daily as directed. Store in refrigerator. After first use, may be kept at room temperature for up to 30 days.",
            //     "dose_schedule" => json_encode([
            //         [
            //             "refill_number" => 0,
            //             "dose" =>
            //                 "0.6mg daily for week 1, 1.2mg daily for week 2, 1.8mg daily for week 3, 2.4mg daily for week 4",
            //         ],
            //         [
            //             "refill_number" => 1,
            //             "dose" => "3.0mg daily",
            //         ],
            //         [
            //             "refill_number" => 2,
            //             "dose" => "3.0mg daily",
            //         ],
            //         [
            //             "refill_number" => 3,
            //             "dose" => "3.0mg daily",
            //         ],
            //         [
            //             "refill_number" => 4,
            //             "dose" => "3.0mg daily",
            //         ],
            //     ]),
            //     "is_global" => true,
            //     "created_by" => $adminId,
            // ],
        ];

        foreach ($templates as $template) {
            PrescriptionTemplate::create($template);
        }
    }
}
