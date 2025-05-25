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
use App\Jobs\ProcessRejectedQuestionnaireJob;

class QuestionnaireController extends Controller
{
    public function index(Request $request)
    {
        // Get all questionnaires
        $questionnaires = Questionnaire::where("is_current", true)
            ->select("id", "title", "description", "created_at")
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

        // Only allow cancellation of draft, pending_payment, or rejected submissions
        if (
            !in_array($submission->status, [
                "draft",
                "pending_payment",
                "rejected",
            ])
        ) {
            return response()->json(
                [
                    "message" =>
                        "Only submissions in draft, pending payment, or rejected status can be cancelled.",
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
        try {
            $submission = QuestionnaireSubmission::with([
                "questionnaire",
                "questionnaire.questions",
                "questionnaire.questions.options",
                "answers",
            ])
                ->where("user_id", auth()->id())
                ->findOrFail($submission_id);
        } catch (\Exception $e) {
            return response()->json(
                [
                    "error" => "Submission not found",
                ],
                404
            );
        }

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

        $currentQuestionnaire = Questionnaire::where("is_current", true)
            ->where("id", $validated["questionnaire_id"])
            ->firstOrFail(); // Ensure it's the current version

        // Check if the user already has any submission for this questionnaire
        $existingSubmission = QuestionnaireSubmission::where([
            "questionnaire_id" => $currentQuestionnaire->id,
            "user_id" => auth()->id(),
        ])
            ->orderBy("created_at", "desc")
            ->first();

        // If there's an existing submission, handle based on its status
        if ($existingSubmission) {
            // If it's a draft, return it
            if (
                $existingSubmission->status === "draft" ||
                $existingSubmission->status === "pending_payment"
            ) {
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
            "questionnaire_id" => $currentQuestionnaire->id,
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
    /**
     * Process and save questionnaire answers
     *
     * @param int $submissionId Questionnaire submission ID
     * @param array $answers Array of answer data
     * @param bool $isFinal Whether this is a final submission
     * @return QuestionnaireSubmission
     */
    private function processAnswers($submissionId, $answers, $isFinal = false)
    {
        $submission = QuestionnaireSubmission::findOrFail($submissionId);

        if ($submission->user_id !== auth()->id()) {
            abort(403, "Unauthorized to access submission");
        }

        // Prepare data for batch insertion
        $questionsToUpdate = collect($answers)->pluck("question_id")->toArray();
        $insertData = [];
        $timestamp = now();

        // Process each answer
        foreach ($answers as $answer) {
            $answerText = $answer["answer_text"];

            // Handle array/object values
            if (is_array($answerText) || is_object($answerText)) {
                $answerText = !empty($answerText)
                    ? json_encode($answerText)
                    : null;
            }

            $insertData[] = [
                "submission_id" => $submission->id,
                "question_id" => $answer["question_id"],
                "answer_text" => $answerText,
                "created_at" => $timestamp,
                "updated_at" => $timestamp,
            ];
        }

        // Use a single transaction for efficiency
        DB::transaction(function () use (
            $submission,
            $questionsToUpdate,
            $insertData,
            $isFinal
        ) {
            // Update timestamp without triggering events
            $submission->timestamps = false;
            $submission->touch();
            $submission->timestamps = true;

            // Efficiently delete existing answers for these questions
            QuestionAnswer::where("submission_id", $submission->id)
                ->whereIn("question_id", $questionsToUpdate)
                ->delete();

            // Batch insert all answers at once
            if (!empty($insertData)) {
                QuestionAnswer::insert($insertData);
            }

            // If this is a final submission, update the status
            if ($isFinal) {
                $submission->update([
                    "submitted_at" => now(),
                ]);
            }
        });

        return $submission;
    }

    public function savePartial(Request $request)
    {
        $validated = $request->validate([
            "submission_id" => "required|exists:questionnaire_submissions,id",
            "answers" => "required|array",
            "answers.*.question_id" => "required|integer",
            "answers.*.answer_text" => "nullable",
        ]);

        $submission = $this->processAnswers(
            $validated["submission_id"],
            $validated["answers"]
        );

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

        // Load with questionnaire for required question check
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

        // Process and save the answers
        $this->processAnswers(
            $validated["submission_id"],
            $validated["answers"],
            true // This is a final submission
        );

        // Efficiently check for missing required questions using a single query
        $answeredQuestionIds = QuestionAnswer::where(
            "submission_id",
            $submission->id
        )
            ->pluck("question_id")
            ->toArray();

        $requiredQuestionIds = $submission->questionnaire->questions
            ->where("is_required", true)
            ->pluck("id")
            ->toArray();

        $missingRequired = array_diff(
            $requiredQuestionIds,
            $answeredQuestionIds
        );

        if (!empty($missingRequired)) {
            return response()->json(
                [
                    "message" => "Required questions are not answered",
                    "missing_questions" => $missingRequired,
                ],
                422
            );
        }

        // Now, create a checkout for the consultation product.
        $consultationProductId = ShopifyProductMapping::getConsultationProductId();

        if ($consultationProductId) {
            // Create checkout for the consultation product
            $shopifyService = app(ShopifyService::class);
            $cart = $shopifyService->createCheckout(
                $consultationProductId,
                $submission->id
            );

            if ($cart) {
                $submission->update([
                    "status" => "pending_payment",
                    "submitted_at" => now(),
                ]);

                return response()->json([
                    "message" => "Questionnaire requires payment to complete.",
                    "checkout_url" => $cart["checkoutUrl"],
                ]);
            }
        }
        // If we reach here, either consultationProductId was not found or checkout creation failed.
        throw new \Exception("Failed to create checkout for consultation.");
    }

    public function reject(Request $request, $id)
    {
        $submission = QuestionnaireSubmission::with("user")->find($id);

        if (!$submission) {
            return response()->json(["message" => "Submission not found"], 404);
        }

        // Get the provider who reviewed the submission
        $provider = auth()->user();
        $patient = $submission->user;
        // Check that the provider is in the same team as the patient
        if (
            !$patient ||
            $patient->current_team_id !== $provider->current_team_id
        ) {
            return response()->json(
                ["message" => "You can only reject submissions from your team"],
                403
            );
        }

        $validated = $request->validate([
            "review_notes" => "required|string",
        ]);

        // Store provider ID before potential commit/dispatch
        $providerId = $provider->id;
        $reviewNotes = $validated["review_notes"];

        DB::beginTransaction();
        try {
            $submission->update([
                "status" => "rejected",
                "review_notes" => $validated["review_notes"],
                "reviewed_by" => auth()->id(),
                "reviewed_at" => now(),
            ]);
            // Commit change
            DB::commit();
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

        // --- Dispatch Job AFTER successful local commit ---
        ProcessRejectedQuestionnaireJob::dispatch(
            $submission->id,
            $providerId,
            $reviewNotes
        );
        Log::info(
            "Dispatched ProcessRejectedQuestionnaireJob for submission #{$submission->id}"
        );

        // --- Return Success Response to User ---
        return response()->json([
            "message" =>
                "Questionnaire submission rejected. Background processing initiated.",
        ]);
    }

    public function getTemplate(Request $request, $template_id)
    {
        try {
            // Fetch the questionnaire by its ID, along with its questions and their options
            $questionnaire = Questionnaire::with([
                "questions",
                "questions.options",
            ])
                ->where("is_current", true)
                ->findOrFail($template_id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            // If the questionnaire template is not found, return a 404 error
            return response()->json(
                [
                    "error" => "Questionnaire template not found",
                ],
                404
            );
        }

        // Return the questionnaire structure in a JSON response
        return response()->json([
            "questionnaire" => [
                "id" => $questionnaire->id,
                "title" => $questionnaire->title,
                "description" => $questionnaire->description,
                "questions" => $questionnaire->questions->map(function (
                    $question
                ) {
                    // Note: No answers are included here as this is just the template structure
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
                        "options" => $question->options->map(function (
                            $option
                        ) {
                            return [
                                "number" => $option->option_number,
                                "text" => $option->option_text,
                            ];
                        }),
                    ];
                }),
            ],
        ]);
    }
}
