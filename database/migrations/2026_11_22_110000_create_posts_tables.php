<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A post: written once, approved against the brand rules, then sent (part 3) at its time.
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->text('caption')->nullable();
            $table->string('state')->default('draft');
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('approved_at')->nullable();
            $table->string('approved_by')->nullable();
            $table->json('checklist')->nullable();
            $table->string('approved_fingerprint')->nullable();
            $table->timestamps();
        });

        // The post's version on one network; sent, failed and retried on its own (part 3 fills the sending columns).
        Schema::create('post_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->string('network');
            $table->foreignId('connected_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->text('caption')->nullable();
            $table->string('link')->nullable();
            $table->string('state')->default('waiting');
            $table->string('external_id')->nullable();
            $table->string('container_id')->nullable();
            $table->string('permalink')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['post_id', 'network']);
        });

        // The post's photos and videos in order: the app's own copies in <data>/media/posts/<post id>/.
        Schema::create('post_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_item_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('position')->default(1);
            $table->string('path')->nullable();
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('bytes')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->float('duration')->nullable();
            $table->string('codec')->nullable();
            $table->float('fps')->nullable();
            $table->boolean('faststart')->nullable();
            $table->string('hash')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_media');
        Schema::dropIfExists('post_targets');
        Schema::dropIfExists('posts');
    }
};
