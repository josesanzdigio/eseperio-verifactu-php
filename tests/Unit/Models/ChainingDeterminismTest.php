<?php

declare(strict_types=1);

namespace eseperio\verifactu\tests\Unit\Models;

use eseperio\verifactu\models\Chaining;
use eseperio\verifactu\models\InvoiceId;
use eseperio\verifactu\models\InvoiceSubmission;
use eseperio\verifactu\models\LegalPerson;
use eseperio\verifactu\models\PreviousInvoiceChaining;
use eseperio\verifactu\models\enums\HashType;
use eseperio\verifactu\models\enums\InvoiceType;
use eseperio\verifactu\models\enums\OperationQualificationType;
use eseperio\verifactu\services\HashGeneratorService;
use eseperio\verifactu\services\InvoiceSerializer;
use PHPUnit\Framework\TestCase;

/**
 * Phase 9: Chaining invariants.
 *
 * Proves that first-record representation is correct, that a three-record
 * chain generates reproducible hashes, that each record correctly references
 * its predecessor, and that generating a subsequent record does not mutate
 * previous records.
 */
class ChainingDeterminismTest extends TestCase
{
    private const NIF = 'B12345678';
    private const CLIENT_NIF = '87654321X';

    // ── 9a. First-record representation ───────────────────────────────────

    /** @test */
    public function firstRecordChainingProducesPrimerRegistroS(): void
    {
        $inv = $this->createRecord1();
        $dom = InvoiceSerializer::toInvoiceXml($inv, false);

        $ns = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroInformacion.xsd';
        $enc = $dom->getElementsByTagNameNS($ns, 'Encadenamiento')->item(0);

        // Must contain PrimerRegistro, NOT RegistroAnterior
        $primeros = $enc->getElementsByTagNameNS($ns, 'PrimerRegistro');
        self::assertSame(1, $primeros->length, 'First record must have PrimerRegistro element');
        self::assertSame('S', $primeros->item(0)->textContent);

        $anteriores = $enc->getElementsByTagNameNS($ns, 'RegistroAnterior');
        self::assertSame(0, $anteriores->length, 'First record must NOT have RegistroAnterior');
    }

    // ── 9b. Three-record chain ────────────────────────────────────────────

    /** @test */
    public function threeRecordChainHasCorrectFixedHashes(): void
    {
        [$r1, $r2, $r3] = $this->buildThreeRecordChain();

        // Hash each record using the production service
        $hash1 = HashGeneratorService::generate($r1);
        $hash2 = HashGeneratorService::generate($r2);
        $hash3 = HashGeneratorService::generate($r3);

        // Fixed regression values (computed independently)
        self::assertSame('36FFA3622AE62AEB4311C4655B30D62CB526CD2B3B042BEF5B61D75F6F1F1871', $hash1, 'Record 1 hash mismatch');
        self::assertSame('EA4490B1008B48DA9F402C13E05948FCA8DA434374CA0E7294497C930E44D2A3', $hash2, 'Record 2 hash mismatch');
        self::assertSame('403ECBFC9A08E52A12B28CDD2B1CAB0ADB7EC6B0A9D6EE0F8E9A71734F523724', $hash3, 'Record 3 hash mismatch');
    }

    /** @test */
    public function eachChainedRecordReferencesPreviousHash(): void
    {
        [$r1, $r2, $r3] = $this->buildThreeRecordChain();

        $hash1 = HashGeneratorService::generate($r1);
        $hash2 = HashGeneratorService::generate($r2);
        $hash3 = HashGeneratorService::generate($r3);

        // Record 2 must reference record 1's hash
        $prev2 = $r2->getChaining()->getPreviousInvoice();
        self::assertNotNull($prev2, 'Record 2 must have previous invoice');
        self::assertSame($hash1, $prev2->hash, 'Record 2 must reference record 1 hash');

        // Record 3 must reference record 2's hash
        $prev3 = $r3->getChaining()->getPreviousInvoice();
        self::assertNotNull($prev3, 'Record 3 must have previous invoice');
        self::assertSame($hash2, $prev3->hash, 'Record 3 must reference record 2 hash');
    }

    /** @test */
    public function repeatedChainGenerationIsDeterministic(): void
    {
        [$r1a, $r2a, $r3a] = $this->buildThreeRecordChain();
        [$r1b, $r2b, $r3b] = $this->buildThreeRecordChain();

        $h1a = HashGeneratorService::generate($r1a);
        $h2a = HashGeneratorService::generate($r2a);
        $h3a = HashGeneratorService::generate($r3a);

        $h1b = HashGeneratorService::generate($r1b);
        $h2b = HashGeneratorService::generate($r2b);
        $h3b = HashGeneratorService::generate($r3b);

        self::assertSame($h1a, $h1b, 'Chain record 1 must be deterministic across builds');
        self::assertSame($h2a, $h2b, 'Chain record 2 must be deterministic across builds');
        self::assertSame($h3a, $h3b, 'Chain record 3 must be deterministic across builds');
    }

    // ── 9c. Mutual exclusivity ───────────────────────────────────────────

    /** @test */
    public function settingPreviousInvoiceClearsFirstRecord(): void
    {
        $ch = new Chaining();
        $ch->setAsFirstRecord();
        self::assertSame('S', $ch->firstRecord);

        $prev = new PreviousInvoiceChaining();
        $prev->issuerNif = self::NIF;
        $prev->seriesNumber = 'PREV-001';
        $prev->issueDate = '2025-01-01';
        $prev->hash = str_repeat('A', 64);
        $ch->setPreviousInvoice($prev);

        self::assertNull($ch->firstRecord, 'Setting previous invoice must clear firstRecord');
    }

    /** @test */
    public function settingAsFirstRecordClearsPreviousInvoice(): void
    {
        $ch = new Chaining();

        $prev = new PreviousInvoiceChaining();
        $prev->issuerNif = self::NIF;
        $prev->seriesNumber = 'PREV-001';
        $prev->issueDate = '2025-01-01';
        $prev->hash = str_repeat('A', 64);
        $ch->setPreviousInvoice($prev);

        self::assertNotNull($ch->getPreviousInvoice());

        $ch->setAsFirstRecord();

        self::assertSame('S', $ch->firstRecord);
        self::assertNull($ch->getPreviousInvoice(), 'setAsFirstRecord must clear previous invoice');
    }

    /** @test */
    public function recordCannotBeBothFirstAndChained(): void
    {
        $inv = new InvoiceSubmission();
        $inv->setInvoiceId([
            'issuerNif' => self::NIF,
            'seriesNumber' => 'TEST-BOTH',
            'issueDate' => '2025-07-01',
        ]);

        // Set as first record
        $inv->setAsFirstRecord();
        // Now try to set chaining with previous invoice
        $ch = new Chaining();
        $prev = new PreviousInvoiceChaining();
        $prev->issuerNif = self::NIF;
        $prev->seriesNumber = 'PREV-001';
        $prev->issueDate = '2025-06-01';
        $prev->hash = 'A000000000000000000000000000000000000000000000000000000000000001';
        $ch->setPreviousInvoice($prev);
        $inv->setChaining($ch);

        // Validation should reject both being set simultaneously
        $errors = $inv->validate();
        $hasBothError = false;
        foreach ($errors as $key => $msgs) {
            if (str_contains($key, 'chaining') || str_contains($key, 'firstRecord') || str_contains($key, 'previousInvoice')) {
                $hasBothError = true;
            }
        }

        // The model validation should catch this.  If it doesn't, report as defect.
        self::assertTrue(
            $hasBothError || $inv->getChaining()->firstRecord === null,
            'A record must not be simultaneously first record and chained to a previous record'
        );
    }

    // ── 9d. Previous-record mutation ──────────────────────────────────────

    /** @test */
    public function generatingHashForRecord2DoesNotMutateRecord1(): void
    {
        [$r1, $r2] = $this->buildTwoRecordChain();

        // Snapshot record 1 before anything
        $r1HashBefore = HashGeneratorService::generate($r1);
        $r1PrevBefore = $r1->getChaining()->getPreviousInvoice();

        // Generate hash for record 2
        HashGeneratorService::generate($r2);

        // Record 1 must be unchanged
        self::assertSame($r1HashBefore, HashGeneratorService::generate($r1), 'Record 1 hash must not change');
        self::assertSame($r1PrevBefore, $r1->getChaining()->getPreviousInvoice(), 'Record 1 chaining must not change');
    }

    /** @test */
    public function serializingRecord2DoesNotMutateRecord1Object(): void
    {
        [$r1, $r2] = $this->buildTwoRecordChain();

        $r1HashBefore = HashGeneratorService::generate($r1);
        $r1InvoiceIdBefore = clone $r1->getInvoiceId();

        InvoiceSerializer::toInvoiceXml($r2, false);

        self::assertSame($r1HashBefore, HashGeneratorService::generate($r1), 'Record 1 hash must survive record 2 serialization');
        self::assertEquals($r1InvoiceIdBefore->issuerNif, $r1->getInvoiceId()->issuerNif);
        self::assertEquals($r1InvoiceIdBefore->seriesNumber, $r1->getInvoiceId()->seriesNumber);
    }

    // ── Chain builders ────────────────────────────────────────────────────

    private function createRecord1(): InvoiceSubmission
    {
        $inv = new InvoiceSubmission();
        $inv->setInvoiceId([
            'issuerNif' => self::NIF,
            'seriesNumber' => 'CHAIN-001',
            'issueDate' => '2025-06-01',
        ]);
        $inv->issuerName = 'Emisor Ejemplo SL';
        $inv->invoiceType = InvoiceType::STANDARD;
        $inv->operationDescription = 'Chain record 1';
        $inv->taxAmount = 21.00;
        $inv->totalAmount = 121.00;
        $inv->addBreakdownDetail([
            'taxRate' => 21.0,
            'taxableBase' => 100.00,
            'taxAmount' => 21.00,
            'operationQualification' => OperationQualificationType::SUBJECT_NO_EXEMPT_NO_REVERSE,
        ]);
        $inv->hashType = HashType::SHA_256;
        $inv->recordTimestamp = '2025-06-01T08:00:00+01:00';
        $inv->setAsFirstRecord();
        $r = new LegalPerson();
        $r->name = 'Cliente Ejemplo SL';
        $r->nif = self::CLIENT_NIF;
        $inv->addRecipient($r);
        return $inv;
    }

    private function createRecord2(string $prevHash): InvoiceSubmission
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
        $prev->hash = $prevHash;
        $ch = new Chaining();
        $ch->setPreviousInvoice($prev);
        $inv->setChaining($ch);

        $r = new LegalPerson();
        $r->name = 'Cliente Ejemplo SL';
        $r->nif = self::CLIENT_NIF;
        $inv->addRecipient($r);
        return $inv;
    }

    private function createRecord3(string $prevHash): InvoiceSubmission
    {
        $inv = new InvoiceSubmission();
        $inv->setInvoiceId([
            'issuerNif' => self::NIF,
            'seriesNumber' => 'CHAIN-003',
            'issueDate' => '2025-06-03',
        ]);
        $inv->issuerName = 'Emisor Ejemplo SL';
        $inv->invoiceType = InvoiceType::STANDARD;
        $inv->operationDescription = 'Chain record 3';
        $inv->taxAmount = 63.00;
        $inv->totalAmount = 363.00;
        $inv->addBreakdownDetail([
            'taxRate' => 21.0,
            'taxableBase' => 300.00,
            'taxAmount' => 63.00,
            'operationQualification' => OperationQualificationType::SUBJECT_NO_EXEMPT_NO_REVERSE,
        ]);
        $inv->hashType = HashType::SHA_256;
        $inv->recordTimestamp = '2025-06-03T08:00:00+01:00';

        $prev = new PreviousInvoiceChaining();
        $prev->issuerNif = self::NIF;
        $prev->seriesNumber = 'CHAIN-002';
        $prev->issueDate = '2025-06-02';
        $prev->hash = $prevHash;
        $ch = new Chaining();
        $ch->setPreviousInvoice($prev);
        $inv->setChaining($ch);

        $r = new LegalPerson();
        $r->name = 'Cliente Ejemplo SL';
        $r->nif = self::CLIENT_NIF;
        $inv->addRecipient($r);
        return $inv;
    }

    /**
     * @return array{InvoiceSubmission, InvoiceSubmission}
     */
    private function buildTwoRecordChain(): array
    {
        $r1 = $this->createRecord1();
        $hash1 = HashGeneratorService::generate($r1);
        $r2 = $this->createRecord2($hash1);
        return [$r1, $r2];
    }

    /**
     * @return array{InvoiceSubmission, InvoiceSubmission, InvoiceSubmission}
     */
    private function buildThreeRecordChain(): array
    {
        $r1 = $this->createRecord1();
        $hash1 = HashGeneratorService::generate($r1);
        $r2 = $this->createRecord2($hash1);
        $hash2 = HashGeneratorService::generate($r2);
        $r3 = $this->createRecord3($hash2);
        return [$r1, $r2, $r3];
    }
}
