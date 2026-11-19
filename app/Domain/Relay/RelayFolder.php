<?php

namespace App\Domain\Relay;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The hidden folder on moggiesinternational.com that Instagram downloads photos and videos from.
 * The app reaches it with an FTP account locked to that folder; every file gets a random name and is
 * deleted by the same operation that uploaded it. The folder's .htaccess (scripts/hostinger) stops
 * listing, indexing and scripts.
 */
final class RelayFolder
{
    public const ALLOWED = ['jpg', 'jpeg', 'png', 'webp', 'mp4', 'mov', 'txt'];

    private readonly string $publicUrl;

    public function __construct(private readonly Filesystem $disk, string $publicUrl)
    {
        $this->publicUrl = rtrim($publicUrl, '/').'/';
    }

    /** The folder from the saved settings, or null until the owner has set it up. */
    public static function fromSettings(): ?self
    {
        $host = Setting::get('relay.host');
        $username = Setting::get('relay.username');
        $publicUrl = Setting::get('relay.public_url');
        $password = Setting::getSecret('relay.password');
        if (! $host || ! $username || ! $publicUrl || $password === null) {
            return null;
        }

        $disk = Storage::build([
            'driver' => 'ftp',
            'host' => $host,
            'port' => (int) (Setting::get('relay.port') ?: 21),
            'username' => $username,
            'password' => $password,
            'root' => '/',
            'ssl' => (bool) Setting::get('relay.ftps', true),
            'passive' => true,
            'timeout' => 30,
            'throw' => true,
        ]);

        return new self($disk, $publicUrl);
    }

    public function publicUrl(): string
    {
        return $this->publicUrl;
    }

    public function url(string $name): string
    {
        return $this->publicUrl.rawurlencode($name);
    }

    /** @return array{name: string, url: string} */
    public function put(string $localPath): array
    {
        $extension = strtolower(pathinfo($localPath, PATHINFO_EXTENSION));
        if (! in_array($extension, self::ALLOWED, true)) {
            throw new InvalidArgumentException("Only photos and videos can go to the Hostinger folder, not .{$extension} files.");
        }
        $handle = @fopen($localPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The file could not be read: '.basename($localPath));
        }

        $name = bin2hex(random_bytes(16)).'.'.$extension;
        try {
            if ($this->disk->writeStream($name, $handle) === false) {
                throw new RuntimeException('The upload to the Hostinger folder did not finish.');
            }
        } catch (Throwable $e) {
            // A half-finished upload must not stay in the folder.
            try {
                $this->disk->delete($name);
            } catch (Throwable) {
                // The daily sweep removes it if this fails too.
            }
            throw $e;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        return ['name' => $name, 'url' => $this->url($name)];
    }

    /**
     * Deletes leftovers (anything but the lock file) older than $hours: files from a crash, a power cut
     * or an interrupted upload. Returns how many were removed.
     */
    public function sweep(int $hours = 2): int
    {
        $cutoff = time() - $hours * 3600;
        $removed = 0;
        foreach ($this->disk->files() as $name) {
            if ($name === '.htaccess') {
                continue;
            }
            try {
                if ($this->disk->lastModified($name) <= $cutoff) {
                    $this->disk->delete($name);
                    $removed++;
                }
            } catch (Throwable) {
                // Left for the next sweep.
            }
        }

        return $removed;
    }

    public function delete(string $name): void
    {
        $this->disk->delete($name);
    }

    public function exists(string $name): bool
    {
        return $this->disk->exists($name);
    }

    /** Writes the folder's lock file (no listing, no indexing, no scripts) from scripts/hostinger. */
    public function ensureHtaccess(): void
    {
        $lock = (string) file_get_contents(base_path('scripts/hostinger/relay.htaccess'));
        if (! $this->disk->exists('.htaccess') || $this->disk->get('.htaccess') !== $lock) {
            $this->disk->put('.htaccess', $lock);
        }
    }

    /**
     * Uploads a tiny file, downloads it from the website, checks the folder can't be listed, deletes the
     * file and confirms it is gone. The test file is removed even when a step fails.
     *
     * @return array{ok: bool, at: string, steps: list<array{label: string, ok: bool, note: ?string}>}
     */
    public function test(): array
    {
        $steps = [];
        $name = null;
        $deleted = false;
        $label = 'Folder lock in place';

        try {
            $this->ensureHtaccess();
            $swept = $this->sweep();
            $steps[] = ['label' => $label, 'ok' => true, 'note' => $swept > 0 ? "Removed {$swept} old file(s) left from earlier." : null];

            $label = 'Upload a test file';
            $name = bin2hex(random_bytes(16)).'.txt';
            $body = 'SocialMedia Manager folder test '.bin2hex(random_bytes(8));
            if ($this->disk->put($name, $body) === false) {
                throw new RuntimeException('The upload did not finish.');
            }
            $steps[] = ['label' => $label, 'ok' => true, 'note' => null];

            $label = 'Download it from the website';
            $response = $this->fetch($this->url($name));
            if (! $response->successful() || $response->body() !== $body) {
                throw new RuntimeException("The website answered {$response->status()} instead of the test file: check the public address.");
            }
            if (! str_contains(strtolower((string) $response->header('X-Robots-Tag')), 'noindex')) {
                throw new RuntimeException("The folder's lock file is not working (no 'noindex' header).");
            }
            $steps[] = ['label' => $label, 'ok' => true, 'note' => null];

            $label = "The folder can't be listed";
            $listing = $this->fetch($this->publicUrl);
            if ($listing->status() === 200 && str_contains($listing->body(), $name)) {
                throw new RuntimeException('The folder can be listed: anyone could see its files. Check its .htaccess.');
            }
            $steps[] = ['label' => $label, 'ok' => true, 'note' => null];

            $label = 'Delete it';
            $this->disk->delete($name);
            $deleted = true;
            $steps[] = ['label' => $label, 'ok' => true, 'note' => null];

            $label = "It's gone";
            if ($this->disk->exists($name)) {
                throw new RuntimeException('The file is still in the folder after deleting it.');
            }
            $note = $this->fetch($this->url($name))->successful()
                ? "The website's cache may show it for a few minutes; the folder itself is empty."
                : null;
            $steps[] = ['label' => $label, 'ok' => true, 'note' => $note];
        } catch (Throwable $e) {
            $steps[] = ['label' => $label, 'ok' => false, 'note' => $this->plain($e)];
        } finally {
            if ($name !== null && ! $deleted) {
                try {
                    $this->disk->delete($name);
                } catch (Throwable) {
                    // Reported by the failed step; the next test removes nothing else.
                }
            }
        }

        $result = [
            'ok' => count($steps) === 6 && array_reduce($steps, fn (bool $all, array $s): bool => $all && $s['ok'], true),
            'at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'steps' => $steps,
        ];
        Setting::put('relay.last_test', $result);

        return $result;
    }

    private function fetch(string $url): Response
    {
        return Http::timeout(30)->withHeaders(['Cache-Control' => 'no-cache'])->get($url);
    }

    private function plain(Throwable $e): string
    {
        return match (true) {
            $e instanceof ConnectionException => 'Could not reach the website: no internet connection, or the public address is wrong.',
            $e instanceof RuntimeException && ! str_starts_with($e::class, 'League\\') => $e->getMessage(),
            default => 'The Hostinger folder could not be reached: '.$e->getMessage(),
        };
    }
}
