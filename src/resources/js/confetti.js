/**
 * Accessibility Audit: the all-clear flourish.
 *
 * Self-contained on purpose. A control panel should not reach out to a CDN for
 * a decoration: it breaks on an offline install, it is one more origin for a
 * site's CSP to allow, and a plugin that argues for care about what a page
 * loads should not load a third-party script to throw confetti.
 *
 * The canvas is aria-hidden, takes no pointer events and removes itself when
 * the last piece leaves the screen, so nothing it does outlives the moment.
 */
(function () {
  'use strict';

  const SELECTOR = '[data-accessibility-audit-confetti]';
  const COUNT = 90;
  const GRAVITY = 0.32;
  const DRAG = 0.99;
  const SPREAD = 1.1;
  const COLOURS = ['#1e7a3c', '#3672d6', '#e8930c', '#b3261e', '#4a3fa3'];

  function reducedMotion() {
    return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  function makeCanvas() {
    const canvas = document.createElement('canvas');
    const ratio = window.devicePixelRatio || 1;

    canvas.setAttribute('aria-hidden', 'true');
    canvas.width = window.innerWidth * ratio;
    canvas.height = window.innerHeight * ratio;
    canvas.style.cssText =
      'position:fixed;inset:0;width:100%;height:100%;pointer-events:none;z-index:9999';

    document.body.appendChild(canvas);

    const ctx = canvas.getContext('2d');
    ctx.scale(ratio, ratio);

    return { canvas: canvas, ctx: ctx };
  }

  function makePieces(originX, originY) {
    const pieces = [];

    for (let i = 0; i < COUNT; i++) {
      const angle = -Math.PI / 2 + (Math.random() - 0.5) * SPREAD;
      const speed = 7 + Math.random() * 9;

      pieces.push({
        x: originX,
        y: originY,
        vx: Math.cos(angle) * speed,
        vy: Math.sin(angle) * speed,
        w: 5 + Math.random() * 5,
        h: 3 + Math.random() * 4,
        rot: Math.random() * Math.PI,
        vr: (Math.random() - 0.5) * 0.3,
        colour: COLOURS[Math.floor(Math.random() * COLOURS.length)],
      });
    }

    return pieces;
  }

  function burst(originX, originY) {
    const stage = makeCanvas();
    let pieces = makePieces(originX, originY);

    (function frame() {
      stage.ctx.clearRect(0, 0, window.innerWidth, window.innerHeight);

      pieces = pieces.filter(function (p) {
        p.vx *= DRAG;
        p.vy = p.vy * DRAG + GRAVITY;
        p.x += p.vx;
        p.y += p.vy;
        p.rot += p.vr;

        if (p.y - p.h > window.innerHeight) {
          return false;
        }

        stage.ctx.save();
        stage.ctx.translate(p.x, p.y);
        stage.ctx.rotate(p.rot);
        stage.ctx.fillStyle = p.colour;
        stage.ctx.fillRect(-p.w / 2, -p.h / 2, p.w, p.h);
        stage.ctx.restore();

        return true;
      });

      if (pieces.length > 0) {
        window.requestAnimationFrame(frame);

        return;
      }

      stage.canvas.remove();
    })();
  }

  function init() {
    const button = document.querySelector(SELECTOR);

    if (!button) {
      return;
    }

    // The whole thing is motion, so it is not offered to anyone who asked for
    // less of it rather than offered and then quietly doing nothing.
    if (reducedMotion()) {
      button.remove();

      return;
    }

    button.hidden = false;

    button.addEventListener('click', function () {
      const box = button.getBoundingClientRect();

      burst(box.left + box.width / 2, box.top + box.height / 2);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
