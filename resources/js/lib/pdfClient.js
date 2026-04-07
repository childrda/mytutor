import pdfWorkerSrc from 'pdfjs-dist/build/pdf.worker.min.mjs?url';

async function getPdfJs() {
    const pdfjsLib = await import('pdfjs-dist/build/pdf.mjs');
    pdfjsLib.GlobalWorkerOptions.workerSrc = pdfWorkerSrc;
    return pdfjsLib;
}

/**
 * Render each page of a PDF File to JPEG data URLs (for LLM vision).
 * @param {File} pdfFile
 * @param {number} [maxPages=4]
 * @returns {Promise<string[]>}
 */
export async function renderPdfPagesToBase64(pdfFile, maxPages = 4) {
    const pdfjsLib = await getPdfJs();
    const arrayBuffer = await pdfFile.arrayBuffer();
    const pdf = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;
    const pageCount = Math.min(pdf.numPages, maxPages);
    const images = [];

    for (let pageNum = 1; pageNum <= pageCount; pageNum++) {
        const page = await pdf.getPage(pageNum);
        const viewport = page.getViewport({ scale: 1.5 });
        const canvas = document.createElement('canvas');
        canvas.width = viewport.width;
        canvas.height = viewport.height;
        const ctx = canvas.getContext('2d');
        if (!ctx) {
            throw new Error('Canvas 2D context unavailable');
        }
        const renderTask = page.render({ canvasContext: ctx, viewport });
        await renderTask.promise;
        images.push(canvas.toDataURL('image/jpeg', 0.85));
    }

    return images;
}

/**
 * Extract plain text from a PDF in the browser (fallback when /api/parse-pdf fails).
 * @param {File} pdfFile
 * @param {number} [maxChars=100000]
 * @returns {Promise<string>}
 */
export async function extractPdfTextClient(pdfFile, maxChars = 100_000) {
    const pdfjsLib = await getPdfJs();
    const arrayBuffer = await pdfFile.arrayBuffer();
    const pdf = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;
    const textParts = [];

    for (let i = 1; i <= pdf.numPages; i++) {
        const page = await pdf.getPage(i);
        const content = await page.getTextContent();
        const pageText = content.items
            .map((item) => ('str' in item && typeof item.str === 'string' ? item.str : ''))
            .join(' ');
        textParts.push(pageText);
    }

    return textParts.join('\n\n').slice(0, maxChars);
}
