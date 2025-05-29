<?php

namespace App\Console\Commands;

use App\Models\Prescription;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CancelOverduePendingPaymentPrescriptions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = "app:cancel-overdue-pending-payment-prescriptions";

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Cancels prescriptions that are in 'pending_payment' status for more than 30 days.";

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $cutoffDate = Carbon::now()->subDays(30);
        $this->info(
            "Checking for prescriptions in 'pending_payment' status created before " .
                $cutoffDate->toDateTimeString() .
                "..."
        );

        $overduePrescriptions = Prescription::where("status", "pending_payment")
            ->where("created_at", "<", $cutoffDate)
            ->get();

        if ($overduePrescriptions->isEmpty()) {
            $this->info("No overdue 'pending_payment' prescriptions found.");
            return 0;
        }

        $this->info(
            "Found {$overduePrescriptions->count()} overdue 'pending_payment' prescriptions to cancel."
        );
        $cancelledCount = 0;

        foreach ($overduePrescriptions as $prescription) {
            try {
                $prescription->status = "cancelled";
                $prescription->save();
                $cancelledCount++;
                Log::info(
                    "Cancelled 'pending_payment' prescription #{$prescription->id} due to being overdue (created at: {$prescription->created_at->toDateTimeString()})."
                );
            } catch (\Exception $e) {
                $this->error(
                    "Failed to cancel prescription #{$prescription->id}: {$e->getMessage()}"
                );
                Log::error(
                    "Failed to cancel 'pending_payment' prescription #{$prescription->id} due to being overdue.",
                    [
                        "prescription_id" => $prescription->id,
                        "error" => $e->getMessage(),
                    ]
                );
            }
        }

        $this->info(
            "Process completed. {$cancelledCount} 'pending_payment' prescriptions were cancelled."
        );
        return 0;
    }
}
