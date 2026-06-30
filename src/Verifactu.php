<?php

declare(strict_types=1);
// Main entry point of the Verifactu library

namespace eseperio\verifactu;

use eseperio\verifactu\models\InvoiceCancellation;
use eseperio\verifactu\models\InvoiceQuery;
use eseperio\verifactu\models\InvoiceRecord;
use eseperio\verifactu\models\InvoiceResponse;
use eseperio\verifactu\models\InvoiceSubmission;
use eseperio\verifactu\models\QueryResponse;
use eseperio\verifactu\services\VerifactuService;

class Verifactu
{
    public const ENVIRONMENT_PRODUCTION = 'production';
    public const ENVIRONMENT_SANDBOX = 'sandbox';

    /**
     * Production environment URL.
     */
    public const URL_PRODUCTION = 'https://www1.agenciatributaria.gob.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP';

    /**
     * Production environment URL (seal certificate).
     */
    public const URL_PRODUCTION_SEAL = 'https://www10.agenciatributaria.gob.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP';

    /**
     * Test (homologation) environment URL.
     */
    public const URL_TEST = 'https://prewww1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP';

    /**
     * Test (seal certificate) environment URL.
     */
    public const URL_TEST_SEAL = 'https://prewww10.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP';

    /**
     * QR verification URL (production).
     */
    public const QR_VERIFICATION_URL_PRODUCTION = 'https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR';

    /**
     * QR verification URL (testing/homologation).
     */
    public const QR_VERIFICATION_URL_TEST = 'https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR';

    /**
     * No VERI*FACTU QR verification URL (production).
     */
    public const QR_NO_VERIFACTU_VERIFICATION_URL_PRODUCTION = 'https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQRNoVerifactu';

    /**
     * No VERI*FACTU QR verification URL (testing/homologation).
     */
    public const QR_NO_VERIFACTU_VERIFICATION_URL_TEST = 'https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQRNoVerifactu';

    public const TYPE_CERTIFICATE = 'certificate';
    public const TYPE_SEAL = 'seal';

    /**
     * @param $certPath string Path to the certificate file.
     * @param $certPassword string Password for the certificate.
     * @param $certType string Type of certificate, either 'certificate' or 'seal'.
     * @param $environment string Environment to use, either 'production' or 'sandbox'.
     */
    public static function config($certPath, $certPassword, $certType, $environment = self::ENVIRONMENT_PRODUCTION): void
    {
        $endpoint = match ($environment) {
            self::ENVIRONMENT_PRODUCTION => $certType === self::TYPE_SEAL ? self::URL_PRODUCTION_SEAL : self::URL_PRODUCTION,
            self::ENVIRONMENT_SANDBOX => $certType === self::TYPE_SEAL ? self::URL_TEST_SEAL : self::URL_TEST,
            default => throw new \InvalidArgumentException("Invalid environment: $environment")
        };

        $qrValidationUrl = match ($environment) {
            self::ENVIRONMENT_PRODUCTION => self::QR_VERIFICATION_URL_PRODUCTION,
            self::ENVIRONMENT_SANDBOX => self::QR_VERIFICATION_URL_TEST,
            default => throw new \InvalidArgumentException("Invalid environment: $environment")
        };

        VerifactuService::config([
            VerifactuService::CERT_PATH_KEY => $certPath,
            VerifactuService::CERT_PASSWORD_KEY => $certPassword,
            VerifactuService::SOAP_ENDPOINT => $endpoint,
            VerifactuService::QR_VERIFICATION_URL => $qrValidationUrl,
            VerifactuService::QR_MODE => VerifactuService::QR_MODE_VERIFACTU,
        ]);
    }

    /**
     * Configures the library for QR-only No VERI*FACTU mode.
     *
     * This method sets up only the QR validation endpoint for No VERI*FACTU invoices.
     * No certificate or SOAP configuration is required or set — SOAP operations
     * (register, cancel, query) are not available in this mode.
     *
     * @param string $environment Environment to use: 'production' or 'sandbox'.
     * @throws \InvalidArgumentException if the environment is not recognized.
     */
    public static function configNoVerifactu(string $environment = self::ENVIRONMENT_PRODUCTION): void
    {
        $qrValidationUrl = match ($environment) {
            self::ENVIRONMENT_PRODUCTION => self::QR_NO_VERIFACTU_VERIFICATION_URL_PRODUCTION,
            self::ENVIRONMENT_SANDBOX => self::QR_NO_VERIFACTU_VERIFICATION_URL_TEST,
            default => throw new \InvalidArgumentException("Invalid environment: $environment")
        };

        VerifactuService::config([
            VerifactuService::QR_VERIFICATION_URL => $qrValidationUrl,
            VerifactuService::QR_MODE => VerifactuService::QR_MODE_NO_VERIFACTU,
        ]);
    }

    /**
     * Registers a new invoice (Alta) with AEAT via VERI*FACTU.
     *
     * @throws \DOMException
     * @throws \SoapFault
     */
    public static function registerInvoice(InvoiceSubmission $invoice): InvoiceResponse
    {
        return VerifactuService::registerInvoice($invoice);
    }

    /**
     * Cancels an invoice (Anulación) with AEAT via VERI*FACTU.
     */
    public static function cancelInvoice(InvoiceCancellation $cancellation): InvoiceResponse
    {
        return VerifactuService::cancelInvoice($cancellation);
    }

    /**
     * Queries submitted invoices from AEAT via VERI*FACTU.
     */
    public static function queryInvoices(InvoiceQuery $query): QueryResponse
    {
        return VerifactuService::queryInvoices($query);
    }

    /**
     * Generates a QR code for the provided invoice using the configured QR mode.
     *
     * When the service is configured via `configNoVerifactu()`, this method generates
     * a No VERI*FACTU QR code (no huella, mandatory importe, No VERI*FACTU endpoint).
     * When configured via `config()`, it generates a VERI*FACTU QR code.
     *
     * @return string QR image data (binary PNG by default)
     */
    public static function generateInvoiceQr(InvoiceRecord $record): string
    {
        return VerifactuService::generateInvoiceQr($record);
    }
}
