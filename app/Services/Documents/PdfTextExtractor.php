<?php

namespace App\Services\Documents;

use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Server-side PDF text extraction (Phase 5) using smalot/pdfparser.
 */
final class PdfTextExtractor
{
    public function __construct(
        private readonly ?Parser $parser = null,
    ) {}

    /**
     * @return array{text: string, pages: int, truncated: bool, fileSizeBytes: int}
     *
     * @throws PdfTextExtractionException
     */
    public function extractFromPath(string $absolutePath, int $fileSizeBytes): array
    {
        $maxBytes = max(1, (int) config('tutor.pdf_parse.max_file_bytes', 15_000_000));
        if ($fileSizeBytes > $maxBytes) {
            throw new PdfTextExtractionException(
                'PDF exceeds maximum upload size ('.$maxBytes.' bytes)',
                'INVALID_REQUEST',
                413,
            );
        }

        $maxPages = max(1, (int) config('tutor.pdf_parse.max_pages', 200));
        $maxChars = max(1, (int) config('tutor.pdf_parse.max_output_chars', 500_000));

        $exec = (int) config('tutor.pdf_parse.max_execution_seconds', 60);
        @set_time_limit(max(30, $exec));

        try {
            $parser = $this->parser ?? new Parser;
            $document = $parser->parseFile($absolutePath);
            $pages = $document->getPages();
            $pageCount = count($pages);
            if ($pageCount > $maxPages) {
                throw new PdfTextExtractionException(
                    'PDF exceeds maximum page count ('.$maxPages.' pages)',
                    'INVALID_REQUEST',
                    422,
                );
            }

            $text = $document->getText();
            $text = is_string($text) ? $text : '';
            $text = preg_replace("/[\r\n]+/", "\n", $text) ?? $text;
            $text = trim($text);

            $truncated = false;
            if (mb_strlen($text) > $maxChars) {
                $text = mb_substr($text, 0, $maxChars);
                $truncated = true;
            }

            return [
                'text' => $text,
                'pages' => $pageCount,
                'truncated' => $truncated,
                'fileSizeBytes' => $fileSizeBytes,
            ];
        } catch (PdfTextExtractionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PdfTextExtractionException(
                'Could not parse PDF: '.$e->getMessage(),
                'PARSE_FAILED',
                422,
            );
        }
    }
}
