<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\helpers;

/**
 * Helper for safely emitting CSV that may contain editor-controlled values.
 *
 * Every CSV this plugin writes (the dashboard exports and the report export)
 * includes page titles, issue messages, and element context: strings an editor
 * types, not the plugin. A cell beginning with `=`, `+`, `-`, `@`, tab, or CR
 * is interpreted as a formula by Excel/Sheets when the file is opened, which is
 * a well-known spreadsheet-injection vector. Route every such cell through
 * {@see self::guard()} before writing.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class Csv
{
    // Constants
    // =========================================================================

    /**
     * @var string[] Leading characters that make a spreadsheet treat a cell as
     * a formula. A cell starting with any of these is prefixed with a single
     * quote so it is stored and shown as literal text.
     */
    private const _DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    // Public Methods
    // =========================================================================

    /**
     * Neutralises spreadsheet formula injection on a single CSV cell.
     *
     * Prefixes a leading `=`/`+`/`-`/`@`/tab/CR with a single quote so the value
     * is treated as text, not a formula. Non-dangerous values are returned
     * unchanged, so numeric and ordinary text cells are untouched.
     *
     * @param string $value The raw cell value.
     * @return string The value, quoted only if it would otherwise be a formula.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function guard(string $value): string
    {
        if ($value !== '' && in_array($value[0], self::_DANGEROUS_PREFIXES, true)) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * Guards every cell in a row.
     *
     * A convenience over mapping {@see self::guard()} at each call site. Values
     * are cast to string first, so ints/floats/nulls from a query row are safe
     * to pass straight through.
     *
     * @param array<int, string|int|float|null> $row The row cells.
     * @return string[] The row with every cell guarded.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function guardRow(array $row): array
    {
        return array_map(static fn(mixed $value): string => self::guard((string)$value), $row);
    }
}
