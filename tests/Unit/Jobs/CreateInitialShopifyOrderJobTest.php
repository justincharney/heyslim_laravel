<?php

namespace Tests\Unit\Jobs;

use App\Jobs\CreateInitialShopifyOrderJob;
use App\Models\Prescription;
use Tests\TestCase;
use ReflectionClass;
use ReflectionMethod;

class CreateInitialShopifyOrderJobTest extends TestCase
{
    private CreateInitialShopifyOrderJob $job;
    private ReflectionMethod $getProductVariantMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->job = new CreateInitialShopifyOrderJob(123);

        // Make the private method accessible for testing
        $reflection = new ReflectionClass($this->job);
        $this->getProductVariantMethod = $reflection->getMethod(
            "getProductVariantForPrescription",
        );
        $this->getProductVariantMethod->setAccessible(true);
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
        $prescription->medication_name = "Semaglutide";

        return $prescription;
    }

    /** @test */
    public function it_orders_first_dose_for_initial_order()
    {
        // For initial orders, should always use dose index 0 (2.5mg)
        $prescription = $this->createMockPrescription(2);

        $variantId = $this->getProductVariantMethod->invoke(
            $this->job,
            $prescription,
            false, // isRenewal = false
        );

        $this->assertEquals(
            "gid://shopify/ProductVariant/41902912897120",
            $variantId,
            "Initial order should use first dose (2.5mg)",
        );
    }

    /** @test */
    public function it_orders_correct_dose_for_renewal_with_2_refills_remaining()
    {
        // With 2 refills remaining, should order dose index 1 (5.0mg)
        // maxRefill = 2, refillsRemaining = 2, refillNumberToOrder = 2 - 2 + 1 = 1
        $prescription = $this->createMockPrescription(2);

        $variantId = $this->getProductVariantMethod->invoke(
            $this->job,
            $prescription,
            true, // isRenewal = true
        );

        $this->assertEquals(
            "gid://shopify/ProductVariant/41902912929888",
            $variantId,
            "Renewal with 2 refills remaining should order dose index 1 (5.0mg)",
        );
    }

    /** @test */
    public function it_orders_correct_dose_for_renewal_with_1_refill_remaining()
    {
        // With 1 refill remaining, should order dose index 2 (7.5mg)
        // maxRefill = 2, refillsRemaining = 1, refillNumberToOrder = 2 - 1 + 1 = 2
        $prescription = $this->createMockPrescription(1);

        $variantId = $this->getProductVariantMethod->invoke(
            $this->job,
            $prescription,
            true, // isRenewal = true
        );

        $this->assertEquals(
            "gid://shopify/ProductVariant/41902912962656",
            $variantId,
            "Renewal with 1 refill remaining should order dose index 2 (7.5mg)",
        );
    }

    /** @test */
    public function it_returns_null_for_renewal_with_0_refills_remaining()
    {
        // With 0 refills remaining, should be out of bounds
        // maxRefill = 2, refillsRemaining = 0, refillNumberToOrder = 2 - 0 + 1 = 3 (out of bounds)
        $prescription = $this->createMockPrescription(0);

        $variantId = $this->getProductVariantMethod->invoke(
            $this->job,
            $prescription,
            true, // isRenewal = true
        );

        $this->assertNull(
            $variantId,
            "Renewal with 0 refills remaining should return null (out of bounds)",
        );
    }

    /** @test */
    public function it_handles_empty_dose_schedule()
    {
        $prescription = $this->createMockPrescription(2, []);

        $variantId = $this->getProductVariantMethod->invoke(
            $this->job,
            $prescription,
            false, // isRenewal = false
        );

        $this->assertNull($variantId, "Empty dose schedule should return null");
    }

    /** @test */
    public function it_handles_null_dose_schedule()
    {
        $prescription = $this->createMockPrescription(2, null);

        $variantId = $this->getProductVariantMethod->invoke(
            $this->job,
            $prescription,
            false, // isRenewal = false
        );

        $this->assertNull($variantId, "Null dose schedule should return null");
    }

    /** @test */
    public function it_validates_complete_dose_progression_workflow()
    {
        // Test the complete workflow described by the user:

        // Scenario 1: refills = 2
        // - Order should be for 5.0mg (dose index 1)
        // - After ordering, dose will progress to 7.5mg (dose index 2)
        // - Refills will be decremented to 1

        $prescription1 = $this->createMockPrescription(2);
        $variantForOrder1 = $this->getProductVariantMethod->invoke(
            $this->job,
            $prescription1,
            true, // isRenewal = true
        );

        $this->assertEquals(
            "gid://shopify/ProductVariant/41902912929888",
            $variantForOrder1,
            "Should order 5.0mg when refills = 2",
        );

        // Scenario 2: refills = 1 (after decrementing from scenario 1)
        // - Order should be for 7.5mg (dose index 2)
        // - No next dose to progress to
        // - Refills will be decremented to 0

        $prescription2 = $this->createMockPrescription(1);
        $variantForOrder2 = $this->getProductVariantMethod->invoke(
            $this->job,
            $prescription2,
            true, // isRenewal = true
        );

        $this->assertEquals(
            "gid://shopify/ProductVariant/41902912962656",
            $variantForOrder2,
            "Should order 7.5mg when refills = 1",
        );
    }

    /** @test */
    public function it_uses_dose_index_zero_for_all_initial_orders_regardless_of_refills()
    {
        // Initial orders should always use dose index 0, regardless of refills count

        $prescription1 = $this->createMockPrescription(2);
        $variantId1 = $this->getProductVariantMethod->invoke(
            $this->job,
            $prescription1,
            false, // isRenewal = false
        );

        $prescription2 = $this->createMockPrescription(1);
        $variantId2 = $this->getProductVariantMethod->invoke(
            $this->job,
            $prescription2,
            false, // isRenewal = false
        );

        $prescription3 = $this->createMockPrescription(0);
        $variantId3 = $this->getProductVariantMethod->invoke(
            $this->job,
            $prescription3,
            false, // isRenewal = false
        );

        // All should use the first dose (2.5mg)
        $expectedVariantId = "gid://shopify/ProductVariant/41902912897120";

        $this->assertEquals($expectedVariantId, $variantId1);
        $this->assertEquals($expectedVariantId, $variantId2);
        $this->assertEquals($expectedVariantId, $variantId3);
    }
}
