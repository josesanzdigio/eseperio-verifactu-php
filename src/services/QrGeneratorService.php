<?php

declare(strict_types=1);

namespace eseperio\verifactu\services;

use BaconQrCode\Common\ErrorCorrectionLevel;
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
 *     QrGeneratorService::RENDERER_SVG,
 *     121.40
 * );
 *
 * // Generate QR code as PNG file
 * $pngFilePath = QrGeneratorService::generateQr(
 *     $record,
 *     'https://sede.agenciatributaria.gob.es/verifactu',
 *     QrGeneratorService::DESTINATION_FILE,
 *     300,
 *     QrGeneratorService::RENDERER_GD,
 *     121.40
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
     * using the AEAT Verifactu or No VERI*FACTU QR specification.
     *
     * All generated QR codes use error correction level M per AEAT Art.21.
     *
     * @param InvoiceRecord     $record              Invoice record to encode
     * @param string            $baseVerificationUrl Base URL for AEAT invoice verification
     * @param string            $dest                Destination type (file or string)
     * @param int               $size                Resolution of the QR code in pixels
     * @param string            $engine              Renderer to use (gd, imagick, svg)
     * @param string|int|float  $totalAmount         Total invoice amount (ImporteTotal)
     * @param int               $margin              Renderer margin (module units around QR matrix)
     * @return string QR image data or file path
     * @throws \RuntimeException
     * @throws \InvalidArgumentException if totalAmount is null
     */
    public static function generateQr(
        InvoiceRecord $record,
        $baseVerificationUrl,
        $dest = self::DESTINATION_STRING,
        $size = 300,
        $engine = self::RENDERER_GD,
        $totalAmount = null,
        int $margin = 4,
    ) {
        $qrContent = self::generateQrContent($record, $baseVerificationUrl, $totalAmount);
        $writer = self::createWriter($engine, $size, $margin);
        $qrData = $writer->writeString($qrContent, 'UTF-8', ErrorCorrectionLevel::M());

        if ($dest === self::DESTINATION_FILE) {
            $filePath = sys_get_temp_dir() . '/qr_' . uniqid() . self::getFileExtension($engine);
            file_put_contents($filePath, $qrData);

            return $filePath;
        }

        return $qrData;
    }

    /**
     * Generates the canonical URL encoded in an invoice QR code.
     *
     * @param InvoiceRecord     $record              Invoice record to encode
     * @param string            $baseVerificationUrl Base URL for AEAT invoice verification
     * @param string|int|float  $totalAmount         Total invoice amount (ImporteTotal)
     * @return string Complete absolute verification URL
     * @throws \InvalidArgumentException if validation fails
     */
    public static function generateQrContent(
        InvoiceRecord $record,
        string $baseVerificationUrl,
        $totalAmount,
    ): string {
        return self::buildQrContent($record, $baseVerificationUrl, $totalAmount);
    }

    /**
     * Builds the QR content string according to the AEAT QR specification.
     *
     * Unified parameter contract for both VERI*FACTU and No VERI*FACTU:
     * - `nif`      — issuer NIF (mandatory; exactly 9 printable ASCII characters)
     * - `numserie` — invoice series + number (mandatory; 1-60 printable ASCII characters)
     * - `fecha`    — issue date in DD-MM-YYYY format (mandatory; QR-only conversion)
     * - `importe`  — total invoice amount (mandatory; up to 12 integer digits, 2 decimal places)
     *
     * `huella` MUST NOT appear in the QR URL per the AEAT specification.
     * `formato=json` MUST NOT appear in the QR URL per the AEAT specification.
     *
     * @param InvoiceRecord     $record              Invoice record to encode
     * @param string            $baseVerificationUrl Base URL for AEAT invoice verification
     * @param string|int|float  $totalAmount         Total invoice amount (ImporteTotal)
     * @throws \InvalidArgumentException if totalAmount is null or validation fails
     */
    protected static function buildQrContent(
        InvoiceRecord $record,
        $baseVerificationUrl,
        $totalAmount,
    ): string {
        $invoiceId = $record->getInvoiceId();
        $nif    = self::requireValidNif($invoiceId?->issuerNif ?? null, 'nif');
        $series = self::requireValidNumserie($invoiceId?->seriesNumber ?? null, 'numserie');

        $date = self::reformatDateForQr($invoiceId?->getIssueDate() ?? null);

        $params = [
            'nif'      => $nif,
            'numserie' => $series,
            'fecha'    => $date,
        ];

        $params['importe'] = self::formatQrAmount($totalAmount);

        return rtrim($baseVerificationUrl, '?') . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Converts a date string from YYYY-MM-DD (internal ISO format) to DD-MM-YYYY
     * as required by the AEAT VERI*FACTU QR specification.
     *
     * This method is intentionally scoped to QR URL generation only.
     * The XML serializer performs its own date conversion independently.
     *
     * @param mixed $isoDate Date in YYYY-MM-DD format
     * @return string Date in DD-MM-YYYY format
     */
    protected static function reformatDateForQr($isoDate): string
    {
        if (!is_string($isoDate) || $isoDate === '') {
            throw new \InvalidArgumentException('fecha is required and must use YYYY-MM-DD format.');
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $isoDate)) {
            throw new \InvalidArgumentException('fecha must use YYYY-MM-DD format.');
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $isoDate);
        $errors = \DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new \InvalidArgumentException('fecha must be a valid calendar date in YYYY-MM-DD format.');
        }

        return $date->format('d-m-Y');
    }

    /**
     * @param mixed $value
     */
    private static function requireValidNif($value, string $field): string
    {
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException(sprintf('%s is required and must be a non-empty string.', $field));
        }

        if (strlen($value) !== 9) {
            throw new \InvalidArgumentException(sprintf('%s must be exactly 9 characters.', $field));
        }

        if (!preg_match('/^[\x20-\x7E]{9}$/', $value)) {
            throw new \InvalidArgumentException(sprintf('%s must contain only printable ASCII characters.', $field));
        }

        return $value;
    }

    /**
     * @param mixed $value
     */
    private static function requireValidNumserie($value, string $field): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException(sprintf('%s is required and must be a non-empty string.', $field));
        }

        if (mb_strlen($value) > 60) {
            throw new \InvalidArgumentException(sprintf('%s must not exceed 60 characters.', $field));
        }

        if (!preg_match('/^[\x20-\x7E]+$/', $value)) {
            throw new \InvalidArgumentException(sprintf('%s must contain only printable ASCII characters.', $field));
        }

        return $value;
    }

    /**
     * @param string|int|float|null $amount
     */
    private static function formatQrAmount($amount): string
    {
        if (is_bool($amount)) {
            throw new \InvalidArgumentException('importe must not be a boolean.');
        }

        if (is_array($amount)) {
            throw new \InvalidArgumentException('importe must not be an array.');
        }

        if (is_object($amount)) {
            throw new \InvalidArgumentException('importe must not be an object.');
        }

        if (is_string($amount)) {
            if ($amount === '') {
                throw new \InvalidArgumentException('importe must not be empty.');
            }

            if (str_contains($amount, ',')) {
                throw new \InvalidArgumentException('importe must use "." as decimal separator.');
            }

            if (!preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/', $amount)) {
                throw new \InvalidArgumentException('importe must be numeric.');
            }

            $normalizedAmount = $amount;
        } elseif (is_int($amount)) {
            $normalizedAmount = (string) $amount;
        } elseif (is_float($amount)) {
            if (!is_finite($amount)) {
                throw new \InvalidArgumentException('importe must be a finite number.');
            }

            $roundedAmount = round($amount, 2);

            if (abs($roundedAmount - $amount) > 0.0000001) {
                throw new \InvalidArgumentException('importe must not contain more than 2 decimal places.');
            }

            $normalizedAmount = number_format($roundedAmount, 2, '.', '');
            $normalizedAmount = rtrim(rtrim($normalizedAmount, '0'), '.');
        } else {
            throw new \InvalidArgumentException('importe must be numeric.');
        }

        if (in_array($normalizedAmount, ['-0', '-0.0', '-0.00', '-0.000'], true)) {
            return '0';
        }

        $numericPart = $normalizedAmount;
        if (str_starts_with($normalizedAmount, '-')) {
            $numericPart = substr($normalizedAmount, 1);
        }

        $intPart = explode('.', $numericPart)[0];
        if (strlen($intPart) > 12) {
            throw new \InvalidArgumentException('importe must not have more than 12 integer digits.');
        }

        if (!preg_match('/^-?(?:0|[1-9]\d*)(?:\.(\d+))?$/', $normalizedAmount, $matches)) {
            throw new \InvalidArgumentException('importe must be numeric.');
        }

        $decimals = $matches[1] ?? '';
        if (strlen($decimals) > 2) {
            throw new \InvalidArgumentException('importe must not contain more than 2 decimal places.');
        }

        if ($decimals === '') {
            return $normalizedAmount;
        }

        return rtrim(rtrim($normalizedAmount, '0'), '.');
    }

    /**
     * Creates a writer with the specified renderer, resolution, and margin.
     *
     * @param string $renderer
     * @param int    $resolution
     * @param int    $margin
     * @throws \RuntimeException
     */
    protected static function createWriter($renderer, $resolution, int $margin = 4): Writer
    {
        switch ($renderer) {
            case self::RENDERER_GD:
                return new Writer(new GDLibRenderer($resolution, $margin));

            case self::RENDERER_IMAGICK:
                $imageRenderer = new ImageRenderer(
                    new RendererStyle($resolution, $margin),
                    new ImagickImageBackEnd()
                );

                return new Writer($imageRenderer);

            case self::RENDERER_SVG:
                $imageRenderer = new ImageRenderer(
                    new RendererStyle($resolution, $margin),
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
