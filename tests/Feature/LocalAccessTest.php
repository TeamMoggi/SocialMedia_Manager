<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalAccessTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_panel_opens_without_login_from_loopback(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->followingRedirects()
            ->get('/');

        $response->assertOk();
        $this->assertAuthenticated();
        $this->assertSame('owner@smm.local', auth()->user()->email);
    }

    public function test_request_from_other_device_is_refused(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.20'])
            ->get('/')
            ->assertForbidden();

        $this->assertGuest();
    }

    public function test_hosted_mode_does_not_auto_login(): void
    {
        config(['smm.mode' => 'hosted']);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->get('/');

        $this->assertNotSame(200, $response->getStatusCode());
        $response->assertRedirect();
        $this->assertGuest();
    }

    public function test_brand_header_shows_company_name_and_logo_alt(): void
    {
        $this->followingRedirects()
            ->get('/')
            ->assertOk()
            ->assertSee('Moggies International')
            ->assertSee('alt="Moggies International logo"', false)
            ->assertSee('SocialMedia Manager');
    }
}
