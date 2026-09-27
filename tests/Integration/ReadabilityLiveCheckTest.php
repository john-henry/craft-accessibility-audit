<?php

use craft\db\Query;
use craft\elements\Entry;
use craft\events\AuthorizationCheckEvent;
use craft\fields\Dropdown;
use craft\fields\Lightswitch;
use craft\fields\Number;
use craft\fields\PlainText;
use craft\models\FieldLayout;
use craft\services\Elements;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\services\ReadabilityService;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Readability while an entry is being written.
//
// The text being written is not the published page: a draft's check scores the
// draft's own field text, never fetches the live URL in its place, and never
// stores a result, since the stored result describes what readers see.
// Suggestions for a draft are kept per user, out of the table.
//
// Helpers are uniquely named (rl*): Pest loads every test file into one process.
// ---------------------------------------------------------------------------

function rlDraftOf(Entry $entry): Entry
{
    $draft = Craft::$app->getDrafts()->createDraft($entry, $entry->authorId ?: 1, 'readability draft');

    return Entry::find()
        ->draftId($draft->draftId)
        ->siteId($entry->siteId)
        ->status(null)
        ->one();
}

/** Stored readability rows for an element. */
function rlStoredRows(int $elementId): int
{
    return (int)(new Query())
        ->from('{{%accessibilityaudit_readability}}')
        ->where(['elementId' => $elementId])
        ->count();
}

/** The body of a plugin source method, for wiring assertions. */
function rlSourceBody(string $file, string $signature): string
{
    $source = (string)file_get_contents(dirname(__DIR__, 2) . '/src/' . $file);
    $start = strpos($source, $signature);

    if ($start === false) {
        return '';
    }

    $end = strpos($source, "\n    p", $start + strlen($signature));

    return substr($source, $start, $end === false ? null : $end - $start);
}

beforeEach(function() {
    $this->rlEdition = AccessibilityAudit::getInstance()->edition;
    AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
    $this->actingAs(UserFactory::factory()->admin(true)->create());
});

afterEach(function() {
    AccessibilityAudit::getInstance()->edition = $this->rlEdition;
});

describe('Analysing text as it stands', function() {
    it('scores text directly, with the longest sentences', function() {
        $result = AccessibilityAudit::getInstance()->getReadability()
            ->analyseText(str_repeat('The cat ran off to the den. ', 15));

        expect($result)->not->toHaveKey('error')
            ->and($result['readingEase'])->toBe(100.0)
            ->and($result['gradeLevel'])->toBe(0.0)
            ->and($result['complexSentences'])->not->toBeEmpty();
    });

    it('says how much more text an element needs instead of fetching its page', function() {
        // The fixture entry has a URL and no text fields. analyseElement() would
        // fall back to fetching that URL; text still being written must not.
        $entry = scannableEntry();

        $result = AccessibilityAudit::getInstance()->getReadability()->analyseElementText($entry);

        expect($result)->toHaveKey('error')
            ->and($result['charactersNeeded'])->toBe(100);
    });

    it('ends a sentence at a paragraph break, so a heading does not run into the text after it', function() {
        // A heading or a label has no full stop. Split on punctuation alone, it
        // joined the first sentence of the next paragraph.
        $result = AccessibilityAudit::getInstance()->getReadability()
            ->analyseText("Opening hours for every visitor\n\n" . str_repeat('The cat ran off to the den. ', 15));

        expect($result['sentenceCount'])->toBe(16)
            ->and($result['complexSentences'][0])->toBe('The cat ran off to the den.');
    });

    it('keeps paragraphs apart when stripping rich text', function() {
        $fieldToText = new ReflectionMethod(ReadabilityService::class, '_fieldToText');

        expect($fieldToText->invoke(
            AccessibilityAudit::getInstance()->getReadability(),
            '<p>First paragraph ends here.</p><p>Second one &amp; more.</p>',
        ))->toBe("First paragraph ends here.\n\nSecond one & more.");
    });

    it('reads text fields only, not the settings and labels a layout also holds', function() {
        // Lightswitches, dropdowns and numbers hold settings, not prose.
        $isTextField = new ReflectionMethod(ReadabilityService::class, '_isTextField');
        $service = AccessibilityAudit::getInstance()->getReadability();

        expect(rlSourceBody('services/ReadabilityService.php', 'private function _extractElementText('))
            ->toContain('_isTextField(')
            ->and($isTextField->invoke($service, new PlainText()))->toBeTrue()
            ->and($isTextField->invoke($service, new Lightswitch()))->toBeFalse()
            ->and($isTextField->invoke($service, new Dropdown()))->toBeFalse()
            ->and($isTextField->invoke($service, new Number()))->toBeFalse();
    });

    it("reads a Commerce product's variants along with the product", function() {
        // Commerce attaches variants through its own layout element, not a
        // custom field, so walking the product's fields never reached them.
        if (!class_exists(craft\commerce\elements\Product::class)) {
            $this->markTestSkipped('Commerce is not installed.');
        }

        $layoutWith = static fn(string $handle): FieldLayout => new class($handle) extends FieldLayout {
            public function __construct(private string $textHandle)
            {
                parent::__construct();
            }

            public function getCustomFields(): array
            {
                return [new PlainText(['handle' => $this->textHandle])];
            }
        };

        $variant = new class($layoutWith('blurb')) extends craft\commerce\elements\Variant {
            public function __construct(private FieldLayout $stubLayout)
            {
                parent::__construct();
            }

            public function getFieldLayout(): ?FieldLayout
            {
                return $this->stubLayout;
            }

            public function getFieldValue(string $fieldHandle): mixed
            {
                return 'A hardback edition, printed on recycled paper.';
            }
        };

        $product = new class($layoutWith('intro'), $variant) extends craft\commerce\elements\Product {
            public function __construct(private FieldLayout $stubLayout, private craft\commerce\elements\Variant $stubVariant)
            {
                parent::__construct();
            }

            public function getFieldLayout(): ?FieldLayout
            {
                return $this->stubLayout;
            }

            public function getFieldValue(string $fieldHandle): mixed
            {
                return 'The manifesto, in its own words.';
            }

            public function getVariants(?bool $includeDisabled = null): craft\commerce\elements\VariantCollection
            {
                return craft\commerce\elements\VariantCollection::make([$this->stubVariant]);
            }
        };

        $extract = new ReflectionMethod(ReadabilityService::class, '_extractElementText');

        expect($extract->invoke(AccessibilityAudit::getInstance()->getReadability(), $product))
            ->toBe("The manifesto, in its own words.\n\nA hardback edition, printed on recycled paper.");
    });

    it('reads a CKEditor field chunk by chunk, nested entries through their own fields', function() {
        // Printed as a string, the field renders its nested entries through
        // front-end templates, which is neither their text nor safe to run here.
        $layoutWith = static fn(string $handle): FieldLayout => new class($handle) extends FieldLayout {
            public function __construct(private string $textHandle)
            {
                parent::__construct();
            }

            public function getCustomFields(): array
            {
                return [new PlainText(['handle' => $this->textHandle])];
            }
        };

        $nested = new class($layoutWith('quote')) extends Entry {
            public function __construct(private FieldLayout $stubLayout)
            {
                parent::__construct();
            }

            public function getFieldLayout(): ?FieldLayout
            {
                return $this->stubLayout;
            }

            public function getFieldValue(string $fieldHandle): mixed
            {
                return 'A quote held in a nested entry.';
            }
        };

        $chunks = [
            new class() {
                public string $rawHtml = '<p>Markup before the entry.</p>';
            },
            new class($nested) {
                public function __construct(private Entry $entry)
                {
                }

                public function getEntry(): Entry
                {
                    return $this->entry;
                }
            },
        ];

        $host = new class($layoutWith('body'), $chunks) extends Entry {
            public function __construct(private FieldLayout $stubLayout, private array $stubChunks)
            {
                parent::__construct();
            }

            public function getFieldLayout(): ?FieldLayout
            {
                return $this->stubLayout;
            }

            public function getFieldValue(string $fieldHandle): mixed
            {
                return new class($this->stubChunks) {
                    public function __construct(private array $chunks)
                    {
                    }

                    public function getChunks(): array
                    {
                        return $this->chunks;
                    }

                    public function __toString(): string
                    {
                        throw new LogicException('The field must not be rendered.');
                    }
                };
            }
        };

        $extract = new ReflectionMethod(ReadabilityService::class, '_extractElementText');

        expect($extract->invoke(AccessibilityAudit::getInstance()->getReadability(), $host))
            ->toBe("Markup before the entry.\n\nA quote held in a nested entry.");
    });

    it('gathers text from nested content only, once per element', function() {
        // A relation field can point back at the element it sits on, so only
        // owned content is walked, each element once, to a fixed depth.
        $body = rlSourceBody('services/ReadabilityService.php', 'private function _extractElementText(');

        expect($body)->toContain('instanceof ElementContainerFieldInterface')
            ->toContain('isset($seen[$key])')
            ->toContain('self::MAX_NESTING');
    });

    it('keeps suggestions per user, out of the readability table', function() {
        $entry = scannableEntry();
        $readability = AccessibilityAudit::getInstance()->getReadability();

        $readability->cacheSuggestions((int)$entry->id, (int)$entry->siteId, ['summary' => 'Shorter sentences help.']);

        $cached = $readability->getCachedSuggestions((int)$entry->id, (int)$entry->siteId);

        expect($cached['claude']['summary'])->toBe('Shorter sentences help.')
            ->and(rlStoredRows((int)$entry->id))->toBe(0);

        $this->actingAs(UserFactory::factory()->admin(true)->create());

        expect($readability->getCachedSuggestions((int)$entry->id, (int)$entry->siteId))->toBeNull();
    });
});

describe('Analysing an entry', function() {
    it('analyses a draft without storing a result or fetching the page', function() {
        $entry = scannableEntry();
        $draft = rlDraftOf($entry);

        $json = $this->postJson('actions/accessibility-audit/readability/analyse-entry', [
            'elementId' => $draft->id,
            'siteId' => $draft->siteId,
        ])->getJsonContent();

        // Too little text: the draft path reports what is needed, where the
        // published path would have gone to fetch the live URL instead.
        expect($json['success'])->toBeFalse()
            ->and($json['charactersNeeded'])->toBe(100)
            ->and(rlStoredRows((int)$draft->id))->toBe(0)
            ->and(rlStoredRows((int)$entry->id))->toBe(0);
    });

    it('only analyses an element the user may view', function() {
        // Drafts are reachable by id. Denied through Craft's own view check,
        // since a single plugin permission cannot be granted in this harness.
        $entry = scannableEntry();
        $deny = static function(AuthorizationCheckEvent $event): void {
            $event->authorized = false;
        };
        Craft::$app->getElements()->on(Elements::EVENT_AUTHORIZE_VIEW, $deny);

        try {
            $json = $this->postJson('actions/accessibility-audit/readability/analyse-entry', [
                'elementId' => $entry->id,
                'siteId' => $entry->siteId,
            ])->getJsonContent();
        } finally {
            Craft::$app->getElements()->off(Elements::EVENT_AUTHORIZE_VIEW, $deny);
        }

        expect($json['success'])->toBeFalse()
            ->and($json)->not->toHaveKey('charactersNeeded');
    });
});

describe('The sidebar panel', function() {
    it('reports on the entry behind a provisional draft', function() {
        $body = pluginMethodSource('_registerElementSidebarPanel');

        expect($body)->toContain('isProvisionalDraft')
            ->toContain('getCanonical()');
    });
});
