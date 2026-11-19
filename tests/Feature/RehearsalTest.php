<?php

namespace Tests\Feature;

use App\Domain\Library\SourceFiles;
use App\Domain\Meta\MetaGraph;
use App\Domain\Meta\Rehearsal;
use App\Domain\Relay\RelayFolder;
use App\Enums\Network;
use App\Filament\Pages\TestSend;
use App\Models\Brand;
use App\Models\ConnectedAccount;
use App\Models\Setting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class RehearsalTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Brand $brand;

    private Filesystem $disk;

    private RelayFolder $relay;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->brand = Brand::where('slug', 'moggies-international')->firstOrFail();
        $this->disk = Storage::fake('relay');
        $this->relay = new RelayFolder($this->disk, 'https://moggiesinternational.com/mr-test/');
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'smm-rehearsal-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->dir.'/9 Product ownsr');
        file_put_contents($this->dir.'/Moggies Main1 !.jpg', 'jpeg-bytes');
        file_put_contents($this->dir.'/9 Product ownsr/Moggies International your trusted Partener !.mp4', 'mp4-bytes');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function photo(): string
    {
        return $this->dir.'/Moggies Main1 !.jpg';
    }

    private function video(): string
    {
        return $this->dir.'/9 Product ownsr/Moggies International your trusted Partener !.mp4';
    }

    private function connectAccounts(): void
    {
        ConnectedAccount::create(['brand_id' => $this->brand->id, 'network' => Network::FacebookPage, 'external_id' => '1510742765451658', 'name' => 'Moggies International', 'access_token' => 'page-tok']);
        ConnectedAccount::create(['brand_id' => $this->brand->id, 'network' => Network::Instagram, 'external_id' => '17841470732347612', 'name' => '@moggies_international', 'access_token' => 'page-tok', 'meta' => ['page_id' => '1510742765451658']]);
    }

    /** A stand-in for Meta: containers, unpublished Facebook uploads, read-backs and deletes. */
    private function fakeMeta(string $videoStatus = 'FINISHED', int $facebookReadStatus = 200): void
    {
        $photoPolls = 0;
        Http::fake(function (Request $r) use (&$photoPolls, $videoStatus, $facebookReadStatus) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);
            $method = $r->method();

            return match (true) {
                $method === 'POST' && str_ends_with($path, '/17841470732347612/media') => Http::response(['id' => str_contains($r->body(), 'REELS') ? 'cont-video' : 'cont-photo']),
                $method === 'GET' && str_ends_with($path, '/cont-photo') => Http::response(['status_code' => ++$photoPolls === 1 ? 'IN_PROGRESS' : 'FINISHED']),
                $method === 'GET' && str_ends_with($path, '/cont-video') => Http::response(['status_code' => $videoStatus, 'status' => $videoStatus === 'ERROR' ? 'Error: the video is too short' : 'Finished']),
                $method === 'POST' && str_ends_with($path, '/1510742765451658/photos') => Http::response(['id' => 'fb-photo']),
                $method === 'POST' && str_ends_with($path, '/1510742765451658/videos') => Http::response(['id' => 'fb-video']),
                $method === 'GET' && (str_ends_with($path, '/fb-photo') || str_ends_with($path, '/fb-video')) => Http::response(['id' => 'x'], $facebookReadStatus),
                $method === 'DELETE' => Http::response(['success' => true]),
                default => Http::response(['error' => ['message' => "unexpected {$method} {$path}", 'code' => 100]], 400),
            };
        });
    }

    private function rehearsal(string $mode = 'dry-run'): Rehearsal
    {
        return new Rehearsal(new MetaGraph('sec', $mode), $this->relay, fn (int $seconds) => null);
    }

    private function assertNothingPublished(): void
    {
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'media_publish') || str_contains($r->url(), '/feed'));
    }

    public function test_rehearsal_runs_every_step_and_never_publishes(): void
    {
        $this->connectAccounts();
        $this->fakeMeta();

        $result = $this->rehearsal()->run($this->brand, $this->photo(), $this->video());

        $this->assertTrue($result['ok'], json_encode($result['steps']));
        foreach (['Instagram photo', 'Instagram video', 'Facebook photo', 'Facebook video'] as $group) {
            $this->assertNotEmpty(array_filter($result['steps'], fn (array $s): bool => str_starts_with($s['label'], $group)), "No steps for {$group}");
        }
        $this->assertNothingPublished();
        // Multipart fields come as [name, contents] pairs.
        $published = fn (Request $r): mixed => collect($r->data())->firstWhere('name', 'published')['contents'] ?? null;
        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/photos') && $published($r) === 'false');
        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/videos') && $published($r) === 'false');
        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_contains($r->url(), '/fb-photo'));
        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_contains($r->url(), '/fb-video'));
        Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), 'https://graph-video.facebook.com/'));
        $this->assertSame([], $this->disk->allFiles());
        $this->assertSame($result, Setting::get('rehearsal.last'));
    }

    public function test_rehearsal_never_publishes_even_in_live_mode(): void
    {
        $this->connectAccounts();
        $this->fakeMeta();

        $result = $this->rehearsal('live')->run($this->brand, $this->photo(), $this->video());

        $this->assertTrue($result['ok'], json_encode($result['steps']));
        $this->assertNothingPublished();
    }

    public function test_container_error_fails_the_step_and_cleans_up(): void
    {
        $this->connectAccounts();
        $this->fakeMeta(videoStatus: 'ERROR');

        $result = $this->rehearsal()->run($this->brand, $this->photo(), $this->video());

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('the video is too short', json_encode($result['steps']));
        $this->assertSame([], $this->disk->allFiles());
    }

    public function test_facebook_read_failure_still_deletes_the_upload(): void
    {
        $this->connectAccounts();
        $this->fakeMeta(facebookReadStatus: 500);

        $result = $this->rehearsal()->run($this->brand, $this->photo(), $this->video());

        $this->assertFalse($result['ok']);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_contains($r->url(), '/fb-photo'));
        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_contains($r->url(), '/fb-video'));
    }

    public function test_missing_setup_is_explained(): void
    {
        Http::fake();

        $result = $this->rehearsal()->run($this->brand, $this->photo(), $this->video());

        $this->assertFalse($result['ok']);
        $this->assertCount(1, $result['steps']);
        $this->assertStringContainsString('Connect Meta first', $result['steps'][0]['note']);
        Http::assertNothingSent();
    }

    public function test_command_reports_steps_and_fails_when_setup_is_missing(): void
    {
        Http::fake();

        $this->artisan('smm:meta:rehearse', ['--photo' => $this->photo(), '--video' => $this->video()])
            ->expectsOutputToContain('Connect Meta first')
            ->assertExitCode(1);
    }

    public function test_source_files_lists_postable_files_with_any_names(): void
    {
        File::ensureDirectoryExists($this->dir.'/a&b');
        file_put_contents($this->dir.'/a&b/फोटो.jpg', 'jpeg');
        file_put_contents($this->dir.'/x.wav', 'wav');
        file_put_contents($this->dir.'/y.wfp', 'wfp');
        $old = time() - 3600;
        $all = [$this->photo(), $this->video(), $this->dir.'/a&b/फोटो.jpg', $this->dir.'/x.wav', $this->dir.'/y.wfp'];
        foreach ($all as $f) {
            touch($f, $old);
        }
        clearstatcache();

        $files = SourceFiles::list($this->dir);

        $this->assertSame([
            '9 Product ownsr/Moggies International your trusted Partener !.mp4',
            'Moggies Main1 !.jpg',
            'a&b/फोटो.jpg',
        ], array_keys($files));
        $this->assertFileExists($files['a&b/फोटो.jpg']);
        clearstatcache();
        foreach ($all as $f) {
            $this->assertSame($old, filemtime($f));
        }
    }

    public function test_test_send_page_offers_photos_and_videos_from_the_folder(): void
    {
        config(['smm.library_source' => $this->dir]);
        $this->actingAs(User::where('email', User::LOCAL_OWNER_EMAIL)->firstOrFail());
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->brand);

        Livewire::test(TestSend::class)
            ->assertSee('Test send')
            ->assertSee('nothing is published');
    }
}
