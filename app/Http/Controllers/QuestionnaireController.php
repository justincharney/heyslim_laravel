<?php namespace App\Http\Controllers;

use App\Models\QuestionAnswer;
use App\Models\QuestionnaireSubmission;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class QuestionnaireController extends Controller
{
    public function getPatientQuestionnaires(Request $request)
    {
        try {
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
        } catch (\Exception $e) {
            return response()->json(
                [
                    "message" => "Failed to fetch questionnaires",
                    "error" => $e->getMessage(),
                ],
                500
            );
        }
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

            Log::info("Question options:", [
                "questions" => $submission->questionnaire->questions->map(
                    function ($q) {
                        return [
                            "question_number" => $q->question_number,
                            "options_count" => $q->options->count(),
                            "raw_options" => $q->options->toArray(),
                        ];
                    }
                ),
            ]);

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
                        "description" =>
                            $submission->questionnaire->description,
                        "questions" => $submission->questionnaire->questions->map(
                            function ($question) use ($submission) {
                                $answer = $submission->answers->first(
                                    fn($answer) => $answer->question_id ===
                                        $question->id
                                );

                                return [
                                    "number" => $question->question_number,
                                    "text" => $question->question_text,
                                    "type" => $question->question_type,
                                    "label" => $question->label,
                                    "description" => $question->description,
                                    "is_required" => $question->is_required,
                                    "calculated" => $question->calculated,
                                    "validation" => $question->validation,
                                    "answer" => $answer
                                        ? $answer->answer_text
                                        : null,
                                    "options" => $question->options->map(
                                        function ($option) {
                                            return [
                                                "number" =>
                                                    $option->option_number,
                                                "text" => $option->option_text,
                                            ];
                                        }
                                    ),
                                ];
                            }
                        ),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(
                [
                    "message" => "Failed to fetch questionnaire details",
                    "error" => $e->getMessage(),
                ],
                500
            );
        }
    }

    public function initializeDraft(Request $request)
    {
        try {
            $validated = $request->validate([
                "questionnaire_id" => "required|exists:questionnaires,id",
            ]);

            $submission = QuestionnaireSubmission::create([
                "questionnaire_id" => $validated["questionnaire_id"],
                "user_id" => auth()->id(),
                "status" => "draft",
            ]);

            return response()->json(
                [
                    "message" => "Draft questionnaire created",
                ],
                201
            );
        } catch (\Exception $e) {
            return response()->json(
                [
                    "message" => "Failed to create draft questionnaire",
                    "error" => $e->getMessage(),
                ],
                500
            );
        }
    }
    public function savePartial(Request $request)
    {
        try {
            $validated = $request->validate([
                "submission_id" =>
                    "required|exists:questionnaire_submissions,id",
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
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(
                [
                    "message" => "Failed to save answers",
                    "error" => $e->getMessage(),
                ],
                500
            );
        }
    }
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                "submission_id" =>
                    "required|exists:questionnaire_submissions,id",
                "answers" => "required|array",
                "answers.*.question_id" => "required|integer",
                "answers.*.answer_text" => "nullable", // Remove the |string restriction
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

            $missingRequired = array_diff(
                $requiredQuestions,
                $answeredQuestions
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

            $submission->update([
                "status" => "submitted",
                "submitted_at" => now(),
            ]);

            DB::commit();

            return response()->json(
                [
                    "message" => "Questionnaire submitted successfully",
                ],
                201
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(
                [
                    "message" => "Failed to submit questionnaire",
                    "error" => $e->getMessage(),
                ],
                500
            );
        }
    }
}
