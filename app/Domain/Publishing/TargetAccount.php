<?php

namespace App\Domain\Publishing;

use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use RuntimeException;

/** The connected Facebook Page or Instagram account a post's version is sent through. */
final class TargetAccount
{
    public static function for(PostTarget $target): ConnectedAccount
    {
        $account = $target->connectedAccount
            ?? ConnectedAccount::query()
                ->where('brand_id', $target->post->brand_id)
                ->where('network', $target->network)
                ->first();

        return $account ?? throw new RuntimeException('The account for this post is not connected: connect it on the Meta page.');
    }
}
