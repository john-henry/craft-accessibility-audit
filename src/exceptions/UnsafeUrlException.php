<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\exceptions;

use yii\base\Exception;

/**
 * Thrown when a URL is rejected by the SSRF guard because it is malformed,
 * uses a disallowed scheme, or resolves to a private/reserved IP address.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class UnsafeUrlException extends Exception
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'Unsafe URL';
    }
}
