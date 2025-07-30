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
        $currentDoseIndex = $this->calculateCurrentDoseIndex($prescription);

        // Only update if we're actually progressing to a different dose
        if (
            $currentDoseIndex !== $nextDoseIndex &&
            $currentDoseIndex !== null
        ) {
            $currentDosePriceId =
                $schedule[$currentDoseIndex]["chargebee_item_price_id"] ?? null;
            $nextDosePriceId =
                $schedule[$nextDoseIndex]["chargebee_item_price_id"] ?? null;

            UpdateSubscriptionDoseJob::dispatch(
                $prescription->id,
                $nextDoseIndex,
            );

            Log::info("Scheduled dose progression", [
                "prescription_id" => $prescription->id,
                "current_dose_index" => $currentDoseIndex,
                "current_dose" =>
                    $schedule[$currentDoseIndex]["dose"] ?? "unknown",
                "current_dose_price_id" => $currentDosePriceId,
                "next_dose_index" => $nextDoseIndex,
                "next_dose" => $schedule[$nextDoseIndex]["dose"] ?? "unknown",
                "next_dose_price_id" => $nextDosePriceId,
            ]);
        } else {
            Log::info("No dose progression needed - same dose index", [
                "prescription_id" => $prescription->id,
                "current_dose_index" => $currentDoseIndex,
                "next_dose_index" => $nextDoseIndex,
                "current_dose" =>
                    $currentDoseIndex !== null
                        ? $schedule[$currentDoseIndex]["dose"] ?? "unknown"
                        : "unknown",
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

        // Get current dose index (the dose we just ordered)
        $currentDoseIndex = $this->calculateCurrentDoseIndex($prescription);

        if ($currentDoseIndex === null) {
            return null;
        }

        // Next dose is simply the next index in the schedule
        $nextDoseIndex = $currentDoseIndex + 1;

        // Check if next dose exists in schedule
        if (!isset($schedule[$nextDoseIndex])) {
            Log::info("Next dose index not found in schedule", [
                "prescription_id" => $prescription->id,
                "current_dose_index" => $currentDoseIndex,
                "next_dose_index" => $nextDoseIndex,
                "available_doses" => count($schedule),
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

    /**
     * Calculate the current dose index based on refills used
     */
    public function calculateCurrentDoseIndex(Prescription $prescription): ?int
    {
        $schedule = $prescription->dose_schedule;

        if (!$schedule) {
            return null;
        }

        $maxRefill = collect($schedule)->max("refill_number") ?? 0;
        $refillsRemaining = $prescription->refills ?? 0;
        $refillNumberCurrent = $maxRefill - $refillsRemaining + 1;

        // Find the dose index that matches the current refill number (just ordered)
        foreach ($schedule as $index => $dose) {
            if ($dose["refill_number"] === $refillNumberCurrent) {
                return $index;
            }
        }

        return null;
    }
}
