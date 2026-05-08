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
                .then(function () { return viewport; });
        }).then(function (viewport) {
            if (!Array.isArray(bbox) || bbox.length < 4) {
                return;
            }

            /* PDF.js viewport.transform = [scaleX, 0, 0, -scaleY, offsetX, offsetY].
             * PDF origin is bottom-left; canvas origin is top-left.
             * canvasX = scaleX * pdfX + offsetX
             * canvasY = (-scaleY) * pdfY + offsetY  (scale[3] is already negative) */
            var t = viewport.transform;
            var x0 = bbox[0], y0 = bbox[1], x1 = bbox[2], y1 = bbox[3];

            var cx0 = t[0] * x0 + t[4];
            var cy0 = t[3] * y1 + t[5]; // y1 maps to the top canvas edge of the box
            var cx1 = t[0] * x1 + t[4];
            var cy1 = t[3] * y0 + t[5]; // y0 maps to the bottom canvas edge

            var rX = Math.min(cx0, cx1);
            var rY = Math.min(cy0, cy1);
            var rW = Math.abs(cx1 - cx0);
            var rH = Math.abs(cy1 - cy0);

            ctx.save();
            ctx.strokeStyle = 'rgba(255, 180, 0, 1)';
            ctx.fillStyle = 'rgba(255, 220, 0, 0.28)';
            ctx.lineWidth = 2.5;
            ctx.strokeRect(rX, rY, rW, rH);
            ctx.fillRect(rX, rY, rW, rH);
            ctx.restore();

            panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }).catch(function (err) {
            console.warn('[ClinicalCopilot] citation overlay error:', err);
        });
    }

    global.ClinicalCopilotCitationOverlay = { renderBboxOverlay: renderBboxOverlay };
})(window);
