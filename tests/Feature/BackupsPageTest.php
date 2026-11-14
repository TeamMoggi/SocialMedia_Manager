<?php

namespace Tests\Feature;

use App\Filament\Pages\Backups;
use App\Models\Brand;
use App\Models\Setting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BackupsPageTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::where('email', User::LOCAL_OWNER_EMAIL)->firstOrFail());
        Filament::setCurrentPanel('app');
        Filament::setTenant(Brand::where('slug', 'moggies-international')->firstOrFail());
    }

    public function test_password_must_match_and_be_12_characters(): void
    {
        Livewire::test(Backups::class)
            ->callAction('setPassword', data: ['password' => 'short', 'password_confirmation' => 'short'])
            ->assertHasActionErrors(['password' => 'min']);

        Livewire::test(Backups::class)
            ->callAction('setPassword', data: ['password' => 'correct horse battery', 'password_confirmation' => 'correct horse batterY'])
            ->assertHasActionErrors(['password' => 'same']);

        $this->assertNull(Setting::getSecret('backup_password'));

        Livewire::test(Backups::class)
            ->callAction('setPassword', data: ['password' => 'correct horse battery', 'password_confirmation' => 'correct horse battery'])
            ->assertHasNoActionErrors();

        $this->assertSame('correct horse battery', Setting::getSecret('backup_password'));
    }

    public function test_page_reminds_about_the_usb_copy_until_confirmed(): void
    {
        $page = Livewire::test(Backups::class)->assertSee('Copy the Backups folder to your USB drive');

        $page->callAction('usbCopied')->assertHasNoActionErrors();

        $this->assertNotNull(Setting::get('usb_copy_confirmed_at'));
        Livewire::test(Backups::class)->assertDontSee('Copy the Backups folder to your USB drive');
    }
}
