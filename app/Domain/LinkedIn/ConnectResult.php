<?php

namespace App\Domain\LinkedIn;

use App\Models\ConnectedAccount;

/** What connecting the LinkedIn page did: the saved account, or the reasons it was refused. */
final readonly class ConnectResult
{
    /**
     * @param  list<string>  $problems  Any problem means nothing was saved.
     * @param  list<string>  $warnings
     */
    public function __construct(
        public ?ConnectedAccount $account,
        public array $problems = [],
        public array $warnings = [],
    ) {}

    public static function refused(string $problem): self
    {
        return new self(null, [$problem]);
    }
}
