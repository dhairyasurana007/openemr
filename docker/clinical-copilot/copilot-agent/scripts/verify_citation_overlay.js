const fs = require('fs');
const path = require('path');
const vm = require('vm');
const assert = require('assert');

const repoRoot = path.resolve(__dirname, '..', '..', '..', '..');
const overlayPath = path.join(repoRoot, 'interface', 'modules', 'zend_modules', 'public', 'ClinicalCopilot', 'assets', 'citation_overlay.js');
const panelPath = path.join(repoRoot, 'interface', 'modules', 'zend_modules', 'public', 'ClinicalCopilot', 'panel.php');

function waitForMicrotasks() {
  return new Promise((resolve) => setTimeout(resolve, 0));
}

function createHarness() {
  const drawCalls = [];
  const panel = {
    clientWidth: 700,
    classList: {
      _set: new Set(['d-none']),
      add(cls) { this._set.add(cls); },
      remove(cls) { this._set.delete(cls); },
      has(cls) { return this._set.has(cls); },
    },
    scrollIntoViewCalled: false,
    scrollIntoView() {
      this.scrollIntoViewCalled = true;
    },
  };

  const ctx = {
    clearRect() {},
    save() {},
    restore() {},
    strokeRect(x, y, w, h) {
      drawCalls.push({ type: 'stroke', x, y, w, h });
    },
    fillRect(x, y, w, h) {
      drawCalls.push({ type: 'fill', x, y, w, h });
    },
    set strokeStyle(_v) {},
    set fillStyle(_v) {},
    set lineWidth(_v) {},
  };

  const canvas = {
    width: 0,
    height: 0,
    getContext(kind) {
      assert.strictEqual(kind, '2d');
      return ctx;
    },
  };

  const pdfPage = {
    getViewport({ scale }) {
      const width = 600 * scale;
      const height = 800 * scale;
      return {
        width,
        height,
        viewBox: [0, 0, 600, 800],
        convertToViewportRectangle([x0, y0, x1, y1]) {
          return [x0 * scale, y0 * scale, x1 * scale, y1 * scale];
        },
      };
    },
    render() {
      return { promise: Promise.resolve() };
    },
    getTextContent() {
      return Promise.resolve({ items: [] });
    },
  };

  const pdfDoc = {
    numPages: 3,
    getPage(pageNumber) {
      assert.ok(pageNumber >= 1 && pageNumber <= 3);
      return Promise.resolve(pdfPage);
    },
  };

  const pdfjsLib = {
    GlobalWorkerOptions: {},
    getDocument(blobUrl) {
      assert.strictEqual(blobUrl, 'blob:test-pdf');
      return { promise: Promise.resolve(pdfDoc) };
    },
  };

  const document = {
    getElementById(id) {
      if (id === 'ccp-pdf-overlay-panel') return panel;
      if (id === 'ccp-pdf-canvas') return canvas;
      return null;
    },
  };

  const windowObj = {
    document,
    pdfjsLib,
    console,
    setTimeout,
    clearTimeout,
  };

  return { windowObj, panel, drawCalls, pdfjsLib };
}

async function verifyOverlayBehavior() {
  const source = fs.readFileSync(overlayPath, 'utf8');
  const harness = createHarness();

  const context = vm.createContext({
    window: harness.windowObj,
    document: harness.windowObj.document,
    console,
    setTimeout,
    clearTimeout,
  });

  vm.runInContext(source, context, { filename: overlayPath });

  const api = harness.windowObj.ClinicalCopilotCitationOverlay;
  assert.ok(api, 'Overlay API should be exported on window');
  assert.strictEqual(typeof api.renderBboxOverlay, 'function', 'renderBboxOverlay should be a function');

  api.renderBboxOverlay(
    'blob:test-pdf',
    2,
    [100, 120, 240, 160],
    { maxX: 240, maxY: 160 },
    null,
    'glucose 101',
    'glucose'
  );

  await waitForMicrotasks();
  await waitForMicrotasks();

  assert.strictEqual(
    harness.panel.classList.has('d-none'),
    false,
    'Overlay panel should be made visible when rendering starts'
  );
  assert.ok(
    harness.drawCalls.some((c) => c.type === 'stroke' && c.w > 0 && c.h > 0),
    'Overlay should draw a highlighted bounding box stroke'
  );
  assert.ok(
    harness.drawCalls.some((c) => c.type === 'fill' && c.w > 0 && c.h > 0),
    'Overlay should draw a filled highlight rectangle'
  );
  assert.ok(
    harness.panel.scrollIntoViewCalled,
    'Overlay render should scroll preview panel into view'
  );
  assert.ok(
    typeof harness.pdfjsLib.GlobalWorkerOptions.workerSrc === 'string' && harness.pdfjsLib.GlobalWorkerOptions.workerSrc.length > 0,
    'PDF.js worker source should be configured'
  );
}

function verifyPanelWiring() {
  const panelSource = fs.readFileSync(panelPath, 'utf8');

  assert.ok(
    panelSource.includes('window.ClinicalCopilotCitationOverlay.renderBboxOverlay('),
    'panel.php should wire citation badge click events to renderBboxOverlay()'
  );

  assert.ok(
    panelSource.includes('click to view in PDF'),
    'panel.php should provide clickable citation affordance text'
  );

  assert.ok(
    panelSource.includes('id="ccp-pdf-overlay-panel"'),
    'panel.php should include PDF overlay panel container'
  );
}

(async function main() {
  try {
    verifyPanelWiring();
    await verifyOverlayBehavior();
    process.stdout.write('PASS: citation overlay wiring and bbox highlight verification succeeded.\n');
  } catch (error) {
    process.stderr.write(`FAIL: ${error && error.message ? error.message : error}\n`);
    process.exitCode = 1;
  }
})();
