<?php

declare(strict_types=1);

namespace eseperio\verifactu\tests\Unit\Services;

use DateTimeImmutable;
use DOMDocument;
use eseperio\verifactu\models\InvoiceCancellation;
use eseperio\verifactu\models\InvoiceId;
use eseperio\verifactu\models\InvoiceQuery;
use eseperio\verifactu\models\InvoiceSubmission;
use eseperio\verifactu\models\LegalPerson;
use eseperio\verifactu\models\enums\HashType;
use eseperio\verifactu\models\enums\InvoiceType;
use eseperio\verifactu\models\enums\OperationQualificationType;
use eseperio\verifactu\services\InvoiceSerializer;
use eseperio\verifactu\tests\Helpers\SchemaValidatorHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4-5-6: XML determinism, golden fixtures and schema validation.
 *
 * Proves that serializing the same fiscal input always produces
 * byte-for-byte identical XML, that the output matches fixed external
 * regression fixtures, and that the XML is well-formed.
 */
class InvoiceArtifactDeterminismTest extends TestCase
{
    // ── Builder helpers ─────────────────────────────────────────────────────
    // These use only fixed synthetic values. Never clock, random or external
    // input.

    private const NIF_ISSUER = 'B12345678';
    private const NIF_CLIENT = '87654321X';
    private const ISSUER_NAME = 'Emisor Ejemplo SL';

    /**
     * Build a standard invoice matching the golden fixture.
     */
    private function buildStandardInvoice(): InvoiceSubmission
    {
        $inv = new InvoiceSubmission();
        $inv->setInvoiceId([
            'issuerNif' => self::NIF_ISSUER,
            'seriesNumber' => 'TEST-001',
            'issueDate' => '2025-01-15',
        ]);
        $inv->issuerName = self::ISSUER_NAME;
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
        $inv->hash = 'D1347DC346A5AE123C4A473D3343FD77E06BA115C9A2EEA31664C6D230FFD900';
        $inv->recordTimestamp = '2025-01-15T10:30:00+01:00';
        $inv->setAsFirstRecord();

        $r = new LegalPerson();
        $r->name = 'Cliente Ejemplo SL';
        $r->nif = self::NIF_CLIENT;
        $inv->addRecipient($r);

        return $inv;
    }

    /**
     * Build a rectification invoice matching the golden fixture.
     */
    private function buildRectificationInvoice(): InvoiceSubmission
    {
        $inv = new InvoiceSubmission();
        $inv->setInvoiceId([
            'issuerNif' => self::NIF_ISSUER,
            'seriesNumber' => 'RECT-001',
            'issueDate' => '2025-02-20',
        ]);
        $inv->issuerName = self::ISSUER_NAME;
        $inv->invoiceType = InvoiceType::RECTIFICATION_1;
        $inv->rectificationType = \eseperio\verifactu\models\enums\RectificationType::SUBSTITUTIVE;
        $inv->operationDescription = 'Rectificación por diferencias';
        $inv->taxAmount = -21.00;
        $inv->totalAmount = -121.00;
        $inv->addBreakdownDetail([
            'taxRate' => 21.0,
            'taxableBase' => -100.00,
            'taxAmount' => -21.00,
            'operationQualification' => OperationQualificationType::SUBJECT_NO_EXEMPT_NO_REVERSE,
        ]);
        $inv->setRectificationBreakdown([
            'rectifiedBase' => 100.00,
            'rectifiedTax' => 21.00,
        ]);
        $inv->addRectifiedInvoice(self::NIF_ISSUER, 'ORIG-001', '2025-01-15');
        $inv->hashType = HashType::SHA_256;
        $inv->hash = '6B6267796C60BE96D19E3E0CCE2CE751EB8326FC93356C231B6202DE57CFE7CC';
        $inv->recordTimestamp = '2025-02-20T12:00:00+01:00';
        $inv->setAsFirstRecord();

        $r = new LegalPerson();
        $r->name = 'Cliente Ejemplo SL';
        $r->nif = self::NIF_CLIENT;
        $inv->addRecipient($r);

        return $inv;
    }

    /**
     * Build a cancellation invoice matching the golden fixture.
     */
    private function buildCancellationInvoice(): InvoiceCancellation
    {
        $can = new InvoiceCancellation();
        $can->setInvoiceId([
            'issuerNif' => self::NIF_ISSUER,
            'seriesNumber' => 'CANCEL-001',
            'issueDate' => '2025-03-10',
        ]);
        $can->issuerName = self::ISSUER_NAME;
        $can->hashType = HashType::SHA_256;
        $can->hash = 'FAB1AC6515C60484C81E88A2CA3401A6AAC29C1CBD4FB152C2C3EA3978924878';
        $can->recordTimestamp = '2025-03-10T09:00:00+01:00';
        $can->setAsFirstRecord();
        $can->previousRejection = \eseperio\verifactu\models\enums\YesNoType::NO;

        return $can;
    }

    // ── Fixture path ────────────────────────────────────────────────────────

    private static function fixturePath(string $name): string
    {
        return __DIR__ . '/../../Fixtures/Xml/' . $name;
    }

    // ── 4a. Repeated serialisation → byte-for-byte identical ───────────────

    /** @test */
    public function repeatedSerialisationStandardIsIdentical(): void
    {
        $inv = $this->buildStandardInvoice();
        $xml1 = InvoiceSerializer::toInvoiceXml($inv)->saveXML();
        $xml2 = InvoiceSerializer::toInvoiceXml($inv)->saveXML();
        self::assertSame($xml1, $xml2, 'Serialising the same standard invoice twice must produce identical bytes');
    }

    /** @test */
    public function repeatedSerialisationRectificationIsIdentical(): void
    {
        $inv = $this->buildRectificationInvoice();
        $xml1 = InvoiceSerializer::toInvoiceXml($inv)->saveXML();
        $xml2 = InvoiceSerializer::toInvoiceXml($inv)->saveXML();
        self::assertSame($xml1, $xml2, 'Serialising the same rectification invoice twice must produce identical bytes');
    }

    /** @test */
    public function repeatedSerialisationCancellationIsIdentical(): void
    {
        $can = $this->buildCancellationInvoice();
        $xml1 = InvoiceSerializer::toCancellationXml($can)->saveXML();
        $xml2 = InvoiceSerializer::toCancellationXml($can)->saveXML();
        self::assertSame($xml1, $xml2, 'Serialising the same cancellation twice must produce identical bytes');
    }

    // ── 4b. Equivalent object construction ─────────────────────────────────

    /** @test */
    public function equivalentDateInputsProduceIdenticalXml(): void
    {
        $inv1 = $this->buildStandardInvoice();

        $inv2 = $this->buildStandardInvoice();
        $inv2->getInvoiceId()->issueDate = new DateTimeImmutable('2025-01-15');

        $xml1 = InvoiceSerializer::toInvoiceXml($inv1)->saveXML();
        $xml2 = InvoiceSerializer::toInvoiceXml($inv2)->saveXML();

        self::assertSame($xml1, $xml2, 'String date vs DateTimeImmutable must produce identical XML');
    }

    /** @test */
    public function equivalentSettersOrderProduceIdenticalXml(): void
    {
        // Build with a different setter order
        $inv = new InvoiceSubmission();
        $inv->totalAmount = 121.00;
        $inv->issuerName = self::ISSUER_NAME;
        $inv->taxAmount = 21.00;
        $inv->operationDescription = 'Operación de prueba';
        $inv->invoiceType = InvoiceType::STANDARD;
        $inv->setInvoiceId([
            'issuerNif' => self::NIF_ISSUER,
            'seriesNumber' => 'TEST-001',
            'issueDate' => '2025-01-15',
        ]);
        $inv->addBreakdownDetail([
            'taxRate' => 21.0,
            'taxableBase' => 100.00,
            'taxAmount' => 21.00,
            'operationQualification' => OperationQualificationType::SUBJECT_NO_EXEMPT_NO_REVERSE,
        ]);
        $inv->hashType = HashType::SHA_256;
        $inv->hash = 'D1347DC346A5AE123C4A473D3343FD77E06BA115C9A2EEA31664C6D230FFD900';
        $inv->recordTimestamp = '2025-01-15T10:30:00+01:00';
        $inv->setAsFirstRecord();

        $r = new LegalPerson();
        $r->name = 'Cliente Ejemplo SL';
        $r->nif = self::NIF_CLIENT;
        $inv->addRecipient($r);

        $expected = InvoiceSerializer::toInvoiceXml($this->buildStandardInvoice())->saveXML();

        self::assertSame($expected, InvoiceSerializer::toInvoiceXml($inv)->saveXML());
    }

    // ── 4c. No object mutation ─────────────────────────────────────────────

    /** @test */
    public function serialisationDoesNotMutateInvoiceState(): void
    {
        $inv = $this->buildStandardInvoice();
        $snap = [
            'issuerNif' => $inv->getInvoiceId()->issuerNif,
            'seriesNumber' => $inv->getInvoiceId()->seriesNumber,
            'issueDate' => $inv->getInvoiceId()->getIssueDate(),
            'taxAmount' => $inv->taxAmount,
            'totalAmount' => $inv->totalAmount,
            'hash' => $inv->hash,
            'recordTimestamp' => $inv->recordTimestamp,
        ];

        InvoiceSerializer::toInvoiceXml($inv);

        self::assertSame($snap['issuerNif'], $inv->getInvoiceId()->issuerNif, 'issuerNif must not mutate');
        self::assertSame($snap['seriesNumber'], $inv->getInvoiceId()->seriesNumber, 'seriesNumber must not mutate');
        self::assertSame($snap['issueDate'], $inv->getInvoiceId()->getIssueDate(), 'issueDate must not mutate');
        self::assertSame($snap['taxAmount'], $inv->taxAmount, 'taxAmount must not mutate');
        self::assertSame($snap['totalAmount'], $inv->totalAmount, 'totalAmount must not mutate');
        self::assertSame($snap['hash'], $inv->hash, 'hash must not mutate');
        self::assertSame($snap['recordTimestamp'], $inv->recordTimestamp, 'recordTimestamp must not mutate');
    }

    /** @test */
    public function cancellationSerialisationDoesNotMutateState(): void
    {
        $can = $this->buildCancellationInvoice();
        $snap = [
            'issuerNif' => $can->getInvoiceId()->issuerNif,
            'seriesNumber' => $can->getInvoiceId()->seriesNumber,
            'issueDate' => $can->getInvoiceId()->getIssueDate(),
            'hash' => $can->hash,
            'recordTimestamp' => $can->recordTimestamp,
        ];

        InvoiceSerializer::toCancellationXml($can);

        self::assertSame($snap['issuerNif'], $can->getInvoiceId()->issuerNif);
        self::assertSame($snap['seriesNumber'], $can->getInvoiceId()->seriesNumber);
        self::assertSame($snap['issueDate'], $can->getInvoiceId()->getIssueDate());
        self::assertSame($snap['hash'], $can->hash);
        self::assertSame($snap['recordTimestamp'], $can->recordTimestamp);
    }

    // ── 4d. Encoding and escaping ─────────────────────────────────────────
    //
    // The production serializer (TASK-03A) now uses createTextNode for text
    // content, which correctly escapes &, <, >, quotes and apostrophes.

    /** @test */
    public function xmlWithSafeSpecialCharsIsWellFormedAndRoundtrips(): void
    {
        $inv = $this->buildStandardInvoice();
        $inv->operationDescription = 'Cotización: 100€ (IVA inc.) — "OK" \'test\'';
        $inv->issuerName = 'Empresa Cía S.L. (prueba)';

        $dom = InvoiceSerializer::toInvoiceXml($inv, false);
        $xml = $dom->saveXML();

        // Must be well-formed
        $decoded = new DOMDocument();
        $result = $decoded->loadXML($xml);
        self::assertTrue($result, 'XML with accented, quoted and apostrophe chars must be well-formed');

        // Roundtrip must preserve original text
        $desc = $decoded->getElementsByTagNameNS(InvoiceSerializer::SF_NAMESPACE, 'DescripcionOperacion')->item(0)->textContent;
        self::assertSame('Cotización: 100€ (IVA inc.) — "OK" \'test\'', $desc);

        $issuer = $decoded->getElementsByTagNameNS(InvoiceSerializer::SF_NAMESPACE, 'NombreRazonEmisor')->item(0)->textContent;
        self::assertSame('Empresa Cía S.L. (prueba)', $issuer);
    }

    /** @test */
    public function xmlWithAmpersandIsProperlyEscaped(): void
    {
        $inv = $this->buildStandardInvoice();
        $inv->operationDescription = 'A & B';

        $dom = InvoiceSerializer::toInvoiceXml($inv, false);
        $xml = $dom->saveXML();

        // Must be well-formed
        $reparse = new DOMDocument();
        $result = $reparse->loadXML($xml);
        self::assertTrue($result, 'XML with & must be well-formed');

        // Content must be preserved
        $desc = $reparse->getElementsByTagNameNS(InvoiceSerializer::SF_NAMESPACE, 'DescripcionOperacion')->item(0)->textContent;
        self::assertSame('A & B', $desc, 'Ampersand must round-trip correctly');

        // Raw XML must contain entity
        self::assertStringContainsString('&amp;', $xml, '& must be encoded as &amp;');
    }

    /** @test */
    public function xmlIsWellFormed(): void
    {
        $inv = $this->buildStandardInvoice();
        $dom = InvoiceSerializer::toInvoiceXml($inv, false);
        $xml = $dom->saveXML();

        $reparse = new DOMDocument();
        $result = $reparse->loadXML($xml);
        self::assertTrue($result, 'Standard invoice XML must be well-formed');
    }

    // ── 4e. Stable namespace and element order ────────────────────────────

    /** @test */
    public function standardInvoiceHasExpectedRootAndNamespace(): void
    {
        $dom = InvoiceSerializer::toInvoiceXml($this->buildStandardInvoice(), false);
        $root = $dom->documentElement;

        self::assertSame('sf:RegistroAlta', $root->nodeName);
        self::assertSame(
            InvoiceSerializer::SF_NAMESPACE,
            $root->namespaceURI
        );
    }

    /** @test */
    public function cancellationHasExpectedRootAndNamespace(): void
    {
        $dom = InvoiceSerializer::toCancellationXml($this->buildCancellationInvoice(), false);
        $root = $dom->documentElement;

        self::assertSame('sf:RegistroAnulacion', $root->nodeName);
        self::assertSame(
            InvoiceSerializer::SF_NAMESPACE,
            $root->namespaceURI
        );
    }

    // ── 5. Golden XML regression fixtures (exact byte comparison) ──────────

    /** @test */
    public function standardInvoiceMatchesGoldenFixture(): void
    {
        $inv = $this->buildStandardInvoice();
        $actual = InvoiceSerializer::toInvoiceXml($inv, false)->saveXML();

        $fixture = file_get_contents(self::fixturePath('invoice-registration-standard.xml'));
        self::assertNotFalse($fixture, 'Golden fixture not found');

        self::assertSame($fixture, $actual, 'Standard invoice XML must byte-match golden fixture');
    }

    /** @test */
    public function rectificationInvoiceMatchesGoldenFixture(): void
    {
        $inv = $this->buildRectificationInvoice();
        $actual = InvoiceSerializer::toInvoiceXml($inv, false)->saveXML();

        $fixture = file_get_contents(self::fixturePath('invoice-registration-rectification.xml'));
        self::assertNotFalse($fixture, 'Golden fixture not found');

        self::assertSame($fixture, $actual, 'Rectification invoice XML must byte-match golden fixture');
    }

    /** @test */
    public function cancellationMatchesGoldenFixture(): void
    {
        $can = $this->buildCancellationInvoice();
        $actual = InvoiceSerializer::toCancellationXml($can, false)->saveXML();

        $fixture = file_get_contents(self::fixturePath('invoice-cancellation.xml'));
        self::assertNotFalse($fixture, 'Golden fixture not found');

        self::assertSame($fixture, $actual, 'Cancellation XML must byte-match golden fixture');
    }

    // ── 6. Schema validation (offline, local XSD only) ─────────────────────
    //
    // The xmldsig schema is vendored locally at src/schemes/xmldsig-core-schema.xsd.
    // No remote schemaLocation remains in the dependency graph.
    // Validation must never contact the network.

    private const SCHEMA_SUMINISTRO_INFORMACION = __DIR__ . '/../../../src/schemes/SuministroInformacion.xsd';
    private const SCHEMA_SUMINISTRO_LR = __DIR__ . '/../../../src/schemes/SuministroLR.xsd';
    private const SCHEMA_CONSULTA_LR = __DIR__ . '/../../../src/schemes/ConsultaLR.xsd';

    /** @test */
    public function standardRegistrationValidatesAgainstSuministroInformacion(): void
    {
        $dom = InvoiceSerializer::toInvoiceXml($this->buildStandardInvoice(), false);
        self::assertSame('RegistroAlta', $dom->documentElement->localName);
        self::assertSame(InvoiceSerializer::SF_NAMESPACE, $dom->documentElement->namespaceURI);

        SchemaValidatorHelper::assertValid($dom, self::SCHEMA_SUMINISTRO_INFORMACION, 'standard registration');
    }

    /** @test */
    public function rectificationRegistrationValidatesAgainstSuministroInformacion(): void
    {
        $dom = InvoiceSerializer::toInvoiceXml($this->buildRectificationInvoice(), false);
        self::assertSame('RegistroAlta', $dom->documentElement->localName);
        self::assertSame(InvoiceSerializer::SF_NAMESPACE, $dom->documentElement->namespaceURI);

        SchemaValidatorHelper::assertValid($dom, self::SCHEMA_SUMINISTRO_INFORMACION, 'rectification registration');
    }

    /** @test */
    public function cancellationValidatesAgainstSuministroInformacion(): void
    {
        $dom = InvoiceSerializer::toCancellationXml($this->buildCancellationInvoice(), false);
        self::assertSame('RegistroAnulacion', $dom->documentElement->localName);
        self::assertSame(InvoiceSerializer::SF_NAMESPACE, $dom->documentElement->namespaceURI);

        SchemaValidatorHelper::assertValid($dom, self::SCHEMA_SUMINISTRO_INFORMACION, 'cancellation');
    }

    /** @test */
    public function wrappedSubmissionValidatesAgainstSuministroLR(): void
    {
        $inv = $this->buildStandardInvoice();
        $fragment = InvoiceSerializer::toInvoiceXml($inv, false);
        $wrapped = InvoiceSerializer::wrapXmlWithRegFactuStructure(
            $fragment,
            'B12345678',
            'Emisor Ejemplo SL'
        );
        self::assertSame('RegFactuSistemaFacturacion', $wrapped->documentElement->localName);
        self::assertSame(InvoiceSerializer::SFLR_NAMESPACE, $wrapped->documentElement->namespaceURI);

        SchemaValidatorHelper::assertValid($wrapped, self::SCHEMA_SUMINISTRO_LR, 'wrapped submission');
    }

    /** @test */
    public function queryDocumentValidatesAgainstConsultaLR(): void
    {
        $query = new InvoiceQuery();
        $query->year = '2025';
        $query->period = '01';
        $query->seriesNumber = 'TEST-001';
        $query->issueDate = '2025-06-01';
        $query->setIssuerparty('B12345678', 'Emisor Ejemplo SL');

        $dom = InvoiceSerializer::toQueryXml($query, false);
        self::assertSame('ConsultaFactuSistemaFacturacion', $dom->documentElement->localName);
        self::assertSame(InvoiceSerializer::QUERY_NAMESPACE, $dom->documentElement->namespaceURI);

        SchemaValidatorHelper::assertValid($dom, self::SCHEMA_CONSULTA_LR, 'query document');
    }

    // ── 6b. Negative schema controls ──────────────────────────────────────

    /** @test */
    public function invalidRegistrationMissingMandatoryElementFailsValidation(): void
    {
        $dom = InvoiceSerializer::toInvoiceXml($this->buildStandardInvoice(), false);

        // Remove mandatory CuotaTotal element to make the document schema-invalid
        $ns = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroInformacion.xsd';
        $cuotaTotal = $dom->getElementsByTagNameNS($ns, 'CuotaTotal')->item(0);
        self::assertNotNull($cuotaTotal, 'CuotaTotal must exist in valid document');
        $cuotaTotal->parentNode->removeChild($cuotaTotal);

        SchemaValidatorHelper::assertInvalid($dom, self::SCHEMA_SUMINISTRO_INFORMACION, 'missing CuotaTotal');
    }

    /** @test */
    public function invalidCancellationWithBadEnumFailsValidation(): void
    {
        $dom = InvoiceSerializer::toCancellationXml($this->buildCancellationInvoice(), false);

        // Set RechazoPrevio to an invalid value (not S or N)
        $ns = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroInformacion.xsd';
        $rechazo = $dom->getElementsByTagNameNS($ns, 'RechazoPrevio')->item(0);
        self::assertNotNull($rechazo, 'RechazoPrevio must exist in valid document');
        $rechazo->textContent = 'X';

        SchemaValidatorHelper::assertInvalid($dom, self::SCHEMA_SUMINISTRO_INFORMACION, 'invalid RechazoPrevio enum X');
    }

    // ── 7. Escaping through XSD ───────────────────────────────────────────

    /** @test */
    public function escapedBusinessTextValidatesAgainstXsd(): void
    {
        $inv = $this->buildStandardInvoice();
        $inv->operationDescription = 'Cotización & Test < 100 € "O\'Connor"';
        $inv->issuerName = 'Empresa Cía S.L. (prueba)';

        $dom = InvoiceSerializer::toInvoiceXml($inv, false);
        $xml = $dom->saveXML();

        // 1. Must be well-formed (no DOM warning during parse)
        $reparse = new DOMDocument();
        $old = libxml_use_internal_errors(true);
        $result = $reparse->loadXML($xml);
        $parseErrors = libxml_get_errors();
        libxml_use_internal_errors($old);

        self::assertTrue($result, 'XML with &, <, accents and quotes must be well-formed');
        self::assertCount(0, $parseErrors, 'No DOM parse warnings expected');

        // 2. Round-trip text must match original
        $desc = $reparse->getElementsByTagNameNS(InvoiceSerializer::SF_NAMESPACE, 'DescripcionOperacion')->item(0)->textContent;
        self::assertSame('Cotización & Test < 100 € "O\'Connor"', $desc);

        $issuer = $reparse->getElementsByTagNameNS(InvoiceSerializer::SF_NAMESPACE, 'NombreRazonEmisor')->item(0)->textContent;
        self::assertSame('Empresa Cía S.L. (prueba)', $issuer);

        // 3. Schema validation must pass
        SchemaValidatorHelper::assertValid($dom, self::SCHEMA_SUMINISTRO_INFORMACION, 'escaped business text');

        // 4. Verify correct entity escaping in raw XML
        self::assertStringContainsString('&amp;', $xml, '& must be escaped as &amp;');
        self::assertStringContainsString('&lt;', $xml, '< must be escaped as &lt;');
        self::assertStringContainsString('O\'Connor', $xml, 'apostrophe may be literal in XML 1.0');
    }
}
