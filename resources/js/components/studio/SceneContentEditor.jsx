import { useCallback, useLayoutEffect, useMemo, useRef, useState } from 'react';

function newId() {
    return crypto.randomUUID();
}

export function defaultContentForSceneType(type) {
    switch (type) {
        case 'quiz':
            return { type: 'quiz', questions: [] };
        case 'interactive':
            return { type: 'interactive', title: '', url: '', embedHtml: '' };
        case 'pbl':
            return {
                type: 'pbl',
                brief: '',
                projectConfig: {
                    title: '',
                    objective: '',
                    deliverables: [],
                    assessmentNotes: '',
                },
            };
        default:
            return {
                type: 'slide',
                canvas: { title: 'New slide', subtitle: '', footer: '', width: 1000, height: 562.5, elements: [] },
            };
    }
}

export function normalizeSceneContent(sceneType, raw) {
    const c = raw && typeof raw === 'object' ? raw : {};
    switch (sceneType) {
        case 'quiz':
            return normalizeQuiz(c);
        case 'interactive':
            return normalizeInteractive(c);
        case 'pbl':
            return normalizePbl(c);
        default:
            return normalizeSlide(c);
    }
}

const CARD_ACCENTS = new Set(['indigo', 'emerald', 'amber', 'rose', 'violet', 'sky', 'slate']);

function normalizeCardBullets(raw) {
    if (!Array.isArray(raw)) {
        return [];
    }
    const out = [];
    for (const b of raw) {
        if (typeof b === 'string' && b.trim() !== '' && out.length < 8) {
            out.push(b.trim());
        }
    }
    return out;
}

function normalizeImageElement(e) {
    const src = typeof e.src === 'string' ? e.src.trim() : '';
    if (src === '' || /^javascript:/i.test(src)) {
        return null;
    }
    const okData = /^data:image\/(jpeg|jpg|png|webp);base64,/i.test(src);
    const okHttps = /^https:\/\//i.test(src);
    const okPdfPlaceholder = /^pdf_page:\d+$/i.test(src);
    if (!okData && !okHttps && !okPdfPlaceholder) {
        return null;
    }
    const alt = typeof e.alt === 'string' ? e.alt.trim().slice(0, 500) : '';
    return {
        type: 'image',
        id: typeof e.id === 'string' && e.id ? e.id : newId(),
        x: typeof e.x === 'number' && Number.isFinite(e.x) ? e.x : 48,
        y: typeof e.y === 'number' && Number.isFinite(e.y) ? e.y : 120,
        width: typeof e.width === 'number' && Number.isFinite(e.width) ? Math.max(80, e.width) : 420,
        height: typeof e.height === 'number' && Number.isFinite(e.height) ? Math.max(80, e.height) : 360,
        src,
        alt,
    };
}

function normalizeCardElement(e) {
    const title = typeof e.title === 'string' ? e.title.trim() : '';
    const body = typeof e.body === 'string' ? e.body.trim() : '';
    const bullets = normalizeCardBullets(e.bullets);
    const caption = typeof e.caption === 'string' ? e.caption.trim().slice(0, 200) : '';
    const accentRaw = typeof e.accent === 'string' ? e.accent.trim().toLowerCase() : 'indigo';
    const accent = CARD_ACCENTS.has(accentRaw) ? accentRaw : 'indigo';
    let icon = typeof e.icon === 'string' ? e.icon.trim().slice(0, 8) : '';
    if (icon.includes('<') || icon.includes('>')) {
        icon = '';
    }
    const hasContent = title !== '' || body !== '' || bullets.length > 0 || caption !== '';
    if (!hasContent) {
        return null;
    }
    const resolvedTitle = title !== '' ? title : 'Key point';
    return {
        type: 'card',
        id: typeof e.id === 'string' && e.id ? e.id : newId(),
        x: typeof e.x === 'number' && Number.isFinite(e.x) ? e.x : 48,
        y: typeof e.y === 'number' && Number.isFinite(e.y) ? e.y : 200,
        width: typeof e.width === 'number' && Number.isFinite(e.width) ? Math.max(120, e.width) : 290,
        height: typeof e.height === 'number' && Number.isFinite(e.height) ? Math.max(100, e.height) : 320,
        title: resolvedTitle,
        body,
        bullets,
        caption,
        accent,
        icon,
    };
}

function normalizeSlide(c) {
    const canvas = c.canvas && typeof c.canvas === 'object' ? c.canvas : {};
    const rawEl = Array.isArray(canvas.elements) ? canvas.elements : [];
    const elements = rawEl.flatMap((el) => {
        const e = el && typeof el === 'object' ? el : {};
        const elType = typeof e.type === 'string' ? e.type.toLowerCase() : '';
        if (elType === 'image') {
            const img = normalizeImageElement({ ...e, type: 'image' });
            return img ? [img] : [];
        }
        if (elType === 'card') {
            const c = normalizeCardElement({ ...e, type: 'card' });
            return c ? [c] : [];
        }
        const hasText = typeof e.text === 'string' && e.text.trim() !== '';
        const type = hasText ? 'text' : typeof e.type === 'string' && e.type !== '' ? e.type : 'text';
        return [
            {
                ...e,
                type,
                id: typeof e.id === 'string' ? e.id : newId(),
            },
        ];
    });
    return {
        type: 'slide',
        canvas: {
            title: typeof canvas.title === 'string' ? canvas.title : 'Slide',
            subtitle: typeof canvas.subtitle === 'string' ? canvas.subtitle : '',
            footer: typeof canvas.footer === 'string' ? canvas.footer : '',
            width: typeof canvas.width === 'number' ? canvas.width : 1000,
            height: typeof canvas.height === 'number' ? canvas.height : 562.5,
            elements,
        },
        theme: c.theme && typeof c.theme === 'object' ? c.theme : undefined,
    };
}

function normalizeQuestion(q, i) {
    const o = q && typeof q === 'object' ? q : {};
    const options = Array.isArray(o.options)
        ? o.options.map((opt, j) => ({
              id: typeof opt?.id === 'string' ? opt.id : newId(),
              label: typeof opt?.label === 'string' ? opt.label : '',
          }))
        : [];
    const t = o.type;
    const type = t === 'multiple' || t === 'short_answer' ? t : 'single';
    return {
        id: typeof o.id === 'string' ? o.id : `q-${i}`,
        type,
        prompt: typeof o.prompt === 'string' ? o.prompt : '',
        points: typeof o.points === 'number' && o.points > 0 ? o.points : 1,
        options,
        correctIds: Array.isArray(o.correctIds) ? o.correctIds.filter((x) => typeof x === 'string') : [],
        gradingHint: typeof o.gradingHint === 'string' ? o.gradingHint : '',
    };
}

function normalizeQuiz(c) {
    return {
        type: 'quiz',
        questions: Array.isArray(c.questions) ? c.questions.map(normalizeQuestion) : [],
    };
}

function normalizeInteractive(c) {
    return {
        type: 'interactive',
        title: typeof c.title === 'string' ? c.title : '',
        url: typeof c.url === 'string' ? c.url : '',
        embedHtml: typeof c.embedHtml === 'string' ? c.embedHtml : '',
    };
}

function normalizePbl(c) {
    const pc = c.projectConfig && typeof c.projectConfig === 'object' ? c.projectConfig : {};
    const del = Array.isArray(pc.deliverables) ? pc.deliverables.filter((x) => typeof x === 'string') : [];
    return {
        type: 'pbl',
        brief: typeof c.brief === 'string' ? c.brief : '',
        projectConfig: {
            title: typeof pc.title === 'string' ? pc.title : '',
            objective: typeof pc.objective === 'string' ? pc.objective : '',
            deliverables: del,
            assessmentNotes: typeof pc.assessmentNotes === 'string' ? pc.assessmentNotes : '',
        },
    };
}

function newTextElement() {
    return {
        type: 'text',
        id: newId(),
        x: 48,
        y: 48,
        width: 420,
        height: 96,
        text: '',
        fontSize: 22,
    };
}

function newCardElement() {
    return normalizeCardElement({
        type: 'card',
        id: newId(),
        x: 40,
        y: 196,
        width: 300,
        height: 320,
        title: 'New card',
        body: '',
        bullets: ['First idea', 'Second idea'],
        caption: 'Step → outcome',
        accent: 'sky',
        icon: '✨',
    });
}

function slideCardSurface(accent) {
    const a = typeof accent === 'string' ? accent : 'indigo';
    const m = {
        indigo: 'border-indigo-300/80 bg-gradient-to-b from-indigo-50 to-indigo-100/90 text-indigo-950 shadow-md ring-1 ring-indigo-200/50 dark:border-indigo-600 dark:from-indigo-950/80 dark:to-indigo-950/50 dark:text-indigo-50 dark:ring-indigo-800/50',
        emerald: 'border-emerald-300/80 bg-gradient-to-b from-emerald-50 to-emerald-100/90 text-emerald-950 shadow-md ring-1 ring-emerald-200/50 dark:border-emerald-600 dark:from-emerald-950/80 dark:to-emerald-950/50 dark:text-emerald-50 dark:ring-emerald-800/50',
        amber: 'border-amber-300/80 bg-gradient-to-b from-amber-50 to-amber-100/90 text-amber-950 shadow-md ring-1 ring-amber-200/50 dark:border-amber-600 dark:from-amber-950/80 dark:to-amber-950/50 dark:text-amber-50 dark:ring-amber-800/50',
        rose: 'border-rose-300/80 bg-gradient-to-b from-rose-50 to-rose-100/90 text-rose-950 shadow-md ring-1 ring-rose-200/50 dark:border-rose-600 dark:from-rose-950/80 dark:to-rose-950/50 dark:text-rose-50 dark:ring-rose-800/50',
        violet: 'border-violet-300/80 bg-gradient-to-b from-violet-50 to-violet-100/90 text-violet-950 shadow-md ring-1 ring-violet-200/50 dark:border-violet-600 dark:from-violet-950/80 dark:to-violet-950/50 dark:text-violet-50 dark:ring-violet-800/50',
        sky: 'border-sky-300/80 bg-gradient-to-b from-sky-50 to-sky-100/90 text-sky-950 shadow-md ring-1 ring-sky-200/50 dark:border-sky-600 dark:from-sky-950/80 dark:to-sky-950/50 dark:text-sky-50 dark:ring-sky-800/50',
        slate: 'border-slate-300/80 bg-gradient-to-b from-slate-50 to-slate-100/90 text-slate-950 shadow-md ring-1 ring-slate-200/50 dark:border-slate-600 dark:from-slate-900/80 dark:to-slate-950/50 dark:text-slate-50 dark:ring-slate-700/50',
    };
    return m[a] || m.indigo;
}

function cardDisplayLines(el) {
    if (Array.isArray(el.bullets) && el.bullets.length > 0) {
        return el.bullets.filter((b) => typeof b === 'string' && b.trim() !== '');
    }
    if (typeof el.body === 'string' && el.body.trim() !== '') {
        return el.body
            .split('\n')
            .map((s) => s.trim())
            .filter(Boolean);
    }
    return [];
}

/** Pixel-accurate slide canvas (1000×562.5 space); parent supplies scale via CSS transform. */
export function SlideCanvasLayers({ canvas, readOnly, spotlightElementId, spotlightRect }) {
    return (
        <div
            className="relative bg-gradient-to-br from-slate-100/90 via-white to-sky-50/40 dark:from-slate-900 dark:via-slate-900 dark:to-indigo-950/40"
            style={{ width: canvas.width, height: canvas.height }}
        >
            <div className="pointer-events-none absolute left-8 right-8 top-7 z-[1]">
                <h1 className="font-serif text-[2.35rem] font-bold leading-[1.1] tracking-tight text-slate-900 dark:text-slate-50">
                    {canvas.title}
                </h1>
                {canvas.subtitle ? (
                    <p className="mt-1.5 font-serif text-[1.35rem] text-slate-600 dark:text-slate-300">{canvas.subtitle}</p>
                ) : null}
            </div>
            {readOnly &&
            spotlightRect &&
            typeof spotlightRect.x === 'number' &&
            typeof spotlightRect.y === 'number' ? (
                <div
                    className="pointer-events-none absolute z-10 rounded-lg ring-4 ring-amber-400/95 ring-offset-2 ring-offset-transparent"
                    style={{
                        left: spotlightRect.x,
                        top: spotlightRect.y,
                        width: typeof spotlightRect.width === 'number' ? spotlightRect.width : spotlightRect.w ?? 120,
                        height: typeof spotlightRect.height === 'number' ? spotlightRect.height : spotlightRect.h ?? 80,
                    }}
                />
            ) : null}
            {canvas.footer ? (
                <div className="absolute bottom-0 left-0 right-0 z-[2] border-t border-slate-300/80 bg-gradient-to-r from-slate-100/98 via-white/95 to-slate-50/98 px-8 py-2.5 font-serif text-[13px] font-medium leading-snug text-slate-800 shadow-[0_-6px_20px_rgba(15,23,42,0.06)] dark:border-slate-600 dark:from-slate-900/98 dark:via-slate-950/95 dark:to-slate-900/98 dark:text-slate-100">
                    {canvas.footer}
                </div>
            ) : null}
            {canvas.elements.map((el) => {
                if (el.type === 'image' && el.src) {
                    const spotEl = readOnly && spotlightElementId && el.id === spotlightElementId;
                    const pending = /^pdf_page:\d+$/i.test(el.src);
                    return (
                        <div
                            key={el.id}
                            className={`absolute overflow-hidden rounded-2xl border border-slate-200/90 bg-slate-100 shadow-md dark:border-slate-600 dark:bg-slate-800 ${
                                spotEl ? 'z-10 ring-4 ring-amber-400/95 ring-offset-2' : 'z-0'
                            }`}
                            style={{
                                left: el.x,
                                top: el.y,
                                width: el.width,
                                height: el.height,
                            }}
                        >
                            {pending ? (
                                <div className="flex h-full items-center justify-center px-2 text-center font-sans text-[11px] font-medium text-slate-500 dark:text-slate-400">
                                    PDF page preview (re-run generation if this stays empty)
                                </div>
                            ) : (
                                <img src={el.src} alt={el.alt || ''} className="h-full w-full object-cover" />
                            )}
                        </div>
                    );
                }
                if (el.type === 'card') {
                    const spotEl = readOnly && spotlightElementId && el.id === spotlightElementId;
                    const lines = cardDisplayLines(el);
                    return (
                        <div
                            key={el.id}
                            className={`absolute flex flex-col rounded-2xl border-2 px-3 pb-2.5 pt-3 ${slideCardSurface(el.accent)} ${
                                spotEl ? 'z-10 ring-4 ring-amber-400/95 ring-offset-2' : 'z-[1]'
                            }`}
                            style={{
                                left: el.x,
                                top: el.y,
                                width: el.width,
                                minHeight: el.height,
                            }}
                        >
                            <div className="flex min-h-0 flex-1 flex-col items-center text-center">
                                {el.icon ? (
                                    <div className="mb-2 flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-white/85 text-[1.65rem] shadow-md ring-1 ring-black/[0.06] dark:bg-white/20 dark:ring-white/10">
                                        {el.icon}
                                    </div>
                                ) : null}
                                <h3 className="font-serif text-[1.35rem] font-bold leading-tight tracking-tight">{el.title}</h3>
                                {lines.length > 0 ? (
                                    <ul className="mt-2.5 w-full flex-1 space-y-1.5 text-left text-[0.95rem] leading-snug">
                                        {lines.map((line, idx) => (
                                            <li
                                                key={idx}
                                                className="border-l-[3px] border-current/25 pl-2.5 text-current/95 dark:border-white/20"
                                            >
                                                {line}
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="mt-2 text-sm opacity-80">…</p>
                                )}
                            </div>
                            {el.caption ? (
                                <p className="mt-2 border-t border-black/10 pt-2 text-center font-sans text-[0.65rem] font-bold uppercase tracking-[0.12em] text-current/80 dark:border-white/15">
                                    {el.caption}
                                </p>
                            ) : null}
                        </div>
                    );
                }
                const isTextBlock =
                    el.type === 'text' ||
                    (typeof el.text === 'string' &&
                        el.text.trim() !== '' &&
                        el.type !== 'image' &&
                        el.type !== 'card');
                if (!isTextBlock) {
                    return null;
                }
                const spotEl = readOnly && spotlightElementId && el.id === spotlightElementId;
                return (
                    <div
                        key={el.id}
                        className={`absolute border border-dashed border-indigo-200 bg-white/80 px-2 py-1 text-zinc-800 dark:border-indigo-700 dark:bg-zinc-900/80 dark:text-zinc-100 ${
                            spotEl ? 'z-10 ring-4 ring-amber-400/95 ring-offset-2' : ''
                        }`}
                        style={{
                            left: el.x,
                            top: el.y,
                            width: el.width,
                            minHeight: el.height,
                            fontSize: el.fontSize,
                        }}
                    >
                        {el.text || '…'}
                    </div>
                );
            })}
        </div>
    );
}

function newQuizQuestion() {
    const a = newId();
    const b = newId();
    return {
        id: newId(),
        type: 'single',
        prompt: '',
        points: 1,
        options: [
            { id: a, label: '' },
            { id: b, label: '' },
        ],
        correctIds: [],
        gradingHint: '',
    };
}

function SlideEditor({ content, onChange, readOnly, spotlightElementId, spotlightRect, theaterMode = false }) {
    const canvas = content.canvas;
    const setCanvas = (patch) => {
        onChange({ ...content, canvas: { ...canvas, ...patch } });
    };

    const updateElement = (id, patch) => {
        const elements = canvas.elements.map((el) => (el.id === id ? { ...el, ...patch } : el));
        setCanvas({ elements });
    };

    const addText = () => {
        setCanvas({ elements: [...canvas.elements, newTextElement()] });
    };

    const addCard = () => {
        setCanvas({ elements: [...canvas.elements, newCardElement()] });
    };

    const removeElement = (id) => {
        setCanvas({ elements: canvas.elements.filter((el) => el.id !== id) });
    };

    const theaterRef = useRef(null);
    const [theaterBox, setTheaterBox] = useState({ w: 800, h: 450 });

    useLayoutEffect(() => {
        if (!readOnly || !theaterMode) {
            return undefined;
        }
        const el = theaterRef.current;
        if (!el) {
            return undefined;
        }
        const ro = new ResizeObserver(() => {
            const r = el.getBoundingClientRect();
            setTheaterBox({ w: Math.max(120, r.width), h: Math.max(120, r.height) });
        });
        ro.observe(el);
        const r = el.getBoundingClientRect();
        setTheaterBox({ w: Math.max(120, r.width), h: Math.max(120, r.height) });
        return () => ro.disconnect();
    }, [readOnly, theaterMode]);

    const scale = 0.42;
    const w = canvas.width * scale;
    const h = canvas.height * scale;

    const cw = canvas.width;
    const ch = canvas.height;
    const theaterScale =
        readOnly && theaterMode ? Math.min(1, Math.min(theaterBox.w / cw, theaterBox.h / ch) * 0.98) : scale;

    const previewShell = (scaleValue, outerClassName) => (
        <div
            className={`overflow-hidden rounded-2xl border border-slate-200/90 bg-white shadow-lg ring-1 ring-slate-200/60 dark:border-slate-700 dark:bg-slate-900 dark:ring-slate-700/80 ${outerClassName || ''}`}
            style={
                readOnly && theaterMode
                    ? { width: cw * scaleValue, height: ch * scaleValue }
                    : { width: w, height: h }
            }
        >
            <div
                style={{
                    width: cw,
                    height: ch,
                    transform: `scale(${scaleValue})`,
                    transformOrigin: 'top left',
                }}
            >
                <SlideCanvasLayers
                    canvas={canvas}
                    readOnly={readOnly}
                    spotlightElementId={spotlightElementId}
                    spotlightRect={spotlightRect}
                />
            </div>
        </div>
    );

    if (readOnly && theaterMode) {
        return (
            <div ref={theaterRef} className="flex min-h-0 w-full flex-1 items-center justify-center overflow-hidden">
                {previewShell(theaterScale, 'ring-zinc-600 shadow-2xl')}
            </div>
        );
    }

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-2">
                <span className="text-xs font-medium text-zinc-500">Slide canvas</span>
                {!readOnly ? (
                    <span
                        className="rounded border border-dashed border-zinc-300 px-2 py-1 text-xs text-zinc-400"
                        title="Phase 4"
                    >
                        AI layout (Phase 4)
                    </span>
                ) : null}
            </div>
            <div>
                <label className="text-xs font-medium text-zinc-600">Canvas title</label>
                <input
                    className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                    value={canvas.title}
                    disabled={readOnly}
                    onChange={(e) => setCanvas({ title: e.target.value })}
                />
            </div>
            {!readOnly ? (
                <>
                    <div>
                        <label className="text-xs font-medium text-zinc-600">Subtitle (optional)</label>
                        <input
                            className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                            value={canvas.subtitle ?? ''}
                            placeholder="What you notice every day"
                            onChange={(e) => setCanvas({ subtitle: e.target.value })}
                        />
                    </div>
                    <div>
                        <label className="text-xs font-medium text-zinc-600">Footer / quick check (optional)</label>
                        <textarea
                            className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                            rows={2}
                            value={canvas.footer ?? ''}
                            placeholder="Quick check: Which example shows evaporation?"
                            onChange={(e) => setCanvas({ footer: e.target.value })}
                        />
                    </div>
                </>
            ) : null}
            <div>
                <p className="text-xs font-medium text-zinc-600">Preview ({canvas.width}×{canvas.height})</p>
                <div className="mt-2">{previewShell(scale, '')}</div>
            </div>
            {!readOnly ? (
                <div className="space-y-3">
                    <div className="flex items-center justify-between">
                        <span className="text-xs font-semibold text-zinc-700">Text elements</span>
                        <button
                            type="button"
                            onClick={addText}
                            className="rounded-lg bg-zinc-900 px-2 py-1 text-xs font-medium text-white hover:bg-zinc-800 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white"
                        >
                            Add text
                        </button>
                        <button
                            type="button"
                            onClick={addCard}
                            className="rounded-lg border border-zinc-300 bg-white px-2 py-1 text-xs font-medium text-zinc-800 hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:hover:bg-zinc-700"
                        >
                            Add card
                        </button>
                    </div>
                    {canvas.elements.length === 0 ? (
                        <p className="text-sm text-zinc-500">No elements yet. Add text blocks for your slide.</p>
                    ) : (
                        <ul className="space-y-3">
                            {canvas.elements.map((el) => {
                                if (el.type === 'image') {
                                    const pending = /^pdf_page:\d+$/i.test(el.src);
                                    return (
                                        <li key={el.id} className="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                                            <div className="flex items-center justify-between">
                                                <span className="text-xs font-semibold text-zinc-600 dark:text-zinc-300">Image</span>
                                                <button
                                                    type="button"
                                                    onClick={() => removeElement(el.id)}
                                                    className="text-xs text-red-600 hover:underline"
                                                >
                                                    Remove
                                                </button>
                                            </div>
                                            <div className="mt-2 max-h-40 overflow-hidden rounded border border-zinc-200 bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-900">
                                                {pending ? (
                                                    <p className="p-3 text-xs text-amber-800 dark:text-amber-200">
                                                        Placeholder <code className="font-mono">{el.src}</code> — save hydrated lesson or re-generate.
                                                    </p>
                                                ) : (
                                                    <img src={el.src} alt={el.alt || ''} className="max-h-40 w-full object-contain" />
                                                )}
                                            </div>
                                            <div className="mt-2 grid gap-2 sm:grid-cols-2">
                                                <div className="sm:col-span-2">
                                                    <label className="text-xs text-zinc-500">Alt text</label>
                                                    <input
                                                        type="text"
                                                        className="mt-1 w-full rounded border border-zinc-300 px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                                                        value={el.alt ?? ''}
                                                        onChange={(e) => updateElement(el.id, { alt: e.target.value.slice(0, 500) })}
                                                    />
                                                </div>
                                                <div className="sm:col-span-2">
                                                    <label className="text-xs text-zinc-500">Image URL or data URL (large pastes OK)</label>
                                                    <textarea
                                                        className="mt-1 max-h-28 w-full rounded border border-zinc-300 px-2 py-1 font-mono text-[11px] dark:border-zinc-600 dark:bg-zinc-900"
                                                        rows={2}
                                                        value={el.src}
                                                        onChange={(e) => updateElement(el.id, { src: e.target.value })}
                                                    />
                                                </div>
                                                <div className="grid grid-cols-2 gap-2 sm:col-span-2 sm:grid-cols-4">
                                                    <div>
                                                        <label className="text-xs text-zinc-500">X</label>
                                                        <input
                                                            type="number"
                                                            className="mt-1 w-full rounded border border-zinc-300 px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                                                            value={el.x}
                                                            onChange={(e) => updateElement(el.id, { x: Number(e.target.value) || 0 })}
                                                        />
                                                    </div>
                                                    <div>
                                                        <label className="text-xs text-zinc-500">Y</label>
                                                        <input
                                                            type="number"
                                                            className="mt-1 w-full rounded border border-zinc-300 px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                                                            value={el.y}
                                                            onChange={(e) => updateElement(el.id, { y: Number(e.target.value) || 0 })}
                                                        />
                                                    </div>
                                                    <div>
                                                        <label className="text-xs text-zinc-500">W</label>
                                                        <input
                                                            type="number"
                                                            className="mt-1 w-full rounded border border-zinc-300 px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                                                            value={el.width}
                                                            onChange={(e) =>
                                                                updateElement(el.id, { width: Math.max(80, Number(e.target.value) || 80) })
                                                            }
                                                        />
                                                    </div>
                                                    <div>
                                                        <label className="text-xs text-zinc-500">H</label>
                                                        <input
                                                            type="number"
                                                            className="mt-1 w-full rounded border border-zinc-300 px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                                                            value={el.height}
                                                            onChange={(e) =>
                                                                updateElement(el.id, { height: Math.max(80, Number(e.target.value) || 80) })
                                                            }
                                                        />
                                                    </div>
                                                </div>
                                            </div>
                                        </li>
                                    );
                                }
                                if (el.type === 'card') {
                                    return (
                                        <li key={el.id} className="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                                            <div className="flex items-center justify-between">
                                                <span className="text-xs font-semibold text-zinc-600 dark:text-zinc-300">Card</span>
                                                <button
                                                    type="button"
                                                    onClick={() => removeElement(el.id)}
                                                    className="text-xs text-red-600 hover:underline"
                                                >
                                                    Remove
                                                </button>
                                            </div>
                                            <div className="mt-2 grid gap-2 sm:grid-cols-2">
                                                <div>
                                                    <label className="text-xs text-zinc-500">Title</label>
                                                    <input
                                                        type="text"
                                                        className="mt-1 w-full rounded border border-zinc-300 px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                                                        value={el.title}
                                                        onChange={(e) => updateElement(el.id, { title: e.target.value })}
                                                    />
                                                </div>
                                                <div>
                                                    <label className="text-xs text-zinc-500">Accent</label>
                                                    <select
                                                        className="mt-1 w-full rounded border border-zinc-300 px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                                                        value={el.accent}
                                                        onChange={(e) => updateElement(el.id, { accent: e.target.value })}
                                                    >
                                                        {['indigo', 'emerald', 'amber', 'rose', 'violet', 'sky', 'slate'].map((a) => (
                                                            <option key={a} value={a}>
                                                                {a}
                                                            </option>
                                                        ))}
                                                    </select>
                                                </div>
                                                <div className="sm:col-span-2">
                                                    <label className="text-xs text-zinc-500">Bullet lines (one per line; shown in the card)</label>
                                                    <textarea
                                                        className="mt-1 w-full rounded border border-zinc-300 px-2 py-1 font-mono text-sm dark:border-zinc-600 dark:bg-zinc-900"
                                                        rows={4}
                                                        value={Array.isArray(el.bullets) ? el.bullets.join('\n') : ''}
                                                        onChange={(e) =>
                                                            updateElement(el.id, {
                                                                bullets: e.target.value
                                                                    .split('\n')
                                                                    .map((s) => s.trim())
                                                                    .filter(Boolean),
                                                            })
                                                        }
                                                    />
                                                </div>
                                                <div className="sm:col-span-2">
                                                    <label className="text-xs text-zinc-500">Caption (e.g. Water → vapor)</label>
                                                    <input
                                                        type="text"
                                                        className="mt-1 w-full rounded border border-zinc-300 px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                                                        value={el.caption ?? ''}
                                                        onChange={(e) => updateElement(el.id, { caption: e.target.value })}
                                                    />
                                                </div>
                                                <div className="sm:col-span-2">
                                                    <label className="text-xs text-zinc-500">Body (optional; used if bullets empty)</label>
                                                    <textarea
                                                        className="mt-1 w-full rounded border border-zinc-300 px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                                                        rows={2}
                                                        value={el.body ?? ''}
                                                        onChange={(e) => updateElement(el.id, { body: e.target.value })}
                                                    />
                                                </div>
                                                <div>
                                                    <label className="text-xs text-zinc-500">Icon (emoji)</label>
                                                    <input
                                                        type="text"
                                                        className="mt-1 w-full rounded border border-zinc-300 px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                                                        value={el.icon}
                                                        maxLength={8}
                                                        onChange={(e) => updateElement(el.id, { icon: e.target.value.slice(0, 8) })}
                                                    />
                                                </div>
                                                <div className="grid grid-cols-2 gap-2 sm:col-span-2">
                                                    <div>
                                                        <label className="text-xs text-zinc-500">X</label>
                                                        <input
                                                            type="number"
                                                            className="mt-1 w-full rounded border border-zinc-300 px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                                                            value={el.x}
                                                            onChange={(e) => updateElement(el.id, { x: Number(e.target.value) || 0 })}
                                                        />
                                                    </div>
                                                    <div>
                                                        <label className="text-xs text-zinc-500">Y</label>
                                                        <input
                                                            type="number"
                                                            className="mt-1 w-full rounded border border-zinc-300 px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                                                            value={el.y}
                                                            onChange={(e) => updateElement(el.id, { y: Number(e.target.value) || 0 })}
                                                        />
                                                    </div>
                                                    <div>
                                                        <label className="text-xs text-zinc-500">W</label>
                                                        <input
                                                            type="number"
                                                            className="mt-1 w-full rounded border border-zinc-300 px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                                                            value={el.width}
                                                            onChange={(e) =>
                                                                updateElement(el.id, { width: Math.max(120, Number(e.target.value) || 120) })
                                                            }
                                                        />
                                                    </div>
                                                    <div>
                                                        <label className="text-xs text-zinc-500">H</label>
                                                        <input
                                                            type="number"
                                                            className="mt-1 w-full rounded border border-zinc-300 px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                                                            value={el.height}
                                                            onChange={(e) =>
                                                                updateElement(el.id, { height: Math.max(80, Number(e.target.value) || 80) })
                                                            }
                                                        />
                                                    </div>
                                                </div>
                                            </div>
                                        </li>
                                    );
                                }
                                const editableText =
                                    el.type === 'text' ||
                                    (typeof el.text === 'string' && el.text.trim() !== '' && el.type !== 'image' && el.type !== 'card');
                                if (!editableText) {
                                    return (
                                        <li key={el.id} className="rounded-lg border border-zinc-200 p-2 text-xs text-zinc-500">
                                            Unsupported element type: {el.type}
                                        </li>
                                    );
                                }
                                return (
                                    <li key={el.id} className="rounded-lg border border-zinc-200 p-3">
                                        <div className="flex justify-end">
                                            <button
                                                type="button"
                                                onClick={() => removeElement(el.id)}
                                                className="text-xs text-red-600 hover:underline"
                                            >
                                                Remove
                                            </button>
                                        </div>
                                        <div className="mt-2 grid gap-2 sm:grid-cols-2">
                                            <div>
                                                <label className="text-xs text-zinc-500">Text</label>
                                                <textarea
                                                    className="mt-1 w-full rounded border border-zinc-300 px-2 py-1 text-sm"
                                                    rows={2}
                                                    value={el.text}
                                                    onChange={(e) => updateElement(el.id, { text: e.target.value })}
                                                />
                                            </div>
                                            <div className="grid grid-cols-2 gap-2">
                                                <div>
                                                    <label className="text-xs text-zinc-500">X</label>
                                                    <input
                                                        type="number"
                                                        className="mt-1 w-full rounded border border-zinc-300 px-2 py-1 text-sm"
                                                        value={el.x}
                                                        onChange={(e) => updateElement(el.id, { x: Number(e.target.value) || 0 })}
                                                    />
                                                </div>
                                                <div>
                                                    <label className="text-xs text-zinc-500">Y</label>
                                                    <input
                                                        type="number"
                                                        className="mt-1 w-full rounded border border-zinc-300 px-2 py-1 text-sm"
                                                        value={el.y}
                                                        onChange={(e) => updateElement(el.id, { y: Number(e.target.value) || 0 })}
                                                    />
                                                </div>
                                                <div>
                                                    <label className="text-xs text-zinc-500">Size</label>
                                                    <input
                                                        type="number"
                                                        className="mt-1 w-full rounded border border-zinc-300 px-2 py-1 text-sm"
                                                        value={el.fontSize}
                                                        onChange={(e) =>
                                                            updateElement(el.id, { fontSize: Number(e.target.value) || 16 })
                                                        }
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>
            ) : null}
        </div>
    );
}

function QuizEditor({ content, onChange, language, readOnly }) {
    const [answers, setAnswers] = useState({});
    const [shortDraft, setShortDraft] = useState({});
    const [gradeResult, setGradeResult] = useState({});
    const [grading, setGrading] = useState({});

    const setQuestions = (next) => onChange({ ...content, questions: next });

    const updateQuestion = (i, patch) => {
        const next = content.questions.map((q, j) => (j === i ? { ...q, ...patch } : q));
        setQuestions(next);
    };

    const addQuestion = () => setQuestions([...content.questions, newQuizQuestion()]);

    const removeQuestion = (i) => setQuestions(content.questions.filter((_, j) => j !== i));

    const toggleCorrect = (qIndex, optionId, q) => {
        const isMulti = q.type === 'multiple';
        let nextIds = [...q.correctIds];
        if (isMulti) {
            if (nextIds.includes(optionId)) {
                nextIds = nextIds.filter((id) => id !== optionId);
            } else {
                nextIds.push(optionId);
            }
        } else {
            nextIds = [optionId];
        }
        updateQuestion(qIndex, { correctIds: nextIds });
    };

    const gradeShort = async (q) => {
        const draft = shortDraft[q.id] ?? '';
        if (!draft.trim()) {
            return;
        }
        setGrading((g) => ({ ...g, [q.id]: true }));
        try {
            const res = await window.axios.post('/api/quiz-grade', {
                question: q.prompt,
                userAnswer: draft,
                points: q.points,
                commentPrompt: q.gradingHint || undefined,
                language: language || 'en',
            });
            if (res.data?.success) {
                setGradeResult((r) => ({
                    ...r,
                    [q.id]: { score: res.data.score, max: res.data.maxPoints, comment: res.data.comment },
                }));
            }
        } catch (e) {
            window.alert(e.response?.data?.error || e.message || 'Grading failed');
        } finally {
            setGrading((g) => ({ ...g, [q.id]: false }));
        }
    };

    const checkMc = (q) => {
        const sel = answers[q.id];
        const correct = [...q.correctIds].sort().join(',');
        const mine = (Array.isArray(sel) ? sel : sel ? [sel] : []).sort().join(',');
        return correct === mine && correct !== '';
    };

    if (readOnly) {
        return (
            <div className="space-y-6">
                <p className="text-xs text-zinc-500">Playback — answer questions below.</p>
                {content.questions.map((q, i) => (
                    <div key={q.id} className="rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                        <p className="text-sm font-medium text-zinc-900">
                            {i + 1}. {q.prompt || '(No prompt)'}
                        </p>
                        <p className="text-xs text-zinc-500">{q.points} pt(s)</p>
                        {q.type === 'short_answer' ? (
                            <div className="mt-3 space-y-2">
                                <textarea
                                    className="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                                    rows={3}
                                    placeholder="Your answer"
                                    value={shortDraft[q.id] ?? ''}
                                    onChange={(e) => setShortDraft((d) => ({ ...d, [q.id]: e.target.value }))}
                                />
                                <button
                                    type="button"
                                    disabled={grading[q.id]}
                                    onClick={() => gradeShort(q)}
                                    className="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                                >
                                    {grading[q.id] ? 'Grading…' : 'Grade with AI'}
                                </button>
                                {gradeResult[q.id] ? (
                                    <p className="text-sm text-zinc-700">
                                        Score: {gradeResult[q.id].score} / {gradeResult[q.id].max} — {gradeResult[q.id].comment}
                                    </p>
                                ) : null}
                            </div>
                        ) : (
                            <ul className="mt-3 space-y-2">
                                {q.options.map((opt) => (
                                    <li key={opt.id}>
                                        <label className="flex cursor-pointer items-start gap-2 text-sm">
                                            <input
                                                type={q.type === 'multiple' ? 'checkbox' : 'radio'}
                                                name={`pq-${q.id}`}
                                                checked={
                                                    q.type === 'multiple'
                                                        ? (answers[q.id] || []).includes(opt.id)
                                                        : answers[q.id] === opt.id
                                                }
                                                onChange={() => {
                                                    if (q.type === 'multiple') {
                                                        const cur = answers[q.id] || [];
                                                        const next = cur.includes(opt.id)
                                                            ? cur.filter((x) => x !== opt.id)
                                                            : [...cur, opt.id];
                                                        setAnswers((a) => ({ ...a, [q.id]: next }));
                                                    } else {
                                                        setAnswers((a) => ({ ...a, [q.id]: opt.id }));
                                                    }
                                                }}
                                                className="mt-1"
                                            />
                                            <span>{opt.label || '(empty option)'}</span>
                                        </label>
                                    </li>
                                ))}
                                <li className="pt-2">
                                    <button
                                        type="button"
                                        onClick={() => {
                                            const ok = checkMc(q);
                                            window.alert(ok ? 'Correct!' : 'Not quite — try again.');
                                        }}
                                        className="text-sm font-medium text-indigo-600 hover:underline"
                                    >
                                        Check answer
                                    </button>
                                </li>
                            </ul>
                        )}
                    </div>
                ))}
            </div>
        );
    }

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="text-xs font-medium text-zinc-500">Quiz builder</span>
                <span
                    className="rounded border border-dashed border-zinc-300 px-2 py-1 text-xs text-zinc-400"
                    title="Phase 4"
                >
                    Generate questions (Phase 4)
                </span>
            </div>
            {content.questions.length === 0 ? (
                <p className="text-sm text-zinc-500">No questions yet.</p>
            ) : null}
            <ul className="space-y-6">
                {content.questions.map((q, i) => (
                    <li key={q.id} className="rounded-xl border border-zinc-200 p-4">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <span className="text-xs font-semibold text-zinc-500">Question {i + 1}</span>
                            <button type="button" onClick={() => removeQuestion(i)} className="text-xs text-red-600 hover:underline">
                                Remove
                            </button>
                        </div>
                        <div className="mt-3 grid gap-3 sm:grid-cols-2">
                            <div className="sm:col-span-2">
                                <label className="text-xs text-zinc-500">Prompt</label>
                                <textarea
                                    className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                                    rows={2}
                                    value={q.prompt}
                                    onChange={(e) => updateQuestion(i, { prompt: e.target.value })}
                                />
                            </div>
                            <div>
                                <label className="text-xs text-zinc-500">Type</label>
                                <select
                                    className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                                    value={q.type}
                                    onChange={(e) =>
                                        updateQuestion(i, {
                                            type: e.target.value,
                                            correctIds: [],
                                        })
                                    }
                                >
                                    <option value="single">Single choice</option>
                                    <option value="multiple">Multiple choice</option>
                                    <option value="short_answer">Short answer (AI graded)</option>
                                </select>
                            </div>
                            <div>
                                <label className="text-xs text-zinc-500">Points</label>
                                <input
                                    type="number"
                                    min={1}
                                    className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                                    value={q.points}
                                    onChange={(e) => updateQuestion(i, { points: Math.max(1, Number(e.target.value) || 1) })}
                                />
                            </div>
                            {q.type === 'short_answer' ? (
                                <div className="sm:col-span-2">
                                    <label className="text-xs text-zinc-500">Grading hint (optional)</label>
                                    <textarea
                                        className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                                        rows={2}
                                        value={q.gradingHint}
                                        onChange={(e) => updateQuestion(i, { gradingHint: e.target.value })}
                                    />
                                </div>
                            ) : null}
                        </div>
                        {q.type !== 'short_answer' ? (
                            <div className="mt-4">
                                <p className="text-xs font-medium text-zinc-600">Options (mark correct)</p>
                                <ul className="mt-2 space-y-2">
                                    {q.options.map((opt) => (
                                        <li key={opt.id} className="flex items-center gap-2">
                                            <input
                                                type={q.type === 'multiple' ? 'checkbox' : 'radio'}
                                                name={`correct-${q.id}`}
                                                checked={q.correctIds.includes(opt.id)}
                                                onChange={() => toggleCorrect(i, opt.id, q)}
                                                className="shrink-0"
                                            />
                                            <input
                                                className="flex-1 rounded border border-zinc-300 px-2 py-1 text-sm"
                                                value={opt.label}
                                                onChange={(e) => {
                                                    const opts = q.options.map((o) =>
                                                        o.id === opt.id ? { ...o, label: e.target.value } : o,
                                                    );
                                                    updateQuestion(i, { options: opts });
                                                }}
                                            />
                                        </li>
                                    ))}
                                </ul>
                                <button
                                    type="button"
                                    onClick={() =>
                                        updateQuestion(i, {
                                            options: [...q.options, { id: newId(), label: '' }],
                                        })
                                    }
                                    className="mt-2 text-xs font-medium text-indigo-600 hover:underline"
                                >
                                    Add option
                                </button>
                            </div>
                        ) : null}
                    </li>
                ))}
            </ul>
            <button
                type="button"
                onClick={addQuestion}
                className="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50"
            >
                Add question
            </button>
        </div>
    );
}

function InteractiveEditor({ content, onChange, readOnly }) {
    const set = (patch) => onChange({ ...content, ...patch });

    return (
        <div className="space-y-4">
            <div>
                <label className="text-xs font-medium text-zinc-600">Title</label>
                <input
                    className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                    value={content.title}
                    disabled={readOnly}
                    onChange={(e) => set({ title: e.target.value })}
                />
            </div>
            <div>
                <label className="text-xs font-medium text-zinc-600">URL (embed)</label>
                <input
                    className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                    placeholder="https://…"
                    value={content.url}
                    disabled={readOnly}
                    onChange={(e) => set({ url: e.target.value })}
                />
            </div>
            <div>
                <label className="text-xs font-medium text-zinc-600">Embedded HTML (optional)</label>
                <textarea
                    className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 font-mono text-xs"
                    rows={6}
                    placeholder="<div>…</div>"
                    value={content.embedHtml}
                    disabled={readOnly}
                    onChange={(e) => set({ embedHtml: e.target.value })}
                />
                <p className="mt-1 text-xs text-zinc-400">If both are set, URL preview is shown first.</p>
            </div>
            <div>
                <p className="text-xs font-medium text-zinc-600">Preview</p>
                <div className="mt-2 aspect-video w-full overflow-hidden rounded-lg border border-zinc-200 bg-zinc-100">
                    {content.url ? (
                        <iframe
                            title={content.title || 'Interactive'}
                            className="h-full w-full"
                            sandbox="allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox"
                            src={content.url}
                        />
                    ) : content.embedHtml ? (
                        <iframe
                            title={content.title || 'Embedded'}
                            className="h-full w-full"
                            sandbox="allow-scripts allow-same-origin"
                            srcDoc={content.embedHtml}
                        />
                    ) : (
                        <div className="flex h-full items-center justify-center text-sm text-zinc-500">No URL or HTML to preview</div>
                    )}
                </div>
            </div>
        </div>
    );
}

function PblEditor({ content, onChange, readOnly }) {
    const pc = content.projectConfig;
    const setPc = (patch) => onChange({ ...content, projectConfig: { ...pc, ...patch } });

    const setDeliverables = (lines) => {
        const arr = lines.split('\n').map((s) => s.trim()).filter(Boolean);
        setPc({ deliverables: arr });
    };

    return (
        <div className="space-y-4">
            <p className="text-xs text-zinc-500">
                Project brief for learners. PBL chat routing is wired in Phase 3 (<code className="text-zinc-600">/api/project-tutor/chat</code>).
            </p>
            <div>
                <label className="text-xs font-medium text-zinc-600">Short brief</label>
                <textarea
                    className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                    rows={2}
                    value={content.brief}
                    disabled={readOnly}
                    onChange={(e) => onChange({ ...content, brief: e.target.value })}
                />
            </div>
            <div>
                <label className="text-xs font-medium text-zinc-600">Project title</label>
                <input
                    className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                    value={pc.title}
                    disabled={readOnly}
                    onChange={(e) => setPc({ title: e.target.value })}
                />
            </div>
            <div>
                <label className="text-xs font-medium text-zinc-600">Objective</label>
                <textarea
                    className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                    rows={3}
                    value={pc.objective}
                    disabled={readOnly}
                    onChange={(e) => setPc({ objective: e.target.value })}
                />
            </div>
            <div>
                <label className="text-xs font-medium text-zinc-600">Deliverables (one per line)</label>
                <textarea
                    className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                    rows={4}
                    value={pc.deliverables.join('\n')}
                    disabled={readOnly}
                    onChange={(e) => setDeliverables(e.target.value)}
                />
            </div>
            <div>
                <label className="text-xs font-medium text-zinc-600">Assessment notes</label>
                <textarea
                    className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                    rows={2}
                    value={pc.assessmentNotes}
                    disabled={readOnly}
                    onChange={(e) => setPc({ assessmentNotes: e.target.value })}
                />
            </div>
            <button
                type="button"
                disabled
                className="cursor-not-allowed rounded border border-dashed border-zinc-300 px-3 py-1.5 text-xs text-zinc-400"
            >
                Open PBL chat (Phase 3)
            </button>
        </div>
    );
}

export default function SceneContentEditor({
    scene,
    onContentChange,
    language,
    playbackMode,
    spotlightElementId,
    spotlightRect,
    theaterMode = false,
}) {
    const readOnly = playbackMode === true;

    const normalized = useMemo(
        () => normalizeSceneContent(scene.type, scene.content),
        [scene.type, scene.content],
    );

    const push = useCallback(
        (next) => {
            onContentChange(next);
        },
        [onContentChange],
    );

    switch (scene.type) {
        case 'quiz':
            return <QuizEditor content={normalized} onChange={push} language={language} readOnly={readOnly} />;
        case 'interactive':
            return <InteractiveEditor content={normalized} onChange={push} readOnly={readOnly} />;
        case 'pbl':
            return <PblEditor content={normalized} onChange={push} readOnly={readOnly} />;
        default:
            return (
                <SlideEditor
                    content={normalized}
                    onChange={push}
                    readOnly={readOnly}
                    spotlightElementId={readOnly ? spotlightElementId : undefined}
                    spotlightRect={readOnly ? spotlightRect : undefined}
                    theaterMode={readOnly && theaterMode}
                />
            );
    }
}

/** Read-only viewer for published / shared lessons (quiz playback, slide preview, etc.). */
export function SceneContentView({ scene, language, spotlightElementId, spotlightRect, theaterMode = false }) {
    return (
        <SceneContentEditor
            scene={scene}
            onContentChange={() => {}}
            language={language || 'en'}
            playbackMode
            spotlightElementId={spotlightElementId}
            spotlightRect={spotlightRect}
            theaterMode={theaterMode}
        />
    );
}
