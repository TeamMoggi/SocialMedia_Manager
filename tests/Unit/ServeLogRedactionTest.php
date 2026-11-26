<?php

namespace Tests\Unit;

use App\Console\Commands\ServeCommand;
use PHPUnit\Framework\TestCase;

class ServeLogRedactionTest extends TestCase
{
    public function test_sign_in_code_and_state_are_redacted(): void
    {
        $line = "[Thu Nov 26 12:00:01 2026] 127.0.0.1:52011 [302]: GET /linkedin/callback?code=AQTsecretcode123&state=Zx9state456 \n";

        $out = ServeCommand::redact($line);

        $this->assertStringNotContainsString('AQTsecretcode123', $out);
        $this->assertStringNotContainsString('Zx9state456', $out);
        $this->assertStringContainsString('GET /linkedin/callback?code=[hidden]&state=[hidden]', $out);
    }

    public function test_other_lines_are_unchanged(): void
    {
        $line = "[Thu Nov 26 12:00:02 2026] 127.0.0.1:52012 [200]: GET /moggies-international/linkedin?tab=x\n";

        $this->assertSame($line, ServeCommand::redact($line));
    }
}
