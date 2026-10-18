<?php

use Illuminate\Support\Facades\Schedule;

// Needs `php artisan schedule:work` (or a cron entry running schedule:run every minute).
Schedule::command('outbox:relay')->everyFiveMinutes()->withoutOverlapping();
