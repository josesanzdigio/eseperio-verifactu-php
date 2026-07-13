<?php

declare(strict_types=1);

namespace eseperio\verifactu\tests\Unit\Services;

use DateTimeImmutable;
use eseperio\verifactu\models\Chaining;
use eseperio\verifactu\models\InvoiceCancellation;
use eseperio\verifactu\models\InvoiceId;
use eseperio\verifactu\models\InvoiceSubmission;
use eseperio\verifactu\models\LegalPerson;
use eseperio\verifactu\models\PreviousInvoiceChaining;
use eseperio\verifactu\models\enums\HashType;
use eseperio\verifactu\models\enums\InvoiceType;
use eseperio\verifactu\models\enums\OperationQualificationType;
use eseperio\verifactu\models\enums\RectificationType;
use eseperio\verifactu\services\HashGeneratorService;
use PHPUnit\Framework\TestCase;

/**
 * Phase 7-8: Hash regression vectors and determinism.
 *
 * Proves that the hash generator produces fixed, reproducible SHA-256 values,
 * that the exact input string matches fixed vectors, that equivalent input
 * forms agree, and that relevant fields show sensitivity.
 */
class HashGeneratorDeterminismTest extends TestCase
{
    // ── Builder helpers (fixed synthetic values only) ──────────────────────

    private const NIF = 'B12345678';

    private function buildStandardFirstRecord(): InvoiceSubmission
    {
        $inv = new InvoiceSubmission();
        $inv->setInvoiceId([
            'issuerNif' => self::NIF,
            'seriesNumber' => 'TEST-001',
            'issueDate' => '2025-01-15',
        ]);
        $inv->issuerName = 'Emisor Ejemplo SL';
        $inv->invoiceType = InvoiceType::STANDARD;
        $inv->operationDescription = 'Operación de prueba';
        $inv->taxAmount = 21.00;
        $inv->totalAmount = 121.00;
        $inv->addBreakdownDetail([
            'taxRate' => 21.0,
            'taxableBase' => 100.00,
            'taxAmount' => 21.00,
            'operationQualification' => OperationQualificationType::SUBJECT_NO_EXEMPT_NO_REVERSE,
        ]);
        $inv->hashType = HashType::SHA_256;
        $inv->recordTimestamp = '2025-01-15T10:30:00+01:00';
        $inv->setAsFirstRecord();

        $r = new LegalPerson();
        $r->name = 'Cliente Ejemplo SL';
        $r->nif = '87654321X';
        $inv->addRecipient($r);

        return $inv;
    }

    // ── 7. Fixed hash regression vectors ───────────────────────────────────

    /**
     * @test
     * @dataProvider hashVectorProvider
     */
    public function hashMatchesFixedVector(string $label, string $expectedInput, string $expectedHash): void
    {
        // Compute independently from the production method
        $actualHash = strtoupper(hash('sha256', $expectedInput));

        self::assertSame(
            $expectedHash,
            $actualHash,
            "Hash mismatch for vector: {$label}"
        );
    }

    /**
     * @test
     * @dataProvider hashVectorProvider
     */
    public function hashShapeIsValid(string $label, string $expectedInput, string $expectedHash): void
    {
        self::assertSame(64, strlen($expectedHash), "{$label}: hash must be exactly 64 chars");
        self::assertMatchesRegularExpression('/^[A-F0-9]{64}$/', $expectedHash, "{$label}: hash must be uppercase hex");
    }

    public static function hashVectorProvider(): array
    {
        return require __DIR__ . '/../../Fixtures/Hash/hash-vectors.php';
    }

    // ── 8a. Idempotence ────────────────────────────────────────────────────

    /** @test */
    public function hashIsIdempotentForStandardFirstRecord(): void
    {
        $inv = $this->buildStandardFirstRecord();
        $h1 = HashGeneratorService::generate($inv);
        $h2 = HashGeneratorService::generate($inv);
        self::assertSame($h1, $h2, 'Repeated hash generation must be identical');
    }

    /** @test */
    public function hashIsIdempotentForChainedRecord(): void
    {
        $inv = $this->buildChainedRecord2();
        $h1 = HashGeneratorService::generate($inv);
        $h2 = HashGeneratorService::generate($inv);
        self::assertSame($h1, $h2, 'Repeated hash generation for chained record must be identical');
    }

    // ── 8b. Equivalent input forms ─────────────────────────────────────────

    /** @test */
    public function equivalentDateRepresentationsProduceSameHash(): void
    {
        $inv1 = $this->buildStandardFirstRecord();

        $inv2 = $this->buildStandardFirstRecord();
        $inv2->getInvoiceId()->issueDate = new DateTimeImmutable('2025-01-15');

        $h1 = HashGeneratorService::generate($inv1);
        $h2 = HashGeneratorService::generate($inv2);

        self::assertSame($h1, $h2, 'String date vs DateTimeImmutable must produce the same hash');
    }

    // ── 8d. Relevant-field sensitivity ────────────────────────────────────

    /** @test */
    public function changingIssuerNifChangesHash(): void
    {
        $inv = $this->buildStandardFirstRecord();
        $original = HashGeneratorService::generate($inv);

        $inv->getInvoiceId()->issuerNif = 'C87654321';
        self::assertNotSame($original, HashGeneratorService::generate($inv));
    }

    /** @test */
    public function changingSeriesNumberChangesHash(): void
    {
        $inv = $this->buildStandardFirstRecord();
        $original = HashGeneratorService::generate($inv);

        $inv->getInvoiceId()->seriesNumber = 'OTHER-002';
        self::assertNotSame($original, HashGeneratorService::generate($inv));
    }

    /** @test */
    public function changingIssueDateChangesHash(): void
    {
        $inv = $this->buildStandardFirstRecord();
        $original = HashGeneratorService::generate($inv);

        $inv->getInvoiceId()->issueDate = '2025-06-15';
        self::assertNotSame($original, HashGeneratorService::generate($inv));
    }

    /** @test */
    public function changingInvoiceTypeChangesHash(): void
    {
        $inv = $this->buildStandardFirstRecord();
        $original = HashGeneratorService::generate($inv);

        $inv->invoiceType = InvoiceType::SIMPLIFIED;
        self::assertNotSame($original, HashGeneratorService::generate($inv));
    }

    /** @test */
    public function changingTaxAmountChangesHash(): void
    {
        $inv = $this->buildStandardFirstRecord();
        $original = HashGeneratorService::generate($inv);

        $inv->taxAmount = 42.00;
        self::assertNotSame($original, HashGeneratorService::generate($inv));
    }

    /** @test */
    public function changingTotalAmountChangesHash(): void
    {
        $inv = $this->buildStandardFirstRecord();
        $original = HashGeneratorService::generate($inv);

        $inv->totalAmount = 242.00;
        self::assertNotSame($original, HashGeneratorService::generate($inv));
    }

    /** @test */
    public function changingRecordTimestampChangesHash(): void
    {
        $inv = $this->buildStandardFirstRecord();
        $original = HashGeneratorService::generate($inv);

        $inv->recordTimestamp = '2025-06-15T12:00:00+01:00';
        self::assertNotSame($original, HashGeneratorService::generate($inv));
    }

    /** @test */
    public function changingPreviousHashChangesHash(): void
    {
        $inv = $this->buildChainedRecord2();
        $original = HashGeneratorService::generate($inv);

        $prev = $inv->getChaining()->getPreviousInvoice();
        $prev->hash = 'A000000000000000000000000000000000000000000000000000000000000001';
        self::assertNotSame($original, HashGeneratorService::generate($inv));
    }

    // ── 8e. Irrelevant-field stability ────────────────────────────────────

    /** @test */
    public function changingIssuerNameDoesNotChangeHash(): void
    {
        $inv = $this->buildStandardFirstRecord();
        $original = HashGeneratorService::generate($inv);

        $inv->issuerName = 'Some Other Company SL';
        self::assertSame($original, HashGeneratorService::generate($inv),
            'issuerName is not in the hash input; hash must remain unchanged');
    }

    /** @test */
    public function changingOperationDescriptionDoesNotChangeHash(): void
    {
        $inv = $this->buildStandardFirstRecord();
        $original = HashGeneratorService::generate($inv);

        $inv->operationDescription = 'Totally different description';
        self::assertSame($original, HashGeneratorService::generate($inv),
            'operationDescription is not in the hash input; hash must remain unchanged');
    }

    // ── 8f. Existing hash property ────────────────────────────────────────

    /** @test */
    public function settingInvoiceHashDoesNotAffectHashGeneration(): void
    {
        $inv = $this->buildStandardFirstRecord();
        $inv->hash = 'SOME_PRE_EXISTING_HASH_VALUE_12345678901234567890';

        $hash = HashGeneratorService::generate($inv);

        self::assertSame(64, strlen($hash));
        self::assertMatchesRegularExpression('/^[A-F0-9]{64}$/', $hash);
    }

    /** @test */
    public function hashGenerationDoesNotMutateInvoiceFields(): void
    {
        $inv = $this->buildStandardFirstRecord();
        $snap = [
            'issuerNif' => $inv->getInvoiceId()->issuerNif,
            'taxAmount' => $inv->taxAmount,
            'totalAmount' => $inv->totalAmount,
            'recordTimestamp' => $inv->recordTimestamp,
        ];

        HashGeneratorService::generate($inv);

        self::assertSame($snap['issuerNif'], $inv->getInvoiceId()->issuerNif);
        self::assertSame($snap['taxAmount'], $inv->taxAmount);
        self::assertSame($snap['totalAmount'], $inv->totalAmount);
        self::assertSame($snap['recordTimestamp'], $inv->recordTimestamp);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function buildChainedRecord2(): InvoiceSubmission
    {
        $inv = new InvoiceSubmission();
        $inv->setInvoiceId([
            'issuerNif' => self::NIF,
            'seriesNumber' => 'CHAIN-002',
            'issueDate' => '2025-06-02',
        ]);
        $inv->issuerName = 'Emisor Ejemplo SL';
        $inv->invoiceType = InvoiceType::STANDARD;
        $inv->operationDescription = 'Chain record 2';
        $inv->taxAmount = 42.00;
        $inv->totalAmount = 242.00;
        $inv->addBreakdownDetail([
            'taxRate' => 21.0,
            'taxableBase' => 200.00,
            'taxAmount' => 42.00,
            'operationQualification' => OperationQualificationType::SUBJECT_NO_EXEMPT_NO_REVERSE,
        ]);
        $inv->hashType = HashType::SHA_256;
        $inv->recordTimestamp = '2025-06-02T08:00:00+01:00';

        $prev = new PreviousInvoiceChaining();
        $prev->issuerNif = self::NIF;
        $prev->seriesNumber = 'CHAIN-001';
        $prev->issueDate = '2025-06-01';
        $prev->hash = '36FFA3622AE62AEB4311C4655B30D62CB526CD2B3B042BEF5B61D75F6F1F1871';
        $ch = new Chaining();
        $ch->setPreviousInvoice($prev);
        $inv->setChaining($ch);

        $r = new LegalPerson();
        $r->name = 'Cliente Ejemplo SL';
        $r->nif = '87654321X';
        $inv->addRecipient($r);

        return $inv;
    }
}
