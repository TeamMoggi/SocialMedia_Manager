<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One group per folder of a source folder (Markting Social Media), named after the folder at first.
        Schema::create('media_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('source_root');
            $table->string('folder')->default('');
            $table->string('name');
            $table->timestamps();
            $table->unique(['brand_id', 'source_root', 'folder']);
        });

        // One row per file found in a source folder. The file itself is never copied or changed.
        Schema::create('media_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_group_id')->constrained()->cascadeOnDelete();
            $table->string('source_root');
            $table->string('relative_path');
            $table->string('kind');
            $table->string('product_range')->nullable();
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('bytes')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->float('duration')->nullable();
            $table->string('codec')->nullable();
            $table->float('fps')->nullable();
            $table->boolean('faststart')->nullable();
            $table->unsignedBigInteger('mtime')->default(0);
            $table->string('hash')->nullable()->index();
            $table->boolean('ai_made')->default(false);
            $table->boolean('shows_people')->nullable();
            $table->text('description')->nullable();
            $table->string('facts_error')->nullable();
            $table->timestamp('missing_since')->nullable();
            $table->timestamps();
            $table->unique(['brand_id', 'source_root', 'relative_path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_items');
        Schema::dropIfExists('media_groups');
    }
};
