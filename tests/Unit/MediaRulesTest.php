<?php

namespace Tests\Unit;

use App\Domain\Posts\MediaRules;
use App\Domain\Posts\Problem;
use App\Enums\PostType;
use Tests\TestCase;

/** Meta's limits (Graph API v27.0 docs, read 22 Nov 2026) as block/warn checks, tested at their edges. */
class MediaRulesTest extends TestCase
{
    private const MB = 1048576;

    private function photo(array $over = []): array
    {
        return $over + ['name' => 'p.jpg', 'mime' => 'image/jpeg', 'bytes' => 2 * self::MB, 'width' => 1080, 'height' => 1080,
            'duration' => null, 'codec' => null, 'fps' => null, 'faststart' => null, 'exists' => true, 'isVideo' => false];
    }

    private function video(array $over = []): array
    {
        return $over + ['name' => 'v.mp4', 'mime' => 'video/mp4', 'bytes' => 20 * self::MB, 'width' => 1080, 'height' => 1920,
            'duration' => 30.0, 'codec' => 'avc1', 'fps' => 30.0, 'faststart' => true, 'exists' => true, 'isVideo' => true];
    }

    /** @param list<Problem> $problems */
    private function levels(array $problems): array
    {
        return array_map(fn (Problem $p) => $p->level, $problems);
    }

    private function blocks(PostType $type, array $files, string $caption = 'Hello', ?string $link = null): bool
    {
        return in_array('block', $this->levels(MediaRules::check($type, $files, $caption, $link)), true);
    }

    private function warns(PostType $type, array $files, string $caption = 'Hello', ?string $link = null): bool
    {
        return in_array('warn', $this->levels(MediaRules::check($type, $files, $caption, $link)), true);
    }

    public function test_clean_posts_have_no_problems(): void
    {
        $this->assertSame([], MediaRules::check(PostType::InstagramPhoto, [$this->photo()], 'Hello'));
        $this->assertSame([], MediaRules::check(PostType::InstagramReel, [$this->video()], 'Hello'));
        $this->assertSame([], MediaRules::check(PostType::FacebookPhotos, [$this->photo(), $this->photo()], 'Hello'));
        $this->assertSame([], MediaRules::check(PostType::FacebookText, [], 'Hello'));
    }

    public function test_instagram_photo_ratio_edges(): void
    {
        $this->assertFalse($this->blocks(PostType::InstagramPhoto, [$this->photo(['width' => 800, 'height' => 1000])]));
        $this->assertFalse($this->blocks(PostType::InstagramPhoto, [$this->photo(['width' => 1910, 'height' => 1000])]));
        $this->assertTrue($this->blocks(PostType::InstagramPhoto, [$this->photo(['width' => 790, 'height' => 1000])]));
        $this->assertTrue($this->blocks(PostType::InstagramPhoto, [$this->photo(['width' => 1920, 'height' => 1000])]));
    }

    public function test_instagram_photo_size_and_format(): void
    {
        $this->assertFalse($this->blocks(PostType::InstagramPhoto, [$this->photo(['bytes' => 8 * self::MB])]));
        $this->assertTrue($this->blocks(PostType::InstagramPhoto, [$this->photo(['bytes' => 8 * self::MB + 1])]));
        $this->assertTrue($this->blocks(PostType::InstagramPhoto, [$this->photo(['mime' => 'image/png'])]));
        $this->assertTrue($this->warns(PostType::InstagramPhoto, [$this->photo(['width' => 2000, 'height' => 2000])]));
    }

    public function test_instagram_photo_takes_exactly_one_photo(): void
    {
        $this->assertTrue($this->blocks(PostType::InstagramPhoto, []));
        $this->assertTrue($this->blocks(PostType::InstagramPhoto, [$this->photo(), $this->photo()]));
        $this->assertTrue($this->blocks(PostType::InstagramPhoto, [$this->video()]));
    }

    public function test_reel_duration_size_fps_width_codec_edges(): void
    {
        $this->assertFalse($this->blocks(PostType::InstagramReel, [$this->video(['duration' => 3.0])]));
        $this->assertFalse($this->blocks(PostType::InstagramReel, [$this->video(['duration' => 900.0])]));
        $this->assertTrue($this->blocks(PostType::InstagramReel, [$this->video(['duration' => 2.9])]));
        $this->assertTrue($this->blocks(PostType::InstagramReel, [$this->video(['duration' => 900.1])]));
        $this->assertFalse($this->blocks(PostType::InstagramReel, [$this->video(['bytes' => 300 * self::MB])]));
        $this->assertTrue($this->blocks(PostType::InstagramReel, [$this->video(['bytes' => 300 * self::MB + 1])]));
        $this->assertFalse($this->blocks(PostType::InstagramReel, [$this->video(['fps' => 23.0])]));
        $this->assertFalse($this->blocks(PostType::InstagramReel, [$this->video(['fps' => 60.0])]));
        $this->assertTrue($this->blocks(PostType::InstagramReel, [$this->video(['fps' => 22.0])]));
        $this->assertTrue($this->blocks(PostType::InstagramReel, [$this->video(['fps' => 61.0])]));
        $this->assertFalse($this->blocks(PostType::InstagramReel, [$this->video(['width' => 1920, 'height' => 3413])]));
        $this->assertTrue($this->blocks(PostType::InstagramReel, [$this->video(['width' => 1921, 'height' => 3414])]));
        $this->assertTrue($this->blocks(PostType::InstagramReel, [$this->video(['codec' => 'mp4v'])]));
        $this->assertFalse($this->blocks(PostType::InstagramReel, [$this->video(['codec' => 'hvc1'])]));
        $this->assertTrue($this->blocks(PostType::InstagramReel, [$this->video(['mime' => 'video/webm', 'name' => 'v.webm'])]));
    }

    public function test_reel_index_at_the_end_only_warns(): void
    {
        $problems = MediaRules::check(PostType::InstagramReel, [$this->video(['faststart' => false])], 'Hello');

        $this->assertSame(['warn'], $this->levels($problems));
    }

    public function test_reel_shape_warns_when_not_upright(): void
    {
        $this->assertTrue($this->warns(PostType::InstagramReel, [$this->video(['width' => 1440, 'height' => 1080])]));
        $this->assertFalse($this->blocks(PostType::InstagramReel, [$this->video(['width' => 1440, 'height' => 1080])]));
    }

    public function test_unreadable_video_warns(): void
    {
        $this->assertTrue($this->warns(PostType::InstagramReel, [$this->video(['duration' => null, 'width' => null, 'height' => null, 'codec' => null, 'fps' => null])]));
    }

    public function test_story_video_edges_and_caption_warning(): void
    {
        $this->assertFalse($this->blocks(PostType::InstagramStory, [$this->video(['duration' => 60.0])], ''));
        $this->assertTrue($this->blocks(PostType::InstagramStory, [$this->video(['duration' => 60.1])], ''));
        $this->assertTrue($this->blocks(PostType::InstagramStory, [$this->video(['bytes' => 100 * self::MB + 1])], ''));
        $this->assertTrue($this->warns(PostType::InstagramStory, [$this->photo()], 'A caption'));
        $this->assertFalse($this->blocks(PostType::InstagramStory, [$this->photo(['width' => 1080, 'height' => 1920])], ''));
    }

    public function test_carousel_counts_and_shapes(): void
    {
        $this->assertTrue($this->blocks(PostType::InstagramCarousel, [$this->photo()]));
        $this->assertFalse($this->blocks(PostType::InstagramCarousel, array_fill(0, 20, $this->photo())));
        $this->assertTrue($this->blocks(PostType::InstagramCarousel, array_fill(0, 21, $this->photo())));
        $this->assertFalse($this->blocks(PostType::InstagramCarousel, [$this->photo(), $this->video(['duration' => 1200.0])]));
        $this->assertTrue($this->warns(PostType::InstagramCarousel, [$this->photo(), $this->photo(['width' => 1080, 'height' => 1350])]));
    }

    public function test_instagram_caption_limits(): void
    {
        $tags = fn (int $n) => implode(' ', array_map(fn ($i) => "#tag{$i}", range(1, $n)));

        $this->assertTrue($this->warns(PostType::InstagramPhoto, [$this->photo()], $tags(6)));
        $this->assertFalse($this->blocks(PostType::InstagramPhoto, [$this->photo()], $tags(30)));
        $this->assertTrue($this->blocks(PostType::InstagramPhoto, [$this->photo()], $tags(31)));
        $this->assertFalse($this->blocks(PostType::InstagramPhoto, [$this->photo()], str_repeat('a', 2200)));
        $this->assertTrue($this->blocks(PostType::InstagramPhoto, [$this->photo()], str_repeat('a', 2201)));
        $mentions = implode(' ', array_map(fn ($i) => "@user{$i}", range(1, 21)));
        $this->assertTrue($this->blocks(PostType::InstagramPhoto, [$this->photo()], $mentions));
    }

    public function test_facebook_rules(): void
    {
        $this->assertTrue($this->blocks(PostType::FacebookText, [], '  '));
        $this->assertTrue($this->warns(PostType::FacebookText, [$this->photo()], 'Hello'));
        $this->assertTrue($this->blocks(PostType::FacebookLink, [], 'Hello', null));
        $this->assertTrue($this->blocks(PostType::FacebookLink, [], 'Hello', 'http://moggies.in'));
        $this->assertFalse($this->blocks(PostType::FacebookLink, [], 'Hello', 'https://moggies.in/guides/'));
        $this->assertTrue($this->blocks(PostType::FacebookPhotos, []));
        $this->assertTrue($this->blocks(PostType::FacebookPhotos, [$this->photo(), $this->video()]));
        $this->assertFalse($this->blocks(PostType::FacebookPhotos, [$this->photo(['bytes' => 10 * self::MB])]));
        $this->assertTrue($this->blocks(PostType::FacebookPhotos, [$this->photo(['bytes' => 10 * self::MB + 1])]));
        $this->assertFalse($this->blocks(PostType::FacebookVideo, [$this->video()]));
        $this->assertTrue($this->blocks(PostType::FacebookVideo, [$this->video(), $this->video()]));
        $this->assertTrue($this->warns(PostType::FacebookVideo, [$this->video(['mime' => 'video/webm', 'name' => 'v.webm'])]));
    }

    public function test_missing_copy_blocks_with_its_name(): void
    {
        $problems = MediaRules::check(PostType::InstagramPhoto, [$this->photo(['exists' => false, 'name' => 'Raisins.jpg'])], 'Hello');

        $this->assertSame('block', $problems[0]->level);
        $this->assertStringContainsString("'Raisins.jpg'", $problems[0]->message);
    }

    public function test_messages_name_the_file_and_the_limit(): void
    {
        $problems = MediaRules::check(PostType::InstagramReel, [$this->video(['name' => 'Raisin grading.mp4', 'duration' => 1020.0])], 'Hello');

        $this->assertStringContainsString("'Raisin grading.mp4'", $problems[0]->message);
        $this->assertStringContainsString('15 min', $problems[0]->message);
    }

    public function test_library_badges_count_png_as_jpeg_and_flag_long_reels(): void
    {
        $png = new \App\Models\MediaItem(['relative_path' => 'CD AI/a.png', 'kind' => \App\Enums\MediaKind::AiImage,
            'mime' => 'image/png', 'bytes' => self::MB, 'width' => 1080, 'height' => 1080]);
        $long = new \App\Models\MediaItem(['relative_path' => '5/long.mp4', 'kind' => \App\Enums\MediaKind::FinishedVideo,
            'mime' => 'video/mp4', 'bytes' => 50 * self::MB, 'width' => 1080, 'height' => 1920, 'duration' => 1020.0,
            'codec' => 'avc1', 'fps' => 30.0, 'faststart' => true]);

        $this->assertSame(['Instagram photo' => true, 'Story' => true, 'Facebook photo' => true], MediaRules::badges($png));
        $this->assertSame(['Reel' => false, 'Story' => false, 'Facebook video' => true], MediaRules::badges($long));
    }

    public function test_post_types_by_network(): void
    {
        $this->assertSame(['facebook_text', 'facebook_link', 'facebook_photos', 'facebook_video'], array_keys(PostType::forNetwork(\App\Enums\Network::FacebookPage)));
        $this->assertSame(\App\Enums\Network::Instagram, PostType::InstagramReel->network());
    }
}
