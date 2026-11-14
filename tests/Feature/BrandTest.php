<?php

namespace Tests\Feature;

use App\Filament\Pages\Tenancy\RegisterBrand;
use App\Models\Brand;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BrandTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function owner(): User
    {
        return User::where('email', User::LOCAL_OWNER_EMAIL)->firstOrFail();
    }

    public function test_owner_lands_on_moggies_international(): void
    {
        $this->get('/')->assertRedirect('/moggies-international');

        $this->get('/moggies-international')
            ->assertOk()
            ->assertSee('Moggies International');
    }

    public function test_owner_can_add_a_second_brand(): void
    {
        $this->actingAs($this->owner());
        Filament::setCurrentPanel('app');

        Livewire::test(RegisterBrand::class)
            ->fillForm(['name' => 'Moggies Export Services'])
            ->call('register')
            ->assertHasNoFormErrors();

        $brand = Brand::where('slug', 'moggies-export-services')->first();
        $this->assertNotNull($brand);
        $this->assertSame('Asia/Kolkata', $brand->timezone);
        $this->assertTrue($brand->users()->whereKey($this->owner()->id)->exists());
    }

    public function test_brand_name_must_be_unique(): void
    {
        $this->actingAs($this->owner());
        Filament::setCurrentPanel('app');

        Livewire::test(RegisterBrand::class)
            ->fillForm(['name' => 'Moggies International'])
            ->call('register')
            ->assertHasFormErrors(['name' => 'unique']);

        $this->assertSame(1, Brand::count());
    }

    public function test_owner_cannot_open_a_brand_they_are_not_attached_to(): void
    {
        Brand::create(['name' => 'Someone Else']);

        $this->get('/someone-else')->assertNotFound();
    }
}
