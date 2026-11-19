<?php

namespace App\Domain\Meta;

use App\Models\Setting;
use App\Support\PublishGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The one way the app talks to Meta's Graph API (Facebook Page and Instagram).
 *
 * Every call carries the API version from config('smm.meta') and, when the App Secret is saved, an
 * appsecret_proof. In dry-run (always in Dev, see PublishGuard) any call that would publish is refused
 * before a request is sent, so every publish path inherits the Dev guard.
 */
final class MetaGraph
{
    public function __construct(private readonly ?string $appSecret, private readonly string $mode) {}

    public static function make(): self
    {
        return new self(
            Setting::getSecret('meta.app_secret'),
            PublishGuard::effectiveMode((string) config('smm.publish_mode'), base_path()),
        );
    }

    /** 'live' or 'dry-run'. */
    public function mode(): string
    {
        return $this->mode;
    }

    /** True for calls that make something visible on a network. */
    public static function isPublishing(string $method, string $path, array $params): bool
    {
        if (strtoupper($method) !== 'POST') {
            return false;
        }
        $path = rtrim(strtolower((string) (parse_url($path, PHP_URL_PATH) ?? $path)), '/');
        if (str_ends_with($path, '/feed') || str_ends_with($path, '/media_publish')) {
            return true;
        }
        if (array_key_exists('is_published', $params) && ! self::isFalse($params['is_published'])) {
            return true;
        }
        if (str_ends_with($path, '/photos') || str_ends_with($path, '/videos')) {
            return ! array_key_exists('published', $params) || ! self::isFalse($params['published']);
        }

        return false;
    }

    public function get(string $path, array $query, string $token): array
    {
        return $this->send('GET', $path, $query, $token);
    }

    public function post(string $path, array $params, string $token): array
    {
        return $this->send('POST', $path, $params, $token);
    }

    public function delete(string $path, string $token): array
    {
        return $this->send('DELETE', $path, [], $token);
    }

    /** Uploads a file as the multipart field "source" (photos and videos straight from the laptop). */
    public function upload(string $path, array $params, string $filePath, string $token, bool $video = false): array
    {
        $this->guard('POST', $path, $params);
        $handle = @fopen($filePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The file could not be read: '.basename($filePath));
        }

        try {
            $response = $this->client(600)
                ->attach('source', $handle, basename($filePath))
                ->post($this->url($path, $video), $this->withAuth($params, $token));
        } catch (ConnectionException) {
            throw MetaError::offline();
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        return $this->decode($response);
    }

    private function send(string $method, string $path, array $params, string $token): array
    {
        $this->guard($method, $path, $params);
        $data = $this->withAuth($params, $token);

        try {
            $response = match ($method) {
                'POST' => $this->client(30)->asForm()->post($this->url($path), $data),
                'DELETE' => $this->client(30)->delete($this->url($path).'?'.http_build_query($data)),
                default => $this->client(30)->get($this->url($path).'?'.http_build_query($data)),
            };
        } catch (ConnectionException) {
            throw MetaError::offline();
        }

        return $this->decode($response);
    }

    private function guard(string $method, string $path, array $params): void
    {
        if ($this->mode !== 'live' && self::isPublishing($method, $path, $params)) {
            throw new PublishBlocked;
        }
    }

    private function client(int $timeout): PendingRequest
    {
        return Http::timeout($timeout)->acceptJson();
    }

    private function url(string $path, bool $video = false): string
    {
        $base = rtrim((string) config($video ? 'smm.meta.video_url' : 'smm.meta.graph_url'), '/');

        return $base.'/'.config('smm.meta.graph_version').'/'.ltrim($path, '/');
    }

    /** Adds the token and its proof, and turns booleans and lists into the strings Meta expects. */
    private function withAuth(array $params, string $token): array
    {
        $data = [];
        foreach ($params as $key => $value) {
            $data[$key] = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                is_array($value) => json_encode($value),
                default => $value,
            };
        }
        $data['access_token'] = $token;
        if ($this->appSecret !== null && $this->appSecret !== '') {
            $data['appsecret_proof'] = hash_hmac('sha256', $token, $this->appSecret);
        }

        return $data;
    }

    private function decode(Response $response): array
    {
        $json = $response->json();
        if ($response->failed() || (is_array($json) && isset($json['error']))) {
            throw MetaError::fromResponse(is_array($json) && is_array($json['error'] ?? null) ? $json['error'] : [], $response->status());
        }

        return is_array($json) ? $json : [];
    }

    private static function isFalse(mixed $value): bool
    {
        return in_array($value, [false, 'false', 0, '0'], true);
    }
}
