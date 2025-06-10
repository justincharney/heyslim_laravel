<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Services\GpLetterService;
use App\Notifications\GpLetterNotification;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Prescription;
use Illuminate\Support\Facades\Log;

class SendGpLetterJob implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue, SerializesModels;

    protected int $prescriptionId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $prescriptionId)
    {
        $this->prescriptionId = $prescriptionId;
    }

    /**
     * Execute the job.
     */
    public function handle(GpLetterService $gpLetterService): void
    {
        $prescription = Prescription::with([
            "patient",
            "clinicalPlan.questionnaireSubmission.answers.question.options",
        ])->find($this->prescriptionId);

        if (!$prescription) {
            Log::error(
                "SendGpLetterJob: Prescription not found for ID: {$this->prescriptionId}. Job will fail."
            );
            $this->fail("Prescription not found: {$this->prescriptionId}");
            return;
        }

        if (!$prescription->patient) {
            Log::error(
                "SendGpLetterJob: Patient not found for prescription ID: {$this->prescriptionId}. Job will fail."
            );
            $this->fail(
                "Patient not found for prescription: {$this->prescriptionId}"
            );
            return;
        }

        // Fetch and prepare questionnaire answers
        $keyedAnswers = collect();
        $keyedAnswers = $prescription->clinicalPlan->questionnaireSubmission->answers
            ->keyBy(function ($answer) {
                // Answer question_text safely
                return optional(optional($answer)->question)->question_text;
            })
            ->filter(function ($value, $key) {
                return !is_null($key);
            });

        // Text for the question answers we need for the GP letter
        $q_height_text = "Height (cm)";
        $q_weight_text = "Weight (kg)";
        $q_bmi_text = "Calculated BMI";
        $q_conditions_text =
            "Do you have or have you ever had any of the following conditions?";
        $q_pregnancy_text =
            "Are you pregnant, planning to become pregnant, or currently breastfeeding?";
        $q_bariatric_text = "Have you had any bariatric (weight loss) surgery?";

        $viewAnswers = [
            "height" => optional($keyedAnswers->get($q_height_text))
                ->answer_text,
            "weight" => optional($keyedAnswers->get($q_weight_text))
                ->answer_text,
            "bmi" => optional($keyedAnswers->get($q_bmi_text))->answer_text,
            "conditions" => optional($keyedAnswers->get($q_conditions_text))
                ->answer_text,
            "pregnancy" => strtoupper(
                optional($keyedAnswers->get($q_pregnancy_text))->answer_text
            ),
            "bariatric_surgery" => strtoupper(
                optional($keyedAnswers->get($q_bariatric_text))->answer_text
            ),
        ];

        // Fetch options for conditions
        $conditionsAnswerObject = $keyedAnswers->get($q_conditions_text);
        $conditionsQuestion = optional($conditionsAnswerObject)->question;
        $conditionsOptionsList = collect();
        $conditionsOptionsList = $conditionsQuestion->options->pluck(
            "option_text"
        );

        $viewAnswers["conditions_options"] = $conditionsOptionsList;

        // Handle 'conditions' if it's stored as a JSON string array
        $conditionsData = $viewAnswers["conditions"];
        if (is_string($conditionsData)) {
            $decodedConditions = json_decode($conditionsData, true);
            if (
                json_last_error() === JSON_ERROR_NONE &&
                is_array($decodedConditions)
            ) {
                $viewAnswers["conditions"] = $decodedConditions;
            }
        }

        try {
            $pdfBinary = $gpLetterService->generatePdfContent(
                $prescription,
                $viewAnswers
            );

            if (!$pdfBinary) {
                Log::error(
                    "SendGpLetterJob: Failed to generate PDF content for prescription ID: {$this->prescriptionId}. Releasing job."
                );
                $this->release(60); // Release for 1 minute
                return;
            }

            // Decode the document
            // $pdfBinary = base64_decode($pdfBinary);

            $pdfFilename = "GP_Letter_Prescription_{$prescription->id}.pdf";

            $prescription->patient->notify(
                new GpLetterNotification(
                    $prescription,
                    $pdfBinary,
                    $pdfFilename
                )
            );
        } catch (\Exception $e) {
            Log::error(
                "SendGpLetterJob: Exception occurred for prescription ID: {$this->prescriptionId}",
                [
                    "error" => $e->getMessage(),
                    "trace" => $e->getTraceAsString(),
                ]
            );
            $this->release(60); // Release for 1 minute
        }
    }
}
