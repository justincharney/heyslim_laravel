<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use App\Models\WeightLog;

class WeightLogController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();

        // Optional filtering by date range
        $startDate = $request->input("start_date");
        $endDate = $request->input("end_date");
        $limit = $request->input("limit", 50); // Default to 50

        $query = $user->weightLogs();

        if ($startDate) {
            $query->where(
                "date",
                ">=",
                Carbon::parse($startDate)->toDateString()
            );
        }

        if ($endDate) {
            $query->where(
                "date",
                "<=",
                Carbon::parse($endDate)->toDateString()
            );
        }

        $weightLogs = $query->limit($limit)->get();

        return response()->json([
            "weight_logs" => $weightLogs,
        ]);
    }

    public function store(Request $request)
    {
        $user = Auth::user();
        $validated = $request->validate([
            "weight" => "required|numeric|min:1|max:999.99",
            "unit" => "required|in:kg,lb",
            "log_date" => "nullable|date|before_or_equal:today",
        ]);

        // Default to today if not provided
        $logDate = $validated["log_date"] ?? today()->toDateString();
        $validated["log_date"] = $logDate;

        // Check if an existing log exists for the same date
        $existingLog = $user
            ->weightLogs()
            ->whereDate("log_date", $logDate)
            ->first();
        if ($existingLog) {
            // Update the existing log
            $existingLog->update([
                "weight" => $validated["weight"],
                "unit" => $validated["unit"],
            ]);
            return response()->json([
                "message" => "Weight log updated successfully",
            ]);
        }

        // Create a new log
        $validated["user_id"] = $user->id;

        try {
            $weightLog = WeightLog::create($validated);

            return response()->json([
                "message" => "Weight log created successfully",
            ]);
        } catch (\Exception $e) {
            return response()->json(
                [
                    "error" => $e->getMessage(),
                ],
                422
            );
        }
    }
}
