<?php

namespace App\Domain\Meta;

use RuntimeException;

/** Thrown before any request when a copy that may not publish (Dev) tries to publish. */
class PublishBlocked extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Dev is a practice copy: it never publishes. Real posts go out only from the App copy.');
    }
}
