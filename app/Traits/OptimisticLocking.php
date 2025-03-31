<?php

namespace App\Traits;

use DateTime;
use Illuminate\Database\Eloquent\Model;

trait OptimisticLocking
{
    /**
     * Verify optimistic locking by comparing timestamps
     *
     * @param Model $model The model to check
     * @param string $clientTimestamp The timestamp from the client
     * @return array|null Returns null if check passes, or an error response array if there's a conflict
     */
    protected function checkOptimisticLock(
        Model $model,
        string $clientTimestamp
    ): ?array {
        // Convert to the same format for comparison
        $clientDateTime = new DateTime($clientTimestamp);
        $serverDateTime = new DateTime($model->updated_at);

        // Check if timestamps match (using format to compare strings)
        if (
            $clientDateTime->format("Y-m-d H:i:s.u") !==
            $serverDateTime->format("Y-m-d H:i:s.u")
        ) {
            return [
                "message" =>
                    "The record was modified by another user. Please refresh and try again.",
                "status" => 409,
            ];
        }

        return null;
    }
}
