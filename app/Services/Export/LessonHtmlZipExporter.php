<?php

namespace App\Services\Export;

use App\Models\TutorLesson;
use App\Models\TutorScene;
use Illuminate\Support\Collection;
use ZipArchive;

/**
 * Builds a small static HTML lesson bundle as a ZIP (Phase 5).
 */
final class LessonHtmlZipExporter
{
    /**
     * @return array{path: string, filename: string}
     *
     * @throws LessonHtmlZipExportException
     */
    public function createZip(TutorLesson $lesson): array
    {
        $lesson->loadMissing(['scenes' => fn ($q) => $q->orderBy('scene_order')]);
        $scenes = $lesson->scenes;
        $maxScenes = max(1, (int) config('tutor.lesson_export.max_scenes', 500));
        if ($scenes->count() > $maxScenes) {
            throw new LessonHtmlZipExportException(
                'Lesson exceeds maximum scene count for export ('.$maxScenes.')',
                'INVALID_REQUEST',
                422,
            );
        }

        $maxJson = max(5_000, (int) config('tutor.lesson_export.max_scene_json_chars', 200_000));
        foreach ($scenes as $scene) {
            $enc = json_encode($scene->content ?? [], JSON_UNESCAPED_UNICODE);
            if (is_string($enc) && strlen($enc) > $maxJson) {
                throw new LessonHtmlZipExportException(
                    'A scene payload is too large to export',
                    'INVALID_REQUEST',
                    422,
                );
            }
        }

        $html = $this->buildIndexHtml($lesson, $scenes);
        $css = $this->stylesheet();
        $manifest = json_encode([
            'format' => 'mytutor-lesson-html-zip',
            'version' => 1,
            'lessonId' => $lesson->id,
            'lessonName' => $lesson->name,
            'exportedAt' => now()->toIso8601String(),
            'sceneCount' => $scenes->count(),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $uncompressed = strlen($html) + strlen($css) + strlen($manifest);
        $maxUncompressed = max(100_000, (int) config('tutor.lesson_export.max_uncompressed_bytes', 5_000_000));
        if ($uncompressed > $maxUncompressed) {
            throw new LessonHtmlZipExportException(
                'Export would exceed size limit',
                'INVALID_REQUEST',
                422,
            );
        }

        $tmp = tempnam(sys_get_temp_dir(), 'mtlzip_');
        if ($tmp === false) {
            throw new LessonHtmlZipExportException('Could not create temporary file', 'INTERNAL_ERROR', 500);
        }

        $zipPath = $tmp.'.zip';
        if (! @rename($tmp, $zipPath)) {
            @unlink($tmp);
            throw new LessonHtmlZipExportException('Could not prepare zip path', 'INTERNAL_ERROR', 500);
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);
            throw new LessonHtmlZipExportException('Could not open zip archive', 'INTERNAL_ERROR', 500);
        }

        $zip->addFromString('index.html', $html);
        $zip->addFromString('styles.css', $css);
        $zip->addFromString('manifest.json', $manifest);
        $zip->close();

        $safe = $this->slugify($lesson->name ?: 'lesson');
        $filename = $safe.'-'.$lesson->id.'.zip';

        return ['path' => $zipPath, 'filename' => $filename];
    }

    /**
     * @param  Collection<int, TutorScene>  $scenes
     */
    private function buildIndexHtml(TutorLesson $lesson, $scenes): string
    {
        $title = htmlspecialchars($lesson->name ?: 'Lesson', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $desc = htmlspecialchars((string) ($lesson->description ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lang = htmlspecialchars((string) ($lesson->language ?: 'en'), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $blocks = '';
        foreach ($scenes as $i => $scene) {
            $blocks .= $this->sceneSection($scene, $i);
        }

        return '<!DOCTYPE html>
<html lang="'.$lang.'">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <title>'.$title.'</title>
    <link rel="stylesheet" href="styles.css"/>
</head>
<body>
<header class="banner">
    <h1>'.$title.'</h1>
    '.($desc !== '' ? '<p class="desc">'.$desc.'</p>' : '').'
</header>
<main class="scenes">
'.$blocks.'
</main>
<footer class="foot">Exported for offline reading — structure may differ from the live studio.</footer>
</body>
</html>';
    }

    private function sceneSection(TutorScene $scene, int $index): string
    {
        $n = $index + 1;
        $st = htmlspecialchars((string) ($scene->type ?? 'scene'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $ti = htmlspecialchars((string) ($scene->title ?: 'Scene '.$n), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $body = $this->renderSceneBody($scene);

        return '
<section class="scene" id="scene-'.htmlspecialchars((string) $scene->id, ENT_QUOTES | ENT_HTML5, 'UTF-8').'">
    <p class="scene-meta">'.$st.' · Scene '.$n.'</p>
    <h2>'.$ti.'</h2>
    <div class="scene-body">'.$body.'</div>
</section>';
    }

    /**
     * @param  array<string, mixed>  $el
     */
    private static function isSafeSlideImageSrc(string $src): bool
    {
        $src = trim($src);
        if ($src === '') {
            return false;
        }
        $compact = preg_replace('/\s+/', '', $src) ?? $src;
        if (preg_match('#^data:image/(jpeg|png|webp);base64,[a-zA-Z0-9+/=]+$#', $compact) === 1) {
            return true;
        }

        return preg_match('#^https://[^\s\'"<>]+$#i', $src) === 1;
    }

    private static function renderSlideCardHtml(array $el): string
    {
        $accent = strtolower(trim((string) ($el['accent'] ?? 'indigo')));
        $allowed = ['indigo', 'emerald', 'amber', 'rose', 'violet', 'sky', 'slate'];
        if (! in_array($accent, $allowed, true)) {
            $accent = 'indigo';
        }
        $title = htmlspecialchars((string) ($el['title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $bodyRaw = isset($el['body']) && is_string($el['body']) ? trim($el['body']) : '';
        $lines = [];
        if (isset($el['bullets']) && is_array($el['bullets'])) {
            foreach ($el['bullets'] as $b) {
                if (is_string($b) && trim($b) !== '') {
                    $lines[] = htmlspecialchars(trim($b), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
        }
        $bodyBlock = '';
        if ($lines !== []) {
            $bodyBlock = '<ul class="scard-bullets">'.implode('', array_map(static fn (string $l) => '<li>'.$l.'</li>', $lines)).'</ul>';
        } elseif ($bodyRaw !== '') {
            $bodyBlock = '<div class="scard-body">'.nl2br(htmlspecialchars($bodyRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8')).'</div>';
        }
        $captionRaw = isset($el['caption']) && is_string($el['caption']) ? trim($el['caption']) : '';
        $captionBlock = $captionRaw !== ''
            ? '<p class="scard-caption">'.htmlspecialchars($captionRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8').'</p>'
            : '';
        $iconBlock = '';
        if (isset($el['icon']) && is_string($el['icon'])) {
            $ic = trim($el['icon']);
            if ($ic !== '' && ! str_contains($ic, '<') && ! str_contains($ic, '>')) {
                $iconBlock = '<p class="scard-icon">'.htmlspecialchars(mb_substr($ic, 0, 8), ENT_QUOTES | ENT_HTML5, 'UTF-8').'</p>';
            }
        }
        $cls = $accent === 'indigo' ? 'slide-card' : 'slide-card '.$accent;

        return '<div class="'.$cls.'">'.$iconBlock.'<p class="scard-title">'.$title.'</p>'.$bodyBlock.$captionBlock.'</div>';
    }

    private function renderSceneBody(TutorScene $scene): string
    {
        $content = is_array($scene->content) ? $scene->content : [];
        $type = $content['type'] ?? null;

        if ($type === 'slide') {
            $canvas = is_array($content['canvas'] ?? null) ? $content['canvas'] : [];
            $ct = htmlspecialchars((string) ($canvas['title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $parts = [];
            if ($ct !== '') {
                $parts[] = '<p class="canvas-title">'.$ct.'</p>';
            }
            $csub = isset($canvas['subtitle']) && is_string($canvas['subtitle']) ? trim($canvas['subtitle']) : '';
            if ($csub !== '') {
                $parts[] = '<p class="canvas-subtitle">'.htmlspecialchars($csub, ENT_QUOTES | ENT_HTML5, 'UTF-8').'</p>';
            }
            $els = $canvas['elements'] ?? [];
            if (is_array($els)) {
                foreach ($els as $el) {
                    if (! is_array($el)) {
                        continue;
                    }
                    $elt = strtolower(trim((string) ($el['type'] ?? '')));
                    if ($elt === 'text' && isset($el['text']) && is_string($el['text'])) {
                        $parts[] = '<p>'.nl2br(htmlspecialchars($el['text'], ENT_QUOTES | ENT_HTML5, 'UTF-8')).'</p>';

                        continue;
                    }
                    if ($elt === 'card') {
                        $parts[] = self::renderSlideCardHtml($el);

                        continue;
                    }
                    if ($elt === 'image' && isset($el['src']) && is_string($el['src']) && self::isSafeSlideImageSrc($el['src'])) {
                        $alt = isset($el['alt']) && is_string($el['alt']) ? trim($el['alt']) : '';
                        $altEsc = htmlspecialchars(mb_substr($alt, 0, 500), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        $srcEsc = htmlspecialchars($el['src'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        $parts[] = '<p class="slide-img-wrap"><img class="slide-img" src="'.$srcEsc.'" alt="'.$altEsc.'" loading="lazy" decoding="async"/></p>';
                    }
                }
            }
            if ($parts !== []) {
                $cfoot = isset($canvas['footer']) && is_string($canvas['footer']) ? trim($canvas['footer']) : '';
                if ($cfoot !== '') {
                    $parts[] = '<p class="canvas-footer">'.htmlspecialchars($cfoot, ENT_QUOTES | ENT_HTML5, 'UTF-8').'</p>';
                }

                return implode("\n", $parts);
            }
        }

        if ($type === 'quiz') {
            $qs = $content['questions'] ?? [];
            if (is_array($qs) && $qs !== []) {
                $out = '<ol class="quiz">';
                foreach ($qs as $q) {
                    if (! is_array($q)) {
                        continue;
                    }
                    $stem = htmlspecialchars((string) ($q['stem'] ?? $q['question'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $out .= '<li>'.$stem.'</li>';
                }
                $out .= '</ol>';

                return $out;
            }
        }

        $json = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $json = is_string($json) ? $json : '{}';

        return '<pre class="json-fallback">'.htmlspecialchars($json, ENT_QUOTES | ENT_HTML5, 'UTF-8').'</pre>';
    }

    private function stylesheet(): string
    {
        return 'body{font-family:system-ui,sans-serif;line-height:1.5;max-width:48rem;margin:0 auto;padding:1.25rem;color:#1e293b;background:#f8fafc;}
.banner{border-bottom:1px solid #e2e8f0;padding-bottom:1rem;margin-bottom:1.5rem;}
.banner h1{margin:0;font-size:1.5rem;}
.desc{color:#64748b;margin:0.5rem 0 0;}
.scene{border:1px solid #e2e8f0;border-radius:0.75rem;padding:1rem 1.25rem;margin-bottom:1rem;background:#fff;}
.scene-meta{font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;color:#94a3b8;margin:0;}
.scene h2{margin:0.35rem 0 0.75rem;font-size:1.15rem;}
.canvas-title{font-family:Georgia,serif;font-size:1.75rem;font-weight:700;margin:0;line-height:1.15;}
.canvas-subtitle{font-family:Georgia,serif;font-size:1.1rem;color:#475569;margin:0.35rem 0 0.85rem;}
.canvas-footer{font-family:Georgia,serif;font-size:0.92rem;margin-top:0.85rem;padding:0.65rem 0.85rem;background:#f1f5f9;border-radius:0.5rem;border-left:4px solid #6366f1;color:#334155;}
.slide-card{border:2px solid #c7d2fe;border-radius:0.75rem;padding:0.65rem 0.85rem;margin:0.5rem 0;background:linear-gradient(180deg,#eef2ff,#e0e7ff);}
.slide-card.emerald{border-color:#a7f3d0;background:#ecfdf5;}
.slide-card.amber{border-color:#fde68a;background:#fffbeb;}
.slide-card.rose{border-color:#fecdd3;background:#fff1f2;}
.slide-card.violet{border-color:#ddd6fe;background:#f5f3ff;}
.slide-card.sky{border-color:#bae6fd;background:#f0f9ff;}
.slide-card.slate{border-color:#cbd5e1;background:#f8fafc;}
.slide-card .scard-title{font-family:Georgia,serif;font-weight:700;margin:0.15rem 0 0.25rem;font-size:1.05rem;}
.slide-card .scard-body{margin:0;font-size:0.95rem;line-height:1.45;white-space:pre-wrap;}
.slide-card .scard-bullets{margin:0.35rem 0 0;padding-left:1.1rem;font-size:0.9rem;line-height:1.4;}
.slide-card .scard-bullets li{margin:0.2rem 0;}
.slide-card .scard-caption{margin:0.5rem 0 0;padding-top:0.45rem;border-top:1px solid rgba(0,0,0,0.08);font-size:0.68rem;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;text-align:center;color:#334155;}
.slide-card .scard-icon{font-size:1.35rem;line-height:1;}
.slide-img-wrap{margin:0.65rem 0;}
.slide-img{max-width:100%;height:auto;border-radius:0.5rem;border:1px solid #e2e8f0;}
.json-fallback{overflow:auto;font-size:0.8rem;background:#f1f5f9;padding:0.75rem;border-radius:0.5rem;}
.quiz{margin:0;padding-left:1.25rem;}
.foot{margin-top:2rem;font-size:0.8rem;color:#94a3b8;}
';
    }

    private function slugify(string $name): string
    {
        $s = strtolower(trim($name));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $s = trim($s, '-');

        return $s !== '' ? substr($s, 0, 60) : 'lesson';
    }
}
