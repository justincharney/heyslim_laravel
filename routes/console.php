<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command("inspire", function () {
    $this->comment(Inspiring::quote());
})->purpose("Display an inspiring quote");

// Schedule commands
Schedule::command("telescope:prune --hours=48")->daily();
Schedule::command("app:check-unread-messages")->everyFifteenMinutes();
Schedule::command("app:validate-subscription-renewals")->twiceDaily();
