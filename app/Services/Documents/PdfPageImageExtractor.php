<?php

namespace App\Services\Documents;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Rasterizes the first N pages of a PDF to JPEG data URLs using poppler {@code pdftoppm} (if installed).
 * Enables vision-capable lesson generation to "see" diagrams from the source PDF.
 */
final class PdfPageImageExtractor
{
    /**
     * Rasterize PDF pages for multimodal lesson generation.
     *
     * @return array{images: list<string>, diagnostic: ?string} {@code images} are JPEG data URLs; {@code diagnostic} explains empty images (for UI / ops).
     */
    public function rasterizeFirstPages(string $absolutePath, int $fileSizeBytes): array
    {
        if (! config('tutor.pdf_parse.page_images_enabled', true)) {
            return ['images' => [], 'diagnostic' => 'page_images_disabled'];
        }

        $maxBytes = max(1, (int) config('tutor.pdf_parse.max_file_bytes', 15_000_000));
        if ($fileSizeBytes > $maxBytes) {
            return ['images' => [], 'diagnostic' => 'pdf_file_too_large_for_rasterization'];
        }

        $binary = trim((string) config('tutor.pdf_parse.pdftoppm_binary', ''));
        if ($binary === '') {
            $binary = (new ExecutableFinder)->find('pdftoppm', null, [
                '/usr/bin',
                '/usr/local/bin',
                '/opt/homebrew/bin',
            ]) ?? '';
        }
        if ($binary === '' || ! is_readable($binary)) {
            return ['images' => [], 'diagnostic' => 'pdftoppm_not_found'];
        }

        $maxPages = max(1, min(5, (int) config('tutor.pdf_parse.page_images_max_pages', 3)));
        $scaleTo = max(400, min(1200, (int) config('tutor.pdf_parse.page_images_scale_to_px', 900)));
        $dpi = max(72, min(200, (int) config('tutor.pdf_parse.page_images_dpi', 110)));

        $dir = sys_get_temp_dir().'/mytutor_pdfimg_'.bin2hex(random_bytes(8));
        if (! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            return ['images' => [], 'diagnostic' => 'temp_dir_unusable'];
        }

        $prefix = $dir.'/p';
        $lastPage = (string) $maxPages;

        try {
            $process = new Process(
                [
                    $binary,
                    '-jpeg',
                    '-r',
                    (string) $dpi,
                    '-scale-to',
                    (string) $scaleTo,
                    '-f',
                    '1',
                    '-l',
                    $lastPage,
                    $absolutePath,
                    $prefix,
                ],
                null,
                null,
                null,
                (float) max(15, (int) config('tutor.pdf_parse.page_images_timeout_seconds', 45)),
            );
            $process->run();
            if (! $process->isSuccessful()) {
                return ['images' => [], 'diagnostic' => 'pdftoppm_failed'];
            }

            $out = [];
            $perFileMax = max(50_000, (int) config('tutor.pdf_parse.page_images_max_bytes', 450_000));

            for ($p = 1; $p <= $maxPages; $p++) {
                $candidates = [
                    $prefix.'-'.$p.'.jpg',
                    $prefix.'-'.sprintf('%02d', $p).'.jpg',
                ];
                $bytes = null;
                foreach ($candidates as $file) {
                    if (is_readable($file)) {
                        $raw = @file_get_contents($file);
                        if (is_string($raw) && $raw !== '') {
                            $bytes = $raw;
                            break;
                        }
                    }
                }
                if ($bytes === null || strlen($bytes) > $perFileMax) {
                    continue;
                }
                $out[] = 'data:image/jpeg;base64,'.base64_encode($bytes);
            }

            if ($out === []) {
                return ['images' => [], 'diagnostic' => 'page_images_exceed_size_limit'];
            }

            return ['images' => $out, 'diagnostic' => null];
        } catch (Throwable) {
            return ['images' => [], 'diagnostic' => 'pdftoppm_exception'];
        } finally {
            foreach (glob($dir.'/p-*.jpg') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }
}
