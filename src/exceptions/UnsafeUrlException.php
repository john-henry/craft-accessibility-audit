<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\exceptions;

use yii\base\Exception;

/**
 * Thrown when [[UrlSafety]] refuses a fetch.
 *
 * The guard covers the whole fetch rather than the address alone, so the
 * reasons run past the URL's own shape. A URL can be malformed or carry a
 * scheme other than http or https; a host can fail to resolve, or resolve to a
 * private or reserved address; a redirect chain can run past its limit; and a
 * response body can run past its byte cap. The last two are decided from what
 * came back, and reach here through the same guard.
 *
 * Messages are plain source strings, because the guard is a helper with no
 * view of the request's language. Whatever shows one to a person translates it
 * there, and UnsafeUrlMessageTest keeps every one of them in the message files.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
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
