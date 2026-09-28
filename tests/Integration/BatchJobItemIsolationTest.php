<?php

use craft\base\Element;
use craft\elements\Entry;
use craft\events\DefineUrlEvent;
use johnhenry\accessibilityaudit\jobs\AnalyseReadability;
use yii\base\Event;

// ---------------------------------------------------------------------------
// One awkward page must not end a site-wide run.
//
// The batch jobs wrap each item so a page that will not read is logged and
// passed over. The part that keeps getting left outside that wrapper is
// resolving the element: getUrl() fires beforeDefineUrl and afterDefineUrl, so
// any third-party handler throwing on one page takes the whole run with it,
// and the guard conditions run before the work does.
//
// This has been wrong twice in this plugin, in two different jobs, so it is
// pinned rather than left to review.
// ---------------------------------------------------------------------------

describe('a batch job item that throws while being resolved', function() {
    it('is logged and passed over rather than ending the run', function() {
        $entry = Entry::find()->one();

        expect($entry)->not->toBeNull();

        // Stand in for a third-party plugin with a handler on this event.
        $thrower = static function(DefineUrlEvent $event): void {
            throw new RuntimeException('a plugin threw while defining the URL');
        };

        Event::on(Element::class, Element::EVENT_BEFORE_DEFINE_URL, $thrower);

        try {
            $job = new AnalyseReadability(['siteId' => (int) $entry->siteId]);

            $method = new ReflectionMethod(AnalyseReadability::class, 'processItem');
            $method->setAccessible(true);

            // Reaching the assertion at all is the point: unhandled, this
            // throws out of processItem and fails the whole batch.
            $method->invoke($job, [
                'elementId' => (int) $entry->id,
                'elementType' => Entry::class,
            ]);

            expect(true)->toBeTrue();
        } finally {
            Event::off(Element::class, Element::EVENT_BEFORE_DEFINE_URL, $thrower);
        }
    });
});
