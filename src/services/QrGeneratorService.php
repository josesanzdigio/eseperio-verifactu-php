<?php

declare(strict_types=1);

namespace eseperio\verifactu\services;

use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use eseperio\verifactu\models\InvoiceRecord;

/**
 * Service responsible for generating QR codes for invoices according to the AEAT Verifactu specification.
 *
 * This service uses the bacon/bacon-qr-code library to generate QR codes that comply with
 * the Spanish Tax Agency (AEAT) Verifactu requirements. The QR code contains a URL with
 * embedded invoice information that can be used to verify the invoice's authenticity.
 *
 * Usage:
 * The main method is `generateQr()` which takes an InvoiceRecord object and generates
 * a QR code with the verification URL containing the invoice's key data.
 *
 * Available rendering engines:
 * - GD (RENDERER_GD): Uses PHP's GD library. Output format is PNG. Most widely compatible option.
 * - Imagick (RENDERER_IMAGICK): Uses ImageMagick for higher quality images. Output format is PNG.
 * - SVG (RENDERER_SVG): Generates vector SVG files that can be scaled without quality loss.
 *
 * Output destinations:
 * - String (DESTINATION_STRING): Returns the binary/text content of the QR image.
 * - File (DESTINATION_FILE): Saves the QR to a temporary file and returns the file path.
 *
 * Example usage:
 * ```
 * // Create an invoice record
 * $invoiceId = new InvoiceId('B12345678', 'FACT-2023-001', '31-12-2023');
 * $record = new InvoiceRecord($invoiceId, 'abcdef123456789');
 *
 * // Generate QR code as SVG string
 * $svgQrCode = QrGeneratorService::generateQr(
 *     $record,
 *     'https://sede.agenciatributaria.gob.es/verifactu',
 *     QrGeneratorService::DESTINATION_STRING,
 *     400,
 *     QrGeneratorService::RENDERER_SVG
 * );
 *
 * // Generate QR code as PNG file
 * $pngFilePath = QrGeneratorService::generateQr(
 *     $record,
 *     'https://sede.agenciatributaria.gob.es/verifactu',
 *     QrGeneratorService::DESTINATION_FILE,
 *     300,
 *     QrGeneratorService::RENDERER_GD
 * );
 * ```
 */
class QrGeneratorService
{
    /**
     * Destination constants.
     */
    public const DESTINATION_FILE = 'file';
    public const DESTINATION_STRING = 'string';

    /**
     * Renderer constants.
     */
    public const RENDERER_GD = 'gd';
    public const RENDERER_IMAGICK = 'imagick';
    public const RENDERER_SVG = 'svg';

    /**
     * Generates a QR code for a given invoice record,
     * using the AEAT Verifactu QR specification (URL and fields).
     *
     * @param string $baseVerificationUrl Base URL for AEAT invoice verification
     * @param string $dest Destination type (file or string)
     * @param int $size Resolution of the QR code
     * @param string $engine Renderer to use (gd, imagick, svg)
     * @param float|null $totalAmount Total invoice amount (ImporteTotal); included in QR only when provided
     * @return string QR image data or file path
     * @throws \RuntimeException
     */
    public static function generateQr(
        InvoiceRecord $record,
        $baseVerificationUrl,
        $dest = self::DESTINATION_STRING,
        $size = 300,
        $engine = self::RENDERER_GD,
        ?float $totalAmount = null
    ) {
        $qrContent = self::buildQrContent($record, $baseVerificationUrl, $totalAmount);
        $writer = self::createWriter($engine, $size);
        $qrData = $writer->writeString($qrContent);

        if ($dest === self::DESTINATION_FILE) {
            $filePath = sys_get_temp_dir() . '/qr_' . uniqid() . self::getFileExtension($engine);
            file_put_contents($filePath, $qrData);

            return $filePath;
        }

        return $qrData;
    }

    /**
     * Builds the QR content string according to the AEAT VERI*FACTU QR specification.
     *
     * AEAT-compliant parameter contract:
     * - `nif`      — issuer NIF (mandatory)
     * - `numserie` — invoice series + number (mandatory; replaces legacy `num`)
     * - `fecha`    — issue date in DD-MM-YYYY format (mandatory; QR-only conversion)
     * - `importe`  — total invoice amount (mandatory for verifiable invoices; omitted when null)
     * - `huella`   — SHA-256 hash/fingerprint (optional; included only when hash is set)
     *
     * `formato=json` MUST NOT appear in the QR URL per AEAT specification.
     *
     * @param string     $baseVerificationUrl Base URL for AEAT invoice verification
     * @param float|null $totalAmount         Total invoice amount (ImporteTotal); omitted when null
     */
    protected static function buildQrContent(
        InvoiceRecord $record,
        $baseVerificationUrl,
        ?float $totalAmount = null
    ): string {
        $invoiceId = $record->getInvoiceId();
        $nif    = $invoiceId->issuerNif;
        $series = $invoiceId->seriesNumber;
        $hash   = $record->hash;

        // Reformat date from internal YYYY-MM-DD to the AEAT-mandated DD-MM-YYYY.
        // This conversion is scoped to QR output only; the XML serializer is untouched.
        $date = self::reformatDateForQr($invoiceId->issueDate);

        $params = [
            'nif'      => $nif,
            'numserie' => $series,
            'fecha'    => $date,
        ];

        if ($totalAmount !== null) {
            $params['importe'] = $totalAmount;
        }

        if (!empty($hash)) {
            $params['huella'] = $hash;
        }

        return rtrim($baseVerificationUrl, '?') . '?' . http_build_query($params);
    }

    /**
     * Converts a date string from YYYY-MM-DD (internal ISO format) to DD-MM-YYYY
     * as required by the AEAT VERI*FACTU QR specification.
     *
     * This method is intentionally scoped to QR URL generation only.
     * The XML serializer performs its own date conversion independently.
     *
     * @param string $isoDate Date in YYYY-MM-DD format
     * @return string Date in DD-MM-YYYY format
     */
    protected static function reformatDateForQr(string $isoDate): string
    {
        $parts = explode('-', $isoDate);

        if (count($parts) !== 3) {
            return $isoDate;
        }

        [$year, $month, $day] = $parts;

        return $day . '-' . $month . '-' . $year;
    }

    /**
     * Creates a writer with the specified renderer and resolution.
     *
     * @param string $renderer
     * @param int $resolution
     * @throws \RuntimeException
     */
    protected static function createWriter($renderer, $resolution): Writer
    {
        switch ($renderer) {
            case self::RENDERER_GD:
                return new Writer(new GDLibRenderer($resolution));

            case self::RENDERER_IMAGICK:
                $imageRenderer = new ImageRenderer(
                    new RendererStyle($resolution),
                    new ImagickImageBackEnd()
                );

                return new Writer($imageRenderer);

            case self::RENDERER_SVG:
                $imageRenderer = new ImageRenderer(
                    new RendererStyle($resolution),
                    new SvgImageBackEnd()
                );

                return new Writer($imageRenderer);

            default:
                throw new \RuntimeException("Unsupported renderer: {$renderer}");
        }
    }

    /**
     * Gets the file extension for the specified renderer.
     *
     * @param string $renderer
     */
    protected static function getFileExtension($renderer): string
    {
        return match ($renderer) {
            self::RENDERER_SVG => '.svg',
            default => '.png',
        };
    }
}
