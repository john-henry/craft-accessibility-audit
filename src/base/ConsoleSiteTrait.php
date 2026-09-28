<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\base;

use Craft;
use craft\errors\SiteNotFoundException;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use yii\helpers\Console;

/**
 * The `--site` option and the confirmation prompt, shared by the plugin's
 * console commands.
 *
 * Both were written out in each controller in turn. The site resolution in
 * particular carries a refusal rather than a fallback, and a rule enforced in
 * one copy and not the other is the shape of a command that quietly acts on a
 * site nobody named.
 *
 * @property bool $interactive Whether the command may prompt, from
 *                             craft\console\Controller.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.5.0
 */
trait ConsoleSiteTrait
{
    // Public Properties
    // =========================================================================

    /**
     * @var string|null The handle of the site to act on. Pro only; the
     *                  Standard edition always acts on the primary site.
     */
    public ?string $site = null;

    // Private Methods
    // =========================================================================

    /**
     * Resolves the site ID from the --site option, falling back to the primary
     * site. Null when --site names a site that does not exist.
     *
     * @return int|null
     * @throws SiteNotFoundException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _resolveSiteId(): ?int
    {
        // Multi-site is a Pro feature: --site is only honoured on Pro, so
        // Standard always operates on the primary site.
        if ($this->site) {
            if (!AccessibilityAudit::getInstance()->isPro()) {
                $this->stderr(
                    "Multi-site is a Pro feature; --site is ignored on the Standard edition.\n",
                    Console::FG_YELLOW
                );
            } else {
                $site = Craft::$app->getSites()->getSiteByHandle($this->site);

                // A handle that names no site is refused rather than quietly
                // answered with the primary one. A mistyped or renamed handle
                // in a scheduled command would otherwise act on a site nobody
                // asked for, and report success for doing it.
                if ($site === null) {
                    $this->stderr(
                        "No site with the handle \"{$this->site}\".\n",
                        Console::FG_RED
                    );

                    return null;
                }

                return $site->id;
            }
        }

        return Craft::$app->getSites()->getPrimarySite()->id;
    }

    /**
     * Asks before removing something, defaulted to no.
     *
     * Defaulted to no because nothing rebuilds what these commands remove:
     * somebody running one to read the count and pressing return out of habit
     * should not lose it. A non-interactive run (--interactive=0) answers yes,
     * which is the console convention and the only way to script one.
     *
     * @param string $question The prompt to put.
     * @return bool Whether to go ahead.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _confirmRemoval(string $question = 'Remove them?'): bool
    {
        if (!$this->interactive) {
            return true;
        }

        return $this->confirm($question, false);
    }
}
