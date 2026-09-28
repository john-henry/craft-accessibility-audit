<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\controllers;

use craft\helpers\DateTimeHelper;
use Exception;
use johnhenry\accessibilityaudit\models\OrganisationMetaModel;

/**
 * Shared reading of the organisation details that the statement and the VPAT
 * both carry.
 *
 * Product name, contact and evaluation method describe the organisation rather
 * than either document, so they are stored once and posted by both forms. The
 * reading of them lives here so the two cannot drift into disagreeing about
 * what a field is called or how it is cleaned up.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.4.0
 */
trait OrganisationMetaTrait
{
    // Protected Methods
    // =========================================================================

    /**
     * The organisation details as posted, ready to validate.
     *
     * @return OrganisationMetaModel The populated, not yet validated, model.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.4.0
     */
    protected function organisationMetaFromRequest(): OrganisationMetaModel
    {
        $shared = new OrganisationMetaModel();
        $shared->productName = trim((string) $this->request->getBodyParam('productName', ''));
        $shared->productDescription = trim((string) $this->request->getBodyParam('productDescription', ''));
        $shared->contactName = trim((string) $this->request->getBodyParam('contactName', ''));
        $shared->contactEmail = trim((string) $this->request->getBodyParam('contactEmail', ''));
        $shared->contactPhone = trim((string) $this->request->getBodyParam('contactPhone', ''));
        $shared->evalMethodology = trim((string) $this->request->getBodyParam('evalMethodology', ''));
        $shared->evalMethods = $this->splitLines((string) $this->request->getBodyParam('evalMethods', ''));
        $shared->scopePages = $this->splitLines((string) $this->request->getBodyParam('scopePages', ''));

        return $shared;
    }

    /**
     * A textarea's lines as a list, blanks and repeats dropped.
     *
     * Split on `\R` rather than on a newline: a textarea posted from Windows
     * sends CRLF, and splitting on the newline alone leaves a carriage return
     * on the end of every line, which then travels into the published document.
     *
     * @param string $value The raw newline-separated value.
     * @return string[] The lines, in the order given.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.4.0
     */
    protected function splitLines(string $value): array
    {
        $lines = preg_split('/\R/', $value) ?: [];
        $lines = array_filter(array_map('trim', $lines), static fn(string $line): bool => $line !== '');

        return array_values(array_unique($lines));
    }

    /**
     * A posted date as `Y-m-d`, or an empty string when nothing usable came.
     *
     * Craft's date field posts an array carrying its own `date` key, so an
     * untouched field arrives as a populated array with nothing in it rather
     * than as an empty value.
     *
     * @param string $param The body param name (e.g. `reportDate`).
     * @return string The `Y-m-d` date, or an empty string.
     * @throws Exception
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.4.0
     */
    protected function dateParamToYmd(string $param): string
    {
        $value = $this->request->getBodyParam($param);

        if (empty($value) || (is_array($value) && empty($value['date']))) {
            return '';
        }

        $date = DateTimeHelper::toDateTime($value);

        return $date !== false ? $date->format('Y-m-d') : '';
    }
}
