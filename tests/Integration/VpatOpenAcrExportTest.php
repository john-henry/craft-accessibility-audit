<?php

use johnhenry\accessibilityaudit\AccessibilityAudit;
use johnhenry\accessibilityaudit\helpers\OpenAcr;
use JsonSchema\Validator;
use markhuot\craftpest\factories\User as UserFactory;
use Symfony\Component\Yaml\Yaml;

// ---------------------------------------------------------------------------
// The OpenACR export is read by a buyer's tooling, not a person, so a file
// that does not validate is a failure even when it reads fine. These tests
// hold it to the GSA schema (tests/fixtures/openacr-0.1.0.json, from
// https://github.com/GSA/openacr/blob/main/schema/openacr-0.1.0.json) and to
// the catalog's criteria lists: OpenACR's catalog check fails a report that
// names a criterion the catalog does not have.
//
// Helpers are uniquely named (oa*): Pest loads every test file into one process.
// ---------------------------------------------------------------------------

/**
 * The criteria in the VPAT 2.5 WCAG 2.2 catalog (2.5-edition-wcag-2.2-en.yaml),
 * by chapter.
 */
const OA_CATALOG_CRITERIA = [
    'success_criteria_level_a' => [
        '1.1.1', '1.2.1', '1.2.2', '1.2.3', '1.3.1', '1.3.2', '1.3.3', '1.4.1', '1.4.2',
        '2.1.1', '2.1.2', '2.1.4', '2.2.1', '2.2.2', '2.3.1', '2.4.1', '2.4.2', '2.4.3',
        '2.4.4', '2.5.1', '2.5.2', '2.5.3', '2.5.4', '3.1.1', '3.2.1', '3.2.2', '3.2.6',
        '3.3.1', '3.3.2', '3.3.7', '4.1.1', '4.1.2',
    ],
    'success_criteria_level_aa' => [
        '1.2.4', '1.2.5', '1.3.4', '1.3.5', '1.4.3', '1.4.4', '1.4.5', '1.4.10', '1.4.11',
        '1.4.12', '1.4.13', '2.4.5', '2.4.6', '2.4.7', '2.4.11', '2.5.7', '2.5.8', '3.1.2',
        '3.2.3', '3.2.4', '3.3.3', '3.3.4', '3.3.8', '4.1.3',
    ],
];

/** Schema errors for a document, after a round trip through YAML. */
function oaSchemaErrors(array $document): array
{
    $data = json_decode((string)json_encode(Yaml::parse(OpenAcr::toYaml($document))));
    $validator = new Validator();
    $validator->validate($data, (object)['$ref' => 'file://' . realpath(dirname(__DIR__) . '/fixtures/openacr-0.1.0.json')]);

    return $validator->getErrors();
}

/** A report shaped like VpatService::getFullReport(), with only what is given. */
function oaReport(array $overrides = []): array
{
    return array_replace_recursive([
        'meta' => ['productName' => 'Acme Website', 'contactEmail' => 'access@example.com'],
        'levelA' => [],
        'levelAA' => [],
        'en301549' => false,
        'en301549Version' => 'V3.2.1',
    ], $overrides);
}

/** A report row with a level and remark. */
function oaRow(string $level, string $remarks = '', ?string $enClause = 'x'): array
{
    return ['effectiveLevel' => $level, 'effectiveRemarks' => $remarks, 'enClause' => $enClause];
}

beforeEach(function() {
    AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_PRO;
    $this->oaEn = AccessibilityAudit::getInstance()->getSettings()->en301549;
});

afterEach(function() {
    AccessibilityAudit::getInstance()->getSettings()->en301549 = $this->oaEn;
});

describe('The document', function() {
    it('validates against the OpenACR schema for a real report', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        saveVpatMetaFlat($siteId, ['productName' => 'Acme Website', 'contactEmail' => 'access@example.com']);

        $report = AccessibilityAudit::getInstance()->vpat->getFullReport($siteId);

        expect(oaSchemaErrors(OpenAcr::document($report, 'Acme')))->toBe([]);
    });

    it('names only criteria the catalog has, in the chapter the catalog puts them', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $report = AccessibilityAudit::getInstance()->vpat->getFullReport($siteId);
        $chapters = OpenAcr::document($report, 'Acme')['chapters'];

        foreach (OA_CATALOG_CRITERIA as $chapter => $allowed) {
            $numbers = array_column($chapters[$chapter]['criteria'], 'num');

            expect($numbers)->not->toBeEmpty()
                ->and(array_diff($numbers, $allowed))->toBe([]);
        }
    });

    it('leaves out 4.1.1 rather than claiming a level nobody chose', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $report = AccessibilityAudit::getInstance()->vpat->getFullReport($siteId);
        $numbers = array_column(OpenAcr::document($report, 'Acme')['chapters']['success_criteria_level_a']['criteria'], 'num');

        expect($numbers)->not->toContain('4.1.1');
    });

    it('writes every plugin level as its OpenACR term, and an unanswered row as not evaluated', function() {
        $document = OpenAcr::document(oaReport(['levelA' => [
            '1.1.1' => oaRow('Supports'),
            '1.2.1' => oaRow('Partially Supports'),
            '1.2.2' => oaRow('Does Not Support'),
            '1.2.3' => oaRow('Not Applicable'),
            '1.3.1' => oaRow('Not Evaluated'),
            '1.3.2' => oaRow(''),
        ]]), 'Acme');

        $levels = array_map(
            static fn(array $criterion): string => $criterion['components'][0]['adherence']['level'],
            $document['chapters']['success_criteria_level_a']['criteria'],
        );

        expect($levels)->toBe([
            'supports', 'partially-supports', 'does-not-support', 'not-applicable', 'not-evaluated', 'not-evaluated',
        ]);
    });

    it('files each result under the web component, with the remark as its notes', function() {
        $document = OpenAcr::document(oaReport(['levelA' => [
            '1.1.1' => oaRow('Partially Supports', "Eleven archive images.\nFixing in Q1."),
            '1.2.1' => oaRow('Supports'),
        ]]), 'Acme');

        [$withRemark, $withoutRemark] = $document['chapters']['success_criteria_level_a']['criteria'];

        expect($withRemark['components'])->toBe([[
            'name' => 'web',
            'adherence' => ['level' => 'partially-supports', 'notes' => "Eleven archive images.\nFixing in Q1."],
        ]])
            ->and($withoutRemark['components'][0]['adherence'])->not->toHaveKey('notes');
    });

    it('marks Level AAA as out of scope', function() {
        $chapter = OpenAcr::document(oaReport(), 'Acme')['chapters']['success_criteria_level_aaa'];

        expect($chapter['disabled'])->toBeTrue()
            ->and($chapter)->not->toHaveKey('criteria');
    });

    it('falls back to the site name when no product name is set', function() {
        $document = OpenAcr::document(oaReport(['meta' => ['productName' => '']]), 'Cork Library');

        expect($document['product']['name'])->toBe('Cork Library')
            ->and($document['title'])->toContain('Cork Library');
    });

    it('carries the evaluation period and scope pages in the notes, and leaves revisions out', function() {
        $document = OpenAcr::document(oaReport([
            'meta' => [
                'notes' => 'Reviewed quarterly.',
                'reportPeriodFrom' => '2026-08-01',
                'reportPeriodTo' => '2026-08-31',
                'scopePages' => ['Home', 'Checkout flow'],
            ],
            'revisions' => [['date' => '2026-09-01', 'changes' => []]],
        ]), 'Acme');

        expect($document['notes'])
            ->toContain('Reviewed quarterly.')
            ->toContain('2026-08-01')
            ->toContain('2026-08-31')
            ->toContain('Checkout flow')
            ->and($document)->not->toHaveKey('revisions')
            ->and(OpenAcr::toYaml($document))->not->toContain('revision');
    });

    it('claims only automated scanning when no methods were recorded', function() {
        expect(OpenAcr::document(oaReport(), 'Acme')['evaluation_methods_used'])
            ->toBe('Automated scanning with axe-core and server-side HTML analysis.');
    });

    it('leaves empty fields out instead of writing blank strings', function() {
        $document = OpenAcr::document(oaReport(), 'Acme');

        expect($document)->not->toHaveKey('legal_disclaimer')
            ->and($document)->not->toHaveKey('report_date')
            ->and($document['product'])->not->toHaveKey('version');
    });
});

describe('EN 301 549', function() {
    it('uses the WCAG catalog with no EN chapters when it is off', function() {
        $document = OpenAcr::document(oaReport(), 'Acme');

        expect($document['catalog'])->toBe(OpenAcr::CATALOG_WCAG)
            ->and(array_keys($document['chapters']))->toBe([
                'success_criteria_level_a', 'success_criteria_level_aa', 'success_criteria_level_aaa',
            ]);
    });

    it('uses the International Edition, pointing Chapter 9 at the WCAG tables and closing the rest', function() {
        $document = OpenAcr::document(oaReport([
            'en301549' => true,
            'levelA' => ['1.1.1' => oaRow('Supports')],
            'levelAA' => ['2.4.11' => oaRow('Supports', '', null)],
        ]), 'Acme');

        $chapters = $document['chapters'];
        $web = $chapters['en_301_549_web'];
        $others = array_diff_key($chapters, array_flip([
            'success_criteria_level_a', 'success_criteria_level_aa', 'en_301_549_web',
        ]));

        expect($document['catalog'])->toBe(OpenAcr::CATALOG_INTERNATIONAL)
            ->and($web)->not->toHaveKey('disabled')
            ->and($web['notes'])->toContain('V3.2.1')->toContain('2.4.11')->not->toContain('1.1.1')
            ->and(array_unique(array_column($others, 'disabled')))->toBe([true])
            ->and(oaSchemaErrors($document))->toBe([]);
    });
});

describe('The export action', function() {
    beforeEach(function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());
    });

    it('downloads the document as a YAML file named for the product', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        saveVpatMetaFlat($siteId, ['productName' => 'Acme Website', 'contactEmail' => 'access@example.com']);

        $response = $this->http('get', 'actions/accessibility-audit/vpat/export-open-acr')->send();
        $response->assertOk();

        $parsed = Yaml::parse((string)$response->content);

        expect($response->getHeaders()->get('Content-Disposition'))->toContain('acme-website-openacr.yaml')
            ->and($response->getHeaders()->get('Content-Type'))->toContain('application/yaml')
            ->and($parsed['product']['name'])->toBe('Acme Website')
            ->and($parsed['author']['email'])->toBe('access@example.com');
    });

    it('refuses, saying what to fill in, when there is no contact email', function() {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        saveVpatMetaFlat($siteId, ['productName' => 'Acme Website', 'contactEmail' => '']);

        expect(fn() => $this->http('get', 'actions/accessibility-audit/vpat/export-open-acr')->send())
            ->toThrow(\yii\web\BadRequestHttpException::class, 'contact email');
    });

    it('stays behind the Pro gate', function() {
        AccessibilityAudit::getInstance()->edition = AccessibilityAudit::EDITION_STANDARD;

        $json = $this->http('get', 'actions/accessibility-audit/vpat/export-open-acr')->send()->getJsonContent();

        expect($json['success'])->toBeFalse()
            ->and($json['proRequired'])->toBeTrue();
    });
});
