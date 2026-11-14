<?php

use Illuminate\Support\Facades\Schedule;

// Timed jobs. On the laptop, Windows Task Scheduler runs `php artisan schedule:run` every minute (SM-005).

// Proof that timed jobs run (status card, smm:doctor).
Schedule::command('smm:heartbeat')->everyMinute();

// Daily encrypted backup: checked every hour, made when the last one is over 20 hours old
// (so a laptop that was off at night still gets its backup later in the day).
Schedule::command('smm:backup --if-older-than=20')->hourly();

// Warn before account access expires (once a day, India time).
Schedule::command('smm:check-accounts')->dailyAt('09:00')->timezone('Asia/Kolkata');
