<?php

namespace App\Console\Commands;

use App\Domain\Meta\MetaGraph;
use App\Domain\Meta\Rehearsal;
use App\Domain\Relay\RelayFolder;
use App\Models\Brand;
use Illuminate\Console\Command;

class RehearseCommand extends Command
{
    protected $signature = 'smm:meta:rehearse
        {--photo= : Full path of a .jpg photo}
        {--video= : Full path of an .mp4 or .mov video}
        {--brand=moggies-international : The brand (its web address name)}';

    protected $description = 'Test send: take a photo and a video through every step to Instagram and Facebook without publishing';

    public function handle(): int
    {
        $brand = Brand::where('slug', $this->option('brand'))->first();
        if ($brand === null) {
            $this->error('No brand '.$this->option('brand').'.');

            return self::FAILURE;
        }

        set_time_limit(0);
        $result = (new Rehearsal(app(MetaGraph::class), app(RelayFolder::class)))
            ->run($brand, (string) $this->option('photo'), (string) $this->option('video'));

        foreach ($result['steps'] as $step) {
            $this->line(($step['ok'] ? '[ok]     ' : '[FAILED] ').$step['label'].($step['note'] ? ': '.$step['note'] : ''));
        }
        $this->line($result['ok'] ? 'Test send passed: nothing was published.' : 'Test send failed: see the steps above. Nothing was published.');

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
