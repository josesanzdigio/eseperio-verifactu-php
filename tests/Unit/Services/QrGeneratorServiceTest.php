<?php

declare(strict_types=1);

namespace eseperio\verifactu\tests\Unit;

use BaconQrCode\Writer;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use eseperio\verifactu\models\InvoiceId;
use eseperio\verifactu\models\InvoiceRecord;
use eseperio\verifactu\models\InvoiceSubmission;
use eseperio\verifactu\services\QrGeneratorService;
use eseperio\verifactu\services\VerifactuService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for QrGeneratorService.
 *
 * Validates the AEAT VERI*FACTU QR URL contract:
 * - Uses `numserie` (not legacy `num`)
 * - Emits `fecha` as DD-MM-YYYY
 * - Emits `importe` as a decimal amount when a total amount is provided
 * - Omits `importe` when no total amount is available
 * - Keeps `huella` optional (present only when hash is set)
 * - Never emits `formato=json`
 */
class QrGeneratorServiceTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Task 1.1 — Verifiable invoice: AEAT parameter contract (RED)
    // -------------------------------------------------------------------------

    /**
     * AEAT VERI*FACTU QR spec: a verifiable invoice QR URL must contain
     * nif, numserie, fecha (DD-MM-YYYY), importe as a decimal amount using `.` as separator, and optionally huella.
     * It must NOT contain legacy `num` or `formato=json`.
     */
    public function testBuildQrContentEmitsAeatCompliantParams(): void
    {
        $record = $this->makeRecord('B12345678', 'FACT-001', '2026-06-24', 'abcdef1234567890');

        $result = $this->invokeBuildQrContent($record, 'https://example.com/verify', 121.00);

        // Must use numserie, not num
        $this->assertStringContainsString('numserie=', $result, 'QR URL must contain numserie parameter');
        $this->assertStringNotContainsString('num=', $result, 'QR URL must NOT contain legacy num parameter');

        // fecha must be DD-MM-YYYY
        $this->assertStringContainsString('fecha=24-06-2026', $result, 'fecha must be in DD-MM-YYYY format');
        $this->assertStringNotContainsString('fecha=2026-06-24', $result, 'fecha must NOT be in YYYY-MM-DD format');

        // importe must be present when amount is provided
        $this->assertStringContainsString('importe=121', $result, 'importe must be present for verifiable invoices');

        // nif must still be present
        $this->assertStringContainsString('nif=B12345678', $result, 'nif must be present');

        // formato=json must never appear
        $this->assertStringNotContainsString('formato', $result, 'formato=json must NOT appear in QR URL');
    }

    /**
     * When a hash is set, huella must appear alongside the mandatory params.
     */
    public function testBuildQrContentIncludesHuellaWhenHashIsSet(): void
    {
        $record = $this->makeRecord('B12345678', 'FACT-001', '2026-06-24', 'abcdef1234567890');

        $result = $this->invokeBuildQrContent($record, 'https://example.com/verify', 121.00);

        $this->assertStringContainsString('huella=abcdef1234567890', $result, 'huella must be present when hash is set');

        // Mandatory params must still be present alongside huella
        $this->assertStringContainsString('numserie=', $result);
        $this->assertStringContainsString('importe=121', $result);
    }

    /**
     * Date and amount contract: a specific date/amount pair must round-trip
     * to an AEAT-valid QR format.
     *
     * Spec scenario: "Given a verifiable invoice dated 2026-06-24 with total amount 121.4
     * When its QR URL is generated
     * Then fecha=24-06-2026 AND importe=121.4"
     */
    public function testBuildQrContentDateAndAmountMatchAeatContract(): void
    {
        $record = $this->makeRecord('A00000001', 'SER/2026/001', '2026-06-24', null);

        $result = $this->invokeBuildQrContent($record, 'https://example.com/verify', 121.4);

        $this->assertStringContainsString('fecha=24-06-2026', $result);
        $this->assertStringContainsString('importe=121.4', $result);
        $this->assertStringNotContainsString('huella', $result, 'huella must be absent when hash is null');
    }

    public function testBuildQrContentNormalizesDateTimeIssueDate(): void
    {
        $record = $this->makeRecord('A00000001', 'SER/2026/001', '2026-06-24', null);
        $record->getInvoiceId()->issueDate = new DateTimeImmutable('2026-06-24 14:00:00');

        $result = $this->invokeBuildQrContent($record, 'https://example.com/verify', 121.4);

        $this->assertStringContainsString('fecha=24-06-2026', $result);
        $this->assertStringNotContainsString('fecha=2026-06-24', $result);
    }

    /**
     * Public QR generation path must thread InvoiceSubmission::$totalAmount into the QR payload.
     */
    public function testVerifactuServiceGenerateInvoiceQrThreadsSubmissionTotalAmountIntoQrPayload(): void
    {
        VerifactuService::config([
            VerifactuService::QR_VERIFICATION_URL => 'https://example.com/verify',
        ]);

        $submission = $this->makeSubmissionRecord(121.4, 'abcdef1234567890');

        $serviceQr = VerifactuService::generateInvoiceQr(
            $submission,
            QrGeneratorService::DESTINATION_STRING,
            300,
            QrGeneratorService::RENDERER_SVG
        );

        $expectedQr = QrGeneratorService::generateQr(
            $submission,
            'https://example.com/verify',
            QrGeneratorService::DESTINATION_STRING,
            300,
            QrGeneratorService::RENDERER_SVG,
            121.4
        );

        $qrWithoutAmount = QrGeneratorService::generateQr(
            $submission,
            'https://example.com/verify',
            QrGeneratorService::DESTINATION_STRING,
            300,
            QrGeneratorService::RENDERER_SVG,
            null
        );

        $this->assertSame($expectedQr, $serviceQr);
        $this->assertNotSame($qrWithoutAmount, $serviceQr);
        $this->assertStringContainsString('<svg', $serviceQr);
    }

    // -------------------------------------------------------------------------
    // Task 1.2 — Null-amount path: huella optional, importe omitted (RED)
    // -------------------------------------------------------------------------

    /**
     * When no total amount is available (e.g. non-submission / cancellation-style record),
     * importe must be omitted — not emitted as an empty value.
     * huella is still included if a hash is set.
     */
    public function testBuildQrContentOmitsImporteWhenAmountIsNull(): void
    {
        $record = $this->makeRecord('B12345678', 'FACT-001', '2023-01-01', 'abcdef1234567890');

        // Pass null for totalAmount — simulates a non-submission or cancellation record
        $result = $this->invokeBuildQrContent($record, 'https://example.com/verify', null);

        $this->assertStringNotContainsString('importe', $result, 'importe must be absent when totalAmount is null');

        // huella should still be present (hash was set)
        $this->assertStringContainsString('huella=abcdef1234567890', $result);

        // numserie must be present (AEAT param name)
        $this->assertStringContainsString('numserie=', $result);

        // no legacy num
        $this->assertStringNotContainsString('num=', $result);
    }

    /**
     * When both hash and amount are absent, only the mandatory
     * nif / numserie / fecha params are emitted.
     */
    public function testBuildQrContentWithNoHashAndNoAmount(): void
    {
        $record = $this->makeRecord('B12345678', 'FACT-001', '2023-01-01', null);

        $result = $this->invokeBuildQrContent($record, 'https://example.com/verify', null);

        $this->assertStringNotContainsString('huella', $result);
        $this->assertStringNotContainsString('importe', $result);
        $this->assertStringContainsString('nif=B12345678', $result);
        $this->assertStringContainsString('numserie=FACT-001', $result);
        $this->assertStringContainsString('fecha=01-01-2023', $result);
        $this->assertStringNotContainsString('num=', $result);
        $this->assertStringNotContainsString('formato', $result);
    }

    // -------------------------------------------------------------------------
    // Task 3.1 — Legacy assertions removed; full URL shape verified
    // -------------------------------------------------------------------------

    /**
     * Full URL shape: base URL + ? + params in correct order and encoding.
     * Verifies the exact AEAT-compliant URL built for a known input set.
     */
    public function testBuildQrContentProducesCorrectFullUrl(): void
    {
        $record = $this->makeRecord('B12345678', 'FACT-001', '2023-01-01', null);

        $result = $this->invokeBuildQrContent($record, 'https://example.com/verify', null);

        $expectedParams = http_build_query([
            'nif'      => 'B12345678',
            'numserie' => 'FACT-001',
            'fecha'    => '01-01-2023',
        ]);
        $expected = 'https://example.com/verify?' . $expectedParams;

        $this->assertEquals($expected, $result);
    }

    /**
     * Full URL shape with hash but no amount.
     */
    public function testBuildQrContentWithHashButNoAmount(): void
    {
        $record = $this->makeRecord('B12345678', 'FACT-001', '2023-01-01', 'abcdef1234567890');

        $result = $this->invokeBuildQrContent($record, 'https://example.com/verify', null);

        $expectedParams = http_build_query([
            'nif'      => 'B12345678',
            'numserie' => 'FACT-001',
            'fecha'    => '01-01-2023',
            'huella'   => 'abcdef1234567890',
        ]);
        $expected = 'https://example.com/verify?' . $expectedParams;

        $this->assertEquals($expected, $result);
    }

    // -------------------------------------------------------------------------
    // Renderer and writer tests (unchanged — verify infrastructure only)
    // -------------------------------------------------------------------------

    /**
     * Test that getFileExtension returns the correct extension.
     */
    public function testGetFileExtension(): void
    {
        $reflectionClass = new \ReflectionClass(QrGeneratorService::class);
        $method = $reflectionClass->getMethod('getFileExtension');
        $method->setAccessible(true);

        $this->assertEquals('.png', $method->invoke(null, QrGeneratorService::RENDERER_GD));
        $this->assertEquals('.png', $method->invoke(null, QrGeneratorService::RENDERER_IMAGICK));
        $this->assertEquals('.svg', $method->invoke(null, QrGeneratorService::RENDERER_SVG));
        $this->assertEquals('.png', $method->invoke(null, 'unknown'));
    }

    /**
     * Test that createWriter creates the correct writer.
     */
    public function testCreateWriter(): void
    {
        $reflectionClass = new \ReflectionClass(QrGeneratorService::class);
        $method = $reflectionClass->getMethod('createWriter');
        $method->setAccessible(true);

        $writer = $method->invoke(null, QrGeneratorService::RENDERER_GD, 300);
        $this->assertInstanceOf(Writer::class, $writer);

        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick extension is not installed; skipping Imagick renderer test. Install ext-imagick to run this part.');
        }
        $writer = $method->invoke(null, QrGeneratorService::RENDERER_IMAGICK, 300);
        $this->assertInstanceOf(Writer::class, $writer);

        $writer = $method->invoke(null, QrGeneratorService::RENDERER_SVG, 300);
        $this->assertInstanceOf(Writer::class, $writer);

        $this->expectException(\RuntimeException::class);
        $method->invoke(null, 'invalid', 300);
    }

    /**
     * generateQr returns non-empty binary string with default parameters.
     */
    public function testGenerateQrWithDefaultParameters(): void
    {
        $result = QrGeneratorService::generateQr(
            $this->makeRecord('B12345678', 'FACT-001', '2023-01-01', 'abcdef1234567890'),
            'https://example.com/verify'
        );

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    /**
     * generateQr saves to file when DESTINATION_FILE is requested.
     */
    public function testGenerateQrWithFileDestination(): void
    {
        $result = QrGeneratorService::generateQr(
            $this->makeRecord('B12345678', 'FACT-001', '2023-01-01', 'abcdef1234567890'),
            'https://example.com/verify',
            QrGeneratorService::DESTINATION_FILE
        );

        $this->assertIsString($result);
        $this->assertStringContainsString('/qr_', $result);
        $this->assertStringEndsWith('.png', $result);
        $this->assertFileExists($result);

        if (file_exists($result)) {
            unlink($result);
        }
    }

    /**
     * generateQr with SVG renderer produces SVG markup.
     */
    public function testGenerateQrWithSvgRenderer(): void
    {
        $result = QrGeneratorService::generateQr(
            $this->makeRecord('B12345678', 'FACT-001', '2023-01-01', 'abcdef1234567890'),
            'https://example.com/verify',
            QrGeneratorService::DESTINATION_STRING,
            300,
            QrGeneratorService::RENDERER_SVG
        );

        $this->assertIsString($result);
        $this->assertStringContainsString('<svg', $result);
        $this->assertStringContainsString('</svg>', $result);
    }

    /**
     * Larger resolution produces larger output.
     */
    public function testGenerateQrWithDifferentResolutions(): void
    {
        $record = $this->makeRecord('B12345678', 'FACT-001', '2023-01-01', 'abcdef1234567890');
        $baseUrl = 'https://example.com/verify';

        $smallQr = QrGeneratorService::generateQr($record, $baseUrl, QrGeneratorService::DESTINATION_STRING, 100);
        $largeQr = QrGeneratorService::generateQr($record, $baseUrl, QrGeneratorService::DESTINATION_STRING, 300);

        $this->assertIsString($smallQr);
        $this->assertIsString($largeQr);
        $this->assertGreaterThan(strlen($smallQr), strlen($largeQr));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a mock InvoiceRecord with the given field values.
     */
    private function makeRecord(
        string $nif,
        string $seriesNumber,
        string $issueDate,
        ?string $hash
    ): MockObject {
        $mock = $this->getMockBuilder(InvoiceRecord::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getInvoiceId'])
            ->getMockForAbstractClass();

        $invoiceId = new InvoiceId();
        $invoiceId->issuerNif    = $nif;
        $invoiceId->seriesNumber = $seriesNumber;
        $invoiceId->issueDate    = $issueDate;

        $mock->method('getInvoiceId')->willReturn($invoiceId);
        $mock->hash = $hash;

        return $mock;
    }

    /**
     * Build a concrete InvoiceSubmission for public-path QR generation tests.
     */
    private function makeSubmissionRecord(float $totalAmount, ?string $hash): InvoiceSubmission
    {
        $submission = new InvoiceSubmission();

        $invoiceId = new InvoiceId();
        $invoiceId->issuerNif = 'B12345678';
        $invoiceId->seriesNumber = 'FACT-001';
        $invoiceId->issueDate = '2026-06-24';

        $submission->setInvoiceId($invoiceId);
        $submission->totalAmount = $totalAmount;
        $submission->hash = $hash;

        return $submission;
    }

    /**
     * Invoke the protected buildQrContent method via reflection.
     */
    private function invokeBuildQrContent(
        InvoiceRecord $record,
        string $baseUrl,
        ?float $totalAmount
    ): string {
        $rc     = new \ReflectionClass(QrGeneratorService::class);
        $method = $rc->getMethod('buildQrContent');
        $method->setAccessible(true);

        return (string) $method->invoke(null, $record, $baseUrl, $totalAmount);
    }
}
