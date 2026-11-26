<?php

namespace Tests\Feature;

use App\Enums\Network;
use App\Enums\PostState;
use App\Enums\PostType;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostTarget;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublishDueCommandTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_publish_due_command_prints_actions_and_sends_nothing_in_dry_run(): void
    {
        Http::fake();
        $brand = Brand::where('slug', 'moggies-international')->firstOrFail();
        $post = Post::create(['brand_id' => $brand->id, 'caption' => 'Hi', 'state' => PostState::Approved, 'scheduled_at' => CarbonImmutable::parse('2026-12-04 06:00:00', 'UTC')]);
        PostTarget::create(['post_id' => $post->id, 'network' => Network::FacebookPage, 'type' => PostType::FacebookText]);

        $this->artisan('smm:publish-due', ['--now' => '2026-12-03 06:00:00'])
            ->expectsOutputToContain('dry run: would hand to Facebook')
            ->expectsOutputToContain('Published 0, failed 0, late 0, missed 0.')
            ->assertSuccessful();

        Http::assertNothingSent();
    }
}
