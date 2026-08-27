<?php

use johnhenry\accessibilityaudit\helpers\VisionImage;

// ---------------------------------------------------------------------------
// Deciding which SVGs this server can honestly describe.
//
// ImageMagick delegates SVG to librsvg where it is installed and falls back to
// its own renderer where it is not. The fallback draws fills and silently
// drops strokes, so the question is not "does this server do SVG" but "does
// this drawing survive this server". Asked the first way, one stroke-only
// probe turned off SVG alt text for every image on the box, including the
// fill-only icons that render perfectly.
// ---------------------------------------------------------------------------

describe('VisionImage::usesStrokes', function() {
    it('sees a stroke on a path', function() {
        expect(VisionImage::usesStrokes(
            '<svg xmlns="http://www.w3.org/2000/svg"><path d="M6 20 L34 20" stroke="#000" stroke-width="4"/></svg>'
        ))->toBeTrue();
    });

    it('sees a stroke set in a style attribute', function() {
        // Presentation attribute or CSS, it draws the same. Read only the
        // attribute form and half the icon sets in the world slip through.
        expect(VisionImage::usesStrokes(
            '<svg xmlns="http://www.w3.org/2000/svg"><path d="M6 20 L34 20" style="stroke:#000;stroke-width:4"/></svg>'
        ))->toBeTrue();
    });

    it('does not count a fill-only drawing', function() {
        expect(VisionImage::usesStrokes(
            '<svg xmlns="http://www.w3.org/2000/svg"><rect width="30" height="30" fill="#333"/></svg>'
        ))->toBeFalse();
    });

    it('does not count stroke="none", which draws nothing', function() {
        expect(VisionImage::usesStrokes(
            '<svg xmlns="http://www.w3.org/2000/svg"><rect width="30" height="30" fill="#333" stroke="none"/></svg>'
        ))->toBeFalse();
    });

    it('counts a drawing that is part fills and part strokes', function() {
        // The case the render cannot reveal: the fills come through, so there
        // is ink on the canvas and nothing about the output says a piece of it
        // went missing.
        expect(VisionImage::usesStrokes(
            '<svg xmlns="http://www.w3.org/2000/svg"><circle cx="20" cy="20" r="15" fill="#eee"/>'
            . '<path d="M6 20 L34 20" stroke="#000" stroke-width="4"/></svg>'
        ))->toBeTrue();
    });
});

describe('VisionImage renderer capability', function() {
    it('reports stroke support separately from SVG support', function() {
        // Two different questions. A server can read SVG perfectly well and
        // still lose every stroke in the drawing, and answering only the first
        // is what made the plugin refuse images it could have described.
        expect(VisionImage::canReadVectors())->toBeBool()
            ->and(VisionImage::rendersStrokes())->toBeBool();

        if (VisionImage::rendersStrokes()) {
            expect(VisionImage::canReadVectors())->toBeTrue();
        }
    });

    it('renders a fill-only drawing wherever SVG can be read at all', function() {
        if (!VisionImage::canReadVectors()) {
            $this->markTestSkipped('Imagick has no SVG support here.');
        }

        // The claim the whole change rests on: the fallback renderer handles
        // fills, so a fill-only icon has ink whether or not librsvg is present.
        $imagick = new Imagick();
        $imagick->setBackgroundColor(new ImagickPixel('white'));
        $imagick->readImageBlob(
            '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40">'
            . '<rect x="5" y="5" width="30" height="30" fill="#000000"/></svg>'
        );

        expect($imagick->getImageColors())->toBeGreaterThan(1);
        $imagick->clear();
    });
});
