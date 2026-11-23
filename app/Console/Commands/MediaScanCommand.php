<?php

namespace App\Console\Commands;

use App\Domain\Library\LibraryScanner;
use App\Models\Brand;
use Illuminate\Console\Command;

/**
 * Looks for new, changed, moved and missing files in each brand's source folders (read-only). Runs when the
 * app opens (start.ps1, in the background), in the daily jobs, and from the Library's "Look for new files".
 */
class MediaScanCommand extends Command
{
    protected $signature = 'smm:media:scan {--brand= : Only this brand (its web address name)}';

    protected $description = 'Library: look for new, changed, moved and missing files in the source folders (read only)';

    public function handle(LibraryScanner $scanner): int
    {
        $brands = Brand::query()->when($this->option('brand'), fn ($q, $slug) => $q->where('slug', $slug))->get();

        foreach ($brands as $brand) {
            $this->line($scanner->scan($brand)->summary());
        }

        return self::SUCCESS;
    }
}
