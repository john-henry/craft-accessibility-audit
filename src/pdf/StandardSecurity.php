<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\pdf;

/**
 * Asks the standard security handler one question: does the file open without
 * a password?
 *
 * Most encrypted PDFs carry only an owner password, which restricts printing
 * or copying but lets anyone open the file; a user password locks everyone
 * out until they type it. The two read the same from outside, so the empty
 * password is tried against /U the way a reader does when a file is opened
 * (PDF 32000-1 algorithms 6 and 7, ISO 32000-2 algorithms 11 and 2.B).
 *
 * Nothing in the file is decrypted. This only derives the key far enough to
 * compare it with /U.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
class StandardSecurity
{
    // Const Properties
    // =========================================================================

    /**
     * @var string The 32 padding bytes every password is completed with
     * (PDF 32000-1, algorithm 2, step a).
     */
    public const PADDING = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08"
        . "\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

    // Public Methods
    // =========================================================================

    /**
     * Whether the empty user password opens the file.
     *
     * A revision this does not know is given the benefit of the doubt: the
     * check goes ahead on what can be read, and the browser pass, which
     * decrypts, settles it where it runs.
     *
     * @param int $revision /R from the encryption dictionary.
     * @param int $keyBits The key length in bits (/Length, 40 where absent).
     * @param string $owner /O.
     * @param string $user /U.
     * @param int $permissions /P.
     * @param string $fileId The first element of the trailer's /ID.
     * @param bool $encryptMetadata /EncryptMetadata (revision 4).
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function opensWithoutPassword(
        int $revision,
        int $keyBits,
        string $owner,
        string $user,
        int $permissions,
        string $fileId,
        bool $encryptMetadata = true,
    ): bool {
        return match (true) {
            $revision === 2 => self::_rc4UserKeyMatches(2, 5, $owner, $user, $permissions, $fileId, $encryptMetadata),
            $revision === 3, $revision === 4 => self::_rc4UserKeyMatches(
                $revision,
                max(5, min(16, intdiv($keyBits, 8))),
                $owner,
                $user,
                $permissions,
                $fileId,
                $encryptMetadata,
            ),
            $revision === 5 => strlen($user) >= 40
                && hash_equals(substr($user, 0, 32), hash('sha256', substr($user, 32, 8), true)),
            $revision === 6 => strlen($user) >= 40 && self::_revision6Opens($user),
            default => true,
        };
    }

    /**
     * RC4, the stream cipher revisions 2 to 4 are built on.
     *
     * Written out because OpenSSL 3 moved RC4 into its legacy provider, which
     * most PHP builds do not load.
     *
     * @param string $key The key.
     * @param string $data The data to encrypt or decrypt.
     * @return string
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function rc4(string $key, string $data): string
    {
        $s = range(0, 255);
        $keyLength = strlen($key);

        if ($keyLength === 0) {
            return $data;
        }

        for ($i = 0, $j = 0; $i < 256; $i++) {
            $j = ($j + $s[$i] + ord($key[$i % $keyLength])) % 256;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
        }

        $out = '';
        $length = strlen($data);

        for ($n = 0, $i = 0, $j = 0; $n < $length; $n++) {
            $i = ($i + 1) % 256;
            $j = ($j + $s[$i]) % 256;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
            $out .= chr(ord($data[$n]) ^ $s[($s[$i] + $s[$j]) % 256]);
        }

        return $out;
    }

    /**
     * The file key for a password, revisions 2 to 4 (PDF 32000-1, algorithm 2).
     *
     * @param string $password The password, empty for the user password test.
     * @param int $revision /R.
     * @param int $keyBytes The key length in bytes.
     * @param string $owner /O.
     * @param int $permissions /P.
     * @param string $fileId The first element of the trailer's /ID.
     * @param bool $encryptMetadata /EncryptMetadata.
     * @return string
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function rc4FileKey(
        string $password,
        int $revision,
        int $keyBytes,
        string $owner,
        int $permissions,
        string $fileId,
        bool $encryptMetadata = true,
    ): string {
        $input = substr($password . self::PADDING, 0, 32)
            . substr(str_pad($owner, 32, "\0"), 0, 32)
            . pack('V', $permissions & 0xFFFFFFFF)
            . $fileId;

        if ($revision >= 4 && !$encryptMetadata) {
            $input .= "\xFF\xFF\xFF\xFF";
        }

        $hash = md5($input, true);

        if ($revision >= 3) {
            for ($i = 0; $i < 50; $i++) {
                $hash = md5(substr($hash, 0, $keyBytes), true);
            }
        }

        return substr($hash, 0, $keyBytes);
    }

    /**
     * /U for a file key, revisions 2 to 4 (PDF 32000-1, algorithms 4 and 5).
     *
     * For revisions 3 and 4 only the first 16 bytes mean anything; the rest
     * is padding of the writer's choice.
     *
     * @param string $key The file key.
     * @param int $revision /R.
     * @param string $fileId The first element of the trailer's /ID.
     * @return string
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public static function rc4UserEntry(string $key, int $revision, string $fileId): string
    {
        if ($revision === 2) {
            return self::rc4($key, self::PADDING);
        }

        $value = self::rc4($key, md5(self::PADDING . $fileId, true));

        for ($i = 1; $i <= 19; $i++) {
            $value = self::rc4(self::_xorKey($key, $i), $value);
        }

        return $value;
    }

    // Private Methods
    // =========================================================================

    /**
     * Whether the empty password's key reproduces /U, revisions 2 to 4.
     *
     * @param int $revision /R.
     * @param int $keyBytes The key length in bytes.
     * @param string $owner /O.
     * @param string $user /U.
     * @param int $permissions /P.
     * @param string $fileId The first element of the trailer's /ID.
     * @param bool $encryptMetadata /EncryptMetadata.
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _rc4UserKeyMatches(
        int $revision,
        int $keyBytes,
        string $owner,
        string $user,
        int $permissions,
        string $fileId,
        bool $encryptMetadata,
    ): bool {
        $key = self::rc4FileKey('', $revision, $keyBytes, $owner, $permissions, $fileId, $encryptMetadata);
        $expected = self::rc4UserEntry($key, $revision, $fileId);
        $compare = $revision === 2 ? 32 : 16;

        return strlen($user) >= $compare && hash_equals(substr($expected, 0, $compare), substr($user, 0, $compare));
    }

    /**
     * The key with every byte XORed with a round number (algorithm 5, step e).
     *
     * @param string $key The file key.
     * @param int $round The round, 1 to 19.
     * @return string
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _xorKey(string $key, int $round): string
    {
        $out = '';
        $length = strlen($key);

        for ($i = 0; $i < $length; $i++) {
            $out .= chr(ord($key[$i]) ^ $round);
        }

        return $out;
    }

    /**
     * Whether the empty password reproduces a revision 6 /U.
     *
     * Where the build has no AES-128-CBC the answer is "opens": the browser
     * pass settles it where it runs.
     *
     * @param string $user /U.
     * @return bool
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _revision6Opens(string $user): bool
    {
        $hash = self::_hash2B('', substr($user, 32, 8));

        return $hash === null || hash_equals(substr($user, 0, 32), $hash);
    }

    /**
     * The revision 6 password hash (ISO 32000-2, algorithm 2.B), for a user
     * password, so with no user key mixed in.
     *
     * @param string $password The password.
     * @param string $salt The 8-byte validation salt.
     * @return string|null The 32-byte hash, or null where AES is unavailable.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    private static function _hash2B(string $password, string $salt): ?string
    {
        if (!function_exists('openssl_encrypt')) {
            return null;
        }

        $k = hash('sha256', $password . $salt, true);

        for ($round = 0; ; $round++) {
            $block = str_repeat($password . $k, 64);
            $e = openssl_encrypt($block, 'aes-128-cbc', substr($k, 0, 16), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr($k, 16, 16));

            if ($e === false) {
                return null;
            }

            $sum = 0;

            for ($i = 0; $i < 16; $i++) {
                $sum += ord($e[$i]);
            }

            $k = match ($sum % 3) {
                0 => hash('sha256', $e, true),
                1 => hash('sha384', $e, true),
                default => hash('sha512', $e, true),
            };

            if ($round >= 63 && ord($e[strlen($e) - 1]) <= $round - 31) {
                return substr($k, 0, 32);
            }
        }
    }
}
