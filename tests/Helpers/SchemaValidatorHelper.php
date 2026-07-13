<?php

declare(strict_types=1);

namespace eseperio\verifactu\tests\Helpers;

use DOMDocument;
use LibXMLError;

/**
 * Lightweight schema validation helper for TASK-03B.
 *
 * Validates a DOMDocument against a local XSD file using only
 * repository-local schema paths.  Never contacts the network.
 */
final class SchemaValidatorHelper
{
    /**
     * Validate $dom against the XSD at $schemaPath.
     *
     * Returns an array with:
     *   - 'valid'   => bool
     *   - 'errors'  => LibXMLError[]
     *   - 'schema'  => string (resolved schema path)
     *
     * The previous libxml error state is restored in all cases.
     */
    public static function validate(DOMDocument $dom, string $schemaPath): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $valid = $dom->schemaValidate($schemaPath);
            $errors = libxml_get_errors();
            libxml_clear_errors();

            return [
                'valid' => $valid,
                'errors' => $errors,
                'schema' => realpath($schemaPath) ?: $schemaPath,
            ];
        } finally {
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * Format an array of LibXmlError objects into a readable string.
     */
    public static function formatErrors(array $errors): string
    {
        $lines = [];
        foreach ($errors as $e) {
            $level = match ($e->level) {
                LIBXML_ERR_WARNING => 'WARNING',
                LIBXML_ERR_ERROR => 'ERROR',
                LIBXML_ERR_FATAL => 'FATAL',
                default => (string) $e->level,
            };
            $lines[] = sprintf('  [%s] Line %d, Col %d: %s', $level, $e->line, $e->column, trim($e->message));
        }
        return implode("\n", $lines);
    }

    /**
     * Assert that $dom validates against $schemaPath.
     *
     * @throws \PHPUnit\Framework\AssertionFailedError
     */
    public static function assertValid(DOMDocument $dom, string $schemaPath, string $label = ''): void
    {
        $result = self::validate($dom, $schemaPath);
        $prefix = $label ? "[{$label}] " : '';

        \PHPUnit\Framework\Assert::assertTrue(
            $result['valid'],
            $prefix . 'Schema validation failed against ' . basename($result['schema']) . "\n" . self::formatErrors($result['errors'])
        );
    }

    /**
     * Assert that $dom fails validation against $schemaPath.
     */
    public static function assertInvalid(DOMDocument $dom, string $schemaPath, string $label = ''): void
    {
        $result = self::validate($dom, $schemaPath);
        $prefix = $label ? "[{$label}] " : '';

        \PHPUnit\Framework\Assert::assertFalse(
            $result['valid'],
            $prefix . 'Expected schema validation to fail but it passed'
        );
        \PHPUnit\Framework\Assert::assertNotEmpty(
            $result['errors'],
            $prefix . 'Expected at least one libxml diagnostic'
        );
    }
}
