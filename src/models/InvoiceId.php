<?php

declare(strict_types=1);

namespace eseperio\verifactu\models;

/**
 * Model representing invoice identification data (<IDFactura> node).
 * Fields: issuer NIF, series+number, issue date.
 * Original schema: IDFacturaType.
 * @see docs/aeat/esquemas/SuministroInformacion.xsd.xml
 */
class InvoiceId extends Model
{
    /**
     * Issuer NIF (IDEmisorFactura).
     * @var string
     */
    public $issuerNif;

    /**
     * Series and invoice number (NumSerieFactura).
     * @var string
     */
    public $seriesNumber;

    /**
     * Issue date (FechaExpedicionFactura), format YYYY-MM-DD.
     * The serializer will automatically convert this to DD-MM-YYYY as required by the AEAT schema.
     * @var string
     */
    public $issueDate;

    /**
     * Get the normalized issue date.
     */
    public function getIssueDate()
    {
        return self::normalizeIsoDateValue($this->issueDate);
    }

    /**
     * Returns validation rules for the invoice ID.
     */
    public function rules(): array
    {
        return [
            [['issuerNif', 'seriesNumber', 'issueDate'], 'required'],
            [['issuerNif', 'seriesNumber'], 'string'],
            ['issueDate', fn($value): bool|string => is_string($value) ? true : 'Must be a string.'],
            ['issueDate', fn($value): bool|string =>
                // Checks for format YYYY-MM-DD (ISO 8601)
                (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value)) ? true : 'Must be a valid date (YYYY-MM-DD).'],
        ];
    }

    /**
     * Deprecated: This method has been replaced by direct XML generation in InvoiceSerializer.
     * 
     * @deprecated This method has been replaced by direct XML generation in InvoiceSerializer
     * @param \DOMDocument $doc The XML document to use for creating elements
     * @return \DOMElement
     * @throws \Exception
     */
    public function toXml(\DOMDocument $doc)
    {
        throw new \Exception(
            'This method is deprecated. The XML generation has been moved to the InvoiceSerializer service.'
        );
    }
}
