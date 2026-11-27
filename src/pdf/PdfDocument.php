<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\pdf;

use Com\Tecnick\Pdf\Filter\Filter;
use Throwable;

/**
 * Read-only access to a parsed PDF's objects, in the token shape tc-lib hands back.
 *
 * Every token is `[type, value, offset]`: a dictionary's value is a flat list
 * of alternating name and value tokens, an array's a list of tokens, and an
 * indirect reference (`objref`) names its object as `"num_gen"`. Lookups
 * follow references, and anything missing or of the wrong type reads as
 * empty rather than throwing, so one malformed object costs the check that
 * read it and nothing else.
 *
 * @phpstan-type Token array<int, mixed>
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class PdfDocument
{
    // Const Properties
    // =========================================================================

    /**
     * @var int How many references one lookup follows before giving up, so a
     * reference cycle ends instead of looping.
     */
    private const MAX_REFERENCE_HOPS = 32;

    /**
     * @var int The most decoded bytes one metadata stream may produce.
     */
    private const MAX_METADATA_BYTES = 4194304;

    // Public Properties
    // =========================================================================

    /**
     * @var array<string, mixed> The trailer, as tc-lib reads it: `root`, `info`
     * and `encrypt` hold `"num_gen"` references, `size` the declared object count.
     */
    public array $trailer;

    // Private Properties
    // =========================================================================

    /**
     * @var array<string, array<int, mixed>> Every object, keyed by `"num_gen"`.
     */
    private array $_objects;

    // Public Methods
    // =========================================================================

    /**
     * @param array<string, mixed> $trailer The trailer tc-lib read.
     * @param array<string, array<int, mixed>> $objects Every object, keyed by `"num_gen"`.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function __construct(array $trailer, array $objects)
    {
        $this->trailer = $trailer;
        $this->_objects = $objects;
    }

    /**
     * How many objects were read.
     *
     * @return int
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function objectCount(): int
    {
        return count($this->_objects);
    }

    /**
     * A token that refers to a trailer entry, such as `root` or `info`.
     *
     * @param string $key The trailer key.
     * @return Token|null The reference, or null where the trailer has none.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function trailerRef(string $key): ?array
    {
        $ref = $this->trailer[$key] ?? '';

        if (!is_string($ref) || $ref === '') {
            return null;
        }

        return ['objref', $ref, 0];
    }

    /**
     * Follows indirect references to the object they name.
     *
     * @param Token|null $token The token.
     * @return Token|null The direct token, or null where it leads nowhere.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function resolve(?array $token): ?array
    {
        $hops = 0;

        while ($token !== null && ($token[0] ?? null) === 'objref') {
            if (++$hops > self::MAX_REFERENCE_HOPS) {
                return null;
            }

            $object = $this->_objects[(string)($token[1] ?? '')] ?? null;
            $token = is_array($object[0] ?? null) ? $object[0] : null;
        }

        return $token;
    }

    /**
     * A dictionary's entries, keyed by name.
     *
     * @param Token|null $token The token, direct or a reference.
     * @return array<string, Token> The entries, empty where the token is not a dictionary.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function dict(?array $token): array
    {
        $token = $this->resolve($token);

        if ($token === null || $token[0] !== '<<' || !is_array($token[1] ?? null)) {
            return [];
        }

        $items = array_values($token[1]);
        $map = [];

        for ($i = 0; $i + 1 < count($items); $i += 2) {
            $key = $items[$i];

            if (is_array($key) && ($key[0] ?? null) === '/' && is_array($items[$i + 1])) {
                $map[(string)$key[1]] = $items[$i + 1];
            }
        }

        return $map;
    }

    /**
     * An array's items.
     *
     * @param Token|null $token The token, direct or a reference.
     * @return array<int, Token> The items, empty where the token is not an array.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function arr(?array $token): array
    {
        $token = $this->resolve($token);

        if ($token === null || $token[0] !== '[' || !is_array($token[1] ?? null)) {
            return [];
        }

        return array_values(array_filter($token[1], 'is_array'));
    }

    /**
     * A name's value, without the solidus.
     *
     * @param Token|null $token The token, direct or a reference.
     * @return string|null The name, or null where the token is not a name.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function name(?array $token): ?string
    {
        $token = $this->resolve($token);

        return ($token !== null && $token[0] === '/') ? (string)$token[1] : null;
    }

    /**
     * A boolean's value.
     *
     * @param Token|null $token The token, direct or a reference.
     * @return bool|null The value, or null where the token is not a boolean.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function bool(?array $token): ?bool
    {
        $token = $this->resolve($token);

        return ($token !== null && $token[0] === 'boolean') ? $token[1] === 'true' : null;
    }

    /**
     * A number's value.
     *
     * @param Token|null $token The token, direct or a reference.
     * @return float|null The value, or null where the token is not a number.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function num(?array $token): ?float
    {
        $token = $this->resolve($token);

        return ($token !== null && $token[0] === 'numeric' && is_numeric($token[1])) ? (float)$token[1] : null;
    }

    /**
     * A string's raw bytes: a literal string with its escapes undone, or a hex
     * string decoded.
     *
     * @param Token|null $token The token, direct or a reference.
     * @return string|null The bytes, or null where the token is not a string.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function bytes(?array $token): ?string
    {
        $token = $this->resolve($token);

        if ($token === null) {
            return null;
        }

        if ($token[0] === '(') {
            return self::_unescapeLiteral((string)$token[1]);
        }

        if ($token[0] === '<') {
            $hex = (string)preg_replace('/[^0-9a-fA-F]/', '', (string)$token[1]);

            if (strlen($hex) % 2 === 1) {
                $hex .= '0';
            }

            return (string)hex2bin($hex);
        }

        return null;
    }

    /**
     * A text string as UTF-8: UTF-16BE where it opens with the byte-order
     * mark, UTF-8 where it opens with that one, PDFDocEncoding (read as
     * Windows-1252, which agrees with it on everything printable) otherwise.
     *
     * @param Token|null $token The token, direct or a reference.
     * @return string|null The text, or null where the token is not a string.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function text(?array $token): ?string
    {
        $bytes = $this->bytes($token);

        if ($bytes === null) {
            return null;
        }

        if (str_starts_with($bytes, "\xFE\xFF")) {
            return (string)mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE');
        }

        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            return (string)mb_scrub(substr($bytes, 3), 'UTF-8');
        }

        return (string)mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
    }

    /**
     * A stream object's decoded data.
     *
     * Only ever asked of the metadata stream: page content and images are
     * never decoded, which is what keeps a check's memory near the file size.
     *
     * @param Token|null $token A reference to the stream object.
     * @return string|null The data, or null where it is not a stream or will not decode.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function streamData(?array $token): ?string
    {
        if ($token === null || ($token[0] ?? null) !== 'objref') {
            return null;
        }

        $object = $this->_objects[(string)$token[1]] ?? null;
        $stream = $object[1] ?? null;

        if (!is_array($stream) || ($stream[0] ?? null) !== 'stream' || !is_string($stream[1] ?? null)) {
            return null;
        }

        $dict = $this->dict(is_array($object[0] ?? null) ? $object[0] : null);
        $filters = [];
        $filter = $this->resolve($dict['Filter'] ?? null);

        if ($filter !== null && $filter[0] === '/') {
            $filters[] = (string)$filter[1];
        } else {
            foreach ($this->arr($filter) as $item) {
                if (($name = $this->name($item)) !== null) {
                    $filters[] = $name;
                }
            }
        }

        try {
            return (new Filter())->decodeAll($filters, $stream[1], ['MaxOutputSize' => self::MAX_METADATA_BYTES]);
        } catch (Throwable) {
            return null;
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * Undoes a literal string's escapes (PDF 32000-1, 7.3.4.2).
     *
     * @param string $raw The string as written between its parentheses.
     * @return string The bytes it stands for.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _unescapeLiteral(string $raw): string
    {
        return (string)preg_replace_callback(
            '/\\\\(?:([0-7]{1,3})|(\r\n|\r|\n)|(.))/s',
            static function(array $m): string {
                if ($m[1] !== '') {
                    return chr((int)octdec($m[1]) & 0xFF);
                }

                if ($m[2] !== '') {
                    return '';
                }

                return match ($m[3]) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    'b' => "\x08",
                    'f' => "\f",
                    default => $m[3],
                };
            },
            $raw,
        );
    }
}
