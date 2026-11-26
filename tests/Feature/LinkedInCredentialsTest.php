<?php

namespace Tests\Feature;

use App\Domain\LinkedIn\LinkedInCredentials;
use App\Models\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LinkedInCredentialsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_client_id_defaults_to_config(): void
    {
        $this->assertSame('77qh6eszwh69s9', LinkedInCredentials::clientId());
        $this->assertNull(LinkedInCredentials::clientSecret());
        $this->assertNull(LinkedInCredentials::savedAt());
    }

    public function test_secret_is_encrypted_and_trimmed(): void
    {
        LinkedInCredentials::saveApp(' 77qh6eszwh69s9 ', " sec ret\n");

        $this->assertSame('77qh6eszwh69s9', LinkedInCredentials::clientId());
        $this->assertSame('secret', LinkedInCredentials::clientSecret());
        $this->assertNotNull(LinkedInCredentials::savedAt());
        $raw = DB::table('settings')->where('key', 'linkedin.client_secret')->value('value');
        $this->assertStringNotContainsString('secret', (string) $raw);
    }

    public function test_org_id_defaults_for_moggies_only(): void
    {
        $moggies = Brand::where('slug', 'moggies-international')->firstOrFail();
        $other = Brand::create(['name' => 'Other', 'slug' => 'other', 'timezone' => 'Asia/Kolkata']);

        $this->assertSame('145339181', LinkedInCredentials::orgId($moggies));
        $this->assertNull(LinkedInCredentials::orgId($other));

        LinkedInCredentials::saveOrgId($other, '999');
        $this->assertSame('999', LinkedInCredentials::orgId($other));
    }
}
