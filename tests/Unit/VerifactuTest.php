<?php

declare(strict_types=1);

namespace eseperio\verifactu\tests\Unit;

use eseperio\verifactu\Verifactu;
use eseperio\verifactu\services\VerifactuService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Verifactu facade — No VERI*FACTU QR configuration.
 *
 * Spec coverage:
 * - Scenario: Production No VERI*FACTU QR uses the production endpoint
 * - Scenario: Test No VERI*FACTU QR uses the test endpoint
 * - configNoVerifactu() must NOT require SOAP/certificate parameters
 */
class VerifactuTest extends TestCase
{
    protected function tearDown(): void
    {
        // Reset service config between tests to avoid cross-test pollution.
        VerifactuService::config([VerifactuService::QR_VERIFICATION_URL => '']);
    }

    // -------------------------------------------------------------------------
    // Task 1.1 RED — configNoVerifactu(): production endpoint
    // -------------------------------------------------------------------------

    /**
     * Spec: Production No VERI*FACTU QR uses the production endpoint.
     *
     * configNoVerifactu(production) must store the production No VERI*FACTU
     * QR validation URL so that subsequent QR generation uses the correct base URL.
     */
    public function testConfigNoVerifactuUsesProductionEndpoint(): void
    {
        Verifactu::configNoVerifactu(Verifactu::ENVIRONMENT_PRODUCTION);

        $url = VerifactuService::getConfig(VerifactuService::QR_VERIFICATION_URL);

        $this->assertSame(
            Verifactu::QR_NO_VERIFACTU_VERIFICATION_URL_PRODUCTION,
            $url,
            'Production No VERI*FACTU QR must use the production ValidarQRNoVerifactu endpoint'
        );
    }

    /**
     * Spec: Test No VERI*FACTU QR uses the test endpoint.
     *
     * configNoVerifactu(sandbox) must store the sandbox No VERI*FACTU
     * QR validation URL.
     */
    public function testConfigNoVerifactuUsesSandboxEndpoint(): void
    {
        Verifactu::configNoVerifactu(Verifactu::ENVIRONMENT_SANDBOX);

        $url = VerifactuService::getConfig(VerifactuService::QR_VERIFICATION_URL);

        $this->assertSame(
            Verifactu::QR_NO_VERIFACTU_VERIFICATION_URL_TEST,
            $url,
            'Sandbox No VERI*FACTU QR must use the test ValidarQRNoVerifactu endpoint'
        );
    }

    /**
     * configNoVerifactu() must NOT require certificate path or password.
     * It is a QR-only configuration; SOAP setup must not be triggered.
     */
    public function testConfigNoVerifactuDoesNotRequireCertificateParameters(): void
    {
        // Must not throw even though no cert params are supplied
        $this->assertNull(
            Verifactu::configNoVerifactu(Verifactu::ENVIRONMENT_SANDBOX)
        );
    }

    /**
     * configNoVerifactu() stores the QR mode as no_verifactu in VerifactuService.
     */
    public function testConfigNoVerifactuStoresNoVerifactuQrMode(): void
    {
        Verifactu::configNoVerifactu(Verifactu::ENVIRONMENT_SANDBOX);

        $mode = VerifactuService::getConfig(VerifactuService::QR_MODE);

        $this->assertSame(
            VerifactuService::QR_MODE_NO_VERIFACTU,
            $mode,
            'configNoVerifactu() must store qrMode=no_verifactu in the service config'
        );
    }

    /**
     * configNoVerifactu() with an invalid environment must throw InvalidArgumentException.
     */
    public function testConfigNoVerifactuThrowsOnInvalidEnvironment(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Verifactu::configNoVerifactu('invalid_env');
    }

    /**
     * config() (VERI*FACTU) stores the qrMode as verifactu (the default).
     */
    public function testConfigVerifactuStoresVerifactuQrMode(): void
    {
        Verifactu::config('/path/to/cert', 'password', Verifactu::TYPE_CERTIFICATE, Verifactu::ENVIRONMENT_SANDBOX);

        $mode = VerifactuService::getConfig(VerifactuService::QR_MODE);

        $this->assertSame(
            VerifactuService::QR_MODE_VERIFACTU,
            $mode,
            'config() must store qrMode=verifactu (the default VERI*FACTU mode)'
        );
    }
}
