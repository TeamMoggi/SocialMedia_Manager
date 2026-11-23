<?php

namespace Tests\Feature;

use App\Models\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DoctorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_doctor_passes_in_test_environment(): void
    {
        $this->artisan('smm:doctor')
            ->expectsOutputToContain('PHP 8.')
            ->expectsOutputToContain('Data folder')
            ->expectsOutputToContain('Publish mode: dry-run')
            ->doesntExpectOutputToContain('FAIL')
            ->assertExitCode(0);
    }

    public function test_doctor_reports_library_folder(): void
    {
        $brand = Brand::create(['name' => 'Moggies Test']);
        $brand->update(['library_sources' => [sys_get_temp_dir()]]);

        $this->artisan('smm:doctor')
            ->expectsOutputToContain('OK    Library folder found: '.sys_get_temp_dir())
            ->assertExitCode(0);

        $brand->update(['library_sources' => ['Z:\\No such folder']]);

        $this->artisan('smm:doctor')
            ->expectsOutputToContain('WARN  Library folder not found: Z:\\No such folder')
            ->assertExitCode(0);
    }

    public function test_doctor_fails_when_a_database_update_is_waiting(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 1])->assertExitCode(0);

        // One expected phrase per printed line: Laravel's matcher uses up one phrase per line.
        $this->artisan('smm:doctor')
            ->expectsOutputToContain('FAIL  1 database update(s) waiting: run php artisan migrate')
            ->assertExitCode(1);
    }
}
