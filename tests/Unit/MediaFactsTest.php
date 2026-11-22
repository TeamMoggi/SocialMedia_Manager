<?php

namespace Tests\Unit;

use App\Domain\Library\MediaFacts;
use Illuminate\Support\Facades\File;
use Tests\Support\Mp4Fixture;
use Tests\TestCase;

class MediaFactsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'smm-facts-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function jpeg(string $path, int $w, int $h): void
    {
        File::ensureDirectoryExists(dirname($path));
        $img = imagecreatetruecolor($w, $h);
        imagejpeg($img, $path, 80);
        imagedestroy($img);
    }

    public function test_jpeg_facts(): void
    {
        $this->jpeg($this->dir.'/p.jpg', 1080, 1350);

        $facts = MediaFacts::read($this->dir.'/p.jpg');

        $this->assertSame(1080, $facts['width']);
        $this->assertSame(1350, $facts['height']);
        $this->assertSame('image/jpeg', $facts['mime']);
        $this->assertNull($facts['duration']);
        $this->assertNull($facts['error']);
    }

    public function test_mp4_facts(): void
    {
        Mp4Fixture::make($this->dir.'/v.mp4', 1080, 1920, 12.0, 30, 'avc1');

        $facts = MediaFacts::read($this->dir.'/v.mp4');

        $this->assertNull($facts['error']);
        $this->assertSame(1080, $facts['width']);
        $this->assertSame(1920, $facts['height']);
        $this->assertEqualsWithDelta(12.0, $facts['duration'], 0.1);
        $this->assertEqualsWithDelta(30.0, $facts['fps'], 0.5);
        $this->assertSame('avc1', $facts['codec']);
        $this->assertTrue($facts['faststart']);
    }

    public function test_moov_after_mdat_is_reported(): void
    {
        Mp4Fixture::make($this->dir.'/late.mp4', 720, 1280, 5.0, 25, 'avc1', moovFirst: false);

        $facts = MediaFacts::read($this->dir.'/late.mp4');

        $this->assertFalse($facts['faststart']);
        $this->assertEqualsWithDelta(5.0, $facts['duration'], 0.1);
    }

    public function test_broken_video_gives_error_not_exception(): void
    {
        file_put_contents($this->dir.'/broken.mp4', 'not a video');

        $facts = MediaFacts::read($this->dir.'/broken.mp4');

        $this->assertNotNull($facts['error']);
        $this->assertNull($facts['width']);
    }

    public function test_names_with_spaces_and_hindi(): void
    {
        $path = $this->dir.'/a&b/फोटो !.jpg';
        $this->jpeg($path, 640, 480);

        $facts = MediaFacts::read($path);

        $this->assertNull($facts['error']);
        $this->assertSame(640, $facts['width']);
        $this->assertSame(480, $facts['height']);
    }
}
