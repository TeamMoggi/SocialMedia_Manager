<?php

namespace App\Console\Commands;

use App\Domain\Meta\MetaError;
use App\Domain\Meta\MetaGraph;
use App\Enums\Network;
use App\Models\ConnectedAccount;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * The one visible Facebook test post the owner agreed to (18 Nov 2026): proves that posts made by the
 * app are public once the Meta app is Live. Runs only from App; deleted within minutes with --delete,
 * and the daily jobs delete a leftover one (--cleanup).
 */
class MetaTestPostCommand extends Command
{
    protected $signature = 'smm:meta:test-post
        {--delete : Delete the test post made earlier}
        {--cleanup : Delete a leftover test post if there is one, silently otherwise}';

    protected $description = 'Make (or delete) one visible Facebook test post, from the App copy only';

    private const SETTING = 'meta.test_post';

    public function handle(MetaGraph $graph): int
    {
        if ($this->option('delete') || $this->option('cleanup')) {
            return $this->deletePost($graph, quietIfNone: (bool) $this->option('cleanup'));
        }

        if ($graph->mode() !== 'live') {
            $this->error('Only the App copy posts for real; Dev is a practice copy.');

            return self::FAILURE;
        }
        if (Setting::get(self::SETTING) !== null) {
            $this->error('Delete the earlier test post first (--delete).');

            return self::FAILURE;
        }
        $page = ConnectedAccount::with('brand')->where('network', Network::FacebookPage)->first();
        if ($page === null) {
            $this->error('Connect Meta first: save the Meta key on the Meta page.');

            return self::FAILURE;
        }

        $ref = bin2hex(random_bytes(3));
        $now = CarbonImmutable::now($page->brand->timezone ?? config('smm.display_timezone'));
        try {
            $id = (string) $graph->post("{$page->external_id}/feed", [
                'message' => "Test from SocialMedia Manager, {$now->format('j M Y, H:i T')} (ref {$ref}). It will be deleted in a few minutes.",
            ], $page->access_token)['id'];
            $post = $graph->get($id, ['fields' => 'permalink_url,is_published'], $page->access_token);
        } catch (MetaError $e) {
            $this->error('The test post was not made: '.$e->plain());

            return self::FAILURE;
        }

        Setting::put(self::SETTING, [
            'id' => $id,
            'account_id' => $page->id,
            'permalink' => $post['permalink_url'] ?? null,
            'ref' => $ref,
            'created_at' => CarbonImmutable::now('UTC')->toIso8601String(),
        ]);
        $this->info('Test post made: '.($post['permalink_url'] ?? $id));
        $this->line("Reference to look for: {$ref}. Delete it within 10 minutes: php artisan smm:meta:test-post --delete");

        return self::SUCCESS;
    }

    private function deletePost(MetaGraph $graph, bool $quietIfNone): int
    {
        $saved = Setting::get(self::SETTING);
        if ($saved === null) {
            if (! $quietIfNone) {
                $this->line('No test post to delete.');
            }

            return self::SUCCESS;
        }

        $page = ConnectedAccount::find($saved['account_id'] ?? null)
            ?? ConnectedAccount::where('network', Network::FacebookPage)->first();
        if ($page === null) {
            $this->error('The Facebook Page is no longer connected: delete the test post by hand on the Page.');

            return self::FAILURE;
        }

        try {
            $graph->delete($saved['id'], $page->access_token);
        } catch (MetaError $e) {
            $this->error('The test post could not be deleted: '.$e->plain());

            return self::FAILURE;
        }

        try {
            $graph->get($saved['id'], ['fields' => 'id'], $page->access_token);
            $this->error('Facebook still shows the test post: check the Page and delete it by hand.');

            return self::FAILURE;
        } catch (MetaError) {
            // Gone, as it should be.
        }

        Setting::query()->whereKey(self::SETTING)->delete();
        $this->info('Test post deleted.');

        return self::SUCCESS;
    }
}
