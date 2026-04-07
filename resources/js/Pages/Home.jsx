import { Head, Link, router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';

function formatPdfVisionDiagnostic(code) {
    const hints = {
        pdftoppm_not_found:
            '⚠ Slides will NOT include pictures from this PDF until the server can rasterize pages. Install Poppler (e.g. Ubuntu: sudo apt install poppler-utils; macOS: brew install poppler) so `pdftoppm` exists, then upload the PDF again before generating.',
        page_images_disabled: '⚠ Page images for AI are turned off (TUTOR_PDF_PAGE_IMAGES).',
        pdftoppm_failed: '⚠ Could not convert PDF pages to images (pdftoppm error). Check server logs and Poppler install.',
        pdf_file_too_large_for_rasterization: '⚠ PDF too large to rasterize for vision; try a smaller file or raise TUTOR_PDF_MAX_FILE_BYTES.',
        page_images_exceed_size_limit: '⚠ Rasterized pages exceeded size limits; raise TUTOR_PDF_PAGE_IMAGES_MAX_BYTES or lower DPI/scale.',
        temp_dir_unusable: '⚠ Server temp directory not usable for PDF images.',
        pdftoppm_exception: '⚠ Unexpected error while rasterizing PDF pages.',
    };
    return hints[code] || `⚠ PDF page images unavailable (${code}).`;
}

export default function Home({ healthUrl, lessons = [], languageOptions = [] }) {
    const { auth } = usePage().props;
    const [requirement, setRequirement] = useState('');
    const [pdfContent, setPdfContent] = useState('');
    const [pdfPageImages, setPdfPageImages] = useState([]);
    const [language, setLanguage] = useState('en');
    const [enableWebSearch, setEnableWebSearch] = useState(false);
    const [enableImageGeneration, setEnableImageGeneration] = useState(false);
    const [enableVideoGeneration, setEnableVideoGeneration] = useState(false);
    const [enableTTS, setEnableTTS] = useState(false);
    const [agentMode, setAgentMode] = useState('balanced');
    const [pdfFileNote, setPdfFileNote] = useState('');

    const [generationError, setGenerationError] = useState('');
    const [localLessons, setLocalLessons] = useState(lessons);

    useEffect(() => {
        setLocalLessons(lessons);
    }, [lessons]);

    const startGeneration = useCallback(
        async (e) => {
            e.preventDefault();
            if (!auth?.user) {
                setGenerationError('Sign in to generate a lesson.');
                return;
            }
            setGenerationError('');
            try {
                const res = await window.axios.post('/tutor-api/generate-lesson', {
                    requirement,
                    language,
                    pdfContent: pdfContent.trim() || undefined,
                    pdfPageImages: pdfPageImages.length > 0 ? pdfPageImages : undefined,
                    enableWebSearch,
                    enableImageGeneration,
                    enableVideoGeneration,
                    enableTTS,
                    agentMode,
                });
                const data = res.data;
                if (!data.success) {
                    throw new Error(data.error || 'Request failed');
                }
                const path = typeof data.previewPath === 'string' ? data.previewPath : `/generation/${data.jobId}`;
                router.visit(path);
            } catch (err) {
                setGenerationError(err.response?.data?.error || err.message || 'Failed');
            }
        },
        [
            agentMode,
            auth?.user,
            enableImageGeneration,
            enableTTS,
            enableVideoGeneration,
            enableWebSearch,
            language,
            pdfContent,
            pdfPageImages,
            requirement,
        ],
    );

    async function onPdfFile(ev) {
        if (!auth?.user) {
            setPdfFileNote('Sign in to extract text from a PDF.');
            ev.target.value = '';
            return;
        }
        const file = ev.target.files?.[0];
        if (!file) {
            return;
        }
        const looksPdf =
            file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf');
        if (!looksPdf) {
            setPdfFileNote('Please choose a PDF file (or use “Source text”).');
            return;
        }
        setPdfFileNote('Uploading for extraction…');
        try {
            const fd = new FormData();
            fd.append('file', file);
            const res = await window.axios.post('/api/parse-pdf', fd, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            if (res.data?.success && typeof res.data.text === 'string') {
                setPdfContent(res.data.text);
                let pageImages = Array.isArray(res.data.pageImages) ? res.data.pageImages : [];
                const fromServer = pageImages.length > 0;
                if (!fromServer) {
                    try {
                        const { renderPdfPagesToBase64 } = await import('../lib/pdfClient.js');
                        const clientImages = await renderPdfPagesToBase64(file, 4);
                        if (clientImages.length > 0) {
                            pageImages = clientImages;
                        }
                    } catch (renderErr) {
                        console.warn('PDF page rendering in browser failed:', renderErr);
                    }
                }
                setPdfPageImages(pageImages);

                const meta = res.data.meta || {};
                const parts = [];
                if (typeof meta.pages === 'number') {
                    parts.push(`${meta.pages} page(s)`);
                }
                if (pageImages.length > 0) {
                    parts.push(
                        fromServer
                            ? `${pageImages.length} page preview image(s) for AI vision`
                            : `${pageImages.length} page preview image(s) for AI vision (rendered in your browser)`,
                    );
                }
                if (meta.truncated) {
                    parts.push('text truncated to server limit');
                }
                const diag = typeof meta.pageImageDiagnostic === 'string' ? meta.pageImageDiagnostic : null;
                const visionWarn =
                    pageImages.length === 0 && diag && (meta.pageImageCount === 0 || !meta.pageImageCount)
                        ? formatPdfVisionDiagnostic(diag)
                        : '';
                const baseNote = parts.length ? `Extracted (${parts.join(' · ')}). Review “Source text” below.` : '';
                setPdfFileNote([baseNote, visionWarn].filter(Boolean).join(' '));
            }
        } catch (err) {
            const serverMsg =
                err.response?.data?.error || err.message || 'PDF extraction failed. Paste text into “Source text” instead.';
            try {
                const { extractPdfTextClient, renderPdfPagesToBase64 } = await import(
                    '../lib/pdfClient.js'
                );
                const text = await extractPdfTextClient(file, 100_000).catch(() => '');
                let imgs = [];
                try {
                    imgs = await renderPdfPagesToBase64(file, 4);
                } catch {
                    imgs = [];
                }
                if (text.trim() !== '' || imgs.length > 0) {
                    setPdfContent(text);
                    setPdfPageImages(imgs);
                    const bits = [`Extracted in browser (${file.name}).`];
                    if (text.trim() !== '') {
                        bits.push(`${text.length.toLocaleString()} characters.`);
                    }
                    if (imgs.length > 0) {
                        bits.push(`${imgs.length} page preview image(s) for AI vision.`);
                    }
                    bits.push(`Server: ${serverMsg}`);
                    setPdfFileNote(bits.join(' '));
                    return;
                }
            } catch (clientErr) {
                console.warn('Client PDF fallback failed:', clientErr);
            }
            setPdfFileNote(serverMsg);
        }
    }

    async function deleteLesson(id, name) {
        if (!window.confirm(`Delete “${name}”?`)) {
            return;
        }
        try {
            await window.axios.delete(`/tutor-api/lessons/${id}`);
            setLocalLessons((prev) => prev.filter((l) => l.id !== id));
        } catch (err) {
            window.alert(err.response?.data?.error || err.message || 'Delete failed');
        }
    }

    async function renameLesson(id, currentName) {
        const name = window.prompt('Lesson name', currentName);
        if (!name || name === currentName) {
            return;
        }
        try {
            const res = await window.axios.patch(`/tutor-api/lessons/${id}`, { name });
            const updated = res.data?.lesson;
            if (updated) {
                setLocalLessons((prev) => prev.map((l) => (l.id === id ? { ...l, name: updated.name, updatedAt: updated.updatedAt } : l)));
            }
        } catch (err) {
            window.alert(err.response?.data?.error || err.message || 'Rename failed');
        }
    }

    const langOpts = useMemo(() => (languageOptions.length ? languageOptions : [{ value: 'en', label: 'English' }]), [languageOptions]);

    return (
        <>
            <Head title="MyTutor" />
            <div className="min-h-screen bg-zinc-50 dark:bg-zinc-950">
                <div className="mx-auto max-w-6xl px-4 py-10 sm:px-6 lg:px-8">
                    <header className="flex flex-wrap items-start justify-between gap-4 border-b border-zinc-200 pb-8">
                        <div>
                            <h1 className="text-3xl font-semibold tracking-tight text-zinc-900">MyTutor</h1>
                            <p className="mt-2 max-w-2xl text-zinc-600">
                                Create AI-assisted lessons, keep them in your library, and open them in the studio.
                            </p>
                            <p className="mt-2 text-sm text-zinc-500">
                                <a className="text-indigo-600 underline" href={healthUrl} target="_blank" rel="noreferrer">
                                    Health check
                                </a>
                                {' · '}
                                <Link href="/api/integrations" className="text-indigo-600 underline">
                                    Integrations (JSON)
                                </Link>
                            </p>
                        </div>
                        <nav className="flex flex-wrap items-center gap-3 text-sm">
                            {auth?.user ? (
                                <>
                                    <Link href="/studio" className="rounded-lg border border-zinc-300 px-3 py-2 font-medium text-zinc-700 hover:bg-white">
                                        Studio list
                                    </Link>
                                    <Link href="/settings" className="rounded-lg border border-zinc-300 px-3 py-2 font-medium text-zinc-700 hover:bg-white">
                                        Settings
                                    </Link>
                                    <button
                                        type="button"
                                        className="text-zinc-600 hover:underline"
                                        onClick={() => router.post('/logout')}
                                    >
                                        Log out
                                    </button>
                                </>
                            ) : (
                                <>
                                    <Link href="/login" className="font-medium text-indigo-600 hover:underline">
                                        Log in
                                    </Link>
                                    <Link href="/register" className="text-zinc-600 hover:underline">
                                        Register
                                    </Link>
                                </>
                            )}
                        </nav>
                    </header>

                    <div className="mt-10 grid gap-10 lg:grid-cols-2">
                        <section>
                            <h2 className="text-lg font-semibold text-zinc-900">Your lessons</h2>
                            {!auth?.user ? (
                                <p className="mt-4 rounded-xl border border-dashed border-zinc-300 bg-white p-6 text-sm text-zinc-600">
                                    <Link href="/login" className="font-medium text-indigo-600 hover:underline">
                                        Sign in
                                    </Link>{' '}
                                    to see saved lessons and to attach generation jobs to your account (required to import into your library).
                                </p>
                            ) : localLessons.length === 0 ? (
                                <div className="mt-4 rounded-xl border border-dashed border-zinc-300 bg-white p-8 text-center">
                                    <p className="text-sm font-medium text-zinc-800">No lessons yet</p>
                                    <p className="mt-1 text-sm text-zinc-500">Generate one below, or create a blank lesson from the studio.</p>
                                    <Link
                                        href="/studio"
                                        className="mt-4 inline-block rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-800"
                                    >
                                        Open studio
                                    </Link>
                                </div>
                            ) : (
                                <ul className="mt-4 divide-y divide-zinc-200 overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm">
                                    {localLessons.map((lesson) => (
                                        <li key={lesson.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 hover:bg-zinc-50">
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate font-medium text-zinc-900">{lesson.name}</p>
                                                <p className="text-xs text-zinc-400">{lesson.id}</p>
                                            </div>
                                            <div className="flex flex-shrink-0 gap-2">
                                                <Link
                                                    href={`/studio/${lesson.id}`}
                                                    className="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500"
                                                >
                                                    Open
                                                </Link>
                                                <button
                                                    type="button"
                                                    className="rounded-lg border border-zinc-300 px-3 py-1.5 text-sm text-zinc-700 hover:bg-zinc-100"
                                                    onClick={() => renameLesson(lesson.id, lesson.name)}
                                                >
                                                    Rename
                                                </button>
                                                <button
                                                    type="button"
                                                    className="rounded-lg border border-red-200 px-3 py-1.5 text-sm text-red-700 hover:bg-red-50"
                                                    onClick={() => deleteLesson(lesson.id, lesson.name)}
                                                >
                                                    Delete
                                                </button>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>

                        <section className="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                            <h2 className="text-lg font-semibold text-zinc-900">New lesson from AI</h2>
                            <p className="mt-1 text-sm text-zinc-500">
                                Describe what to teach. Optional source text helps ground the outline. Run <code className="text-xs">queue:work</code> so jobs
                                finish.
                            </p>

                            {!auth?.user ? (
                                <p className="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">
                                    <Link href="/login" className="font-medium text-amber-950 underline">
                                        Sign in
                                    </Link>{' '}
                                    to generate lessons, poll jobs, extract PDF text, and import into your library.
                                </p>
                            ) : null}

                            <form className="mt-6 flex flex-col gap-4" onSubmit={startGeneration}>
                                <div>
                                    <label className="text-sm font-medium text-zinc-700" htmlFor="req">
                                        Topic / requirements
                                    </label>
                                    <textarea
                                        id="req"
                                        className="mt-1 min-h-[120px] w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm shadow-inner focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                                        value={requirement}
                                        onChange={(ev) => setRequirement(ev.target.value)}
                                        required
                                    />
                                </div>

                                <div>
                                    <label className="text-sm font-medium text-zinc-700" htmlFor="lang">
                                        Language
                                    </label>
                                    <select
                                        id="lang"
                                        className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                                        value={language}
                                        onChange={(ev) => setLanguage(ev.target.value)}
                                    >
                                        {langOpts.map((o) => (
                                            <option key={o.value} value={o.value}>
                                                {o.label}
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                <div>
                                    <label className="text-sm font-medium text-zinc-700" htmlFor="src">
                                        Source text (optional)
                                    </label>
                                    <textarea
                                        id="src"
                                        className="mt-1 min-h-[80px] w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                                        placeholder="Paste notes or reading excerpt…"
                                        value={pdfContent}
                                        onChange={(ev) => setPdfContent(ev.target.value)}
                                    />
                                </div>

                                <div>
                                    <label className="text-sm font-medium text-zinc-700">PDF file (optional)</label>
                                    <input
                                        type="file"
                                        accept="application/pdf"
                                        disabled={!auth?.user}
                                        className="mt-1 block w-full text-sm text-zinc-600 disabled:cursor-not-allowed disabled:opacity-50"
                                        onChange={onPdfFile}
                                    />
                                    {pdfFileNote ? <p className="mt-1 text-xs text-amber-800">{pdfFileNote}</p> : null}
                                </div>

                                <div>
                                    <p className="text-sm font-medium text-zinc-700">Options</p>
                                    <div className="mt-2 flex flex-col gap-2 text-sm text-zinc-700">
                                        <label className="flex items-center gap-2">
                                            <input type="checkbox" checked={enableWebSearch} onChange={(e) => setEnableWebSearch(e.target.checked)} />
                                            Web search (when backend supports it for this pipeline)
                                        </label>
                                        <label className="flex items-center gap-2">
                                            <input
                                                type="checkbox"
                                                checked={enableImageGeneration}
                                                onChange={(e) => setEnableImageGeneration(e.target.checked)}
                                            />
                                            Image generation
                                        </label>
                                        <label className="flex items-center gap-2">
                                            <input
                                                type="checkbox"
                                                checked={enableVideoGeneration}
                                                onChange={(e) => setEnableVideoGeneration(e.target.checked)}
                                            />
                                            Video generation
                                        </label>
                                        <label className="flex items-center gap-2">
                                            <input type="checkbox" checked={enableTTS} onChange={(e) => setEnableTTS(e.target.checked)} />
                                            Text-to-speech
                                        </label>
                                    </div>
                                </div>

                                <div>
                                    <label className="text-sm font-medium text-zinc-700" htmlFor="agent">
                                        Agent mode
                                    </label>
                                    <select
                                        id="agent"
                                        className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                                        value={agentMode}
                                        onChange={(ev) => setAgentMode(ev.target.value)}
                                    >
                                        <option value="balanced">Balanced</option>
                                        <option value="creative">Creative</option>
                                        <option value="minimal">Minimal</option>
                                    </select>
                                </div>

                                <button
                                    type="submit"
                                    disabled={!auth?.user}
                                    className="rounded-lg bg-indigo-600 py-2.5 text-sm font-medium text-white shadow hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    Generate lesson
                                </button>
                            </form>

                            {generationError ? <p className="mt-4 text-sm text-red-600">{generationError}</p> : null}
                        </section>
                    </div>
                </div>
            </div>
        </>
    );
}
