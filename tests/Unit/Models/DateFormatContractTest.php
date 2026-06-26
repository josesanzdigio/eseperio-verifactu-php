<?php

declare(strict_types=1);

namespace eseperio\verifactu\tests\Unit\Models;

use DateTimeImmutable;
use eseperio\verifactu\models\InvoiceId;
use eseperio\verifactu\models\InvoiceSubmission;
use eseperio\verifactu\models\InvoiceQuery;
use eseperio\verifactu\models\PreviousInvoiceChaining;
use eseperio\verifactu\models\Chaining;
use eseperio\verifactu\models\Breakdown;
use eseperio\verifactu\models\BreakdownDetail;
use eseperio\verifactu\models\ComputerSystem;
use eseperio\verifactu\models\enums\InvoiceType;
use eseperio\verifactu\models\enums\HashType;
use eseperio\verifactu\models\enums\YesNoType;
use eseperio\verifactu\models\enums\OperationQualificationType;
use eseperio\verifactu\models\LegalPerson;
use PHPUnit\Framework\TestCase;

/**
 * Test date format contract for the entire library.
 * 
 * Contract: All date fields in models use ISO 8601 (YYYY-MM-DD) format internally.
 * This is automatically converted to DD-MM-YYYY (AEAT format) during serialization to XML.
 * Exceptions: fechaFinVeriFactu uses DD-MM-YYYY (31-12-YYYY) as per AEAT spec 31.1.3
 */
class DateFormatContractTest extends TestCase
{
    /**
     * Test that InvoiceId.issueDate accepts ISO format (YYYY-MM-DD) and rejects DMY.
     */
    public function testInvoiceIdIssueDateFormatISO(): void
    {
        $invoiceId = new InvoiceId();
        $invoiceId->issuerNif = 'B12345678';
        $invoiceId->seriesNumber = 'FACT-001';
        
        // Accept ISO format
        $invoiceId->issueDate = '2023-01-15';
        $errors = $invoiceId->validate();
        $this->assertEmpty($errors, 'InvoiceId should accept ISO format YYYY-MM-DD');
        
        // Reject DMY format
        $invoiceId->issueDate = '15-01-2023';
        $errors = $invoiceId->validate();
        $this->assertNotEmpty($errors, 'InvoiceId should reject DD-MM-YYYY format');
        $this->assertArrayHasKey(InvoiceId::class . '::$issueDate', $errors);
    }

    /**
     * Test that InvoiceSubmission.operationDate accepts ISO format and rejects DMY.
     */
    public function testInvoiceSubmissionOperationDateFormatISO(): void
    {
        $submission = new InvoiceSubmission();
        $invoiceId = new InvoiceId();
        $invoiceId->issuerNif = 'B12345678';
        $invoiceId->seriesNumber = 'FACT-001';
        $invoiceId->issueDate = '2023-01-15';
        
        $submission->setInvoiceId($invoiceId);
        $submission->issuerName = 'Test Company';
        $submission->invoiceType = InvoiceType::STANDARD;
        $submission->operationDescription = 'Test';
        $submission->taxAmount = 21.00;
        $submission->totalAmount = 121.00;
        
        $chaining = new Chaining();
        $chaining->setAsFirstRecord();
        $submission->setChaining($chaining);
        
        $computerSystem = new ComputerSystem();
        $computerSystem->systemName = 'Test';
        $computerSystem->version = '1.0';
        $computerSystem->providerName = 'Test Provider';
        $computerSystem->systemId = '01';
        $computerSystem->installationNumber = '1';
        $computerSystem->onlyVerifactu = YesNoType::YES;
        $computerSystem->multipleObligations = YesNoType::NO;
        $computerSystem->hasMultipleObligations = YesNoType::NO;
        $provider = new LegalPerson();
        $provider->name = 'Test Provider';
        $provider->nif = 'B87654321';
        $computerSystem->setProviderId($provider);
        $submission->setSystemInfo($computerSystem);

        $breakdown = new Breakdown();
        $detail = new BreakdownDetail();
        $detail->taxableBase = 100.00;
        $detail->taxRate = 21.00;
        $detail->taxAmount = 21.00;
        $detail->operationQualification = OperationQualificationType::SUBJECT_NO_EXEMPT_NO_REVERSE;
        $breakdown->addDetail($detail);
        $submission->setBreakdown($breakdown);

        $submission->recordTimestamp = '2023-01-15T12:00:00+01:00';
        $submission->hashType = HashType::SHA_256;
        
        $recipient = new LegalPerson();
        $recipient->name = 'Client';
        $recipient->nif = '12345678Z';
        $submission->addRecipient($recipient);
        
        // Accept ISO format
        $submission->operationDate = '2023-01-15';
        $errors = $submission->validate();
        $this->assertEmpty($errors, 'InvoiceSubmission should accept ISO format operationDate');
        
        // Reject DMY format
        $submission->operationDate = '15-01-2023';
        $errors = $submission->validate();
        $this->assertNotEmpty($errors, 'InvoiceSubmission should reject DD-MM-YYYY operationDate');
        $this->assertArrayHasKey(InvoiceSubmission::class . '::$operationDate', $errors);
    }

    /**
     * Test that PreviousInvoiceChaining.issueDate accepts ISO format (YYYY-MM-DD).
     * This was fixed to match the contract established by commit 2301d05.
     */
    public function testPreviousInvoiceChainingIssueDateFormatISO(): void
    {
        $chaining = new PreviousInvoiceChaining();
        $chaining->issuerNif = 'B12345678';
        $chaining->seriesNumber = 'PREV-001';
        
        // Accept ISO format
        $chaining->issueDate = '2023-01-15';
        $chaining->hash = 'abc123def456';
        $errors = $chaining->validate();
        $this->assertEmpty($errors, 'PreviousInvoiceChaining should accept ISO format YYYY-MM-DD');
        
        // Reject DMY format
        $chaining->issueDate = '15-01-2023';
        $errors = $chaining->validate();
        $this->assertNotEmpty($errors, 'PreviousInvoiceChaining should reject DD-MM-YYYY format');
        $this->assertArrayHasKey(PreviousInvoiceChaining::class . '::$issueDate', $errors);
        // Verify error message says YYYY-MM-DD
        $this->assertStringContainsString('YYYY-MM-DD', $errors[PreviousInvoiceChaining::class . '::$issueDate'][0]);
    }

    /**
     * Test that InvoiceQuery.issueDate accepts ISO format and rejects DMY.
     */
    public function testInvoiceQueryIssueDateFormatISO(): void
    {
        $query = new InvoiceQuery();
        $query->year = '2023';
        $query->period = '01';
        
        // Accept ISO format
        $query->issueDate = '2023-01-15';
        $errors = $query->validate();
        $this->assertEmpty($errors, 'InvoiceQuery should accept ISO format issueDate');
        
        // Reject DMY format
        $query->issueDate = '15-01-2023';
        $errors = $query->validate();
        $this->assertNotEmpty($errors, 'InvoiceQuery should reject DD-MM-YYYY format');
        $this->assertArrayHasKey(InvoiceQuery::class . '::$issueDate', $errors);
    }

    /**
     * Test that rectification data (rectified/substituted invoice dates) are ISO format.
     */
    public function testRectificationDataDatesAreISO(): void
    {
        $submission = new InvoiceSubmission();
        
        // Docstring confirms ISO format
        $invoiceId = new InvoiceId();
        $invoiceId->issuerNif = 'B12345678';
        $invoiceId->seriesNumber = 'FACT-001';
        $invoiceId->issueDate = '2023-01-15';
        
        $submission->setInvoiceId($invoiceId);
        $submission->issuerName = 'Test Company';
        $submission->invoiceType = InvoiceType::STANDARD;
        $submission->operationDescription = 'Test';
        $submission->taxAmount = 21.00;
        $submission->totalAmount = 121.00;
        
        $chaining = new Chaining();
        $chaining->setAsFirstRecord();
        $submission->setChaining($chaining);
        
        $computerSystem = new ComputerSystem();
        $computerSystem->systemName = 'Test';
        $computerSystem->version = '1.0';
        $computerSystem->providerName = 'Test Provider';
        $computerSystem->systemId = '01';
        $computerSystem->installationNumber = '1';
        $computerSystem->onlyVerifactu = YesNoType::YES;
        $computerSystem->multipleObligations = YesNoType::NO;
        $provider = new LegalPerson();
        $provider->name = 'Test Provider';
        $provider->nif = 'B87654321';
        $computerSystem->setProviderId($provider);
        $submission->setSystemInfo($computerSystem);
        
        $submission->recordTimestamp = '2023-01-15T12:00:00+01:00';
        $submission->hashType = HashType::SHA_256;
        
        $recipient = new LegalPerson();
        $recipient->name = 'Client';
        $recipient->nif = '12345678Z';
        $submission->addRecipient($recipient);
        
        // Add rectified invoice with ISO date
        $submission->addRectifiedInvoice('B87654321', 'PREV-001', '2023-01-01');
        
        $rectData = $submission->getRectificationData();
        $this->assertArrayHasKey('rectified', $rectData);
        $this->assertEquals('2023-01-01', $rectData['rectified'][0]['issueDate']);
        
        // Add substituted invoice with ISO date
        $submission->addSubstitutedInvoice('B87654321', 'PREV-002', '2023-01-02');

        $rectData = $submission->getRectificationData();
        $this->assertArrayHasKey('substituted', $rectData);
        $this->assertEquals('2023-01-02', $rectData['substituted'][0]['issueDate']);

        $submission->setRectificationData([
            'rectified' => [[
                'issuerNif' => 'B87654321',
                'seriesNumber' => 'PREV-003',
                'issueDate' => '01-01-2023',
            ]],
        ]);

        $errors = $submission->validate();
        $this->assertNotEmpty($errors, 'InvoiceSubmission should reject DD-MM-YYYY rectification issueDate');
        $this->assertArrayHasKey(InvoiceSubmission::class . '::$rectificationData', $errors);
    }

    public function testDateTimeInputsAreNormalizedToIsoContract(): void
    {
        $date = new DateTimeImmutable('2023-01-15 09:30:00');

        $invoiceId = new InvoiceId();
        $invoiceId->issuerNif = 'B12345678';
        $invoiceId->seriesNumber = 'FACT-001';
        $invoiceId->issueDate = $date;
        $this->assertSame('2023-01-15', $invoiceId->getIssueDate());
        $this->assertEmpty($invoiceId->validate());

        $query = new InvoiceQuery();
        $query->year = '2023';
        $query->period = '01';
        $query->issueDate = $date;
        $this->assertSame('2023-01-15', $query->getIssueDate());
        $this->assertEmpty($query->validate());

        $submission = new InvoiceSubmission();
        $submission->operationDate = $date;
        $this->assertSame('2023-01-15', $submission->getOperationDate());

        $submission->addRectifiedInvoice('B87654321', 'PREV-001', $date);
        $submission->addSubstitutedInvoice('B87654321', 'PREV-002', $date);
        $rectificationData = $submission->getRectificationData();

        $this->assertSame('2023-01-15', $rectificationData['rectified'][0]['issueDate']);
        $this->assertSame('2023-01-15', $rectificationData['substituted'][0]['issueDate']);
    }
}
