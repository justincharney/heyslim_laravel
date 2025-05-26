<?php

namespace App\Jobs;

use App\Services\RechargeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SkuSwapJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [60, 300, 600];

    protected $rechargeSubscriptionId;
    protected $newShopifyVariantGidNumeric;
    protected $prescriptionId;

    /**
     * Create a new job instance.
     *
     * @param string $rechargeSubscriptionId
     * @param string $newShopifyVariantGidNumeric
     * @param int|null $prescriptionId
     */
    public function __construct(
        string $rechargeSubscriptionId,
        string $newShopifyVariantGidNumeric,
        ?int $prescriptionId = null
    ) {
        $this->rechargeSubscriptionId = $rechargeSubscriptionId;
        $this->newShopifyVariantGidNumeric = $newShopifyVariantGidNumeric;
        $this->prescriptionId = $prescriptionId;
    }

    /**
     * Execute the job.
     *
     * @param RechargeService $rechargeService
     * @return void
     */
    public function handle(RechargeService $rechargeService): void
    {
        Log::info(
            "Executing SkuSwapJob for Recharge Subscription ID: {$this->rechargeSubscriptionId} to Variant GID: {$this->newShopifyVariantGidNumeric}",
            [
                "recharge_subscription_id" => $this->rechargeSubscriptionId,
                "new_shopify_variant_gid_numeric" =>
                    $this->newShopifyVariantGidNumeric,
                "prescription_id" => $this->prescriptionId,
            ]
        );

        try {
            $swapSuccess = $rechargeService->updateSubscriptionVariant(
                $this->rechargeSubscriptionId,
                $this->newShopifyVariantGidNumeric
            );

            if ($swapSuccess) {
                Log::info(
                    "Successfully swapped SKU via SkuSwapJob for Recharge Subscription ID: {$this->rechargeSubscriptionId} to Variant GID: {$this->newShopifyVariantGidNumeric}"
                );
            } else {
                Log::error(
                    "SkuSwapJob: RechargeService::updateSubscriptionVariant returned false for Subscription ID: {$this->rechargeSubscriptionId}",
                    [
                        "recharge_subscription_id" =>
                            $this->rechargeSubscriptionId,
                        "new_shopify_variant_gid_numeric" =>
                            $this->newShopifyVariantGidNumeric,
                    ]
                );
                $this->release(60);
                return;
            }
        } catch (\Exception $e) {
            Log::error(
                "Exception in SkuSwapJob for Recharge Subscription ID: {$this->rechargeSubscriptionId}",
                [
                    "recharge_subscription_id" => $this->rechargeSubscriptionId,
                    "new_shopify_variant_gid_numeric" =>
                        $this->newShopifyVariantGidNumeric,
                    "error" => $e->getMessage(),
                    "trace" => $e->getTraceAsString(), // Be cautious with full traces in production logs
                ]
            );
            $this->release(300); // Release back to the queue with a delay
            return;
        }
    }
}
