/**
 * The PDF check's browser pass, run in a blank tab by HeadlessScanner.
 *
 * pdf.js and its worker arrive as source text and are loaded as blob URL
 * modules, and the PDF arrives as base64, all in chunks through the DevTools
 * protocol. Nothing is fetched, so the tab needs no origin the browser can
 * reach, which is what lets this run on a remote Chrome.
 *
 * The pass reads and returns facts: the catalogue, each sampled page's text
 * and structure tree, and its links. Deciding what fails is left to
 * PdfChecker in PHP, so the rules live in one place.
 */
(function () {
  'use strict';

  var parts = {};
  var lib = null;

  function put(key, part) {
    (parts[key] = parts[key] || []).push(part);
    return true;
  }

  function take(key) {
    var source = (parts[key] || []).join('');
    delete parts[key];
    return source;
  }

  function moduleUrl(source) {
    return URL.createObjectURL(new Blob([source], { type: 'text/javascript' }));
  }

  async function load() {
    var libUrl = moduleUrl(take('lib'));
    var workerUrl = moduleUrl(take('worker'));

    lib = await import(libUrl);
    lib.GlobalWorkerOptions.workerSrc = workerUrl;

    return true;
  }

  function bytes(base64) {
    var binary = atob(base64);
    var out = new Uint8Array(binary.length);

    for (var i = 0; i < binary.length; i++) {
      out[i] = binary.charCodeAt(i);
    }

    return out;
  }

  function plain(value) {
    return value instanceof Map ? Object.fromEntries(value) : value || {};
  }

  function failure(error) {
    var name = error && error.name ? error.name : '';

    return {
      ok: false,
      error: name === 'PasswordException' ? 'password' : 'damaged',
      message: String(error && error.message ? error.message : error).slice(0, 300),
    };
  }

  // One page's structure tree, walked for what the rules need. `parent` is
  // the role of the element above, so an LI can be told apart from an LI in
  // a list.
  function walk(node, parent, page, acc, inLink) {
    if (!node || acc.nodes++ > acc.maxNodes) return;

    var role = node.role || '';
    var kids = node.children || [];
    var link = inLink || role === 'Link';

    if (node.type === 'annotation' && inLink) {
      acc.taggedLinks[String(node.id || '').replace(/^pdfjs_internal_id_/, '')] = true;
    }

    if (role === 'Figure' && acc.figures.length < acc.maxFigures) {
      acc.figures.push({ alt: typeof node.alt === 'string' ? node.alt : null, page: page });
    }

    var heading = /^H([1-6])$/.exec(role);

    if (heading && acc.headings.length < acc.maxHeadings) {
      acc.headings.push({ level: Number(heading[1]), page: page });
    }

    if (role === 'Table') {
      acc.tables++;
      if (!hasRole(node, 'TH')) {
        acc.tablesWithoutHeaders++;
        acc.tablesWithoutHeadersPages[page] = true;
      }
    }

    if (role === 'TH') {
      acc.headerCells++;
      if (!node.scope && !node.headers) {
        acc.headersWithoutScope++;
        acc.headersWithoutScopePages[page] = true;
      }
    }

    if (role === 'LI') {
      acc.listItems++;
      var hasBody = kids.some(function (kid) { return kid.role === 'LBody'; });
      if (parent !== 'L' || !hasBody) {
        acc.brokenListItems++;
        acc.brokenListItemsPages[page] = true;
      }
    }

    for (var i = 0; i < kids.length; i++) {
      walk(kids[i], role, page, acc, link);
    }
  }

  function hasRole(node, role) {
    var kids = node.children || [];

    for (var i = 0; i < kids.length; i++) {
      if (kids[i].role === role || hasRole(kids[i], role)) return true;
    }

    return false;
  }

  function pageList(map) {
    return Object.keys(map).map(Number).sort(function (a, b) { return a - b; });
  }

  async function run(config) {
    var started = Date.now();

    if (!lib) {
      return { ok: false, error: 'damaged', message: 'pdf.js did not load' };
    }

    var task = lib.getDocument({
      data: bytes(take('pdf')),
      isEvalSupported: false,
      enableXfa: false,
      disableFontFace: true,
      stopAtErrors: false,
      verbosity: 0,
    });

    var doc;

    try {
      doc = await task.promise;
    } catch (e) {
      await task.destroy();
      return failure(e);
    }

    try {
      var meta = await doc.getMetadata();
      var info = meta.info || {};
      var xmp = meta.metadata;
      var permissions = await doc.getPermissions();
      var outline = await doc.getOutline();
      var markInfo = plain(await doc.getMarkInfo());
      var viewer = plain(await doc.getViewerPreferences());
      var sample = Math.min(doc.numPages, config.maxPages);

      var acc = {
        nodes: 0,
        maxNodes: config.maxNodes,
        maxFigures: config.maxFigures,
        maxHeadings: config.maxHeadings,
        figures: [],
        headings: [],
        tables: 0,
        tablesWithoutHeaders: 0,
        tablesWithoutHeadersPages: {},
        headerCells: 0,
        headersWithoutScope: 0,
        headersWithoutScopePages: {},
        listItems: 0,
        brokenListItems: 0,
        brokenListItemsPages: {},
        taggedLinks: {},
      };

      var out = {
        ok: true,
        pages: doc.numPages,
        sampled: 0,
        complete: false,
        title: info.Title || null,
        lang: info.Language || null,
        producer: info.Producer || null,
        creator: info.Creator || null,
        xmpTitle: xmp ? xmp.get('dc:title') : null,
        pdfua: xmp ? !!xmp.get('pdfuaid:part') : null,
        encrypted: !!info.EncryptFilterName,
        hasForm: !!info.IsAcroFormPresent,
        xfa: !!info.IsXFAPresent,
        marked: markInfo.Marked === true,
        suspects: markInfo.Suspects === true,
        displayDocTitle: viewer.DisplayDocTitle === true,
        permissions: permissions ? Array.from(permissions) : null,
        outline: outline ? outline.length : 0,
        structPages: 0,
        emptyTextPages: [],
        links: 0,
        untaggedLinks: 0,
        untaggedLinksPages: [],
      };

      for (var n = 1; n <= sample; n++) {
        if (Date.now() - started > config.budgetMs) {
          out.stopped = 'budget';
          break;
        }

        var page = await doc.getPage(n);
        var text = await page.getTextContent();
        var tree = await page.getStructTree();
        var annotations = await page.getAnnotations({ intent: 'display' });

        if (!text.items.some(function (item) { return (item.str || '').trim() !== ''; })) {
          out.emptyTextPages.push(n);
        }

        acc.taggedLinks = {};

        if (tree) {
          out.structPages++;
          walk(tree, '', n, acc, false);
        }

        var linkIds = annotations.filter(function (a) { return a.subtype === 'Link'; }).map(function (a) { return String(a.id); });
        out.links += linkIds.length;

        if (tree) {
          var untagged = linkIds.filter(function (id) { return !acc.taggedLinks[id]; }).length;
          if (untagged > 0) {
            out.untaggedLinks += untagged;
            out.untaggedLinksPages.push(n);
          }
        }

        page.cleanup();
        out.sampled = n;
      }

      out.complete = out.sampled === doc.numPages;
      out.figures = acc.figures;
      out.headings = acc.headings;
      out.tables = acc.tables;
      out.tablesWithoutHeaders = acc.tablesWithoutHeaders;
      out.tablesWithoutHeadersPages = pageList(acc.tablesWithoutHeadersPages);
      out.headerCells = acc.headerCells;
      out.headersWithoutScope = acc.headersWithoutScope;
      out.headersWithoutScopePages = pageList(acc.headersWithoutScopePages);
      out.listItems = acc.listItems;
      out.brokenListItems = acc.brokenListItems;
      out.brokenListItemsPages = pageList(acc.brokenListItemsPages);
      out.ms = Date.now() - started;

      return out;
    } catch (e) {
      return failure(e);
    } finally {
      await task.destroy();
    }
  }

  window.__aaPdfCheck = { put: put, load: load, run: run };
})();
