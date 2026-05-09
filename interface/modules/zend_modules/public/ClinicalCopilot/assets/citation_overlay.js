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
    function renderBboxOverlay(blobUrl, pageNumber, bbox, bboxStats, coordMeta) {
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

            var pageHeightPts = Math.abs(naturalViewport.viewBox[3] - naturalViewport.viewBox[1]);
            var pageWidthPts = Math.abs(naturalViewport.viewBox[2] - naturalViewport.viewBox[0]);
            var statsMaxX = bboxStats && isFinite(Number(bboxStats.maxX)) ? Number(bboxStats.maxX) : Math.max(x0, x1);
            var statsMaxY = bboxStats && isFinite(Number(bboxStats.maxY)) ? Number(bboxStats.maxY) : Math.max(y0, y1);

            // If bbox extents exceed page points, treat incoming values as top-left raster-like coords
            // and scale by per-page citation maxima.
            var needsScale = (Math.max(x0, x1) > pageWidthPts * 1.1) || (Math.max(y0, y1) > pageHeightPts * 1.1);
            var px0 = x0;
            var py0 = y0;
            var px1 = x1;
            var py1 = y1;
            if (needsScale) {
                var sx = statsMaxX > 0 ? (pageWidthPts / statsMaxX) : 1;
                var sy = statsMaxY > 0 ? (pageHeightPts / statsMaxY) : 1;
                px0 = x0 * sx;
                py0 = y0 * sy;
                px1 = x1 * sx;
                py1 = y1 * sy;
            }

            // Deterministic mapping for cropped raster coordinate metadata.
            var hasCropMeta = coordMeta && typeof coordMeta === 'object'
                && String(coordMeta.coordinate_space || '').toLowerCase() === 'cropped_raster'
                && isFinite(Number(coordMeta.source_image_width))
                && isFinite(Number(coordMeta.source_image_height))
                && isFinite(Number(coordMeta.crop_origin_x))
                && isFinite(Number(coordMeta.crop_origin_y))
                && isFinite(Number(coordMeta.crop_width_pts))
                && isFinite(Number(coordMeta.crop_height_pts))
                && Number(coordMeta.source_image_width) > 0
                && Number(coordMeta.source_image_height) > 0
                && Number(coordMeta.crop_width_pts) > 0
                && Number(coordMeta.crop_height_pts) > 0;

            if (hasCropMeta) {
                var srcW = Number(coordMeta.source_image_width);
                var srcH = Number(coordMeta.source_image_height);
                var cropX = Number(coordMeta.crop_origin_x);
                var cropY = Number(coordMeta.crop_origin_y);
                var cropW = Number(coordMeta.crop_width_pts);
                var cropH = Number(coordMeta.crop_height_pts);

                var bx0 = Math.min(x0, x1);
                var by0 = Math.min(y0, y1);
                var bx1 = Math.max(x0, x1);
                var by1 = Math.max(y0, y1);

                var nx0 = bx0 / srcW;
                var nx1 = bx1 / srcW;
                var ny0 = by0 / srcH;
                var ny1 = by1 / srcH;

                var pdfX0 = cropX + (nx0 * cropW);
                var pdfX1 = cropX + (nx1 * cropW);

                // Default extractor contract: bbox y is top-origin in raster space.
                var origin = String(coordMeta.coordinate_origin || 'top_left').toLowerCase();
                var pdfY0;
                var pdfY1;
                if (origin === 'bottom_left') {
                    pdfY0 = cropY + (ny0 * cropH);
                    pdfY1 = cropY + (ny1 * cropH);
                } else {
                    pdfY0 = cropY + (cropH - (ny1 * cropH));
                    pdfY1 = cropY + (cropH - (ny0 * cropH));
                }

                var rectFromMeta = toViewportRectFromPdfRect([pdfX0, pdfY0, pdfX1, pdfY1]);
                if (rectFromMeta && rectFromMeta.w > 0 && rectFromMeta.h > 0) {
                    ctx.save();
                    ctx.strokeStyle = 'rgba(255, 180, 0, 1)';
                    ctx.fillStyle = 'rgba(255, 220, 0, 0.28)';
                    ctx.lineWidth = 2.5;
                    ctx.strokeRect(rectFromMeta.x, rectFromMeta.y, rectFromMeta.w, rectFromMeta.h);
                    ctx.fillRect(rectFromMeta.x, rectFromMeta.y, rectFromMeta.w, rectFromMeta.h);
                    ctx.restore();
                    panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    return;
                }
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
                return visibleArea / area;
            }

            // Evaluate both coordinate orientations:
            // A) direct PDF-space bbox (already bottom-left origin)
            // B) top-left extractor bbox converted to PDF-space via Y inversion
            var rectDirect = toViewportRectFromPdfRect([px0, py0, px1, py1]);
            var rectInverted = toViewportRectFromPdfRect([px0, pageHeightPts - py0, px1, pageHeightPts - py1]);

            var scoreDirect = scoreRect(rectDirect);
            var scoreInverted = scoreRect(rectInverted);
            var bestRect = scoreDirect >= scoreInverted ? rectDirect : rectInverted;
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
