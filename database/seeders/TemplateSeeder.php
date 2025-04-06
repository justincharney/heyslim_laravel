<?php

namespace Database\Seeders;

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
                "condition_treated" =>
                    "Obesity with BMI > 30 and meet the criteria (NICE guidelines [NG246])",
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
                "condition_treated" =>
                    "Type 2 diabetes, obesity with BMI > 35 with weight-related comorbidities",
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
            [
                "name" => "Liraglutide (Saxenda) Treatment",
                "description" =>
                    "Treatment plan for Liraglutide in weight management",
                "condition_treated" => "Obesity with BMI > 35",
                "medicines_that_may_be_prescribed" => "Liraglutide (Saxenda)",
                "dose_schedule" =>
                    "Start with 0.6mg subcutaneously once daily for one week\n" .
                    "Increase by 0.6mg weekly to target dose of 3.0mg daily\n" .
                    "Week 1: 0.6mg daily\n" .
                    "Week 2: 1.2mg daily\n" .
                    "Week 3: 1.8mg daily\n" .
                    "Week 4: 2.4mg daily\n" .
                    "Week 5 onwards: 3.0mg daily",
                "guidelines" =>
                    "NICE guidance [TA664]. Discontinue treatment if less than 5% weight loss achieved after 12 weeks on 3.0mg daily dose.",
                "monitoring_frequency" =>
                    "Weekly during dose escalation, then every 2 months",
                "process_for_reporting_adrs" =>
                    "Monitor for gallbladder disease, pancreatitis, and increased heart rate. Report serious adverse reactions via yellow card system. Common side effects include nausea, constipation, headache, and injection site reactions.",
                "is_global" => true,
                "created_by" => $adminId,
            ],
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
        $templates = [
            [
                "name" => "Wegovy (Semaglutide) Complete Protocol",
                "description" =>
                    "Full dose escalation protocol for Wegovy (semaglutide) for weight management",
                "medication_name" => "Semaglutide (Wegovy)",
                "dose" => "0.25mg, 0.5mg, 1.0mg, 1.7mg, 2.4mg pen injectors",
                "schedule" => "Once weekly injection with dose escalation",
                "refills" => 12,
                "directions" =>
                    "Week 1-4: Inject 0.25mg subcutaneously once weekly\n" .
                    "Week 5-8: Inject 0.5mg subcutaneously once weekly\n" .
                    "Week 9-12: Inject 1.0mg subcutaneously once weekly\n" .
                    "Week 13-16: Inject 1.7mg subcutaneously once weekly\n" .
                    "Week 17 onwards: Inject 2.4mg subcutaneously once weekly (maintenance dose)\n\n" .
                    "Adjust dose as needed for tolerability. Store in refrigerator (2°C to 8°C). Do not freeze. May be kept at room temperature (below 30°C) for up to 28 days.",
                "is_global" => true,
                "created_by" => $adminId,
            ],
            [
                "name" => "Mounjaro/Zepbound (Tirzepatide) Complete Protocol",
                "description" =>
                    "Full dose escalation protocol for Tirzepatide for weight management",
                "medication_name" => "Tirzepatide (Mounjaro/Zepbound)",
                "dose" =>
                    "2.5mg, 5.0mg, 7.5mg, 10mg, 12.5mg, 15mg pen injectors",
                "schedule" => "Once weekly injection with dose escalation",
                "refills" => 12,
                "directions" =>
                    "Week 1-4: Inject 2.5mg subcutaneously once weekly\n" .
                    "Week 5-8: Inject 5.0mg subcutaneously once weekly\n" .
                    "Week 9-12: Inject 7.5mg subcutaneously once weekly\n" .
                    "Week 13-16: Inject 10.0mg subcutaneously once weekly\n" .
                    "Week 17-20: Inject 12.5mg subcutaneously once weekly (if needed)\n" .
                    "Week 21 onwards: Inject 15.0mg subcutaneously once weekly (if needed for maximum efficacy)\n\n" .
                    "Adjust dose or pause dose escalation as needed for tolerability. Store in refrigerator (2°C to 8°C). Do not freeze. May be kept at room temperature (below 30°C) for up to 28 days.",
                "is_global" => true,
                "created_by" => $adminId,
            ],
            [
                "name" => "Saxenda (Liraglutide) Complete Protocol",
                "description" =>
                    "Full dose escalation protocol for Saxenda (liraglutide) for weight management",
                "medication_name" => "Liraglutide (Saxenda)",
                "dose" =>
                    "6mg/mL multi-dose pen (provides doses of 0.6mg, 1.2mg, 1.8mg, 2.4mg, and 3.0mg)",
                "schedule" => "Once daily injection with dose escalation",
                "refills" => 12,
                "directions" =>
                    "Week 1: Inject 0.6mg subcutaneously once daily\n" .
                    "Week 2: Inject 1.2mg subcutaneously once daily\n" .
                    "Week 3: Inject 1.8mg subcutaneously once daily\n" .
                    "Week 4: Inject 2.4mg subcutaneously once daily\n" .
                    "Week 5 onwards: Inject 3.0mg subcutaneously once daily (maintenance dose)\n\n" .
                    "Adjust dose as needed for tolerability. Delay dose escalation if unable to tolerate. Store in refrigerator (2°C to 8°C). After first use, can be stored at room temperature (below 30°C) or refrigerated for up to 30 days.",
                "is_global" => true,
                "created_by" => $adminId,
            ],
        ];

        foreach ($templates as $template) {
            PrescriptionTemplate::create($template);
        }
    }
}
