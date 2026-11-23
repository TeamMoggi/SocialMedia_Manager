<?php

namespace App\Console\Commands;

use App\Domain\Library\LabelImport;
use App\Models\Brand;
use Illuminate\Console\Command;
use InvalidArgumentException;

/** Claude's labelling pass: applies a labels file (JSON) to the Library. Labels and folder names only. */
class MediaLabelCommand extends Command
{
    protected $signature = 'smm:media:label
        {file : A labels file (JSON) prepared by Claude}
        {--brand=moggies-international : The brand (its web address name)}';

    protected $description = "Library: apply Claude's labels file (folder names and file labels only, logged as by Claude)";

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("No file at {$path}.");

            return self::FAILURE;
        }
        $data = json_decode((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) file_get_contents($path)), true);
        if (! is_array($data)) {
            $this->error('The labels file is not valid JSON. Nothing was changed.');

            return self::FAILURE;
        }
        $brand = Brand::where('slug', $this->option('brand'))->first();
        if ($brand === null) {
            $this->error("No brand '{$this->option('brand')}'.");

            return self::FAILURE;
        }

        try {
            $result = LabelImport::apply($brand, $data);
        } catch (InvalidArgumentException $e) {
            $this->error('Nothing was changed. Fix these in the labels file:');
            foreach (explode("\n", $e->getMessage()) as $line) {
                $this->line('  '.$line);
            }

            return self::FAILURE;
        }

        $files = $result['items'] === 1 ? '1 file' : "{$result['items']} files";
        $folders = $result['groups'] === 1 ? '1 folder' : "{$result['groups']} folders";
        $this->info("Labels: {$files} and {$folders} changed.");
        if ($result['unknown'] !== []) {
            $this->warn('Not found: '.implode(', ', $result['unknown']));
        }

        return self::SUCCESS;
    }
}
