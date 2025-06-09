<?php

namespace App\Services;

use App\Models\QuestionnaireSubmission;
use Illuminate\Support\Collection;

class QuestionnaireValidationService
{
    /**
     * Validate questionnaire submission based on answer combinations
     *
     * @param QuestionnaireSubmission $submission
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validateSubmission(
        QuestionnaireSubmission $submission
    ): array {
        // Load answers with question text for easier validation
        $answers = $submission
            ->answers()
            ->with("question")
            ->get()
            ->keyBy("question.question_text");

        $errors = [];

        // Get validation rules for this questionnaire
        $rules = $this->getValidationRules($submission->questionnaire->title);

        foreach ($rules as $rule) {
            $result = $this->evaluateRule($rule, $answers);
            if (!$result["valid"]) {
                $errors[] = $result["error"];
            }
        }

        return [
            "valid" => empty($errors),
            "errors" => $errors,
        ];
    }

    /**
     * Get validation rules for a specific questionnaire
     *
     * @param string $questionnaireTitle
     * @return array
     */
    private function getValidationRules(string $questionnaireTitle): array
    {
        switch ($questionnaireTitle) {
            case "GLP-1 Weight Management Treatment Plan":
                return $this->getGLP1ValidationRules();
            default:
                return [];
        }
    }

    /**
     * Get validation rules for GLP-1 questionnaire
     *
     * @return array
     */
    private function getGLP1ValidationRules(): array
    {
        return [
            [
                "name" => "gallbladder_rule",
                "description" =>
                    "Patients with gallbladder disease must have had gallbladder removed",
                "conditions" => [
                    [
                        "question" =>
                            "Do you have or have you ever had any of the following conditions?",
                        "operator" => "contains",
                        "value" => "Gallbladder disease or gallstones",
                    ],
                    [
                        "question" => "Have you had your gallbladder removed?",
                        "operator" => "equals",
                        "value" => "No",
                    ],
                ],
                "logic" => "and", // All conditions must be true for the rule to trigger
                "error_message" =>
                    "Based on your answers regarding gallbladder issues, we cannot proceed. Please contact support for more information.",
            ],
            // Add more validation rules here as needed
        ];
    }

    /**
     * Evaluate a validation rule against the answers
     *
     * @param array $rule
     * @param Collection $answers
     * @return array ['valid' => bool, 'error' => string|null]
     */
    private function evaluateRule(array $rule, Collection $answers): array
    {
        $conditionResults = [];

        foreach ($rule["conditions"] as $condition) {
            $answer = $answers->get($condition["question"]);

            if (!$answer) {
                // If the question wasn't answered, consider condition as false
                $conditionResults[] = false;
                continue;
            }

            $conditionResults[] = $this->evaluateCondition(
                $condition,
                $answer->answer_text
            );
        }

        // Apply logic (AND/OR)
        $ruleTriggered =
            $rule["logic"] === "and"
                ? !in_array(false, $conditionResults, true)
                : in_array(true, $conditionResults, true);

        return [
            "valid" => !$ruleTriggered, // Rule is valid if it's NOT triggered
            "error" => $ruleTriggered ? $rule["error_message"] : null,
        ];
    }

    /**
     * Evaluate a single condition
     *
     * @param array $condition
     * @param mixed $answerValue
     * @return bool
     */
    private function evaluateCondition(array $condition, $answerValue): bool
    {
        switch ($condition["operator"]) {
            case "equals":
                return strtolower(trim($answerValue)) ===
                    strtolower(trim($condition["value"]));

            case "contains":
                // Handle JSON arrays (for multi-select questions)
                if (is_string($answerValue) && $this->isJson($answerValue)) {
                    $answerArray = json_decode($answerValue, true);
                    return is_array($answerArray) &&
                        in_array($condition["value"], $answerArray, true);
                }
                // Handle regular string contains
                return str_contains(
                    strtolower(trim($answerValue)),
                    strtolower(trim($condition["value"]))
                );

            case "not_equals":
                return strtolower(trim($answerValue)) !==
                    strtolower(trim($condition["value"]));

            case "greater_than":
                return is_numeric($answerValue) &&
                    $answerValue > $condition["value"];

            case "less_than":
                return is_numeric($answerValue) &&
                    $answerValue < $condition["value"];

            default:
                return false;
        }
    }

    /**
     * Check if a string is valid JSON
     *
     * @param string $string
     * @return bool
     */
    private function isJson(string $string): bool
    {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }
}
