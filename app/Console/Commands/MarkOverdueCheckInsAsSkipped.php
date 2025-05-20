<?php

namespace App\Console\Commands;

use App\Models\CheckIn;
use Carbon\Carbon;
use Illuminate\Console\Command;

class MarkOverdueCheckInsAsSkipped extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = "app:mark-overdue-check-ins-as-skipped";

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Marks pending check-ins as skipped if they are overdue";

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $overdueCheckIns = CheckIn::where("status", "pending")
            ->whereData("due_date", "<", Carbon::today())
            ->get();

        if ($overdueCheckIns->isEmpty()) {
            $this->info("No overdue check-ins found.");
            return;
        }

        $skippedCount = 0;

        foreach ($overdueCheckIns as $checkIn) {
            try {
                $checkIn->status = "skipped";
                $checkIn->save();
                $skippedCount++;
            } catch (\Exception $e) {
                $this->error(
                    "Failed to mark check-in {$checkIn->id} as skipped: {$e->getMessage()}"
                );
            }
        }

        $this->info(
            "Process completed. {$skippedCount} check-ins marked as skipped."
        );
    }
}
