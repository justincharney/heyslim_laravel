<?php namespace App\Http\Controllers;

use App\Config\ShopifyProductMapping;
use App\Models\QuestionAnswer;
use App\Models\Questionnaire;
use App\Models\QuestionnaireSubmission;
use App\Models\User;
use App\Notifications\QuestionnaireRejectedNotification;
use App\Services\RechargeService;
use App\Services\ShopifyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Models\ClinicalPlan;
use App\Models\Prescription;

class QuestionnaireController extends Controller
{
    public function index(Request $request)
    {
        // Get all questionnaires
        $questionnaires = Questionnaire::select(
            "id",
            "title",
            "description",
            "created_at"
        )
            ->orderBy("created_at", "desc")
            ->get();

        return response()->json([
            "questionnaires" => $questionnaires->map(function ($questionnaire) {
                return [
                    "id" => $questionnaire->id,
                    "title" => $questionnaire->title,
                    "description" => $questionnaire->description,
                    "created_at" => $questionnaire->created_at->format(
                        "Y-m-d H:i:s"
                    ),
                ];
            }),
        ]);
    }

    public function cancel(Request $request, $submission_id)
    {
        $submission = QuestionnaireSubmission::where("user_id", auth()->id())
            ->where("id", $submission_id)
            ->first();

        if (!$submission) {
            return response()->json(
                [
                    "message" =>
                        "Submission not found or you do not have permission to cancel it.",
                ],
                404
            );
        }

        // Only allow cancellation of draft or pending_payment submissions
        if (!in_array($submission->status, ["draft", "pending_payment"])) {
            return response()->json(
                [
                    "message" =>
                        "Only submissions in draft or pending payment status can be cancelled.",
                ],
                403
            );
        }

        DB::beginTransaction();
        try {
            // Delete all answers for this submission
            QuestionAnswer::where("submission_id", $submission_id)->delete();

            // Delete the submission itself
            $submission->delete();

            DB::commit();

            return response()->json([
                "message" => "Questionnaire submission successfully cancelled.",
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error(
                "Failed to cancel questionnaire submission: " . $e->getMessage()
            );

            return response()->json(
                [
                    "message" => "Failed to cancel questionnaire submission.",
                ],
                500
            );
        }
    }

    public function getPatientQuestionnaires(Request $request)
    {
        $submissions = QuestionnaireSubmission::with(["questionnaire"])
            ->where("user_id", auth()->id())
            ->orderBy("created_at", "desc")
            ->get();

        return response()->json([
            "questionnaires" => $submissions->map(function ($submission) {
                return [
                    "submission_id" => $submission->id,
                    "questionnaire" => [
                        "id" => $submission->questionnaire->id,
                        "title" => $submission->questionnaire->title,
                        "description" =>
                            $submission->questionnaire->description,
                    ],
                    "status" => $submission->status,
                    "submitted_at" => $submission->submitted_at?->format(
                        "Y-m-d H:i:s"
                    ),
                ];
            }),
        ]);
    }

    public function getQuestionnaireDetails($submission_id)
    {
        $submission = QuestionnaireSubmission::with([
            "questionnaire",
            "questionnaire.questions",
            "questionnaire.questions.options",
            "answers",
        ])
            ->where("user_id", auth()->id())
            ->findOrFail($submission_id);

        return response()->json([
            "submission" => [
                "id" => $submission->id,
                "status" => $submission->status,
                "submitted_at" => $submission->submitted_at?->format(
                    "Y-m-d H:i:s"
                ),
                "questionnaire" => [
                    "id" => $submission->questionnaire->id,
                    "title" => $submission->questionnaire->title,
                    "description" => $submission->questionnaire->description,
                    "questions" => $submission->questionnaire->questions->map(
                        function ($question) use ($submission) {
                            $answer = $submission->answers->first(
                                fn($answer) => $answer->question_id ===
                                    $question->id
                            );

                            return [
                                "id" => $question->id,
                                "number" => $question->question_number,
                                "text" => $question->question_text,
                                "type" => $question->question_type,
                                "label" => $question->label,
                                "description" => $question->description,
                                "is_required" => $question->is_required,
                                "required_answer" => $question->required_answer,
                                "calculated" => $question->calculated,
                                "validation" => $question->validation,
                                "answer" => $answer
                                    ? $answer->answer_text
                                    : null,
                                "options" => $question->options->map(function (
                                    $option
                                ) {
                                    return [
                                        "number" => $option->option_number,
                                        "text" => $option->option_text,
                                    ];
                                }),
                            ];
                        }
                    ),
                ],
            ],
        ]);
    }

    public function initializeDraft(Request $request)
    {
        $validated = $request->validate([
            "questionnaire_id" => "required|exists:questionnaires,id",
        ]);

        // Check if the user already has any submission for this questionnaire
        $existingSubmission = QuestionnaireSubmission::where([
            "questionnaire_id" => $validated["questionnaire_id"],
            "user_id" => auth()->id(),
        ])
            ->orderBy("created_at", "desc")
            ->first();

        // If there's an existing submission, handle based on its status
        if ($existingSubmission) {
            // If it's a draft, return it
            if ($existingSubmission->status === "draft") {
                return response()->json([
                    "message" => "Existing draft found",
                    "submission_id" => $existingSubmission->id,
                    "questionnaire_id" => $existingSubmission->questionnaire_id,
                    "status" => "draft",
                    "is_new" => false,
                ]);
            }

            // If it's submitted or approved, check if there's an active prescription
            if (
                in_array($existingSubmission->status, ["submitted", "approved"])
            ) {
                // First check if there's a clinical plan
                $clinicalPlan = ClinicalPlan::where(
                    "questionnaire_submission_id",
                    $existingSubmission->id
                )->first();

                // If no clinical plan exists yet, they should wait for review
                if (!$clinicalPlan) {
                    return response()->json(
                        [
                            "message" =>
                                "Your previous submission is still being reviewed. Please wait for the review to complete.",
                            "submission_id" => $existingSubmission->id,
                            "questionnaire_id" =>
                                $existingSubmission->questionnaire_id,
                            "status" => $existingSubmission->status,
                        ],
                        403
                    );
                }

                // Check if there are any active prescriptions
                $hasActivePrescription = Prescription::where(
                    "clinical_plan_id",
                    $clinicalPlan->id
                )
                    ->where("status", "active")
                    ->where("end_date", ">=", now())
                    ->exists();

                if ($hasActivePrescription) {
                    return response()->json(
                        [
                            "message" =>
                                "You already have an active treatment plan. A new submission is not allowed at this time.",
                            "submission_id" => $existingSubmission->id,
                            "questionnaire_id" =>
                                $existingSubmission->questionnaire_id,
                            "status" => $existingSubmission->status,
                        ],
                        403
                    );
                }
                // If they had prescriptions but all are completed/cancelled, we fall through to allow creation
            }
            // If it's rejected, we'll allow a new submission (fall through to creation code)
        }

        // Create new draft if none exists
        $submission = QuestionnaireSubmission::create([
            "questionnaire_id" => $validated["questionnaire_id"],
            "user_id" => auth()->id(),
            "status" => "draft",
        ]);

        return response()->json(
            [
                "message" => "Draft questionnaire created",
                "submission_id" => $submission->id,
            ],
            201
        );
    }
    public function savePartial(Request $request)
    {
        $validated = $request->validate([
            "submission_id" => "required|exists:questionnaire_submissions,id",
            "answers" => "required|array",
            "answers.*.question_id" => "required|integer",
            "answers.*.answer_text" => "nullable",
        ]);

        DB::beginTransaction();

        $submission = QuestionnaireSubmission::findOrFail(
            $validated["submission_id"]
        );

        if ($submission->user_id !== auth()->id()) {
            return response()->json(
                [
                    "message" => "Unauthorized to access submission",
                ],
                403
            );
        }

        // Keep status as draft for partial submissions
        $submission->touch(); // Update timestamp

        // Delete existing answers for the given questions.
        QuestionAnswer::where("submission_id", $submission->id)
            ->whereIn(
                "question_id",
                collect($validated["answers"])->pluck("question_id")
            )
            ->delete();

        // Save new answers
        foreach ($validated["answers"] as $answer) {
            $answerText = $answer["answer_text"];

            // Handle empty arrays and null values
            if (is_array($answerText)) {
                // Convert empty arrays to null
                $answerText = !empty($answerText)
                    ? json_encode($answerText)
                    : null;
            }
            QuestionAnswer::create([
                "submission_id" => $submission->id,
                "question_id" => $answer["question_id"],
                "answer_text" => $answerText,
            ]);
        }

        DB::commit();

        return response()->json(
            [
                "message" => "Answers saved successfully",
                "submission_id" => $submission->id,
            ],
            200
        );
    }
    public function store(Request $request)
    {
        $validated = $request->validate([
            "submission_id" => "required|exists:questionnaire_submissions,id",
            "answers" => "required|array",
            "answers.*.question_id" => "required|integer",
            "answers.*.answer_text" => "nullable",
        ]);

        DB::beginTransaction();

        $submission = QuestionnaireSubmission::with(
            "questionnaire.questions"
        )->findOrFail($validated["submission_id"]);

        if ($submission->user_id !== auth()->id()) {
            return response()->json(
                [
                    "message" => "Unauthorized to access submission",
                ],
                403
            );
        }

        // Save final answers
        if (!empty($validated["answers"])) {
            // Delete any existing answers for these questions.
            QuestionAnswer::where("submission_id", $submission->id)
                ->whereIn(
                    "question_id",
                    collect($validated["answers"])->pluck("question_id")
                )
                ->delete();

            foreach ($validated["answers"] as $answer) {
                $answerText = $answer["answer_text"];

                // Handle arrays, objects, and null values
                if (is_array($answerText) || is_object($answerText)) {
                    $answerText = !empty($answerText)
                        ? json_encode($answerText)
                        : null;
                }

                QuestionAnswer::create([
                    "submission_id" => $submission->id,
                    "question_id" => $answer["question_id"],
                    "answer_text" => $answerText,
                ]);
            }
        }

        // Check for missing required questions.
        $answeredQuestions = QuestionAnswer::where(
            "submission_id",
            $submission->id
        )
            ->pluck("question_id")
            ->toArray();

        $requiredQuestions = $submission->questionnaire->questions
            ->where("is_required", true)
            ->pluck("id")
            ->toArray();

        $missingRequired = array_diff($requiredQuestions, $answeredQuestions);

        if (!empty($missingRequired)) {
            return response()->json(
                [
                    "message" => "Required questions are not answered",
                    "missing_questions" => $missingRequired,
                ],
                422
            );
        }

        // Check for treatment (product) selection question (there's only one)
        $treatmentSelectionQuestion = $submission->questionnaire
            ->questions()
            ->where("label", "Treatment Selection")
            ->first();

        if ($treatmentSelectionQuestion) {
            // Find the answer for this question
            $treatmentAnswer = null;
            foreach ($validated["answers"] as $answer) {
                if ($answer["question_id"] == $treatmentSelectionQuestion->id) {
                    $treatmentAnswer = $answer["answer_text"];
                    break;
                }
            }

            if ($treatmentAnswer) {
                // Get the product ID from the mapping
                $productId = ShopifyProductMapping::getProductId(
                    $treatmentAnswer
                );

                if ($productId) {
                    // Create a Shopify checkout
                    $shopifyService = app(ShopifyService::class);
                    $cart = $shopifyService->createCheckout(
                        $productId,
                        $submission->id
                    );

                    if ($cart) {
                        // Set status to pending_payment
                        $submission->update([
                            "status" => "pending_payment",
                            "submitted_at" => now(),
                        ]);

                        DB::commit();

                        return response()->json([
                            "message" =>
                                "Questionnaire requires payment to complete.",
                            "checkout_url" => $cart["checkoutUrl"],
                        ]);
                    }
                }
            }
        }

        // If no product selection or checkout creation fails, throw an exception
        throw new \Exception("Failed to create checkout");
    }

    public function reject(Request $request, $id)
    {
        $submission = QuestionnaireSubmission::findOrFail($id);

        $validated = $request->validate([
            "review_notes" => "required|string",
        ]);

        DB::beginTransaction();

        try {
            $submission->update([
                "status" => "rejected",
                "review_notes" => $validated["review_notes"],
                "reviewed_by" => auth()->id(),
                "reviewed_at" => now(),
            ]);

            // Get the provider who reviewed the submission
            $provider = auth()->user();

            // Cancel related subscription
            $rechargeService = app(RechargeService::class);
            $rechargeService->cancelSubscriptionForRejectedQuestionnaire(
                $submission,
                "Questionnaire rejected by healthcare provider"
            );

            // Send email notification to user
            $patient = $submission->user;
            $patient->notify(
                new QuestionnaireRejectedNotification(
                    $submission,
                    $provider,
                    $validated["review_notes"]
                )
            );

            DB::commit();

            return response()->json([
                "message" =>
                    "Questionnaire submission rejected and related subscription cancelled",
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to reject questionnaire submission", [
                "error" => $e->getMessage(),
                "submission_id" => $id,
            ]);

            return response()->json(
                [
                    "message" => "Failed to reject questionnaire submission",
                ],
                500
            );
        }
    }
}
