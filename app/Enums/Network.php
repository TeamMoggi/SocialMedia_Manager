<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum Network: string implements HasLabel
{
    case FacebookPage = 'facebook_page';
    case Instagram = 'instagram';
    case LinkedInOrganization = 'linkedin_org';

    public function label(): string
    {
        return match ($this) {
            self::FacebookPage => 'Facebook Page',
            self::Instagram => 'Instagram',
            self::LinkedInOrganization => 'LinkedIn company page',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
