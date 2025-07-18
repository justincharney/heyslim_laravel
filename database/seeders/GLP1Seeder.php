<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Questionnaire;

class GLP1Seeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $questionnaireTitle = "GLP-1 Weight Management Treatment Plan";
        $targetVersion = 7; // Define the version this seeder creates

        // Check if the target version already exists and is current
        $existingCurrentQuestionnaire = Questionnaire::where(
            "title",
            $questionnaireTitle
        )
            ->where("version", $targetVersion)
            ->where("is_current", true)
            ->first();

        if ($existingCurrentQuestionnaire) {
            $this->command->info(
                "Questionnaire '{$questionnaireTitle}' version {$targetVersion} already exists and is current. Skipping."
            );
            return; // Exit if the target version is already the current one
        }

        // Find any existing questionnaire with the same title and mark it as not current
        $existingQuestionnaire = Questionnaire::where(
            "title",
            $questionnaireTitle
        )
            ->where("is_current", true)
            ->orderByDesc("version")
            ->first();

        if ($existingQuestionnaire) {
            // If the existing version is newer, don't downgrade.
            if ($existingQuestionnaire->version > $targetVersion) {
                $this->command->warn(
                    "Existing questionnaire '{$questionnaireTitle}' version {$existingQuestionnaire->version} is newer than target version {$targetVersion}. Skipping."
                );
                return;
            }

            // Mark the old version as not current
            $existingQuestionnaire->is_current = false;
            $existingQuestionnaire->save();
            $this->command->info(
                "Marked questionnaire '{$questionnaireTitle}' version {$existingQuestionnaire->version} as not current."
            );
        }

        // Create the new version of the questionnaire
        $newQuestionnaire = Questionnaire::create([
            "title" => $questionnaireTitle,
            "description" =>
                "Start your assessment for GLP-1 weight management therapy",
            "version" => $targetVersion,
            "is_current" => true,
            "created_at" => now(),
            "updated_at" => now(),
        ]);

        $questionnaireId = $newQuestionnaire->id;
        $this->command->info(
            "Created new questionnaire '{$questionnaireTitle}' version {$targetVersion} with ID {$questionnaireId}."
        );

        // Define sections and their questions
        $sections = [
            [
                "title" => "Physical Measurements and Verification",
                "questions" => [
                    [
                        "text" => "Height (cm)",
                        "type" => "number",
                        "required" => true,
                        "validation" => [
                            "min" => 100,
                            "max" => 250,
                            "message" =>
                                "Height must be between 100 and 250 cm",
                        ],
                    ],
                    [
                        "text" => "Weight (kg)",
                        "type" => "number",
                        "required" => true,
                        "validation" => [
                            "min" => 30,
                            "max" => 400,
                            "message" => "Weight must be between 30 and 400 kg",
                        ],
                    ],
                    [
                        "text" => "Calculated BMI",
                        "type" => "number",
                        "required" => true,
                        "calculated" => [
                            "formula" => "weight / ((height / 100)^2)",
                            "inputs" => [
                                "height" => 1,
                                "weight" => 2,
                            ],
                            "validation" => [
                                "min" => 27,
                                "message" =>
                                    "Your BMI must be at least 27 to qualify for GLP-1 treatment",
                            ],
                        ],
                    ],
                ],
            ],
            [
                "title" => "Eligibility Screening",
                "questions" => [
                    [
                        "text" =>
                            "Do you have, or have you ever been diagnosed with, any of the following health conditions?",
                        "type" => "multi-select",
                        "required" => true,
                        "options" => [
                            "Prediabetes",
                            "Type 2 Diabetes",
                            "High blood pressure",
                            "Raised cholesterol (dyslipidemia)",
                            "Sleep apnea",
                            "Polycystic ovarian syndrome (PCOS)",
                            "Fatty liver disease (NAFLD)",
                            "None of the above",
                        ],
                    ],
                    [
                        "text" =>
                            "Has your weight ever caused or worsened any of the following?",
                        "type" => "multi-select",
                        "required" => true,
                        "options" => [
                            "Knee or hip osteoarthritis",
                            "Asthma",
                            "Cardiovascular disease (e.g., heart attack, angina, stroke)",
                            "Acid reflux or GORD",
                            "Erectile dysfunction",
                            "None of the above",
                        ],
                    ],
                ],
            ],
            [
                "title" => "Medical History - Part 1",
                "questions" => [
                    [
                        "text" =>
                            "Do you have or have you ever had any of the following conditions?",
                        "type" => "multi-select",
                        "required" => true,
                        "options" => [
                            "Type 1 Diabetes",
                            "Diabetic retinopathy",
                            "Heart failure",
                            "Pancreatitis (current or history of)",
                            "Gallbladder disease or gallstones",
                            "Thyroid conditions (e.g., hypothyroidism, hyperthyroidism)",
                            "Currently being treated for cancer",
                            "Gastrointestinal conditions (e.g., Crohn's disease, IBS)",
                            "Chronic malabsorption syndrome (problems absorbing food)",
                            "Kidney or liver disease",
                            "Eating disorders (e.g., anorexia nervosa, bulimia)",
                            "Depression or mental health conditions",
                            "None of the above",
                        ],
                        "validation" => [
                            "disqualifying_options" => [
                                "Type 1 Diabetes",
                                "Pancreatitis (current or history of)",
                                "Currently being treated for cancer",
                                "Chronic malabsorption syndrome (problems absorbing food)",
                                "Eating disorders (e.g., anorexia nervosa, bulimia)",
                            ],
                        ],
                    ],
                    [
                        "text" => "Other chronic illnesses (specify)",
                        "type" => "textarea",
                        "required" => false,
                    ],
                    [
                        "text" =>
                            "Have you had any bariatric (weight loss) surgery?",
                        "type" => "yes_no",
                        "required" => true,
                    ],
                ],
            ],
            [
                "title" => "Medical History - Part 2",
                "questions" => [
                    [
                        "text" => "Have you had your gallbladder removed?",
                        "type" => "yes_no",
                        "required" => true,
                    ],
                    [
                        "text" =>
                            "Have you undergone any other surgeries in the past 12 months?",
                        "type" => "yes_no",
                        "required" => true,
                    ],
                ],
            ],
            [
                "title" => "Medication History - Part 1",
                "questions" => [
                    [
                        "text" =>
                            "Please list all prescription medications you are currently taking, including dosages",
                        "type" => "textarea",
                        "required" => false,
                    ],
                    [
                        "text" =>
                            "Please list over-the-counter medications, herbal remedies, or supplements",
                        "type" => "textarea",
                        "required" => false,
                    ],
                    [
                        "text" =>
                            "Do you have any known allergies (e.g., medications, food, or other substances)?",
                        "type" => "yes_no",
                        "required" => true,
                    ],
                    [
                        "text" => "Please specify your allergies",
                        "type" => "textarea",
                        "required" => true,
                        "display_conditions" => [
                            "question_number" => 13,
                            "operator" => "equals",
                            "value" => "yes",
                        ],
                    ],
                ],
            ],
            [
                "title" => "Medication History - Part 2",
                "questions" => [
                    [
                        "text" =>
                            "Have you previously used weight loss medications (e.g., Saxenda, Orlistat)?",
                        "type" => "yes_no",
                        "required" => true,
                    ],
                    [
                        "text" =>
                            "Please specify the medication, dosage, and outcomes",
                        "type" => "textarea",
                        "required" => true,
                        "display_conditions" => [
                            "question_number" => 15,
                            "operator" => "equals",
                            "value" => "yes",
                        ],
                    ],
                ],
            ],
            [
                "title" => "Lifestyle and Weight Management - Part 1",
                "questions" => [
                    [
                        "text" =>
                            "Which of the following best describes your usual eating habits? (Select all that apply)",
                        "type" => "multi-select",
                        "required" => true,
                        "options" => [
                            "I often skip meals",
                            "I eat 3 regular meals a day",
                            "I snack frequently between meals",
                            "I eat out/takeaway more than 2-3 times a week",
                            "I follow a specific diet (e.g. keto, intermittent fasting, vegetarian)",
                            "I tend to eat late at night",
                            "I frequently consume high-sugar or high-fat foods",
                            "I eat when I'm stressed, bored or emotional",
                            "I'm tracking calories/macros or using a diet app",
                            "None of the above",
                        ],
                    ],
                    [
                        "text" =>
                            "How many days a week do you engage in physical activity?",
                        "type" => "select",
                        "required" => true,
                        "options" => ["0", "1-2", "3-4", "5+"],
                    ],
                    [
                        "text" => "What type of exercise do you do?",
                        "type" => "multi-select",
                        "required" => true,
                        "options" => [
                            "Walking",
                            "Gym",
                            "Swimming",
                            "Cycling",
                            "Other",
                        ],
                    ],
                ],
            ],
            [
                "title" => "Lifestyle and Weight Management - Part 2",
                "questions" => [
                    [
                        "text" =>
                            "Are you willing to make long-term lifestyle changes, including diet and exercise, alongside medication?",
                        "type" => "yes_no",
                        "required" => true,
                        "required_answer" => "yes",
                    ],
                    [
                        "text" =>
                            "What are your main reasons for wanting to lose weight?",
                        "type" => "multi-select",
                        "options" => [
                            "Improving my overall health",
                            "Avoiding or managing a specific health condition",
                            "Looking and feeling better",
                            "Becoming more active",
                            "Improving my mood or mental wellbeing",
                            "Improving my sleep or energy levels",
                            "Other",
                        ],
                    ],
                ],
            ],
            [
                "title" => "Risk Assessment and Exclusions - Part 1",
                "questions" => [
                    [
                        "text" =>
                            "Are you pregnant, planning to become pregnant, or currently breastfeeding?",
                        "type" => "yes_no",
                        "required" => true,
                        "required_answer" => "no",
                    ],
                    [
                        "text" =>
                            "Do you drink alcohol more than 3-4 times per week?",
                        "type" => "yes_no",
                        "required" => true,
                    ],
                    [
                        "text" => "Do you smoke or use tobacco products?",
                        "type" => "yes_no",
                        "required" => true,
                    ],
                ],
            ],
            [
                "title" => "Risk Assessment and Exclusions - Part 2",
                "questions" => [
                    [
                        "text" => "Do you use recreational drugs?",
                        "type" => "yes_no",
                        "required" => true,
                    ],
                    [
                        "text" =>
                            "Please specify which recreational drugs you use.",
                        "type" => "textarea",
                        "required" => true,
                        "display_conditions" => [
                            "question_number" => 25,
                            "operator" => "equals",
                            "value" => "yes",
                        ],
                    ],
                    [
                        "text" =>
                            "Do you have a personal or family history of medullary thyroid carcinoma (MTC) or multiple endocrine neoplasia syndrome type 2 (MEN 2)?",
                        "type" => "yes_no",
                        "required" => true,
                        "required_answer" => "no",
                    ],
                    [
                        "text" =>
                            "Have you experienced any hypersensitivity to GLP-1 receptor agonists?",
                        "type" => "yes_no",
                        "required" => true,
                        "required_answer" => "no",
                    ],
                ],
            ],
            [
                "title" => "Understanding and Follow-Up",
                "questions" => [
                    [
                        "text" =>
                            "Are you aware of common side effects such as nausea, vomiting, diarrhea, and constipation?",
                        "type" => "yes_no",
                        "required" => true,
                        "required_answer" => "yes",
                    ],
                    [
                        "text" =>
                            "Do you understand that rare but serious side effects include pancreatitis and gallbladder issues?",
                        "type" => "yes_no",
                        "required" => true,
                        "required_answer" => "yes",
                    ],
                    [
                        "text" =>
                            "Do you consent to regular follow-up appointments to monitor progress, including weight, side effects, and blood tests if required?",
                        "type" => "yes_no",
                        "required" => true,
                        "required_answer" => "yes",
                    ],
                ],
            ],
            [
                "title" => "Treatment Selection",
                "questions" => [
                    [
                        "text" => "Please select your preferred medication",
                        "type" => "select",
                        "required" => true,
                        "options" => [
                            "Mounjaro",
                            "Wegovy",
                            "Let my provider choose / no preference",
                        ],
                    ],
                ],
            ],
            [
                "title" => "Consent and Data Handling",
                "questions" => [
                    [
                        "text" =>
                            "Do you consent to the collection, processing, and GDPR-compliant storage of your personal and medical data for weight loss medication prescription, with information shared only with relevant healthcare professionals and in accordance with our terms and conditions?",
                        "type" => "yes_no",
                        "required" => true,
                        "required_answer" => "yes",
                    ],
                ],
            ],
            [
                "title" => "Declaration",
                "questions" => [
                    [
                        "text" =>
                            "Do you confirm that all information provided is accurate and truthful to the best of your knowledge?",
                        "type" => "yes_no",
                        "required" => true,
                        "required_answer" => "yes",
                    ],
                ],
            ],
        ];

        $questionNumber = 1;
        foreach ($sections as $section) {
            foreach ($section["questions"] as $question) {
                // Insert question
                $questionId = DB::table("questions")->insertGetId([
                    "questionnaire_id" => $questionnaireId,
                    "question_number" => $questionNumber,
                    "question_text" => $question["text"],
                    "label" => $section["title"],
                    "question_type" => $question["type"],
                    "is_required" => $question["required"] ?? true,
                    "required_answer" => $question["required_answer"] ?? null,
                    "calculated" => isset($question["calculated"])
                        ? json_encode($question["calculated"])
                        : null,
                    "validation" => isset($question["validation"])
                        ? json_encode($question["validation"])
                        : null,
                    "display_conditions" => isset(
                        $question["display_conditions"]
                    )
                        ? json_encode($question["display_conditions"])
                        : null,
                    "created_at" => now(),
                    "updated_at" => now(),
                ]);

                // Insert options if they exist
                if (isset($question["options"])) {
                    $optionNumber = 1;
                    foreach ($question["options"] as $option) {
                        DB::table("question_options")->insert([
                            "question_id" => $questionId,
                            "option_number" => $optionNumber,
                            "option_text" => $option,
                            "created_at" => now(),
                            "updated_at" => now(),
                        ]);
                        $optionNumber++;
                    }
                }

                $questionNumber++;
            }
        }
    }
}
