/* Clinical Co-Pilot — citation bbox overlay renderer.
 * Requires PDF.js (pdfjsLib global) loaded before this script.
 * PDF.js version: 3.11.174 (cdnjs) — vendor locally before production deploy.
 */
(function (global) {
    'use strict';

    var _pdfCache = {};
    var _workerConfigured = false;

    function _ensureWorker() {
        if (_workerConfigured || !global.pdfjsLib) {
            return;
        }
        global.pdfjsLib.GlobalWorkerOptions.workerSrc =
            'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        _workerConfigured = true;
    }

    function _getPdfDoc(blobUrl) {
        if (!_pdfCache[blobUrl]) {
            _ensureWorker();
            if (!global.pdfjsLib) {
                return Promise.reject(new Error('PDF.js not loaded'));
            }
            _pdfCache[blobUrl] = global.pdfjsLib.getDocument(blobUrl).promise;
        }
        return _pdfCache[blobUrl];
    }

    /**
     * Render a PDF page onto #ccp-pdf-canvas and draw a highlighted bbox rectangle.
     *
     * @param {string} blobUrl  - object URL for the uploaded PDF file
     * @param {number} pageNumber - 1-indexed page to render
     * @param {number[]} bbox - [x0, y0, x1, y1] in PDF points, origin top-left
     */
    function renderBboxOverlay(blobUrl, pageNumber, bbox) {
        var panel = document.getElementById('ccp-pdf-overlay-panel');
        var canvas = document.getElementById('ccp-pdf-canvas');
        if (!panel || !canvas || !blobUrl) {
            return;
        }

        panel.classList.remove('d-none');
        var ctx = canvas.getContext('2d');

        _getPdfDoc(blobUrl).then(function (pdf) {
            var pageNum = (typeof pageNumber === 'number' && pageNumber >= 1)
                ? Math.min(pageNumber, pdf.numPages)
                : 1;
            return pdf.getPage(pageNum);
        }).then(function (page) {
            var naturalViewport = page.getViewport({ scale: 1 });
            var availableWidth = (panel.clientWidth || 600) - 16;
            var scale = Math.max(0.5, Math.min(2.0, availableWidth / naturalViewport.width));
            var viewport = page.getViewport({ scale: scale });

            canvas.width = viewport.width;
            canvas.height = viewport.height;
            ctx.clearRect(0, 0, canvas.width, canvas.height);

            return page.render({ canvasContext: ctx, viewport: viewport }).promise
                .then(function () { return { viewport: viewport, naturalViewport: naturalViewport }; });
        }).then(function (payload) {
            var viewport = payload.viewport;
            var naturalViewport = payload.naturalViewport;
            if (!Array.isArray(bbox) || bbox.length < 4) {
                return;
            }

            var x0 = Number(bbox[0]), y0 = Number(bbox[1]), x1 = Number(bbox[2]), y1 = Number(bbox[3]);
            if (!isFinite(x0) || !isFinite(y0) || !isFinite(x1) || !isFinite(y1)) {
                return;
            }

            function normalizeRect(rect) {
                var left = Math.min(rect[0], rect[2]);
                var top = Math.min(rect[1], rect[3]);
                var right = Math.max(rect[0], rect[2]);
                var bottom = Math.max(rect[1], rect[3]);
                return {
                    x: left,
                    y: top,
                    w: Math.max(0, right - left),
                    h: Math.max(0, bottom - top),
                    r: right,
                    b: bottom
                };
            }

            function toViewportRectFromPdfRect(pdfRect) {
                return normalizeRect(viewport.convertToViewportRectangle(pdfRect));
            }

            function scoreRect(rect) {
                if (!rect || rect.w <= 0 || rect.h <= 0) {
                    return -Infinity;
                }
                var vx0 = Math.max(0, rect.x);
                var vy0 = Math.max(0, rect.y);
                var vx1 = Math.min(canvas.width, rect.r);
                var vy1 = Math.min(canvas.height, rect.b);
                var visibleW = Math.max(0, vx1 - vx0);
                var visibleH = Math.max(0, vy1 - vy0);
                var visibleArea = visibleW * visibleH;
                var area = rect.w * rect.h;
                if (area <= 0) {
                    return -Infinity;
                }
                var coverage = visibleArea / area;
                var canvasShare = visibleArea / (canvas.width * canvas.height || 1);
                // Prefer mostly visible rectangles that are not absurdly large relative to page.
                return coverage - Math.max(0, canvasShare - 0.5);
            }

            // Candidate A: bbox already in PDF coordinates (origin bottom-left).
            var rectPdf = toViewportRectFromPdfRect([x0, y0, x1, y1]);

            // Candidate B: bbox in top-left coordinates but same point units as PDF.
            var pageHeightPts = Math.abs(naturalViewport.viewBox[3] - naturalViewport.viewBox[1]);
            var rectTopLeftPts = toViewportRectFromPdfRect([x0, pageHeightPts - y0, x1, pageHeightPts - y1]);

            // Candidate C: bbox in top-left raster pixels at extraction scale; normalize to page points.
            var pageWidthPts = Math.abs(naturalViewport.viewBox[2] - naturalViewport.viewBox[0]);
            var sx = Math.max(x0, x1) > 0 ? (pageWidthPts / Math.max(x0, x1)) : 1;
            var sy = Math.max(y0, y1) > 0 ? (pageHeightPts / Math.max(y0, y1)) : 1;
            var rx0 = x0 * sx;
            var ry0 = y0 * sy;
            var rx1 = x1 * sx;
            var ry1 = y1 * sy;
            var rectTopLeftScaled = toViewportRectFromPdfRect([rx0, pageHeightPts - ry0, rx1, pageHeightPts - ry1]);

            var candidates = [rectPdf, rectTopLeftPts, rectTopLeftScaled];
            var bestRect = null;
            var bestScore = -Infinity;
            for (var i = 0; i < candidates.length; i++) {
                var currentScore = scoreRect(candidates[i]);
                if (currentScore > bestScore) {
                    bestScore = currentScore;
                    bestRect = candidates[i];
                }
            }
            if (!bestRect || bestRect.w <= 0 || bestRect.h <= 0) {
                return;
            }

            ctx.save();
            ctx.strokeStyle = 'rgba(255, 180, 0, 1)';
            ctx.fillStyle = 'rgba(255, 220, 0, 0.28)';
            ctx.lineWidth = 2.5;
            ctx.strokeRect(bestRect.x, bestRect.y, bestRect.w, bestRect.h);
            ctx.fillRect(bestRect.x, bestRect.y, bestRect.w, bestRect.h);
            ctx.restore();

            panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }).catch(function (err) {
            console.warn('[ClinicalCopilot] citation overlay error:', err);
        });
    }

    global.ClinicalCopilotCitationOverlay = { renderBboxOverlay: renderBboxOverlay };
})(window);
