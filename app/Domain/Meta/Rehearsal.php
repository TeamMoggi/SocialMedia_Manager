<?php

namespace App\Domain\Meta;

use App\Domain\Relay\RelayFolder;
use App\Enums\Network;
use App\Models\Brand;
use App\Models\ConnectedAccount;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Closure;
use RuntimeException;
use Throwable;

/**
 * "Test send": takes one photo and one video through every step to Instagram and Facebook and stops
 * just before publishing. Instagram: Hostinger folder → container → ready (never media_publish).
 * Facebook: unpublished upload → read back → delete. Every uploaded file is removed again.
 */
final class Rehearsal
{
    private readonly Closure $sleep;

    /** @var list<array{label: string, ok: bool, note: ?string}> */
    private array $steps = [];

    public function __construct(private readonly MetaGraph $graph, private readonly ?RelayFolder $relay, ?Closure $sleep = null)
    {
        $this->sleep = $sleep ?? fn (int $seconds) => sleep($seconds);
    }

    /** @return array{ok: bool, at: string, copy: string, steps: list<array{label: string, ok: bool, note: ?string}>} */
    public function run(Brand $brand, string $photoPath, string $videoPath): array
    {
        $this->steps = [];
        $page = $brand->connectedAccounts()->where('network', Network::FacebookPage)->first();
        $instagram = $brand->connectedAccounts()->where('network', Network::Instagram)->first();

        $problem = match (true) {
            $page === null || $instagram === null => 'Connect Meta first: save the Meta key on the Meta page.',
            $this->relay === null => 'Set up the Hostinger folder first.',
            ! in_array(strtolower(pathinfo($photoPath, PATHINFO_EXTENSION)), ['jpg', 'jpeg'], true) || ! is_file($photoPath) => 'Pick a .jpg photo that exists.',
            ! in_array(strtolower(pathinfo($videoPath, PATHINFO_EXTENSION)), ['mp4', 'mov'], true) || ! is_file($videoPath) => 'Pick an .mp4 or .mov video that exists.',
            default => null,
        };

        if ($problem !== null) {
            $this->fail('Before starting', $problem);
        } else {
            $this->instagram('Instagram photo', $instagram, $photoPath, false);
            $this->instagram('Instagram video', $instagram, $videoPath, true);
            $this->facebook('Facebook photo', $page, $photoPath, false);
            $this->facebook('Facebook video', $page, $videoPath, true);
        }

        $result = [
            'ok' => array_reduce($this->steps, fn (bool $all, array $s): bool => $all && $s['ok'], true),
            'at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'copy' => basename(base_path()),
            'steps' => $this->steps,
        ];
        Setting::put('rehearsal.last', $result);

        return $result;
    }

    private function instagram(string $group, ConnectedAccount $account, string $path, bool $video): void
    {
        $uploaded = null;
        $label = "{$group}: copy to the Hostinger folder";
        try {
            $uploaded = $this->relay->put($path);
            $this->pass($label);

            $label = "{$group}: Instagram fetches and prepares it (not published)";
            $params = $video ? ['media_type' => 'REELS', 'video_url' => $uploaded['url']] : ['image_url' => $uploaded['url']];
            $container = (string) ($this->graph->post("{$account->external_id}/media", $params, $account->access_token)['id'] ?? '');
            if ($container === '') {
                throw new RuntimeException('Instagram gave no container id.');
            }
            $this->waitUntilReady($container, $account->access_token, $video ? 10 : 3, $video ? 600 : 60);
            $this->pass($label);
        } catch (Throwable $e) {
            $this->fail($label, $this->plain($e));
        } finally {
            if ($uploaded !== null) {
                try {
                    $this->relay->delete($uploaded['name']);
                    $this->pass("{$group}: removed from the Hostinger folder");
                } catch (Throwable $e) {
                    $this->fail("{$group}: removed from the Hostinger folder", 'Could not delete '.$uploaded['name'].': '.$this->plain($e));
                }
            }
        }
    }

    private function facebook(string $group, ConnectedAccount $page, string $path, bool $video): void
    {
        $id = null;
        $label = "{$group}: upload, not published";
        try {
            $id = (string) ($this->graph->upload("{$page->external_id}/".($video ? 'videos' : 'photos'), ['published' => false], $path, $page->access_token, $video)['id'] ?? '');
            if ($id === '') {
                $id = null;
                throw new RuntimeException('Facebook gave no id for the upload.');
            }
            $this->pass($label);

            $label = "{$group}: Facebook has it";
            $this->graph->get($id, ['fields' => $video ? 'id,status' : 'id'], $page->access_token);
            $this->pass($label);
        } catch (Throwable $e) {
            $this->fail($label, $this->plain($e));
        } finally {
            if ($id !== null) {
                try {
                    $this->graph->delete($id, $page->access_token);
                    $this->pass("{$group}: deleted again");
                } catch (Throwable $e) {
                    $this->fail("{$group}: deleted again", "Could not delete the unpublished upload {$id}: ".$this->plain($e));
                }
            }
        }
    }

    private function waitUntilReady(string $container, string $token, int $every, int $limit): void
    {
        for ($waited = 0; ; $waited += $every) {
            $status = $this->graph->get($container, ['fields' => 'status_code,status'], $token);
            $code = (string) ($status['status_code'] ?? '');
            if ($code === 'FINISHED') {
                return;
            }
            if (in_array($code, ['ERROR', 'EXPIRED'], true)) {
                throw new RuntimeException('Instagram could not prepare it: '.($status['status'] ?? $code));
            }
            if ($waited >= $limit) {
                throw new RuntimeException('Instagram took over '.intdiv($limit, 60).' minute(s) to prepare it.');
            }
            ($this->sleep)($every);
        }
    }

    private function plain(Throwable $e): string
    {
        return $e instanceof MetaError ? $e->plain() : $e->getMessage();
    }

    private function pass(string $label): void
    {
        $this->steps[] = ['label' => $label, 'ok' => true, 'note' => null];
    }

    private function fail(string $label, string $note): void
    {
        $this->steps[] = ['label' => $label, 'ok' => false, 'note' => $note];
    }
}
