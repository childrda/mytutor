import { useEffect, useState } from 'react';

/**
 * Brand icons load from jsDelivr’s pinned simple-icons package (SVG).
 * cdn.simpleicons.org often 404s for newer or missing slugs; avoid it for <img src>.
 * Bump SIMPLE_ICONS_NPM_VERSION when you need icons added in newer releases.
 */
const SIMPLE_ICONS_NPM_VERSION = '15.9.0';

/**
 * Provider `icon` id from providers.json → Simple Icons file slug (see npm simple-icons/icons/*.svg).
 * Use null for monogram only (no network) when no stable icon exists.
 */
const SIMPLEICON_SLUG = {
    openai: 'openai',
    anthropic: 'anthropic',
    google: 'google',
    groq: 'groq',
    deepseek: null,
    qwen: 'alibabacloud',
    kimi: null,
    minimax: 'minimax',
    glm: null,
    doubao: 'bytedance',
    siliconflow: null,
    grok: 'x',
    elevenlabs: 'elevenlabs',
    stability: 'stability',
    azure: 'microsoftazure',
    ollama: 'ollama',
    custom: null,
    'nano-banana': 'google',
    seedream: 'bytedance',
    'qwen-image': 'alibabacloud',
    'minimax-image': 'minimax',
    'grok-image': 'x',
    'glm-tts': null,
    'qwen-tts': 'alibabacloud',
    'doubao-tts': 'bytedance',
    'minimax-tts': 'minimax',
    'qwen-asr': 'alibabacloud',
    unpdf: null,
    mineru: null,
    seedance: null,
    kling: null,
    veo: 'google',
    sora: 'openai',
    'grok-video': 'x',
    tavily: null,
    // Generic capability icons in providers.json — not Simple Icons slugs
    image: null,
    pdf: null,
    video: null,
};

/**
 * @returns {string|null} Simple Icons slug, or null to use monogram only (no CDN request).
 */
function resolveSimpleIconSlug(icon) {
    if (!icon || typeof icon !== 'string') {
        return null;
    }
    if (Object.prototype.hasOwnProperty.call(SIMPLEICON_SLUG, icon)) {
        const mapped = SIMPLEICON_SLUG[icon];
        return typeof mapped === 'string' && mapped !== '' ? mapped : null;
    }
    // Unknown catalog icon id — do not guess a CDN path (avoids 404 spam for arbitrary strings).
    return null;
}

function monogram(label) {
    const s = (label || '?').trim();
    if (!s) {
        return '?';
    }
    const parts = s.split(/\s+/).filter(Boolean);
    if (parts.length >= 2) {
        return (parts[0][0] + parts[1][0]).toUpperCase();
    }
    return s.slice(0, 2).toUpperCase();
}

export default function ProviderBrandIcon({ icon, label, sizeClass = 'h-9 w-9', textClass = 'text-xs' }) {
    const slug = resolveSimpleIconSlug(icon);
    const [imgFailed, setImgFailed] = useState(false);

    useEffect(() => {
        setImgFailed(false);
    }, [slug]);

    if (!slug || imgFailed) {
        return (
            <span
                className={`inline-flex ${sizeClass} flex-shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-zinc-200 to-zinc-300 font-semibold text-zinc-700 dark:from-zinc-600 dark:to-zinc-700 dark:text-zinc-100 ${textClass}`}
                aria-hidden
            >
                {monogram(label)}
            </span>
        );
    }

    const src = `https://cdn.jsdelivr.net/npm/simple-icons@${SIMPLE_ICONS_NPM_VERSION}/icons/${encodeURIComponent(slug)}.svg`;

    return (
        <img
            src={src}
            alt=""
            width={36}
            height={36}
            className={`${sizeClass} flex-shrink-0 rounded-lg bg-white object-contain p-1 ring-1 ring-zinc-200 dark:bg-zinc-900 dark:ring-zinc-600`}
            loading="lazy"
            decoding="async"
            referrerPolicy="no-referrer"
            onError={() => setImgFailed(true)}
        />
    );
}
