<?php

namespace Tests\Feature;

use Composer\CaBundle\CaBundle;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PHP on Windows ships without a list of trusted certificate authorities, so every secure call
 * (Meta, LinkedIn, the website) failed with curl error 60 and the app said "no internet".
 */
class TrustedCertificatesTest extends TestCase
{
    public function test_every_web_request_checks_certificates_against_a_real_list(): void
    {
        $verify = Http::createPendingRequest()->getOptions()['verify'] ?? null;

        $this->assertIsString($verify, 'Web requests must name a certificate list, not rely on PHP having one.');
        $this->assertFileIsReadable($verify);
        $this->assertSame(CaBundle::getSystemCaRootBundlePath(), $verify);
    }
}
