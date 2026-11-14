<?php

use Illuminate\Support\Facades\Schedule;

// On the laptop the Windows task "\SocialMediaManager\Daily" runs `php artisan smm:daily` once a day (SM-007),
// which runs the jobs below directly. This schedule is for a hosted copy with a cron job (Phase 4).

// Daily encrypted backup: checked every hour, made when the last one is over 20 hours old
// (so a laptop that was off at night still gets its backup later in the day).
Schedule::command('smm:backup --if-older-than=20')->hourly();

// Warn before account access expires (once a day, India time).
Schedule::command('smm:check-accounts')->dailyAt('09:00')->timezone('Asia/Kolkata');
