<?php

namespace App\Services;

use App\Jobs\UpdateSubscriptionDoseJob;
use App\Models\Prescription;
use Illuminate\Support\Facades\Log;

class DoseProgressionService
{
    /**
     * Handle dose progression for a prescription
     */
    public function progressDose(Prescription $prescription): void
    {
        if (!$this->shouldProgressDose($prescription)) {
            return;
        }

        $nextDoseIndex = $this->calculateNextDoseIndex($prescription);

        if ($nextDoseIndex === null) {
            return;
        }

        $schedule = $prescription->dose_schedule;
        $currentDosePriceId =
            $prescription->subscription?->chargebee_item_price_id;
        $nextDosePriceId =
            $schedule[$nextDoseIndex]["chargebee_item_price_id"] ?? null;

        // Only update if the plan is actually changing
        if ($currentDosePriceId !== $nextDosePriceId && $nextDosePriceId) {
            UpdateSubscriptionDoseJob::dispatch(
                $prescription->id,
                $nextDoseIndex,
            );

            Log::info("Scheduled dose progression", [
                "prescription_id" => $prescription->id,
                "current_dose_price_id" => $currentDosePriceId,
                "next_dose_price_id" => $nextDosePriceId,
                "next_dose_index" => $nextDoseIndex,
                "next_dose" => $schedule[$nextDoseIndex]["dose"] ?? "unknown",
            ]);
        } else {
            Log::info("No dose progression needed - same dose", [
                "prescription_id" => $prescription->id,
                "current_dose_price_id" => $currentDosePriceId,
                "next_dose_index" => $nextDoseIndex,
            ]);
        }
    }

    /**
     * Check if dose progression should occur for this prescription
     */
    public function shouldProgressDose(Prescription $prescription): bool
    {
        $schedule = $prescription->dose_schedule;

        if (!$schedule || count($schedule) <= 1) {
            Log::info(
                "No dose progression needed - insufficient dose schedule",
                [
                    "prescription_id" => $prescription->id,
                    "dose_count" => $schedule ? count($schedule) : 0,
                ],
            );
            return false;
        }

        if (!$prescription->subscription) {
            Log::error("No subscription found for dose progression", [
                "prescription_id" => $prescription->id,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Calculate the next dose index based on prescription state
     */
    public function calculateNextDoseIndex(Prescription $prescription): ?int
    {
        $schedule = $prescription->dose_schedule;

        if (!$schedule) {
            return null;
        }

        $maxRefill = collect($schedule)->max("refill_number") ?? 0;
        $usedSoFar = $maxRefill - ($prescription->refills ?? 0);

        // We target the next dose index. Since 'usedSoFar' is 0-indexed count of used refills,
        // we need to add 1 to get the next dose entry in the 0-indexed schedule array.
        $nextDoseIndex = $usedSoFar + 1;

        if (!isset($schedule[$nextDoseIndex])) {
            Log::info("Next dose index not found in schedule", [
                "prescription_id" => $prescription->id,
                "calculated_next_index" => $nextDoseIndex,
                "available_doses" => count($schedule),
                "max_refill" => $maxRefill,
                "refills_remaining" => $prescription->refills,
                "used_so_far" => $usedSoFar,
            ]);
            return null;
        }

        return $nextDoseIndex;
    }

    /**
     * Get the next dose information without dispatching any jobs
     */
    public function getNextDoseInfo(Prescription $prescription): ?array
    {
        if (!$this->shouldProgressDose($prescription)) {
            return null;
        }

        $nextDoseIndex = $this->calculateNextDoseIndex($prescription);

        if ($nextDoseIndex === null) {
            return null;
        }

        $schedule = $prescription->dose_schedule;

        return [
            "index" => $nextDoseIndex,
            "dose" => $schedule[$nextDoseIndex]["dose"] ?? null,
            "chargebee_item_price_id" =>
                $schedule[$nextDoseIndex]["chargebee_item_price_id"] ?? null,
            "shopify_variant_gid" =>
                $schedule[$nextDoseIndex]["shopify_variant_gid"] ?? null,
            "refill_number" =>
                $schedule[$nextDoseIndex]["refill_number"] ?? null,
        ];
    }
}
