<?php

declare(strict_types=1);

namespace eseperio\verifactu\tests\Unit\Services;

use DateTimeImmutable;
use eseperio\verifactu\models\Chaining;
use eseperio\verifactu\models\InvoiceCancellation;
use eseperio\verifactu\models\InvoiceId;
use eseperio\verifactu\models\InvoiceRecord;
use eseperio\verifactu\models\InvoiceSubmission;
use eseperio\verifactu\models\PreviousInvoiceChaining;
use eseperio\verifactu\models\enums\InvoiceType;
use eseperio\verifactu\services\HashGeneratorService;
use eseperio\verifactu\services\InvoiceSerializer;
use PHPUnit\Framework\TestCase;

/**
 * AEAT official golden vectors for the VeriFactu hash ("huella").
 *
 * Source: AEAT "Detalle de las especificaciones tecnicas para generacion
 * de la huella o hash de los registros de facturacion", v0.1.2 (27/08/2024),
 * §6 Ejemplos (pp. 10-12). Local copy:
 * docs/aeat/Algoritmo de calculo.../especificaciones-hash.pdf
 *
 * Every expected hash below is a literal published by AEAT. It must NEVER
 * be recomputed from the implementation under test.
 */
class AeatHashGoldenVectorTest extends TestCase
{
    private const AEAT_NIF = '89890001K';

    private const CASE1_CANONICAL = 'IDEmisorFactura=89890001K&NumSerieFactura=12345678/G33&FechaExpedicionFactura=01-01-2024&TipoFactura=F1&CuotaTotal=12.35&ImporteTotal=123.45&Huella=&FechaHoraHusoGenRegistro=2024-01-01T19:20:30+01:00';
    private const CASE1_HASH = '3C464DAF61ACB827C65FDA19F352A4E3BDC2C640E9E9FC4CC058073F38F12F60';

    private const CASE2_HASH = 'F7B94CFD8924EDFF273501B01EE5153E4CE8F259766F88CF6ACB8935802A2B97';

    private const CASE3_HASH = '177547C0D57AC74748561D054A9CEC14B4C4EA23D1BEFD6F2E69E3A388F90C68';

    // ── AEAT Caso 1: RegistroAlta, first record ─────────────────────────────

    /** @test */
    public function aeatCase1FirstAltaRecordMatchesOfficialHash(): void
    {
        $inv = $this->buildAlta('12345678/G33', '2024-01-01', 12.35, 123.45, '', '2024-01-01T19:20:30+01:00');

        self::assertSame(self::CASE1_HASH, HashGeneratorService::generate($inv));
    }

    /** @test */
    public function aeatCase1CanonicalUsesFiscalDate(): void
    {
        $inv = $this->buildAlta('12345678/G33', '2024-01-01', 12.35, 123.45, '', '2024-01-01T19:20:30+01:00');

        self::assertSame(self::CASE1_CANONICAL, self::canonicalOf($inv));
    }

    // ── AEAT Caso 2: RegistroAlta, chained record ───────────────────────────

    /** @test */
    public function aeatCase2ChainedAltaRecordMatchesOfficialHash(): void
    {
        $inv = $this->buildAlta('12345679/G34', '2024-01-01', 12.35, 123.45, self::CASE1_HASH, '2024-01-01T19:20:35+01:00');

        self::assertSame(self::CASE2_HASH, HashGeneratorService::generate($inv));
        self::assertStringContainsString('FechaExpedicionFactura=01-01-2024&', self::canonicalOf($inv));
    }

    // ── AEAT Caso 3: RegistroAnulacion, chained record ──────────────────────

    /** @test */
    public function aeatCase3CancellationRecordMatchesOfficialHash(): void
    {
        $can = $this->buildCancellation('12345679/G34', '2024-01-01', self::CASE2_HASH, '2024-01-01T19:20:40+01:00');

        self::assertSame(self::CASE3_HASH, HashGeneratorService::generate($can));
        self::assertStringContainsString('FechaExpedicionFacturaAnulada=01-01-2024&', self::canonicalOf($can));
    }

    // ── Cross-test: hash canonical date == XML date ─────────────────────────

    /** @test */
    public function altaHashDateMatchesXmlDate(): void
    {
        $inv = $this->buildAlta('12345678/G33', '2024-01-01', 12.35, 123.45, '', '2024-01-01T19:20:30+01:00');

        $canonical = self::canonicalOf($inv);
        $xmlDate = $this->xmlDate($inv, 'FechaExpedicionFactura');

        self::assertStringContainsString('FechaExpedicionFactura=01-01-2024', $canonical);
        self::assertSame('01-01-2024', $xmlDate);
        // The stored model value stays ISO; only the fiscal representation changes.
        self::assertSame('2024-01-01', $inv->getInvoiceId()->getIssueDate());
    }

    /** @test */
    public function cancellationHashDateMatchesXmlDate(): void
    {
        $can = $this->buildCancellation('12345679/G34', '2024-01-01', self::CASE2_HASH, '2024-01-01T19:20:40+01:00');

        $canonical = self::canonicalOf($can);
        $xml = InvoiceSerializer::toCancellationXml($can, false)->saveXML();
        self::assertNotFalse($xml);
        preg_match('/<sf:FechaExpedicionFacturaAnulada>([^<]+)</', $xml, $m);

        self::assertStringContainsString('FechaExpedicionFacturaAnulada=01-01-2024', $canonical);
        self::assertSame('01-01-2024', $m[1] ?? null);
        self::assertSame('2024-01-01', $can->getInvoiceId()->getIssueDate());
    }

    // ── Public input contract (BC) ──────────────────────────────────────────

    /** @test */
    public function isoPublicInputRemainsValid(): void
    {
        $id = new InvoiceId();
        $id->issuerNif = self::AEAT_NIF;
        $id->seriesNumber = '12345678/G33';
        $id->issueDate = '2024-01-01';

        self::assertSame([], $id->validate());
    }

    /** @test */
    public function dateTimeInputFlowsToFiscalHash(): void
    {
        $inv = $this->buildAlta('12345678/G33', new DateTimeImmutable('2024-01-01 12:34:56'), 12.35, 123.45, '', '2024-01-01T19:20:30+01:00');

        self::assertSame('2024-01-01', $inv->getInvoiceId()->getIssueDate());
        self::assertSame(self::CASE1_HASH, HashGeneratorService::generate($inv));
    }

    /** @test */
    public function invalidDateIsRejectedAndNeverSilentlyConverted(): void
    {
        $id = new InvoiceId();
        $id->issuerNif = self::AEAT_NIF;
        $id->seriesNumber = '12345678/G33';
        $id->issueDate = 'not-a-date';

        self::assertNotEmpty($id->validate());

        $inv = $this->buildAlta('12345678/G33', '2024-01-01', 12.35, 123.45, '', '2024-01-01T19:20:30+01:00');
        $inv->getInvoiceId()->issueDate = 'not-a-date';

        // Passes through untouched instead of being "fixed" into a fiscal date.
        self::assertStringContainsString('FechaExpedicionFactura=not-a-date', self::canonicalOf($inv));
    }

    // ── Symposium regression (synthetic data, same shape as AUT-26000004) ───

    /** @test */
    public function symposiumShapedRecordHashesFiscalDate(): void
    {
        $prevHash = str_repeat('B', 64);
        $inv = $this->buildAlta('AUT-26000004', '2026-10-13', 43.81, 420.00, $prevHash, '2026-10-13T09:08:12+00:00');

        $expectedCanonical = 'IDEmisorFactura=89890001K&NumSerieFactura=AUT-26000004&FechaExpedicionFactura=13-10-2026&TipoFactura=F1&CuotaTotal=43.81&ImporteTotal=420.00&Huella=' . $prevHash . '&FechaHoraHusoGenRegistro=2026-10-13T09:08:12+00:00';

        self::assertSame($expectedCanonical, self::canonicalOf($inv));
        self::assertSame(strtoupper(hash('sha256', $expectedCanonical)), HashGeneratorService::generate($inv));
        self::assertSame('13-10-2026', $this->xmlDate($inv, 'FechaExpedicionFactura'));
    }

    // ── Builders ────────────────────────────────────────────────────────────

    private function buildAlta(string $seriesNumber, mixed $issueDate, float $tax, float $total, string $prevHash, string $timestamp): InvoiceSubmission
    {
        $inv = new InvoiceSubmission();
        $inv->setInvoiceId([
            'issuerNif' => self::AEAT_NIF,
            'seriesNumber' => $seriesNumber,
            'issueDate' => $issueDate,
        ]);
        $inv->issuerName = 'Emisor Ejemplo SL';
        $inv->invoiceType = InvoiceType::STANDARD;
        $inv->operationDescription = 'Operacion de prueba';
        $inv->taxAmount = $tax;
        $inv->totalAmount = $total;
        $inv->recordTimestamp = $timestamp;

        $chaining = new Chaining();
        if ($prevHash === '') {
            $chaining->setAsFirstRecord();
        } else {
            $prev = new PreviousInvoiceChaining();
            $prev->issuerNif = self::AEAT_NIF;
            $prev->seriesNumber = 'PREV-001';
            $prev->issueDate = '2024-01-01';
            $prev->hash = $prevHash;
            $chaining->setPreviousInvoice($prev);
        }
        $inv->setChaining($chaining);

        return $inv;
    }

    private function buildCancellation(string $seriesNumber, string $issueDate, string $prevHash, string $timestamp): InvoiceCancellation
    {
        $can = new InvoiceCancellation();
        $can->setInvoiceId([
            'issuerNif' => self::AEAT_NIF,
            'seriesNumber' => $seriesNumber,
            'issueDate' => $issueDate,
        ]);
        $can->recordTimestamp = $timestamp;

        $prev = new PreviousInvoiceChaining();
        $prev->issuerNif = self::AEAT_NIF;
        $prev->seriesNumber = 'PREV-001';
        $prev->issueDate = '2024-01-01';
        $prev->hash = $prevHash;
        $chaining = new Chaining();
        $chaining->setPreviousInvoice($prev);
        $can->setChaining($chaining);

        return $can;
    }

    private static function canonicalOf(InvoiceRecord $record): string
    {
        $method = new \ReflectionMethod(HashGeneratorService::class, 'buildDataString');

        return $method->invoke(null, $record);
    }

    private function xmlDate(InvoiceSubmission $inv, string $node): string
    {
        $xml = InvoiceSerializer::toInvoiceXml($inv, false)->saveXML();
        self::assertNotFalse($xml);
        preg_match('/<sf:' . preg_quote($node, '/') . '>([^<]+)</', $xml, $m);

        return $m[1] ?? '';
    }
}
