<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            // When a failed send may be tried again (the 3 short retries).
            $table->timestamp('next_attempt_at')->nullable();
            // How many of the next-day catch-up days have been used (0 to 3).
            $table->unsignedTinyInteger('late_days')->default(0);
            // Sending bookkeeping: relay file names, Instagram child containers, Facebook photo ids, publish_started_at.
            $table->json('work')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            $table->dropColumn(['next_attempt_at', 'late_days', 'work']);
        });
    }
};
