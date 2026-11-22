<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who did what and when: approvals, label changes (by the owner or Claude), reschedules.
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->nullableMorphs('subject');
            $table->string('action');
            $table->json('details')->nullable();
            $table->string('by')->default('owner');
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
