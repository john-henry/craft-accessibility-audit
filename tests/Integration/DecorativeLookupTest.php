<?php

use craft\elements\Asset;
use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\variables\AccessibilityVariable;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Decorative lookups from a template
//
// Front-end templates ask per image, inside a macro, so a query per call is a
// query per image on the page. The flag set is loaded once per request and
// answered from memory after that.
// ---------------------------------------------------------------------------

beforeEach(function() {
    $this->actingAs(UserFactory::factory()->admin(true)->create());

    // The fixture library has its own flags, so each test starts from a clean
    // table. Cleared inside the transaction, so it rolls back with the rest.
    Craft::$app->getDb()->createCommand()->delete('{{%accessibilityaudit_asset_flags}}')->execute();

    // The service is a singleton, so its memo outlives the transaction each
    // test rolls back.
    AccessibilityAudit::getInstance()->getAssets()->forgetDecorative();
});

/** Real asset ids, since the flag table has a foreign key onto elements. */
function decorativeTestAssetIds(int $count = 3): array
{
    $ids = array_map(
        static fn(Asset $asset): int => (int)$asset->id,
        Asset::find()->kind(Asset::KIND_IMAGE)->limit($count)->all(),
    );

    if (count($ids) < $count) {
        test()->markTestSkipped('needs ' . $count . ' image assets in the test library');
    }

    return $ids;
}

/** Runs a callback with query logging on, returning how many ran. */
function countQueries(callable $fn): int
{
    $db = Craft::$app->getDb();
    $before = $db->createCommand('SHOW SESSION STATUS LIKE "Questions"')->queryOne();
    $fn();
    $after = $db->createCommand('SHOW SESSION STATUS LIKE "Questions"')->queryOne();

    // The two status reads themselves count, hence the offset.
    return max(0, (int)$after['Value'] - (int)$before['Value'] - 2);
}

it('answers repeated lookups without a query each', function() {
    [$marked, $plain] = decorativeTestAssetIds(2);
    $assets = AccessibilityAudit::getInstance()->getAssets();
    $assets->setDecorative($marked, true);

    // Warm the set, then ask a hundred times.
    $assets->isDecorative($marked);

    $queries = countQueries(function() use ($assets, $marked, $plain) {
        for ($i = 0; $i < 100; $i++) {
            $assets->isDecorative($marked);
            $assets->isDecorative($plain);
        }
    });

    expect($queries)->toBe(0);
});

it('reports a marked asset as decorative and an unmarked one as not', function() {
    [$marked, $plain] = decorativeTestAssetIds(2);
    $assets = AccessibilityAudit::getInstance()->getAssets();
    $assets->setDecorative($marked, true);

    expect($assets->isDecorative($marked))->toBeTrue()
        ->and($assets->isDecorative($plain))->toBeFalse();
});

it('picks up a change made in the same request', function() {
    // The memo would otherwise answer from a set taken before the write.
    [$id] = decorativeTestAssetIds(1);
    $assets = AccessibilityAudit::getInstance()->getAssets();

    expect($assets->isDecorative($id))->toBeFalse();

    $assets->setDecorative($id, true);

    expect($assets->isDecorative($id))->toBeTrue();

    $assets->setDecorative($id, false);

    expect($assets->isDecorative($id))->toBeFalse();
});

it('takes an asset or an id from Twig, and nothing at all', function() {
    [$marked, $plain] = decorativeTestAssetIds(2);
    $variable = new AccessibilityVariable();
    AccessibilityAudit::getInstance()->getAssets()->setDecorative($marked, true);

    expect($variable->isDecorative($marked))->toBeTrue()
        ->and($variable->isDecorative($plain))->toBeFalse()
        ->and($variable->isDecorative(null))->toBeFalse()
        ->and($variable->isDecorative(0))->toBeFalse();
});

it('hands the whole set over for a template that wants it', function() {
    [$id] = decorativeTestAssetIds(1);
    $assets = AccessibilityAudit::getInstance()->getAssets();
    $assets->setDecorative($id, true);

    expect((new AccessibilityVariable())->decorativeAssetIds())->toHaveKey($id);
});
