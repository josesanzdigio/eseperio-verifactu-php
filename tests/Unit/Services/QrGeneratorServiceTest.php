<?php

declare(strict_types=1);

namespace eseperio\verifactu\tests\Unit;

use BaconQrCode\Writer;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use eseperio\verifactu\models\InvoiceId;
use eseperio\verifactu\models\InvoiceCancellation;
use eseperio\verifactu\models\InvoiceRecord;
use eseperio\verifactu\models\InvoiceSubmission;
use eseperio\verifactu\models\InvoiceQuery;
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
        $query = $this->parseQrQuery($result);

        $this->assertSame(['nif', 'numserie', 'fecha', 'importe', 'huella'], array_keys($query));
        $this->assertSame('B12345678', $query['nif']);
        $this->assertSame('24-06-2026', $query['fecha']);
        $this->assertSame('121', $query['importe']);
        $this->assertArrayNotHasKey('num', $query);
        $this->assertArrayNotHasKey('formato', $query);
    }

    /**
     * When a hash is set, huella must appear alongside the mandatory params.
     */
    public function testBuildQrContentIncludesHuellaWhenHashIsSet(): void
    {
        $record = $this->makeRecord('B12345678', 'FACT-001', '2026-06-24', 'abcdef1234567890');

        $result = $this->invokeBuildQrContent($record, 'https://example.com/verify', 121.00);
        $query = $this->parseQrQuery($result);

        $this->assertSame('abcdef1234567890', $query['huella']);
        $this->assertSame('121', $query['importe']);
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
        $query = $this->parseQrQuery($result);

        $this->assertSame('24-06-2026', $query['fecha']);
        $this->assertSame('121.4', $query['importe']);
        $this->assertArrayNotHasKey('huella', $query);
    }

    public function testBuildQrContentNormalizesDateTimeIssueDate(): void
    {
        $record = $this->makeRecord('A00000001', 'SER/2026/001', '2026-06-24', null);
        $record->getInvoiceId()->issueDate = new DateTimeImmutable('2026-06-24 14:00:00');

        $result = $this->invokeBuildQrContent($record, 'https://example.com/verify', 121.4);

        $this->assertSame('24-06-2026', $this->parseQrQuery($result)['fecha']);
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
        $query = $this->parseQrQuery($result);

        $this->assertArrayNotHasKey('importe', $query);
        $this->assertSame('abcdef1234567890', $query['huella']);
        $this->assertArrayNotHasKey('num', $query);
    }

    /**
     * When both hash and amount are absent, only the mandatory
     * nif / numserie / fecha params are emitted.
     */
    public function testBuildQrContentWithNoHashAndNoAmount(): void
    {
        $record = $this->makeRecord('B12345678', 'FACT-001', '2023-01-01', null);

        $result = $this->invokeBuildQrContent($record, 'https://example.com/verify', null);
        $query = $this->parseQrQuery($result);

        $this->assertSame(['nif', 'numserie', 'fecha'], array_keys($query));
        $this->assertSame('B12345678', $query['nif']);
        $this->assertSame('FACT-001', $query['numserie']);
        $this->assertSame('01-01-2023', $query['fecha']);
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
        ], '', '&', PHP_QUERY_RFC3986);
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
        ], '', '&', PHP_QUERY_RFC3986);
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
    // Task 2.1 RED — No VERI*FACTU URL contract
    // -------------------------------------------------------------------------

    /**
     * Spec scenario: No VERI*FACTU URL emits only the mandated parameters.
     *
     * Given a No VERI*FACTU invoice with NIF, series, date 2026-06-30, amount 121.4, and a hash,
     * When its QR URL is generated in No VERI*FACTU mode,
     * Then the query contains exactly nif, numserie, fecha=30-06-2026, importe=121.4
     * AND the query does NOT contain huella, num, or formato=json.
     */
    public function testNoVerifactuUrlEmitsOnlyMandatedParameters(): void
    {
        $record = $this->makeRecord('B12345678', 'FACT-NOVERIF-001', '2026-06-30', 'somehash');

        $result = $this->invokeBuildQrContentNoVerifactu($record, 'https://example.com/noverif', 121.4);
        $query = $this->parseQrQuery($result);

        $this->assertSame(['nif', 'numserie', 'fecha', 'importe'], array_keys($query));
        $this->assertSame('B12345678', $query['nif']);
        $this->assertSame('FACT-NOVERIF-001', $query['numserie']);
        $this->assertSame('30-06-2026', $query['fecha']);
        $this->assertSame('121.4', $query['importe']);
        $this->assertArrayNotHasKey('huella', $query);
        $this->assertArrayNotHasKey('num', $query);
        $this->assertArrayNotHasKey('formato', $query);
    }

    /**
     * Spec scenario: No VERI*FACTU rejects a missing amount.
     *
     * Given a No VERI*FACTU invoice whose total amount is unavailable,
     * When its QR URL is generated,
     * Then generation MUST fail because importe is mandatory.
     */
    public function testNoVerifactuThrowsWhenImporteIsNull(): void
    {
        $record = $this->makeRecord('B12345678', 'FACT-001', '2026-06-30', null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/importe/i');

        $this->invokeBuildQrContentNoVerifactu($record, 'https://example.com/noverif', null);
    }

    /**
     * VERI*FACTU regression: after adding No VERI*FACTU support,
     * existing VERI*FACTU URLs must keep their semantics unchanged.
     *
     * Spec scenario: VERI*FACTU QR keeps the current endpoint and parameter names.
     */
    public function testVerifactuUrlRegressionAfterModeExpansion(): void
    {
        $record = $this->makeRecord('A87654321', 'SER/2026/099', '2026-01-15', 'verifactuhash');

        // Standard VERI*FACTU call (noVerifactu = false, which is the default)
        $result = $this->invokeBuildQrContent($record, 'https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR', 99.9);
        $query = $this->parseQrQuery($result);

        $this->assertSame('A87654321', $query['nif']);
        $this->assertSame('15-01-2026', $query['fecha']);
        $this->assertSame('99.9', $query['importe']);
        $this->assertSame('verifactuhash', $query['huella']);
        $this->assertArrayNotHasKey('num', $query);
        $this->assertArrayNotHasKey('formato', $query);
    }

    /**
     * VERI*FACTU still includes huella only when available.
     * Spec scenario: VERI*FACTU still includes huella only when available.
     */
    public function testVerifactuOmitsHuellaWhenHashIsNull(): void
    {
        $record = $this->makeRecord('A87654321', 'SER/2026/099', '2026-01-15', null);

        $result = $this->invokeBuildQrContent($record, 'https://example.com/verify', 50.0);
        $query = $this->parseQrQuery($result);

        $this->assertArrayNotHasKey('huella', $query);
        $this->assertSame('A87654321', $query['nif']);
        $this->assertSame('SER/2026/099', $query['numserie']);
    }

    /**
     * No VERI*FACTU: uses the configured No VERI*FACTU base URL.
     * Spec scenario: Production No VERI*FACTU QR uses the production endpoint.
     */
    public function testNoVerifactuUrlUsesConfiguredNoVerifactuBaseUrl(): void
    {
        $record = $this->makeRecord('B12345678', 'FACT-001', '2026-06-30', null);
        $noVerifactuBaseUrl = 'https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQRNoVerifactu';

        $result = $this->invokeBuildQrContentNoVerifactu($record, $noVerifactuBaseUrl, 50.0);

        $this->assertStringStartsWith($noVerifactuBaseUrl . '?', $result);
    }

    public function testBuildQrContentRejectsMissingNif(): void
    {
        $record = $this->makeRecord('', 'FACT-001', '2026-07-01', null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/nif/i');

        $this->invokeBuildQrContent($record, 'https://example.com/verify', 121);
    }

    public function testBuildQrContentRejectsMissingNumserie(): void
    {
        $record = $this->makeRecord('B12345678', '', '2026-07-01', null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/numserie/i');

        $this->invokeBuildQrContent($record, 'https://example.com/verify', 121);
    }

    public function testReformatDateForQrAcceptsStrictIsoDate(): void
    {
        $this->assertSame('01-07-2026', $this->invokeReformatDateForQr('2026-07-01'));
    }

    public function testReformatDateForQrRejectsInvalidDates(): void
    {
        foreach (['2026-02-31', '2026-7-1', 'not-a-date', ''] as $date) {
            try {
                $this->invokeReformatDateForQr($date);
                $this->fail(sprintf('Expected invalid date failure for "%s".', $date));
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('fecha', $exception->getMessage());
            }
        }
    }

    public function testAmountNormalizationVariants(): void
    {
        $record = $this->makeRecord('B12345678', 'FACT-001', '2026-07-01', null);

        $this->assertSame('121', $this->parseQrQuery($this->invokeBuildQrContent($record, 'https://example.com/verify', 121))['importe']);
        $this->assertSame('121.4', $this->parseQrQuery($this->invokeBuildQrContent($record, 'https://example.com/verify', 121.4))['importe']);
        $this->assertSame('121.4', $this->parseQrQuery($this->invokeBuildQrContentRawAmount($record, 'https://example.com/verify', '121.40'))['importe']);
        $this->assertSame('0.01', $this->parseQrQuery($this->invokeBuildQrContent($record, 'https://example.com/verify', 0.01))['importe']);
    }

    public function testAmountNormalizationRejectsInvalidValues(): void
    {
        $record = $this->makeRecord('B12345678', 'FACT-001', '2026-07-01', null);

        foreach (['121,40', '', 'abc', '1.234', -1] as $amount) {
            try {
                $this->invokeBuildQrContentRawAmount($record, 'https://example.com/verify', $amount);
                $this->fail(sprintf('Expected invalid amount failure for "%s".', is_scalar($amount) ? (string) $amount : gettype($amount)));
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('importe', $exception->getMessage());
            }
        }
    }

    public function testAmountNormalizationRejectsArray(): void
    {
        $record = $this->makeRecord('B12345678', 'FACT-001', '2026-07-01', null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('importe must not be an array.');

        $this->invokeBuildQrContentRawAmount($record, 'https://example.com/verify', [100, 200]);
    }

    public function testAmountNormalizationRejectsObject(): void
    {
        $record = $this->makeRecord('B12345678', 'FACT-001', '2026-07-01', null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('importe must not be an object.');

        $this->invokeBuildQrContentRawAmount($record, 'https://example.com/verify', new \stdClass());
    }

    public function testAmountNormalizationRejectsBoolTrue(): void
    {
        $record = $this->makeRecord('B12345678', 'FACT-001', '2026-07-01', null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('importe must not be a boolean.');

        $this->invokeBuildQrContentRawAmount($record, 'https://example.com/verify', true);
    }

    public function testAmountNormalizationRejectsBoolFalse(): void
    {
        $record = $this->makeRecord('B12345678', 'FACT-001', '2026-07-01', null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('importe must not be a boolean.');

        $this->invokeBuildQrContentRawAmount($record, 'https://example.com/verify', false);
    }

    public function testBuildQrContentUsesRfc3986EncodingForNumserie(): void
    {
        $record = $this->makeRecord('B12345678', '12345678&G33', '2026-07-01', null);

        $result = $this->invokeBuildQrContentNoVerifactu($record, 'https://example.com/noverif', 121);

        $this->assertStringContainsString('numserie=12345678%26G33', $result);
        $this->assertSame('12345678&G33', $this->parseQrQuery($result)['numserie']);
    }

    public function testSoapOperationsAreBlockedInNoVerifactuMode(): void
    {
        VerifactuService::config([
            VerifactuService::QR_VERIFICATION_URL => 'https://example.com/noverif',
            VerifactuService::QR_MODE => VerifactuService::QR_MODE_NO_VERIFACTU,
        ]);

        $submission = new InvoiceSubmission();
        $cancellation = new InvoiceCancellation();
        $query = new InvoiceQuery();

        foreach ([
            static fn() => VerifactuService::registerInvoice($submission),
            static fn() => VerifactuService::cancelInvoice($cancellation),
            static fn() => VerifactuService::queryInvoices($query),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Expected SOAP operation to be blocked in No VERI*FACTU mode.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('No VERI*FACTU mode is QR-only; SOAP operations are not available.', $exception->getMessage());
            }
        }
    }

    // -------------------------------------------------------------------------
    // Task 3.1 RED — EC level M and margin/size stability assertions
    // -------------------------------------------------------------------------

    /**
     * Spec scenario: Default QR output uses AEAT-compliant error correction (level M).
     *
     * EC level M is enforced by passing ErrorCorrectionLevel::M() to Writer::writeString().
     * We verify this indirectly: the same content encoded at L vs M produces
     * different QR images (M carries more redundancy data).
     */
    public function testQrUsesErrorCorrectionLevelM(): void
    {
        $record = $this->makeRecord('B12345678', 'FACT-001', '2026-06-30', null);

        // Generate with the library's default path (which must use EC M after the fix)
        $qrM = QrGeneratorService::generateQr(
            $record,
            'https://example.com/verify',
            QrGeneratorService::DESTINATION_STRING,
            300,
            QrGeneratorService::RENDERER_SVG,
            50.0
        );

        // Independently encode the same content at L to prove the two differ.
        // This rules out "both are the same QR anyway" — level M yields a denser matrix.
        $content = 'https://example.com/verify?' . http_build_query([
            'nif' => 'B12345678',
            'numserie' => 'FACT-001',
            'fecha' => '30-06-2026',
            'importe' => 50.0,
        ], '', '&', PHP_QUERY_RFC3986);

        $writerL = new \BaconQrCode\Writer(
            new \BaconQrCode\Renderer\ImageRenderer(
                new \BaconQrCode\Renderer\RendererStyle\RendererStyle(300),
                new \BaconQrCode\Renderer\Image\SvgImageBackEnd()
            )
        );
        $qrLevelL = $writerL->writeString($content, 'ISO-8859-1', \BaconQrCode\Common\ErrorCorrectionLevel::L());

        $writerM = new \BaconQrCode\Writer(
            new \BaconQrCode\Renderer\ImageRenderer(
                new \BaconQrCode\Renderer\RendererStyle\RendererStyle(300),
                new \BaconQrCode\Renderer\Image\SvgImageBackEnd()
            )
        );
        $qrLevelM = $writerM->writeString($content, 'ISO-8859-1', \BaconQrCode\Common\ErrorCorrectionLevel::M());

        // The library-generated QR must match level M, not level L.
        $this->assertNotSame($qrLevelL, $qrLevelM, 'EC L and M must produce different QR outputs (verifies this test is meaningful)');
        $this->assertSame($qrLevelM, $qrM, 'QrGeneratorService must use EC level M per AEAT Art.21');
    }

    /**
     * Spec scenario: Size and margin tuning preserves QR content semantics.
     *
     * Two QR images with different margin/size must encode the same verification URL.
     * We verify this by extracting the URL from the SVG path data — in practice,
     * we just confirm both SVGs are non-empty and that same content at different
     * sizes still contains the same QR data marker (the SVG rect counts match dimensions
     * but the logical URL payload is identical).
     *
     * Approach: generate the expected URL, build both QRs, confirm they decode to
     * the same content by re-encoding the expected URL at both sizes and matching output.
     */
    public function testMarginAndSizeChangesDoNotAlterQrUrlSemantics(): void
    {
        $record = $this->makeRecord('B12345678', 'SER/2026/001', '2026-06-30', null);

        $qrDefault = QrGeneratorService::generateQr(
            $record,
            'https://example.com/verify',
            QrGeneratorService::DESTINATION_STRING,
            300,
            QrGeneratorService::RENDERER_SVG,
            99.0,
            4  // margin default
        );

        $qrLargeMargin = QrGeneratorService::generateQr(
            $record,
            'https://example.com/verify',
            QrGeneratorService::DESTINATION_STRING,
            300,
            QrGeneratorService::RENDERER_SVG,
            99.0,
            10 // wider margin
        );

        // Both must be non-empty SVG output
        $this->assertStringContainsString('<svg', $qrDefault);
        $this->assertStringContainsString('<svg', $qrLargeMargin);

        // The two renderings differ (different margins = different layout)
        $this->assertNotSame($qrDefault, $qrLargeMargin, 'Different margins must produce different SVG output');

        // Verify the URL payload is the same: encode the same URL at both margin settings
        // using a reference writer and confirm our service output matches.
        $expectedUrl = 'https://example.com/verify?' . http_build_query([
            'nif' => 'B12345678',
            'numserie' => 'SER/2026/001',
            'fecha' => '30-06-2026',
            'importe' => 99.0,
        ], '', '&', PHP_QUERY_RFC3986);

        $refWriterDefault = new \BaconQrCode\Writer(
            new \BaconQrCode\Renderer\ImageRenderer(
                new \BaconQrCode\Renderer\RendererStyle\RendererStyle(300, 4),
                new \BaconQrCode\Renderer\Image\SvgImageBackEnd()
            )
        );
        $refQrDefault = $refWriterDefault->writeString($expectedUrl, 'ISO-8859-1', \BaconQrCode\Common\ErrorCorrectionLevel::M());
        $this->assertSame($refQrDefault, $qrDefault, 'QR with default margin must match reference encoding at margin=4');

        $refWriterLarge = new \BaconQrCode\Writer(
            new \BaconQrCode\Renderer\ImageRenderer(
                new \BaconQrCode\Renderer\RendererStyle\RendererStyle(300, 10),
                new \BaconQrCode\Renderer\Image\SvgImageBackEnd()
            )
        );
        $refQrLarge = $refWriterLarge->writeString($expectedUrl, 'ISO-8859-1', \BaconQrCode\Common\ErrorCorrectionLevel::M());
        $this->assertSame($refQrLarge, $qrLargeMargin, 'QR with large margin must match reference encoding at margin=10');
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
        $totalAmount
    ): string {
        $rc     = new \ReflectionClass(QrGeneratorService::class);
        $method = $rc->getMethod('buildQrContent');
        $method->setAccessible(true);

        return (string) $method->invoke(null, $record, $baseUrl, $totalAmount);
    }

    /**
     * Invoke the protected buildQrContent method in No VERI*FACTU mode via reflection.
     */
    private function invokeBuildQrContentNoVerifactu(
        InvoiceRecord $record,
        string $baseUrl,
        $totalAmount
    ): string {
        $rc     = new \ReflectionClass(QrGeneratorService::class);
        $method = $rc->getMethod('buildQrContent');
        $method->setAccessible(true);

        return (string) $method->invoke(null, $record, $baseUrl, $totalAmount, true);
    }

    private function invokeBuildQrContentRawAmount(
        InvoiceRecord $record,
        string $baseUrl,
        $totalAmount
    ): string {
        return $this->invokeBuildQrContent($record, $baseUrl, $totalAmount);
    }

    private function invokeReformatDateForQr($date): string
    {
        $rc = new \ReflectionClass(QrGeneratorService::class);
        $method = $rc->getMethod('reformatDateForQr');
        $method->setAccessible(true);

        return (string) $method->invoke(null, $date);
    }

    private function parseQrQuery(string $url): array
    {
        $query = parse_url($url, PHP_URL_QUERY);
        $this->assertIsString($query);

        parse_str($query, $params);

        return $params;
    }
}
