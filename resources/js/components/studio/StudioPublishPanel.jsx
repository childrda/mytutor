import { useCallback, useState } from 'react';

function sortScenes(list) {
    return [...list].sort((a, b) => (a.order ?? 0) - (b.order ?? 0));
}

function buildPublishPayload(lessonId, lessonForm, sceneRows) {
    const stage = {
        id: lessonId,
        name: lessonForm.name || 'Untitled lesson',
        description: lessonForm.description || '',
        language: lessonForm.language || 'en',
        style: lessonForm.style || '',
        agentIds: Array.isArray(lessonForm.agentIds) ? lessonForm.agentIds : [],
        meta: {
            studioMode: lessonForm.meta?.studioMode,
            generatedAgents: Array.isArray(lessonForm.meta?.generatedAgents) ? lessonForm.meta.generatedAgents : [],
            ...(lessonForm.meta?.classroomRoles && typeof lessonForm.meta.classroomRoles === 'object'
                ? { classroomRoles: lessonForm.meta.classroomRoles }
                : {}),
        },
    };

    const scenes = sortScenes(sceneRows).map((s) => ({
        id: s.id,
        title: s.title,
        type: s.type,
        order: s.order,
        content: s.content ?? {},
        actions: s.actions ?? [],
        whiteboards: s.whiteboards ?? null,
        multiAgent: s.multiAgent ?? null,
    }));

    return { stage, scenes };
}

export default function StudioPublishPanel({ lessonId, lessonForm, sceneRows, onMetaPatch }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const publishedUrl = lessonForm.meta?.publishedUrl || '';
    const publishedAt = lessonForm.meta?.publishedAt || '';

    const publish = useCallback(async () => {
        setError('');
        setBusy(true);
        try {
            const body = buildPublishPayload(lessonId, lessonForm, sceneRows);
            const res = await window.axios.post('/api/published-lessons', body);
            const data = res.data;
            if (!data.success) {
                throw new Error(data.error || 'Publish failed');
            }
            const url = data.url || '';
            onMetaPatch({
                publishedUrl: url,
                publishedLessonId: data.id,
                publishedAt: new Date().toISOString(),
            });
            try {
                await navigator.clipboard.writeText(url);
            } catch {
                /* clipboard may be unavailable (non-HTTPS, permissions) */
            }
        } catch (e) {
            setError(e.response?.data?.error || e.message || 'Publish failed');
        } finally {
            setBusy(false);
        }
    }, [lessonId, lessonForm, sceneRows, onMetaPatch]);

    const copyLink = useCallback(async () => {
        if (!publishedUrl) {
            return;
        }
        try {
            await navigator.clipboard.writeText(publishedUrl);
        } catch {
            window.prompt('Copy link', publishedUrl);
        }
    }, [publishedUrl]);

    return (
        <section className="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-sm font-semibold text-zinc-900">Publish & share</h2>
                    <p className="mt-1 text-xs text-zinc-500">
                        Uploads a static snapshot (authenticated; <code className="text-zinc-600">stage.id</code> must match this lesson).
                        The public link and media URLs work without signing in for anyone who has the link.
                    </p>
                </div>
                <button
                    type="button"
                    disabled={busy}
                    onClick={publish}
                    className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-500 disabled:opacity-50"
                >
                    {busy ? 'Publishing…' : 'Publish snapshot'}
                </button>
            </div>
            {error ? <p className="mt-2 text-sm text-red-600">{error}</p> : null}
            {publishedUrl ? (
                <div className="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/50 p-3">
                    <p className="text-xs font-medium text-emerald-900">Public link</p>
                    <p className="mt-1 break-all font-mono text-sm text-emerald-950">{publishedUrl}</p>
                    {publishedAt ? <p className="mt-1 text-xs text-emerald-800">Last published: {publishedAt}</p> : null}
                    <button
                        type="button"
                        onClick={copyLink}
                        className="mt-2 rounded-lg border border-emerald-300 bg-white px-3 py-1.5 text-sm font-medium text-emerald-900 hover:bg-emerald-50"
                    >
                        Copy link
                    </button>
                    <p className="mt-2 text-xs text-emerald-800">
                        After a successful publish, the link was copied to your clipboard when the browser allowed it.
                    </p>
                </div>
            ) : (
                <p className="mt-3 text-xs text-zinc-400">Publish once to get a shareable URL. Re-publishing updates the same lesson id.</p>
            )}
            <div className="mt-4 border-t border-zinc-100 pt-4">
                <h3 className="text-sm font-semibold text-zinc-900">Offline export</h3>
                <p className="mt-1 text-xs text-zinc-500">
                    Static HTML + CSS in a ZIP (Phase 5). Open <code className="text-zinc-600">index.html</code> locally.
                </p>
                <a
                    href={`/tutor-api/lessons/${encodeURIComponent(lessonId)}/export/html-zip`}
                    className="mt-2 inline-flex rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm font-medium text-zinc-800 hover:bg-zinc-50"
                    download
                >
                    Download HTML (ZIP)
                </a>
            </div>
        </section>
    );
}
