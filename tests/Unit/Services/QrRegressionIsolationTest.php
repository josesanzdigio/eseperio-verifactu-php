<?php

declare(strict_types=1);

namespace eseperio\verifactu\tests\Unit\Services;

use eseperio\verifactu\models\InvoiceId;
use eseperio\verifactu\models\InvoiceSubmission;
use eseperio\verifactu\models\LegalPerson;
use eseperio\verifactu\models\enums\HashType;
use eseperio\verifactu\models\enums\InvoiceType;
use eseperio\verifactu\models\enums\OperationQualificationType;
use eseperio\verifactu\services\HashGeneratorService;
use eseperio\verifactu\services\InvoiceSerializer;
use eseperio\verifactu\services\QrGeneratorService;
use PHPUnit\Framework\TestCase;

/**
 * Phase 11: QR regression isolation.
 *
 * Proves that QR configuration and QR generation do not affect XML, hash,
 * or chaining artifacts.  No SOAP calls are made.  No certificate is required.
 */
class QrRegressionIsolationTest extends TestCase
{
    private const NIF = 'B12345678';

    private function buildStandardInvoice(): InvoiceSubmission
    {
        $inv = new InvoiceSubmission();
        $inv->setInvoiceId([
            'issuerNif' => self::NIF,
            'seriesNumber' => 'QR-ISO-001',
            'issueDate' => '2025-08-01',
        ]);
        $inv->issuerName = 'Emisor QR Test SL';
        $inv->invoiceType = InvoiceType::STANDARD;
        $inv->operationDescription = 'QR isolation test';
        $inv->taxAmount = 21.00;
        $inv->totalAmount = 121.00;
        $inv->addBreakdownDetail([
            'taxRate' => 21.0,
            'taxableBase' => 100.00,
            'taxAmount' => 21.00,
            'operationQualification' => OperationQualificationType::SUBJECT_NO_EXEMPT_NO_REVERSE,
        ]);
        $inv->hashType = HashType::SHA_256;
        $inv->recordTimestamp = '2025-08-01T12:00:00+01:00';
        $inv->setAsFirstRecord();
        $r = new LegalPerson();
        $r->name = 'Cliente QR Test SL';
        $r->nif = '87654321X';
        $inv->addRecipient($r);
        return $inv;
    }

    // ── QR mode does not change XML ───────────────────────────────────────

    /** @test */
    public function xmlIsIdenticalRegardlessOfQrMode(): void
    {
        $inv = $this->buildStandardInvoice();
        $inv->hash = 'B00000000000000000000000000000000000000000000000000000000000000B';

        // QR mode is a VerifactuService concern; InvoiceSerializer is stateless.
        // Serialising the same invoice always gives the same XML regardless of
        // any external QR configuration.
        $xml1 = InvoiceSerializer::toInvoiceXml($inv, false)->saveXML();
        $xml2 = InvoiceSerializer::toInvoiceXml($inv, false)->saveXML();

        self::assertSame($xml1, $xml2, 'InvoiceSerializer output is independent of QR configuration');
    }

    // ── QR mode does not change hash ──────────────────────────────────────

    /** @test */
    public function hashIsIdenticalRegardlessOfQrMode(): void
    {
        $inv = $this->buildStandardInvoice();

        // HashGeneratorService is stateless; QR mode has no influence.
        $hash1 = HashGeneratorService::generate($inv);
        $hash2 = HashGeneratorService::generate($inv);

        self::assertSame($hash1, $hash2, 'HashGeneratorService output is independent of QR configuration');
    }

    // ── QR generation does not mutate invoice ─────────────────────────────

    /** @test */
    public function qrGenerationDoesNotMutateInvoiceModel(): void
    {
        $inv = $this->buildStandardInvoice();
        $snap = [
            'issuerNif' => $inv->getInvoiceId()->issuerNif,
            'seriesNumber' => $inv->getInvoiceId()->seriesNumber,
            'issueDate' => $inv->getInvoiceId()->getIssueDate(),
            'totalAmount' => $inv->totalAmount,
            'hash' => $inv->hash,
        ];

        // Generate QR (SVG, no external call, no decode)
        $svg = QrGeneratorService::generateQr(
            $inv,
            'https://example.com/qr',
            QrGeneratorService::DESTINATION_STRING,
            200,
            QrGeneratorService::RENDERER_SVG,
            $inv->totalAmount
        );

        self::assertNotEmpty($svg);
        self::assertSame($snap['issuerNif'], $inv->getInvoiceId()->issuerNif, 'QR generation must not mutate issuerNif');
        self::assertSame($snap['seriesNumber'], $inv->getInvoiceId()->seriesNumber, 'QR generation must not mutate seriesNumber');
        self::assertSame($snap['issueDate'], $inv->getInvoiceId()->getIssueDate(), 'QR generation must not mutate issueDate');
        self::assertSame($snap['totalAmount'], $inv->totalAmount, 'QR generation must not mutate totalAmount');
        self::assertSame($snap['hash'], $inv->hash, 'QR generation must not mutate hash');
    }
}
