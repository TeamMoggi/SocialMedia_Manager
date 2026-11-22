<?php

namespace Tests\Feature;

use App\Enums\MediaKind;
use App\Filament\Pages\Tenancy\EditBrand;
use App\Models\Activity;
use App\Models\Brand;
use App\Models\MediaGroup;
use App\Models\MediaItem;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LibraryModelTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->brand = Brand::where('slug', 'moggies-international')->firstOrFail();
    }

    private function item(string $path, MediaKind $kind, bool $missing = false): MediaItem
    {
        $group = MediaGroup::firstOrCreate(
            ['brand_id' => $this->brand->id, 'source_root' => 'D:/src', 'folder' => ''],
            ['name' => 'Top folder'],
        );

        return MediaItem::create([
            'brand_id' => $this->brand->id, 'media_group_id' => $group->id, 'source_root' => 'D:/src',
            'relative_path' => $path, 'kind' => $kind, 'bytes' => 10, 'mtime' => 1,
            'missing_since' => $missing ? now() : null,
        ]);
    }

    public function test_brand_defaults_for_sources_and_checklist(): void
    {
        $this->assertSame([config('smm.library_source')], $this->brand->librarySources());
        $this->assertCount(5, $this->brand->approvalChecklist());

        $this->brand->update(['library_sources' => ['E:/Photos'], 'approval_checklist' => ['One rule']]);
        $this->assertSame(['E:/Photos'], $this->brand->fresh()->librarySources());
        $this->assertSame(['One rule'], $this->brand->fresh()->approvalChecklist());
    }

    public function test_activity_record_saves_who_and_what(): void
    {
        $item = $this->item('a.jpg', MediaKind::Photo);

        Activity::record($this->brand, $item, 'labels changed', ['description' => 'Raisin close-up'], 'Claude');

        $row = Activity::sole();
        $this->assertSame('Claude', $row->by);
        $this->assertSame('labels changed', $row->action);
        $this->assertSame(['description' => 'Raisin close-up'], $row->details);
        $this->assertTrue($row->subject->is($item));
        $this->assertSame($this->brand->id, $row->brand_id);
    }

    public function test_attachable_scope_skips_missing_and_audio(): void
    {
        $photo = $this->item('a.jpg', MediaKind::Photo);
        $this->item('v.wav', MediaKind::VoiceOver);
        $this->item('gone.mp4', MediaKind::Clip, missing: true);

        $this->assertSame([$photo->id], MediaItem::attachable()->pluck('id')->all());
        $this->assertStringEndsWith('a.jpg', $photo->fullPath());
        $this->assertFalse($photo->isMissing());
    }

    public function test_brand_settings_refuse_a_folder_inside_the_data_folder(): void
    {
        $this->actingAs(User::where('email', User::LOCAL_OWNER_EMAIL)->firstOrFail());
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->brand);

        $page = Livewire::test(EditBrand::class)
            ->fillForm(['library_sources' => [['path' => app('smm.data_dir').DIRECTORY_SEPARATOR.'media']]])
            ->call('save')
            ->assertHasFormErrors(['library_sources.0.path']);

        $this->assertStringContainsString("app's own data folder", $page->errors()->first('data.library_sources.0.path'));
        $this->assertNull($this->brand->fresh()->library_sources);
    }

    public function test_brand_settings_save_an_existing_folder(): void
    {
        $this->actingAs(User::where('email', User::LOCAL_OWNER_EMAIL)->firstOrFail());
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->brand);
        $folder = sys_get_temp_dir();

        Livewire::test(EditBrand::class)
            ->fillForm(['library_sources' => [['path' => $folder]]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([$folder], $this->brand->fresh()->library_sources);
    }
}
