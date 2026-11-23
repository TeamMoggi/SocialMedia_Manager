<?php

namespace App\Http\Controllers;

use App\Domain\Library\Thumbnails;
use App\Models\MediaItem;
use App\Models\PostMedia;
use Filament\Facades\Filament;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Shows library files in the app: a small thumbnail, or the photo or video itself (read straight from
 * Markting Social Media, never changed). Only the brand that owns a file can see it.
 */
class LibraryFileController extends Controller
{
    public function thumb(mixed $tenant, MediaItem $item): BinaryFileResponse
    {
        $this->allow($item);
        $path = Thumbnails::for($item);
        abort_if($path === null, 404);

        return response()->file($path, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=86400']);
    }

    public function file(mixed $tenant, MediaItem $item): BinaryFileResponse
    {
        $this->allow($item);
        abort_unless($item->kind->isAttachable() && is_file($item->fullPath()), 404);

        return response()->file($item->fullPath(), array_filter(['Content-Type' => $item->mime]));
    }

    /** The post's own copy of a photo (what will be sent), for the post's preview. */
    public function postMedia(mixed $tenant, PostMedia $media): BinaryFileResponse
    {
        $brand = Filament::getTenant();
        abort_if($brand === null || $media->post?->brand_id !== $brand->getKey(), 404);
        abort_unless(str_starts_with((string) $media->mime, 'image/') && filled($media->path) && is_file($media->fullPath()), 404);

        return response()->file($media->fullPath(), ['Content-Type' => $media->mime, 'Cache-Control' => 'private, max-age=86400']);
    }

    private function allow(MediaItem $item): void
    {
        $brand = Filament::getTenant();
        abort_if($brand === null || $item->brand_id !== $brand->getKey() || $item->isMissing(), 404);
    }
}
