<?php

namespace Tests\Feature;

use App\Domain\Relay\RelayFolder;
use App\Filament\Pages\RelaySettings;
use App\Models\Brand;
use App\Models\Setting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class RelayFolderTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const BASE = 'https://moggiesinternational.com/mr-test/';

    private Filesystem $disk;

    private RelayFolder $relay;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disk = Storage::fake('relay');
        $this->relay = new RelayFolder($this->disk, self::BASE);
    }

    /** A stand-in for the website: serves the folder's files, refuses the folder itself. */
    private function fakeWebsite(array $headers = ['X-Robots-Tag' => 'noindex, nofollow'], int $folderStatus = 403, ?string $folderBody = null, bool $cacheKeepsFiles = false, int $fileStatus = 200): void
    {
        $served = [];
        Http::fake(function (Request $request) use ($headers, $folderStatus, $folderBody, $cacheKeepsFiles, $fileStatus, &$served) {
            $url = $request->url();
            if ($url === self::BASE) {
                return Http::response($folderBody ?? 'Forbidden', $folderStatus);
            }
            $name = basename(parse_url($url, PHP_URL_PATH));
            if ($this->disk->exists($name)) {
                $served[$name] = $this->disk->get($name);

                return Http::response($served[$name], $fileStatus, $headers);
            }
            if ($cacheKeepsFiles && isset($served[$name])) {
                return Http::response($served[$name], 200, $headers);
            }

            return Http::response('Not found', 404);
        });
    }

    public function test_put_uses_random_name_and_public_url(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'smm').'.jpg';
        file_put_contents($file, 'jpeg-bytes');

        $uploaded = $this->relay->put($file);
        @unlink($file);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}\.jpg$/', $uploaded['name']);
        $this->assertSame(self::BASE.$uploaded['name'], $uploaded['url']);
        $this->assertSame('jpeg-bytes', $this->disk->get($uploaded['name']));
    }

    public function test_put_refuses_other_file_types(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'smm').'.php';
        file_put_contents($file, '<?php echo 1;');

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->relay->put($file);
        } finally {
            @unlink($file);
            $this->assertSame([], $this->disk->allFiles());
        }
    }

    public function test_folder_test_passes_and_leaves_only_the_lock_file(): void
    {
        $this->fakeWebsite();

        $result = $this->relay->test();

        $this->assertTrue($result['ok'], json_encode($result['steps']));
        $this->assertCount(6, $result['steps']);
        $this->assertSame(['.htaccess'], $this->disk->allFiles());
        $this->assertStringContainsString('X-Robots-Tag "noindex, nofollow"', $this->disk->get('.htaccess'));
        $this->assertTrue(Setting::get('relay.last_test')['ok']);
    }

    public function test_folder_test_fails_without_noindex_header(): void
    {
        $this->fakeWebsite(headers: []);

        $result = $this->relay->test();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('lock file is not working', json_encode($result['steps']));
        $this->assertSame(['.htaccess'], $this->disk->allFiles());
    }

    public function test_folder_test_fails_when_listing_shows_files(): void
    {
        // A listing page that names every file in the folder.
        Http::fake(function (Request $request) {
            if ($request->url() === self::BASE) {
                return Http::response('Index of /mr-test: '.implode(' ', $this->disk->allFiles()), 200);
            }
            $name = basename(parse_url($request->url(), PHP_URL_PATH));

            return $this->disk->exists($name)
                ? Http::response($this->disk->get($name), 200, ['X-Robots-Tag' => 'noindex, nofollow'])
                : Http::response('Not found', 404);
        });

        $result = $this->relay->test();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('can be listed', json_encode($result['steps']));
        $this->assertSame(['.htaccess'], $this->disk->allFiles());
    }

    public function test_cached_copy_is_a_note_not_a_failure(): void
    {
        $this->fakeWebsite(cacheKeepsFiles: true);

        $result = $this->relay->test();

        $this->assertTrue($result['ok'], json_encode($result['steps']));
        $this->assertStringContainsString('cache', (string) end($result['steps'])['note']);
    }

    public function test_failed_download_still_deletes_the_test_file(): void
    {
        $this->fakeWebsite(fileStatus: 500);

        $result = $this->relay->test();

        $this->assertFalse($result['ok']);
        $this->assertSame(['.htaccess'], $this->disk->allFiles());
    }

    public function test_relay_password_kept_when_left_blank_and_never_shown(): void
    {
        $this->actingAs(User::where('email', User::LOCAL_OWNER_EMAIL)->firstOrFail());
        Filament::setCurrentPanel('app');
        Filament::setTenant(Brand::where('slug', 'moggies-international')->firstOrFail());
        $data = ['host' => 'ftp.moggiesinternational.com', 'port' => 21, 'username' => 'u946790164.relay', 'password' => 'ftp-pass-123', 'public_url' => 'https://moggiesinternational.com/mr-test', 'ftps' => true];

        Livewire::test(RelaySettings::class)
            ->callAction('saveRelay', data: [...$data, 'public_url' => 'http://moggiesinternational.com/mr-test'])
            ->assertHasActionErrors(['public_url']);

        Livewire::test(RelaySettings::class)->callAction('saveRelay', data: $data)->assertHasNoActionErrors();
        Livewire::test(RelaySettings::class)->callAction('saveRelay', data: [...$data, 'password' => ''])->assertHasNoActionErrors();

        $this->assertSame('ftp-pass-123', Setting::getSecret('relay.password'));
        $this->assertSame(self::BASE, Setting::get('relay.public_url'));
        $this->assertStringNotContainsString('ftp-pass-123', (string) \DB::table('settings')->where('key', 'relay.password')->value('value'));
        Livewire::test(RelaySettings::class)
            ->assertDontSee('ftp-pass-123')
            ->assertSee('u946790164.relay');
    }
}
