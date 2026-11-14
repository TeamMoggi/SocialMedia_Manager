<?php

use Illuminate\Support\Facades\Schedule;

// Timed jobs. On the laptop, Windows Task Scheduler runs `php artisan schedule:run` every minute (SM-005).

// Proof that timed jobs run (status card, smm:doctor).
Schedule::command('smm:heartbeat')->everyMinute();

// Warn before account access expires (once a day, India time).
Schedule::command('smm:check-accounts')->dailyAt('09:00')->timezone('Asia/Kolkata');
