<?php

namespace Tests\Feature;

use App\Domain\System\IdleWatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordActivityTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        @unlink(IdleWatch::activityFile());
    }

    protected function tearDown(): void
    {
        @unlink(IdleWatch::activityFile());
        parent::tearDown();
    }

    public function test_page_request_records_activity(): void
    {
        $this->followingRedirects()->get('/')->assertOk();

        $this->assertFileExists(IdleWatch::activityFile());
        clearstatcache();
        $this->assertEqualsWithDelta(time(), filemtime(IdleWatch::activityFile()), 5);
    }

    public function test_health_check_does_not_count_as_use(): void
    {
        // start.ps1 asks /up whether the app is running; that must not keep the app awake.
        $this->get('/up')->assertOk();

        $this->assertFileDoesNotExist(IdleWatch::activityFile());
    }
}
