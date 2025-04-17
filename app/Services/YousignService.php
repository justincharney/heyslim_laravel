<?php

namespace App\Services;

use App\Models\Prescription;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\Browsershot\Browsershot;

class YousignService
{
    private string $api;
    private string $key;

    public function __construct()
    {
        $this->api = config("services.yousign.api_url");
        $this->key = config("services.yousign.api_key");
    }

    public function sendForSignature(Prescription $prescription): ?string
    {
        try {
            $pdfHtml = view("pdfs.prescription", [
                "prescription" => $prescription,
                "patient" => $prescription->patient,
                "prescriber" => $prescription->prescriber,
            ])->render();

            /* Get signature box position */
            $signatureMetrics = $this->getSignatureBoxMetrics($pdfHtml);

            /* Generate the PDF (binary) */
            $pdf = $this->generatePrescriptionPdf($prescription);
            if (!$pdf) {
                throw new \RuntimeException("PDF generation failed.");
            }

            /* Create the Signature Request shell */
            $srId = $this->createSignatureRequest($prescription);
            if (!$srId) {
                throw new \RuntimeException(
                    "Could not create Signature Request."
                );
            }

            /*  Upload the document (returns $documentId) */
            $documentId = $this->uploadDocument($srId, $pdf);
            if (!$documentId) {
                throw new \RuntimeException("Document upload failed.");
            }

            /* Add the prescriber as signer + signature field */
            $signerId = $this->addSigner(
                $srId,
                $prescription->prescriber,
                $documentId,
                $signatureMetrics
            );
            if (!$signerId) {
                throw new \RuntimeException("Could not add signer.");
            }

            /*  Activate the request so Yousign sends the email */
            if (!$this->activateSignatureRequest($srId)) {
                throw new \RuntimeException("Activation failed.");
            }

            return $srId;
        } catch (\Throwable $e) {
            Log::error("Yousign flow failed", [
                "prescription_id" => $prescription->id,
                "error" => $e->getMessage(),
            ]);
            return null;
        }
    }

    /** Initiate the Signature Request */
    private function createSignatureRequest(Prescription $rx): ?string
    {
        $response = $this->yousign()->post("/signature_requests", [
            "name" => "Rx #{$rx->id}",
            "delivery_mode" => "email",
        ]);

        return $response->successful() ? $response->json("id") : null;
    }

    /** Generate the Prescription PDF */
    private function generatePrescriptionPdf(
        Prescription $prescription
    ): ?string {
        try {
            return Pdf::view("pdfs.prescription", [
                "prescription" => $prescription,
                "patient" => $prescription->patient,
                "prescriber" => $prescription->prescriber,
            ])
                ->format("a4")
                ->base64();
        } catch (\Throwable $e) {
            Log::error("Error generating prescription PDF", [
                "error" => $e->getMessage(),
                "prescription_id" => $prescription->id,
            ]);
            return null;
        }
    }

    /** Upload the PDF */
    private function uploadDocument(string $srId, string $pdfBinary): ?string
    {
        $pdfBinary = base64_decode($pdfBinary);

        $tmp = tempnam(sys_get_temp_dir(), "rx_") . ".pdf";
        file_put_contents($tmp, $pdfBinary);

        $response = $this->yousign()
            ->asMultipart() // Laravel HTTP client helper
            ->attach("file", file_get_contents($tmp), "prescription.pdf", [
                "Content-Type" => "application/pdf",
            ])
            ->post("/signature_requests/{$srId}/documents", [
                "nature" => "signable_document",
            ]);

        Log::info("Document response", $response->json());

        unlink($tmp);

        return $response->successful() ? $response->json("id") : null;
    }

    protected function getSignatureBoxMetrics(string $html): array
    {
        $x = Browsershot::html($html)
            ->format("a4")
            ->showBackground()
            ->evaluate(
                "window.document.querySelector('.signature-line').getBoundingClientRect().x"
            );

        $y = Browsershot::html($html)
            ->format("a4")
            ->showBackground()
            ->evaluate(
                "window.document.querySelector('.signature-line').getBoundingClientRect().y"
            );

        $width = Browsershot::html($html)
            ->format("a4")
            ->showBackground()
            ->evaluate(
                "window.document.querySelector('.signature-line').getBoundingClientRect().width"
            );

        $result = [
            "x" => $x,
            "y" => $y,
            "width" => $width,
        ];

        // log the string result - fixing context parameter to be an array
        Log::info("Signature metrics", $result);

        return $result;
    }

    /** Add the Signer + signature Field */
    private function addSigner(
        string $srId,
        User $prescriber,
        string $documentId,
        array $signatureMetrics
    ): ?string {
        // Extract first and last name from the name property
        $nameParts = explode(" ", $prescriber->name, 2);
        $firstName = $nameParts[0] ?? "Unknown";
        $lastName = $nameParts[1] ?? "User";

        // 96 px === 72 pt  ⇒  1 px = 0.75 pt
        $scale = 72 / 96; // = 0.75

        // Position the signature field using the metrics
        $x = (int) ($signatureMetrics["x"] * $scale);
        $y = (int) (($signatureMetrics["y"] - 37) * $scale);

        $response = $this->yousign()->post(
            "/signature_requests/{$srId}/signers",
            [
                "info" => [
                    "first_name" => $firstName,
                    "last_name" => $lastName,
                    "email" => "justin@call-acs.com", //$prescriber->email,
                    "locale" => "en",
                ],
                "signature_level" => "electronic_signature",
                "signature_authentication_mode" => "no_otp",
                "fields" => [
                    [
                        "type" => "signature",
                        "document_id" => $documentId,
                        "page" => 1,
                        "x" => $x,
                        "y" => $y,
                        "width" => 200,
                    ],
                ],
            ]
        );

        Log::info("YousignService::addSigner", [
            "srId" => $srId,
            "prescriber" => $prescriber,
            "response" => $response->json(),
        ]);

        return $response->successful() ? $response->json("id") : null;
    }

    /** Activate */
    private function activateSignatureRequest(string $srId): bool
    {
        return $this->yousign()
            ->post("/signature_requests/{$srId}/activate")
            ->successful();
    }

    /** Helper */
    private function yousign()
    {
        return Http::withToken($this->key)->acceptJson()->baseUrl($this->api);
    }
}
