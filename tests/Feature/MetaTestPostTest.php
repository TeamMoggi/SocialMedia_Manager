<?php

namespace Tests\Feature;

use App\Domain\Meta\MetaGraph;
use App\Enums\Network;
use App\Models\Brand;
use App\Models\ConnectedAccount;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaTestPostTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const POST_ID = '1510742765451658_99';

    protected function setUp(): void
    {
        parent::setUp();
        ConnectedAccount::create([
            'brand_id' => Brand::where('slug', 'moggies-international')->firstOrFail()->id,
            'network' => Network::FacebookPage, 'external_id' => '1510742765451658',
            'name' => 'Moggies International', 'access_token' => 'page-tok',
        ]);
    }

    private function live(): void
    {
        app()->instance(MetaGraph::class, new MetaGraph('sec', 'live'));
    }

    /** A stand-in for the Page: the post exists until it is deleted. */
    private function fakePage(): void
    {
        $deleted = false;
        Http::fake(function (Request $r) use (&$deleted) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);

            return match (true) {
                $r->method() === 'POST' && str_ends_with($path, '/1510742765451658/feed') => Http::response(['id' => self::POST_ID]),
                $r->method() === 'DELETE' && str_ends_with($path, '/'.self::POST_ID) => (function () use (&$deleted) {
                    $deleted = true;

                    return Http::response(['success' => true]);
                })(),
                $r->method() === 'GET' && str_ends_with($path, '/'.self::POST_ID) => $deleted
                    ? Http::response(['error' => ['message' => 'Unsupported get request.', 'code' => 100]], 400)
                    : Http::response(['id' => self::POST_ID, 'permalink_url' => 'https://www.facebook.com/61596626701560/posts/99', 'is_published' => true]),
                default => Http::response(['error' => ['message' => 'unexpected', 'code' => 100]], 400),
            };
        });
    }

    private function feedPosts(): int
    {
        return Http::recorded(fn (Request $r): bool => $r->method() === 'POST' && str_contains($r->url(), '/feed'))->count();
    }

    public function test_dev_refuses_the_test_post(): void
    {
        Http::fake();

        $this->artisan('smm:meta:test-post')
            ->expectsOutputToContain('Only the App copy posts for real')
            ->assertExitCode(1);

        Http::assertNothingSent();
        $this->assertNull(Setting::get('meta.test_post'));
    }

    public function test_live_creates_then_deletes(): void
    {
        $this->live();
        $this->fakePage();

        $this->artisan('smm:meta:test-post')
            ->expectsOutputToContain('https://www.facebook.com/61596626701560/posts/99')
            ->assertExitCode(0);
        $this->assertSame(self::POST_ID, Setting::get('meta.test_post')['id']);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/feed') && str_contains((string) $r['message'], 'Test from SocialMedia Manager'));

        $this->artisan('smm:meta:test-post')
            ->expectsOutputToContain('Delete the earlier test post first')
            ->assertExitCode(1);
        $this->assertSame(1, $this->feedPosts());

        $this->artisan('smm:meta:test-post', ['--delete' => true])
            ->expectsOutputToContain('Test post deleted.')
            ->assertExitCode(0);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_contains($r->url(), self::POST_ID));
        $this->assertNull(Setting::get('meta.test_post'));
    }

    public function test_cleanup_without_a_test_post_is_silent(): void
    {
        Http::fake();

        $this->artisan('smm:meta:test-post', ['--cleanup' => true])
            ->doesntExpectOutputToContain('deleted')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_daily_cleans_up_a_leftover_test_post(): void
    {
        $this->live();
        $this->fakePage();
        Setting::put('meta.test_post', ['id' => self::POST_ID, 'account_id' => ConnectedAccount::firstOrFail()->id, 'permalink' => 'x', 'ref' => 'abc123', 'created_at' => now()->subHour()->toIso8601String()]);

        $this->artisan('smm:daily')->assertExitCode(0);

        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_contains($r->url(), self::POST_ID));
        $this->assertNull(Setting::get('meta.test_post'));
    }
}
