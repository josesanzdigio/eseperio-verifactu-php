<?php

declare(strict_types=1);

namespace eseperio\verifactu\tests\Unit;

use eseperio\verifactu\Verifactu;
use eseperio\verifactu\models\InvoiceId;
use eseperio\verifactu\models\InvoiceSubmission;
use eseperio\verifactu\services\VerifactuService;
use PHPUnit\Framework\TestCase;
use Zxing\QrReader;

/**
 * End-to-end round-trip tests for QR code artifacts.
 *
 * Generates real PNG QR images using the production GD renderer,
 * decodes them with an independent QR decoder (ZXing PHP port),
 * and asserts exact URL equality.
 */
class QrRoundTripTest extends TestCase
{
    protected function tearDown(): void
    {
        VerifactuService::config([VerifactuService::QR_VERIFICATION_URL => '']);
    }

    public function testVerifactuProductionQrRoundTrip(): void
    {
        Verifactu::config(
            '/path/to/dummy/cert.p12',
            'dummy-password',
            Verifactu::TYPE_CERTIFICATE,
            Verifactu::ENVIRONMENT_PRODUCTION,
        );

        $invoice = $this->makeInvoice(
            'B12345678',
            'SER/A&B 1',
            '2026-07-01',
            121.40,
            'test-hash-verifactu-should-not-appear-in-qr',
        );

        $png = Verifactu::generateInvoiceQr($invoice);
        $this->assertPngSignature($png);

        $expectedUrl = 'https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR'
            . '?nif=B12345678'
            . '&numserie=SER%2FA%26B%201'
            . '&fecha=01-07-2026'
            . '&importe=121.4';

        $decodedText = $this->decodePngRoundTrip($png);
        $this->assertSame($expectedUrl, $decodedText);

        $query = $this->parseQuery($decodedText);
        $this->assertSame(['nif', 'numserie', 'fecha', 'importe'], array_keys($query));
        $this->assertSame('SER/A&B 1', $query['numserie']);
        $this->assertSame('121.4', $query['importe']);
        $this->assertArrayNotHasKey('huella', $query);
        $this->assertArrayNotHasKey('num', $query);
        $this->assertArrayNotHasKey('formato', $query);
        $this->assertArrayNotHasKey('idioma', $query);
    }

    public function testNoVerifactuSandboxQrRoundTrip(): void
    {
        Verifactu::configNoVerifactu(Verifactu::ENVIRONMENT_SANDBOX);

        $invoice = $this->makeInvoice(
            'A12345678',
            'RECTIF-001',
            '2026-06-15',
            -242.40,
            'test-hash-noverifactu-should-not-appear-in-qr',
        );

        $png = Verifactu::generateNoVerifactuQr($invoice);
        $this->assertPngSignature($png);

        $expectedUrl = 'https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQRNoVerifactu'
            . '?nif=A12345678'
            . '&numserie=RECTIF-001'
            . '&fecha=15-06-2026'
            . '&importe=-242.4';

        $decodedText = $this->decodePngRoundTrip($png);
        $this->assertSame($expectedUrl, $decodedText);

        $query = $this->parseQuery($decodedText);
        $this->assertSame(['nif', 'numserie', 'fecha', 'importe'], array_keys($query));
        $this->assertSame('-242.4', $query['importe']);
        $this->assertArrayNotHasKey('huella', $query);
        $this->assertArrayNotHasKey('num', $query);
        $this->assertArrayNotHasKey('formato', $query);
        $this->assertArrayNotHasKey('idioma', $query);
    }

    public function testDecoderRejectsNonQrPng(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'qr_rt_invalid_');
        $pngFile = $tmpFile . '.png';
        unlink($tmpFile);

        try {
            $im = imagecreatetruecolor(1, 1);
            imagepng($im, $pngFile);
            imagedestroy($im);

            $reader = new QrReader($pngFile);
            $decodedText = $reader->text();

            $this->assertFalse($decodedText, 'Non-QR PNG must not decode to any text');
        } finally {
            if (file_exists($pngFile)) {
                unlink($pngFile);
            }
        }
    }

    private function assertPngSignature(string $data): void
    {
        $this->assertNotEmpty($data, 'QR output must not be empty');
        $this->assertSame(
            "\x89PNG\r\n\x1a\n",
            substr($data, 0, 8),
            'QR output must be a real PNG (valid 8-byte PNG signature)',
        );
    }

    private function decodePngRoundTrip(string $pngData): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'qr_rt_');
        $pngFile = $tmpFile . '.png';
        unlink($tmpFile);

        try {
            $written = file_put_contents($pngFile, $pngData);
            if ($written === false) {
                throw new \RuntimeException('Failed to write temporary PNG file: ' . $pngFile);
            }

            $reader = new QrReader($pngFile);
            $decodedText = $reader->text();

            if ($decodedText === null || $decodedText === false || $decodedText === '') {
                $this->fail(
                    'QR decoder returned ' . var_export($decodedText, true)
                    . ' — round-trip decoding failed',
                );
            }

            return $decodedText;
        } finally {
            if (file_exists($pngFile)) {
                unlink($pngFile);
            }
        }
    }

    private function makeInvoice(
        string $nif,
        string $seriesNumber,
        string $issueDate,
        float $totalAmount,
        string $hash,
    ): InvoiceSubmission {
        $invoice = new InvoiceSubmission();
        $invoiceId = new InvoiceId();
        $invoiceId->issuerNif = $nif;
        $invoiceId->seriesNumber = $seriesNumber;
        $invoiceId->issueDate = $issueDate;
        $invoice->setInvoiceId($invoiceId);
        $invoice->totalAmount = $totalAmount;
        $invoice->hash = $hash;

        return $invoice;
    }

    private function parseQuery(string $url): array
    {
        $query = parse_url($url, PHP_URL_QUERY);
        $this->assertIsString($query, 'URL must contain a query string');

        parse_str($query, $params);

        return $params;
    }
}
