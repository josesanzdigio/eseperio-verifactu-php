<?php

declare(strict_types=1);

namespace eseperio\verifactu\tests\Unit;

use eseperio\verifactu\Verifactu;
use eseperio\verifactu\models\InvoiceId;
use eseperio\verifactu\models\InvoiceQuery;
use eseperio\verifactu\models\InvoiceSubmission;
use eseperio\verifactu\services\QrGeneratorService;
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
        $this->expectExceptionMessage('Invalid environment: invalid_env');

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

    public function testGenerateInvoiceQrAfterConfigNoVerifactuUsesCorrectContract(): void
    {
        Verifactu::configNoVerifactu(Verifactu::ENVIRONMENT_SANDBOX);

        $invoice = new InvoiceSubmission();
        $invoiceId = new InvoiceId();
        $invoiceId->issuerNif = 'B12345678';
        $invoiceId->seriesNumber = 'FACT-001';
        $invoiceId->issueDate = '2026-07-01';
        $invoice->setInvoiceId($invoiceId);
        $invoice->totalAmount = 121.4;
        $invoice->hash = 'hash-should-not-appear';

        $result = VerifactuService::generateInvoiceQr(
            $invoice,
            QrGeneratorService::DESTINATION_STRING,
            300,
            QrGeneratorService::RENDERER_SVG
        );

        // The No VERI*FACTU endpoint must be used
        $this->assertSame(Verifactu::QR_NO_VERIFACTU_VERIFICATION_URL_TEST, VerifactuService::getConfig(VerifactuService::QR_VERIFICATION_URL));
        $this->assertStringContainsString('<svg', $result);

        $invoiceWithoutAmount = new InvoiceSubmission();
        $invoiceWithoutAmount->setInvoiceId($invoiceId);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/importe/i');

        VerifactuService::generateInvoiceQr(
            $invoiceWithoutAmount,
            QrGeneratorService::DESTINATION_STRING,
            300,
            QrGeneratorService::RENDERER_SVG
        );
    }

    public function testGenerateNoVerifactuQrWorksAfterConfigNoVerifactu(): void
    {
        Verifactu::configNoVerifactu(Verifactu::ENVIRONMENT_SANDBOX);

        $invoice = new InvoiceSubmission();
        $invoiceId = new InvoiceId();
        $invoiceId->issuerNif = 'B12345678';
        $invoiceId->seriesNumber = 'FACT-001';
        $invoiceId->issueDate = '2026-07-01';
        $invoice->setInvoiceId($invoiceId);
        $invoice->totalAmount = 121.4;

        $explicitQr = Verifactu::generateNoVerifactuQr($invoice);
        $standardQr = Verifactu::generateInvoiceQr($invoice);

        $this->assertSame($standardQr, $explicitQr);
        $this->assertNotEmpty($explicitQr);
    }

    public function testGenerateNoVerifactuQrFailsAfterConfigVerifactu(): void
    {
        Verifactu::config('/path/to/cert', 'password', Verifactu::TYPE_CERTIFICATE, Verifactu::ENVIRONMENT_SANDBOX);

        $invoice = new InvoiceSubmission();
        $invoiceId = new InvoiceId();
        $invoiceId->issuerNif = 'B12345678';
        $invoiceId->seriesNumber = 'FACT-001';
        $invoiceId->issueDate = '2026-07-01';
        $invoice->setInvoiceId($invoiceId);
        $invoice->totalAmount = 121.4;

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/configNoVerifactu/i');

        Verifactu::generateNoVerifactuQr($invoice);
    }

    public function testGenerateInvoiceQrWorksAfterConfigVerifactu(): void
    {
        Verifactu::config('/path/to/cert', 'password', Verifactu::TYPE_CERTIFICATE, Verifactu::ENVIRONMENT_SANDBOX);

        $invoice = new InvoiceSubmission();
        $invoiceId = new InvoiceId();
        $invoiceId->issuerNif = 'B12345678';
        $invoiceId->seriesNumber = 'FACT-001';
        $invoiceId->issueDate = '2026-07-01';
        $invoice->setInvoiceId($invoiceId);
        $invoice->totalAmount = 121.4;

        $result = Verifactu::generateInvoiceQr($invoice);

        $this->assertNotEmpty($result);
        // Must use the VERI*FACTU (sandbox) endpoint
        $this->assertSame(Verifactu::QR_VERIFICATION_URL_TEST, VerifactuService::getConfig(VerifactuService::QR_VERIFICATION_URL));
    }

    public function testGenerateInvoiceQrWorksAfterConfigNoVerifactu(): void
    {
        Verifactu::configNoVerifactu(Verifactu::ENVIRONMENT_SANDBOX);

        $invoice = new InvoiceSubmission();
        $invoiceId = new InvoiceId();
        $invoiceId->issuerNif = 'B12345678';
        $invoiceId->seriesNumber = 'FACT-001';
        $invoiceId->issueDate = '2026-07-01';
        $invoice->setInvoiceId($invoiceId);
        $invoice->totalAmount = 121.4;

        $result = Verifactu::generateInvoiceQr($invoice);

        $this->assertNotEmpty($result);
        // Must use the No VERI*FACTU (sandbox) endpoint
        $this->assertSame(Verifactu::QR_NO_VERIFACTU_VERIFICATION_URL_TEST, VerifactuService::getConfig(VerifactuService::QR_VERIFICATION_URL));
    }

    public function testGenerateInvoiceQrRejectsInvoiceCancellation(): void
    {
        Verifactu::configNoVerifactu(Verifactu::ENVIRONMENT_SANDBOX);

        $cancellation = new \eseperio\verifactu\models\InvoiceCancellation();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/InvoiceSubmission/i');

        Verifactu::generateInvoiceQr($cancellation);
    }

    public function testSequentialConfigVerifactuThenNoVerifactuUsesSecondMode(): void
    {
        Verifactu::config('/path/to/cert', 'password', Verifactu::TYPE_CERTIFICATE, Verifactu::ENVIRONMENT_SANDBOX);
        Verifactu::configNoVerifactu(Verifactu::ENVIRONMENT_PRODUCTION);

        $invoice = new InvoiceSubmission();
        $invoiceId = new InvoiceId();
        $invoiceId->issuerNif = 'B12345678';
        $invoiceId->seriesNumber = 'FACT-001';
        $invoiceId->issueDate = '2026-07-01';
        $invoice->setInvoiceId($invoiceId);
        $invoice->totalAmount = 121.4;

        $result = Verifactu::generateInvoiceQr($invoice);

        $this->assertNotEmpty($result);
        // Must use the production No VERI*FACTU endpoint
        $this->assertSame(Verifactu::QR_NO_VERIFACTU_VERIFICATION_URL_PRODUCTION, VerifactuService::getConfig(VerifactuService::QR_VERIFICATION_URL));
    }

    public function testSequentialConfigNoVerifactuThenVerifactuUsesVerifactuEndpoint(): void
    {
        Verifactu::configNoVerifactu(Verifactu::ENVIRONMENT_PRODUCTION);
        Verifactu::config('/path/to/cert', 'password', Verifactu::TYPE_CERTIFICATE, Verifactu::ENVIRONMENT_SANDBOX);

        $invoice = new InvoiceSubmission();
        $invoiceId = new InvoiceId();
        $invoiceId->issuerNif = 'B12345678';
        $invoiceId->seriesNumber = 'FACT-001';
        $invoiceId->issueDate = '2026-07-01';
        $invoice->setInvoiceId($invoiceId);
        $invoice->totalAmount = 121.4;

        $result = Verifactu::generateInvoiceQr($invoice);

        $this->assertNotEmpty($result);
        // Must use the sandbox VERI*FACTU endpoint
        $this->assertSame(Verifactu::QR_VERIFICATION_URL_TEST, VerifactuService::getConfig(VerifactuService::QR_VERIFICATION_URL));
    }

    public function testVerifactuProductionEndpoint(): void
    {
        Verifactu::config('/path/to/cert', 'password', Verifactu::TYPE_CERTIFICATE, Verifactu::ENVIRONMENT_PRODUCTION);
        $url = VerifactuService::getConfig(VerifactuService::QR_VERIFICATION_URL);
        $this->assertSame(Verifactu::QR_VERIFICATION_URL_PRODUCTION, $url);
    }

    public function testVerifactuSandboxEndpoint(): void
    {
        Verifactu::config('/path/to/cert', 'password', Verifactu::TYPE_CERTIFICATE, Verifactu::ENVIRONMENT_SANDBOX);
        $url = VerifactuService::getConfig(VerifactuService::QR_VERIFICATION_URL);
        $this->assertSame(Verifactu::QR_VERIFICATION_URL_TEST, $url);
    }

    public function testNoVerifactuProductionEndpoint(): void
    {
        Verifactu::configNoVerifactu(Verifactu::ENVIRONMENT_PRODUCTION);
        $url = VerifactuService::getConfig(VerifactuService::QR_VERIFICATION_URL);
        $this->assertSame(Verifactu::QR_NO_VERIFACTU_VERIFICATION_URL_PRODUCTION, $url);
    }

    public function testNoVerifactuSandboxEndpoint(): void
    {
        Verifactu::configNoVerifactu(Verifactu::ENVIRONMENT_SANDBOX);
        $url = VerifactuService::getConfig(VerifactuService::QR_VERIFICATION_URL);
        $this->assertSame(Verifactu::QR_NO_VERIFACTU_VERIFICATION_URL_TEST, $url);
    }

    public function testSoapFacadeOperationsAreBlockedInNoVerifactuMode(): void
    {
        Verifactu::configNoVerifactu(Verifactu::ENVIRONMENT_PRODUCTION);

        $query = new InvoiceQuery();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No VERI*FACTU mode is QR-only; SOAP operations are not available.');

        Verifactu::queryInvoices($query);
    }
}
