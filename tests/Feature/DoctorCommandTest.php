<?php

namespace Tests\Feature;

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

    public function test_doctor_fails_when_a_database_update_is_waiting(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 1])->assertExitCode(0);

        // One expected phrase per printed line: Laravel's matcher uses up one phrase per line.
        $this->artisan('smm:doctor')
            ->expectsOutputToContain('FAIL  1 database update(s) waiting: run php artisan migrate')
            ->assertExitCode(1);
    }
}
