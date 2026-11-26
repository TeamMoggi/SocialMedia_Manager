<?php

namespace Tests\Feature;

use App\Enums\Network;
use App\Enums\PostType;
use App\Enums\TargetState;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublishingSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_target_has_publishing_columns_and_late_state(): void
    {
        $brand = Brand::where('slug', 'moggies-international')->firstOrFail();
        $post = Post::create(['brand_id' => $brand->id, 'caption' => 'Hi']);
        $target = PostTarget::create([
            'post_id' => $post->id, 'network' => Network::Instagram, 'type' => PostType::InstagramPhoto,
            'state' => TargetState::Late, 'late_days' => 1, 'work' => ['relay' => ['a.jpg']],
            'next_attempt_at' => '2026-12-04 05:00:00',
        ]);

        $fresh = $target->fresh();

        $this->assertSame('a.jpg', $fresh->work['relay'][0]);
        $this->assertSame(TargetState::Late, $fresh->state);
        $this->assertSame('Late: will retry', $fresh->state->label());
        $this->assertSame(1, $fresh->late_days);
        $this->assertSame('2026-12-04 05:00:00', $fresh->next_attempt_at->format('Y-m-d H:i:s'));
        $this->assertSame(3, config('smm.publishing.catch_up_days'));
        $this->assertSame([1, 4, 10], config('smm.publishing.retry_minutes'));
    }
}
