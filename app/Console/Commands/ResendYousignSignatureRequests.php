<?php

namespace App\Console\Commands;

use App\Models\Prescription;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Jobs\InitiateYousignSignatureJob;

class ResendYousignSignatureRequests extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = "app:resend-yousign-signature-requests {--days=1 : Only resend requests older than 1 day}";

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Re-send Yousign signature requests for prescriptions that have been sent but not signed";

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $days = (int) $this->option("days");

        $cutoffDate = Carbon::now()->subDays($days);

        // Find prescriptions that:
        // 1. have a yousign_signature_request_id
        // 2. have not been signed_at
        // 3. Are older than or equal to cutoff date
        $prescriptions = Prescription::whereNotNull(
            "yousign_signature_request_id"
        )
            ->whereNull("signed_at")
            ->where("created_at", "<=", $cutoffDate)
            ->get();

        if ($prescriptions->isEmpty()) {
            $this->info(
                "No unsigned prescriptions found that meet notification criteria"
            );
            return 0;
        }

        $this->info(
            "Found {$prescriptions->count()} prescriptions to resend signature requests for"
        );

        $processed = 0;
        $errors = 0;

        foreach ($prescriptions as $prescription) {
            try {
                DB::beginTransaction();

                // Clear the existing Yousign IDs to allow re-creation
                $prescription->yousign_signature_request_id = null;
                $prescription->save();

                // Dispatch the job to create a new signature request
                InitiateYousignSignatureJob::dispatch($prescription->id);

                DB::commit();

                $processed++;
            } catch (\Exception $e) {
                DB::rollBack();
                $errors++;
                $this->error(
                    "Failed to process prescription #{$prescription->id}:  {$e->getMessage()}"
                );
            }
        }

        $this->info(
            "Process completed: {$processed} processed, {$errors} errors"
        );

        if ($errors > 0) {
            return 1;
        }

        return 0;
    }
}
