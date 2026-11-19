<?php

namespace Tests\Unit;

use App\Domain\Meta\MetaCredentials;
use App\Domain\Meta\MetaError;
use App\Domain\Meta\MetaGraph;
use App\Domain\Meta\PublishBlocked;
use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MetaGraphTest extends TestCase
{
    public function test_adds_version_token_and_appsecret_proof(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        (new MetaGraph('sec', 'dry-run'))->get('me/accounts', ['fields' => 'id'], 'tok');

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_starts_with($request->url(), 'https://graph.facebook.com/v27.0/me/accounts')
                && $query['access_token'] === 'tok'
                && $query['appsecret_proof'] === hash_hmac('sha256', 'tok', 'sec')
                && $query['fields'] === 'id';
        });
    }

    public static function publishingCalls(): array
    {
        return [
            'page feed' => ['1510742765451658/feed', []],
            'instagram publish' => ['17841470732347612/media_publish', ['creation_id' => '1']],
            'photo without published flag' => ['1/photos', []],
            'video published' => ['1/videos', ['published' => 'true']],
            'scheduled photo' => ['1/photos', ['published' => false, 'scheduled_publish_time' => 1795100000]],
            'scheduled video' => ['1/videos', ['published' => 'false', 'unpublished_content_type' => 'SCHEDULED']],
            'publish an earlier upload' => ['1234567890', ['published' => true]],
            'any other write' => ['1510742765451658/comments', ['message' => 'hi']],
        ];
    }

    public function test_secrets_are_hidden_from_stack_traces(): void
    {
        $sensitive = fn (string $class, string $method, string $param): bool => collect((new \ReflectionMethod($class, $method))->getParameters())
            ->first(fn (\ReflectionParameter $p): bool => $p->getName() === $param)
            ?->getAttributes(\SensitiveParameter::class) !== [];

        foreach (['get', 'post', 'delete', 'upload'] as $method) {
            $this->assertTrue($sensitive(MetaGraph::class, $method, 'token'), "MetaGraph::{$method} \$token");
        }
        $this->assertTrue($sensitive(MetaGraph::class, '__construct', 'appSecret'));
        $this->assertTrue($sensitive(MetaCredentials::class, 'saveAppSecret', 'secret'));
        $this->assertTrue($sensitive(MetaCredentials::class, 'saveKey', 'key'));
        $this->assertTrue($sensitive(Setting::class, 'putSecret', 'value'));
    }

    #[DataProvider('publishingCalls')]
    public function test_dry_run_blocks_publishing_before_sending(string $path, array $params): void
    {
        Http::fake();

        try {
            (new MetaGraph('sec', 'dry-run'))->post($path, $params, 'tok');
            $this->fail('A publishing call was allowed in dry-run.');
        } catch (PublishBlocked $e) {
            $this->assertStringContainsString('never publishes', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_dry_run_allows_unpublished_uploads_and_containers(): void
    {
        Http::fake(['*' => Http::response(['id' => '9'])]);
        $graph = new MetaGraph('sec', 'dry-run');

        $graph->post('1/photos', ['published' => false], 'tok');
        $graph->post('17841470732347612/media', ['image_url' => 'https://x/a.jpg'], 'tok');

        Http::assertSentCount(2);
    }

    public function test_live_mode_allows_publishing(): void
    {
        Http::fake(['*' => Http::response(['id' => '1_2'])]);

        $result = (new MetaGraph('sec', 'live'))->post('1/feed', ['message' => 'hi'], 'tok');

        $this->assertSame('1_2', $result['id']);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/v27.0/1/feed') && $r['message'] === 'hi');
    }

    public function test_meta_error_is_plain_and_never_contains_the_token(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.', 'code' => 190, 'fbtrace_id' => 'A1']], 400)]);

        try {
            (new MetaGraph('sec', 'dry-run'))->get('me', [], 'tok-secret-123');
            $this->fail('No MetaError thrown.');
        } catch (MetaError $e) {
            $this->assertSame(190, $e->code());
            $this->assertFalse($e->transient());
            $this->assertStringContainsString('make a new key', $e->plain());
            $this->assertStringNotContainsString('tok-secret-123', $e->getMessage());
            $this->assertStringNotContainsString('tok-secret-123', $e->plain());
        }
    }

    public function test_connection_failure_is_transient(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host graph.facebook.com?access_token=tok-secret-123'));

        try {
            (new MetaGraph('sec', 'dry-run'))->get('me', [], 'tok-secret-123');
            $this->fail('No MetaError thrown.');
        } catch (MetaError $e) {
            $this->assertTrue($e->transient());
            $this->assertStringContainsString('no internet connection', $e->plain());
            $this->assertStringNotContainsString('tok-secret-123', $e->getMessage());
        }
    }

    public function test_upload_sends_file_as_multipart_to_video_host(): void
    {
        Http::fake(['*' => Http::response(['id' => '77'])]);
        $file = tempnam(sys_get_temp_dir(), 'smm');
        file_put_contents($file, 'video-bytes');

        try {
            $result = (new MetaGraph('sec', 'dry-run'))->upload('1/videos', ['published' => false], $file, 'tok', true);
        } finally {
            @unlink($file);
        }

        $this->assertSame('77', $result['id']);
        Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), 'https://graph-video.facebook.com/v27.0/1/videos')
            && $r->isMultipart());
    }
}
