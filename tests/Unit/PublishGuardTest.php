<?php

namespace Tests\Unit;

use App\Support\PublishGuard;
use PHPUnit\Framework\TestCase;

class PublishGuardTest extends TestCase
{
    public function test_live_allowed_only_in_folder_named_App(): void
    {
        $this->assertTrue(PublishGuard::allowsLive('D:\\Pravin\\Moggi\\SocialMedia_Manager\\App'));
        $this->assertTrue(PublishGuard::allowsLive('D:/Pravin/Moggi/SocialMedia_Manager/App/'));
        $this->assertFalse(PublishGuard::allowsLive('D:\\Pravin\\Moggi\\SocialMedia_Manager\\Dev'));
        $this->assertFalse(PublishGuard::allowsLive('D:\\Pravin\\Moggi\\SocialMedia_Manager\\AppCopy'));
    }

    public function test_live_mode_refused_outside_app_folder(): void
    {
        $this->assertSame('dry-run', PublishGuard::effectiveMode('live', 'D:\\Pravin\\Moggi\\SocialMedia_Manager\\Dev'));
        $this->assertSame('live', PublishGuard::effectiveMode('live', 'D:\\Pravin\\Moggi\\SocialMedia_Manager\\App'));
        $this->assertSame('dry-run', PublishGuard::effectiveMode('dry-run', 'D:\\Pravin\\Moggi\\SocialMedia_Manager\\App'));
        $this->assertSame('dry-run', PublishGuard::effectiveMode('anything-else', 'D:\\Pravin\\Moggi\\SocialMedia_Manager\\App'));
    }
}
