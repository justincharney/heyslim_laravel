<?php

use App\Models\Questionnaire;
use App\Models\QuestionAnswer;
use App\Models\Question;
use App\Models\QuestionnaireSubmission;
use App\Models\User;
use App\Services\QuestionnaireValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->questionnaire = Questionnaire::create([
        "title" => "GLP-1 Weight Management Treatment Plan",
        "description" => "Test questionnaire",
        "version" => 1,
        "is_current" => true,
    ]);

    $this->submission = QuestionnaireSubmission::create([
        "questionnaire_id" => $this->questionnaire->id,
        "user_id" => $this->user->id,
        "status" => "draft",
    ]);

    $this->conditionsQuestion = Question::create([
        "questionnaire_id" => $this->questionnaire->id,
        "question_number" => 1,
        "question_text" =>
            "Do you have or have you ever had any of the following conditions?",
        "question_type" => "checkbox",
        "is_required" => true,
    ]);

    $this->gallbladderRemovedQuestion = Question::create([
        "questionnaire_id" => $this->questionnaire->id,
        "question_number" => 2,
        "question_text" => "Have you had your gallbladder removed?",
        "question_type" => "yes_no",
        "is_required" => false,
    ]);

    $this->validationService = new QuestionnaireValidationService();
});

test(
    "validates successfully when patient has gallbladder disease but gallbladder was removed",
    function () {
        // Patient has gallbladder disease
        QuestionAnswer::create([
            "submission_id" => $this->submission->id,
            "question_id" => $this->conditionsQuestion->id,
            "answer_text" => json_encode([
                "Gallbladder disease or gallstones",
                "Other condition",
            ]),
        ]);

        // But gallbladder was removed
        QuestionAnswer::create([
            "submission_id" => $this->submission->id,
            "question_id" => $this->gallbladderRemovedQuestion->id,
            "answer_text" => "Yes",
        ]);

        $result = $this->validationService->validateSubmission(
            $this->submission
        );

        expect($result["valid"])->toBeTrue();
        expect($result["errors"])->toBeEmpty();
    }
);

test(
    "validates successfully when patient does not have gallbladder disease",
    function () {
        // Patient doesn't have gallbladder disease
        QuestionAnswer::create([
            "submission_id" => $this->submission->id,
            "question_id" => $this->conditionsQuestion->id,
            "answer_text" => json_encode(["Some other condition"]),
        ]);

        // Gallbladder removal status doesn't matter
        QuestionAnswer::create([
            "submission_id" => $this->submission->id,
            "question_id" => $this->gallbladderRemovedQuestion->id,
            "answer_text" => "No",
        ]);

        $result = $this->validationService->validateSubmission(
            $this->submission
        );

        expect($result["valid"])->toBeTrue();
        expect($result["errors"])->toBeEmpty();
    }
);

test(
    "fails validation when patient has gallbladder disease but gallbladder was not removed",
    function () {
        // Patient has gallbladder disease
        QuestionAnswer::create([
            "submission_id" => $this->submission->id,
            "question_id" => $this->conditionsQuestion->id,
            "answer_text" => json_encode([
                "Gallbladder disease or gallstones",
                "Other condition",
            ]),
        ]);

        // And gallbladder was NOT removed
        QuestionAnswer::create([
            "submission_id" => $this->submission->id,
            "question_id" => $this->gallbladderRemovedQuestion->id,
            "answer_text" => "No",
        ]);

        $result = $this->validationService->validateSubmission(
            $this->submission
        );

        expect($result["valid"])->toBeFalse();
        expect($result["errors"])->toHaveCount(1);
        expect($result["errors"][0])->toContain(
            "Based on your answers regarding gallbladder issues"
        );
    }
);
