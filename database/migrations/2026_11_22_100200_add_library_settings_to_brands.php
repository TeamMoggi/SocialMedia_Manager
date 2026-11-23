<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            // Empty means the defaults in config/smm.php (Markting Social Media; the five brand rules).
            $table->json('library_sources')->nullable();
            $table->json('approval_checklist')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn(['library_sources', 'approval_checklist']);
        });
    }
};
