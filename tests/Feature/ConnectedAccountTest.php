<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\Network;
use App\Filament\Resources\ConnectedAccounts\Pages\ListConnectedAccounts;
use App\Models\Brand;
use App\Models\ConnectedAccount;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ConnectedAccountTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function moggies(): Brand
    {
        return Brand::where('slug', 'moggies-international')->firstOrFail();
    }

    private function account(Brand $brand, array $overrides = []): ConnectedAccount
    {
        return ConnectedAccount::create(array_merge([
            'brand_id' => $brand->id,
            'network' => Network::FacebookPage,
            'external_id' => '1503120716208596',
            'name' => 'Moggies International (Facebook)',
            'access_token' => 'secret-token-123',
        ], $overrides));
    }

    public function test_tokens_are_encrypted_at_rest(): void
    {
        $account = $this->account($this->moggies(), ['refresh_token' => 'refresh-456']);

        $raw = DB::table('connected_accounts')->where('id', $account->id)->first();
        $this->assertStringNotContainsString('secret-token-123', $raw->access_token);
        $this->assertStringNotContainsString('refresh-456', $raw->refresh_token);
        $this->assertSame('secret-token-123', $account->fresh()->access_token);
        $this->assertSame('refresh-456', $account->fresh()->refresh_token);
    }

    public function test_tokens_are_hidden_from_arrays(): void
    {
        $array = $this->account($this->moggies(), ['refresh_token' => 'refresh-456'])->fresh()->toArray();

        $this->assertArrayNotHasKey('access_token', $array);
        $this->assertArrayNotHasKey('refresh_token', $array);
        $this->assertSame('Moggies International (Facebook)', $array['name']);
    }

    public function test_check_accounts_notifies_once_when_expiring(): void
    {
        $account = $this->account($this->moggies(), ['token_expires_at' => now()->addDays(3)]);
        $owner = User::where('email', User::LOCAL_OWNER_EMAIL)->firstOrFail();

        $this->artisan('smm:check-accounts')->assertExitCode(0);
        $this->artisan('smm:check-accounts')->assertExitCode(0);

        $this->assertSame(AccountStatus::Expiring, $account->fresh()->status);
        $this->assertSame(1, $owner->notifications()->count());
        $this->assertStringContainsString('Moggies International (Facebook)', json_encode($owner->notifications()->first()->data));
    }

    public function test_accounts_list_only_shows_current_brand(): void
    {
        $moggies = $this->moggies();
        $other = Brand::create(['name' => 'Moggies Export Services']);
        $owner = User::where('email', User::LOCAL_OWNER_EMAIL)->firstOrFail();
        $other->users()->attach($owner);
        $mine = $this->account($moggies);
        $theirs = $this->account($other, ['external_id' => 'other-page', 'name' => 'Export Services Page']);

        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($moggies);
        // In the app the brand scope is added by Filament's middleware; a component test boots it by hand.
        Filament::bootCurrentPanel();

        Livewire::test(ListConnectedAccounts::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }
}
