<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GLP1Seeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Check if questionnaire already exists
        $existingQuestionnaire = DB::table("questionnaires")
            ->where("title", "GLP-1 Weight Management Treatment Plan")
            ->first();

        if ($existingQuestionnaire) {
            $questionnaireId = $existingQuestionnaire->id;
        } else {
            // Create the questionnaire
            $questionnaireId = DB::table("questionnaires")->insertGetId([
                "title" => "GLP-1 Weight Management Treatment Plan",
                "description" =>
                    "Start your assessment for GLP-1 weight management therapy",
                "created_at" => now(),
                "updated_at" => now(),
            ]);
        }

        // Remove existing questions (options will cascade)
        DB::table("questions")
            ->where("questionnaire_id", $questionnaireId)
            ->delete();

        // Define sections and their questions
        $sections = [
            [
                "title" => "Patient Identification and Demographics",
                "questions" => [
                    [
                        "text" => "Full Name",
                        "type" => "text",
                        "required" => true,
                    ],
                    [
                        "text" => "Date of Birth",
                        "type" => "date",
                        "required" => true,
                    ],
                    [
                        "text" => "Gender",
                        "type" => "select",
                        "required" => true,
                        "options" => ["Male", "Female"],
                    ],
                    [
                        "text" => "Ethnicity (for clinical risk assessment)",
                        "type" => "select",
                        "required" => true,
                        "options" => [
                            "White/Caucasian",
                            "Black",
                            "Asian",
                            "Hispanic, Latino, or Spanish Origin",
                            "Native American/American Indian/Alaska Native",
                            "Pacific Islander/Native Hawaiian",
                            "Mixed/Multiple ethnic groups",
                        ],
                    ],
                ],
            ],
            [
                "title" => "Consent and Data Handling",
                "questions" => [
                    [
                        "text" =>
                            "Do you consent to the collection and processing of your personal and medical data for the purpose of prescribing weight loss medication?",
                        "type" => "yes_no",
                        "required" => true,
                    ],
                    [
                        "text" =>
                            "Do you acknowledge that your information will be stored in compliance with GDPR and shared only with healthcare professionals involved in your care?",
                        "type" => "yes_no",
                        "required" => true,
                    ],
                    [
                        "text" =>
                            "Have you read and understood the medication information leaflet for Wegovy/Mounjaro?",
                        "type" => "yes_no",
                        "required" => true,
                    ],
                    [
                        "text" =>
                            "Are you aware that this medication is not a substitute for lifestyle changes?",
                        "type" => "yes_no",
                        "required" => true,
                    ],
                ],
            ],
            [
                "title" => "Physical Measurements and Verification",
                "questions" => [
                    [
                        "text" => "Height (feet)",
                        "type" => "number",
                        "required" => true,
                        "validation" => [
                            "min" => 3,
                            "max" => 8,
                            "message" => "Height must be between 3 and 8 feet",
                        ],
                    ],
                    [
                        "text" => "Height (inches)",
                        "type" => "number",
                        "required" => true,
                        "validation" => [
                            "min" => 0,
                            "max" => 11,
                            "message" => "Inches must be between 0 and 11",
                        ],
                    ],
                    [
                        "text" => "Weight (lbs)",
                        "type" => "number",
                        "required" => true,
                        "validation" => [
                            "min" => 50,
                            "max" => 800,
                            "message" =>
                                "Weight must be between 50 and 800 lbs",
                        ],
                    ],
                    [
                        "text" => "Calculated BMI",
                        "type" => "number",
                        "required" => true,
                        "calculated" => [
                            "formula" =>
                                "weight / ((feet*12 + inches)^2) * 703",
                            "inputs" => [
                                "feet" => 9,
                                "inches" => 10,
                                "weight" => 11,
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
                            "Do you have any of the following conditions?",
                        "type" => "multi-select",
                        "required" => true,
                        "options" => [
                            "Type 2 Diabetes",
                            "High blood pressure",
                            "Dyslipidemia",
                            "Sleep apnea",
                            "None of the above",
                        ],
                    ],
                ],
            ],
            [
                "title" => "Medical History",
                "questions" => [
                    [
                        "text" =>
                            "Do you have or have you ever had any of the following conditions?",
                        "type" => "multi-select",
                        "required" => true,
                        "options" => [
                            "Type 1 Diabetes",
                            "Type 2 Diabetes",
                            "Cardiovascular disease (e.g., heart attack, angina, stroke)",
                            "Pancreatitis (current or history of)",
                            "Gallbladder disease or gallstones",
                            "Thyroid conditions (e.g., hypothyroidism, hyperthyroidism)",
                            'Gastrointestinal conditions (e.g., Crohn\'s disease, IBS)',
                            "Kidney or liver disease",
                            "Eating disorders (e.g., anorexia nervosa, bulimia)",
                            "Depression or mental health conditions",
                            "None of the above",
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
                        "required" => false,
                    ],
                    [
                        "text" =>
                            "Have you undergone any other surgeries in the past 12 months?",
                        "type" => "yes_no",
                        "required" => false,
                    ],
                ],
            ],
            [
                "title" => "Medication History",
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
                        "required" => false,
                    ],
                    [
                        "text" => "Please specify any allergies",
                        "type" => "textarea",
                        "required" => false,
                    ],
                    [
                        "text" =>
                            "Have you previously used weight loss medications (e.g., Saxenda, Orlistat)?",
                        "type" => "yes_no",
                        "required" => false,
                    ],
                    [
                        "text" =>
                            "Please specify the medication, dosage, and outcomes",
                        "type" => "textarea",
                        "required" => false,
                    ],
                ],
            ],
            [
                "title" => "Lifestyle and Weight Management",
                "questions" => [
                    [
                        "text" =>
                            "Describe your current diet (e.g., calorie intake, type of meals)",
                        "type" => "textarea",
                        "required" => true,
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
                            "None of the above",
                        ],
                    ],
                    [
                        "text" =>
                            "Are you willing to make long-term lifestyle changes, including diet and exercise, alongside medication?",
                        "type" => "yes_no",
                        "required" => true,
                    ],
                ],
            ],
            [
                "title" => "Risk Assessment and Exclusions",
                "questions" => [
                    [
                        "text" =>
                            "Are you pregnant, planning to become pregnant, or currently breastfeeding?",
                        "type" => "yes_no",
                        "required" => false,
                        "required_answer" => "no",
                    ],
                    [
                        "text" =>
                            "Do you consume alcohol? If yes, specify weekly units",
                        "type" => "text",
                        "required" => true,
                        "required_answer" => "no",
                    ],
                    [
                        "text" => "Do you smoke or use tobacco products?",
                        "type" => "yes_no",
                        "required" => false,
                    ],
                    [
                        "text" => "Do you use recreational drugs?",
                        "type" => "yes_no",
                        "required" => false,
                    ],
                    [
                        "text" =>
                            "Do you have a family history of medullary thyroid carcinoma (MTC) or multiple endocrine neoplasia syndrome type 2 (MEN 2)?",
                        "type" => "yes_no",
                        "required" => false,
                        "required_answer" => "no",
                    ],
                    [
                        "text" =>
                            "Have you experienced any hypersensitivity to GLP-1 receptor agonists?",
                        "type" => "yes_no",
                        "required" => false,
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
                    ],
                    [
                        "text" =>
                            "Do you understand that rare but serious side effects include pancreatitis and gallbladder issues?",
                        "type" => "yes_no",
                        "required" => true,
                    ],
                    [
                        "text" =>
                            "Do you consent to regular follow-up appointments to monitor progress, including weight, side effects, and blood tests if required?",
                        "type" => "yes_no",
                        "required" => true,
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
                            "Mounjaro (£199.00)",
                            "Ozempic (£189.00)",
                            "Wegovy (£209.00)",
                        ],
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

        // Create a draft questionnaire submission for user 1
        // $user = User::find(1);

        // if ($user) {
        //     // Delete any existing submissions for theis user and questionnaire
        //     DB::table("questionnaire_submissions")
        //         ->where("user_id", $user->id)
        //         ->where("questionnaire_id", $questionnaireId)
        //         ->delete();

        //     // Create new draft submission
        //     DB::table("questionnaire_submissions")->insertGetId([
        //         "questionnaire_id" => $questionnaireId,
        //         "user_id" => $user->id,
        //         "status" => "draft",
        //         "submitted_at" => now(),
        //         "created_at" => now(),
        //         "updated_at" => now(),
        //     ]);
        // } else {
        //     echo "User 1 not found - couldn't create the draft submission\n";
        // }
    }
}
