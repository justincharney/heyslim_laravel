<?php

namespace Tests\Unit\Services;

use App\Models\Prescription;
use App\Services\DoseProgressionService;
use Tests\TestCase;

class DoseProgressionServiceTest extends TestCase
{
    private DoseProgressionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DoseProgressionService();
    }

    private function createMockPrescription(
        int $refills,
        ?array $doseSchedule = null,
    ): Prescription {
        $defaultDoseSchedule = [
            [
                "refill_number" => 0,
                "dose" => "2.5mg",
                "shopify_variant_gid" =>
                    "gid://shopify/ProductVariant/41902912897120",
                "chargebee_item_price_id" => "41902912897120-GBP-Monthly",
            ],
            [
                "refill_number" => 1,
                "dose" => "5.0mg",
                "shopify_variant_gid" =>
                    "gid://shopify/ProductVariant/41902912929888",
                "chargebee_item_price_id" => "41902912929888-GBP-Monthly",
            ],
            [
                "refill_number" => 2,
                "dose" => "7.5mg",
                "shopify_variant_gid" =>
                    "gid://shopify/ProductVariant/41902912962656",
                "chargebee_item_price_id" => "41902912962656-GBP-Monthly",
            ],
        ];

        $prescription = new Prescription();
        $prescription->dose_schedule =
            func_num_args() >= 2 ? $doseSchedule : $defaultDoseSchedule;
        $prescription->refills = $refills;
        $prescription->id = 123;

        // Mock subscription relationship
        $subscription = $this->createMock(\App\Models\Subscription::class);
        $prescription->setRelation("subscription", $subscription);

        return $prescription;
    }

    /** @test */
    public function it_calculates_current_dose_index_correctly_with_2_refills_remaining()
    {
        // When refills = 2, we should be on dose index 1 (5.0mg)
        // maxRefill = 2, refillsRemaining = 2, refillNumberCurrent = 2 - 2 + 1 = 1
        $prescription = $this->createMockPrescription(2);

        $currentDoseIndex = $this->service->calculateCurrentDoseIndex(
            $prescription,
        );

        $this->assertEquals(
            1,
            $currentDoseIndex,
            "With 2 refills remaining, current dose index should be 1 (5.0mg)",
        );
    }

    /** @test */
    public function it_calculates_current_dose_index_correctly_with_1_refill_remaining()
    {
        // When refills = 1, we should be on dose index 2 (7.5mg)
        // maxRefill = 2, refillsRemaining = 1, refillNumberCurrent = 2 - 1 + 1 = 2
        $prescription = $this->createMockPrescription(1);

        $currentDoseIndex = $this->service->calculateCurrentDoseIndex(
            $prescription,
        );

        $this->assertEquals(
            2,
            $currentDoseIndex,
            "With 1 refill remaining, current dose index should be 2 (7.5mg)",
        );
    }

    /** @test */
    public function it_calculates_current_dose_index_correctly_with_0_refills_remaining()
    {
        // When refills = 0, we should be past the end of the schedule
        // maxRefill = 2, refillsRemaining = 0, refillNumberCurrent = 2 - 0 + 1 = 3 (out of bounds)
        $prescription = $this->createMockPrescription(0);

        $currentDoseIndex = $this->service->calculateCurrentDoseIndex(
            $prescription,
        );

        $this->assertNull(
            $currentDoseIndex,
            "With 0 refills remaining, current dose index should be null (past end of schedule)",
        );
    }

    /** @test */
    public function it_calculates_next_dose_index_correctly_with_2_refills_remaining()
    {
        // Current dose index = 1, next should be 2
        $prescription = $this->createMockPrescription(2);

        $nextDoseIndex = $this->service->calculateNextDoseIndex($prescription);

        $this->assertEquals(
            2,
            $nextDoseIndex,
            "With 2 refills remaining, next dose index should be 2 (7.5mg)",
        );
    }

    /** @test */
    public function it_calculates_next_dose_index_correctly_with_1_refill_remaining()
    {
        // Current dose index = 2, next should be null (end of schedule)
        $prescription = $this->createMockPrescription(1);

        $nextDoseIndex = $this->service->calculateNextDoseIndex($prescription);

        $this->assertNull(
            $nextDoseIndex,
            "With 1 refill remaining, next dose index should be null (end of schedule)",
        );
    }

    /** @test */
    public function it_returns_correct_next_dose_info_with_2_refills_remaining()
    {
        $prescription = $this->createMockPrescription(2);

        $nextDoseInfo = $this->service->getNextDoseInfo($prescription);

        $this->assertNotNull($nextDoseInfo);
        $this->assertEquals(2, $nextDoseInfo["index"]);
        $this->assertEquals("7.5mg", $nextDoseInfo["dose"]);
        $this->assertEquals(
            "41902912962656-GBP-Monthly",
            $nextDoseInfo["chargebee_item_price_id"],
        );
        $this->assertEquals(2, $nextDoseInfo["refill_number"]);
    }

    /** @test */
    public function it_returns_null_next_dose_info_with_1_refill_remaining()
    {
        $prescription = $this->createMockPrescription(1);

        $nextDoseInfo = $this->service->getNextDoseInfo($prescription);

        $this->assertNull(
            $nextDoseInfo,
            "With 1 refill remaining, there should be no next dose",
        );
    }

    /** @test */
    public function it_handles_single_dose_schedule()
    {
        $singleDoseSchedule = [
            [
                "refill_number" => 0,
                "dose" => "5.0mg",
                "shopify_variant_gid" =>
                    "gid://shopify/ProductVariant/41902912929888",
                "chargebee_item_price_id" => "41902912929888-GBP-Monthly",
            ],
        ];

        $prescription = $this->createMockPrescription(1, $singleDoseSchedule);

        $shouldProgress = $this->service->shouldProgressDose($prescription);

        $this->assertFalse(
            $shouldProgress,
            "Single dose schedule should not progress",
        );
    }

    /** @test */
    public function it_handles_empty_dose_schedule()
    {
        $prescription = $this->createMockPrescription(2, []);

        $shouldProgress = $this->service->shouldProgressDose($prescription);
        $currentDoseIndex = $this->service->calculateCurrentDoseIndex(
            $prescription,
        );
        $nextDoseIndex = $this->service->calculateNextDoseIndex($prescription);

        $this->assertFalse($shouldProgress);
        $this->assertNull($currentDoseIndex);
        $this->assertNull($nextDoseIndex);
    }

    /** @test */
    public function it_handles_null_dose_schedule()
    {
        $prescription = $this->createMockPrescription(2, null);

        $shouldProgress = $this->service->shouldProgressDose($prescription);
        $currentDoseIndex = $this->service->calculateCurrentDoseIndex(
            $prescription,
        );
        $nextDoseIndex = $this->service->calculateNextDoseIndex($prescription);

        $this->assertFalse($shouldProgress);
        $this->assertNull($currentDoseIndex);
        $this->assertNull($nextDoseIndex);
    }

    /** @test */
    public function it_calculates_dose_progression_scenario_refills_2_to_1()
    {
        // Scenario: refills = 2
        // - Current dose index should be 1 (5.0mg) - this is what we order
        // - Next dose index should be 2 (7.5mg) - this is what we progress to
        // - After ordering, refills will be decremented to 1

        $prescription = $this->createMockPrescription(2);

        $currentDoseIndex = $this->service->calculateCurrentDoseIndex(
            $prescription,
        );
        $nextDoseInfo = $this->service->getNextDoseInfo($prescription);

        // We should order dose index 1 (5.0mg)
        $this->assertEquals(1, $currentDoseIndex);

        // We should progress to dose index 2 (7.5mg)
        $this->assertNotNull($nextDoseInfo);
        $this->assertEquals(2, $nextDoseInfo["index"]);
        $this->assertEquals("7.5mg", $nextDoseInfo["dose"]);
    }

    /** @test */
    public function it_calculates_dose_progression_scenario_refills_1_to_0()
    {
        // Scenario: refills = 1
        // - Current dose index should be 2 (7.5mg) - this is what we order
        // - Next dose index should be null - no progression
        // - After ordering, refills will be decremented to 0

        $prescription = $this->createMockPrescription(1);

        $currentDoseIndex = $this->service->calculateCurrentDoseIndex(
            $prescription,
        );
        $nextDoseInfo = $this->service->getNextDoseInfo($prescription);

        // We should order dose index 2 (7.5mg)
        $this->assertEquals(2, $currentDoseIndex);

        // No next dose to progress to
        $this->assertNull($nextDoseInfo);
    }
}
