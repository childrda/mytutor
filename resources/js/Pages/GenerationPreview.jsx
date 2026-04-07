import { Head, Link, router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import GenerationPreviewExperience from '../components/generation/GenerationPreviewExperience';

export default function GenerationPreview({ jobId, pollIntervalMs = 3000, pipelineSteps = [] }) {
    const { auth } = usePage().props;
    const [phase, setPhase] = useState('queued');
    const [progress, setProgress] = useState(0);
    const [status, setStatus] = useState('queued');
    const [phaseDetail, setPhaseDetail] = useState(null);
    const [partialResult, setPartialResult] = useState(null);
    const [error, setError] = useState('');
    const [pollError, setPollError] = useState('');
    const [saving, setSaving] = useState(false);
    const [classroomRoles, setClassroomRoles] = useState(null);

    useEffect(() => {
        let cancelled = false;
        const tickMs = phase === 'course_outline' ? 750 : pollIntervalMs;
        const tick = async () => {
            try {
                const res = await window.axios.get(`/api/generate-lesson/${jobId}`);
                const data = res.data;
                if (!data.success) {
                    if (!cancelled) {
                        setPollError(data.error || 'Poll failed');
                    }
                    return;
                }
                if (cancelled) {
                    return;
                }
                setPollError('');
                setStatus(data.status);
                setPhase(data.phase || 'queued');
                setProgress(typeof data.progress === 'number' ? data.progress : 0);
                setPhaseDetail(data.phaseDetail ?? null);
                if (data.result && typeof data.result === 'object') {
                    setPartialResult(data.result);
                }
                if (data.classroomRoles && typeof data.classroomRoles === 'object') {
                    setClassroomRoles(data.classroomRoles);
                }
                if (data.status === 'failed') {
                    setError(data.error || 'Generation failed');
                }
            } catch (err) {
                if (!cancelled) {
                    setPollError(err.response?.data?.error || err.message || 'Poll error');
                }
            }
        };
        tick();
        const t = setInterval(tick, tickMs);
        return () => {
            cancelled = true;
            clearInterval(t);
        };
    }, [jobId, pollIntervalMs, phase]);

    const saveToLibrary = useCallback(async () => {
        if (!auth?.user || !jobId) {
            return;
        }
        setSaving(true);
        setError('');
        try {
            const res = await window.axios.post('/tutor-api/lessons/import-from-job', { jobId });
            const data = res.data;
            if (!data.success) {
                throw new Error(data.error || 'Import failed');
            }
            if (data.studioUrl) {
                router.visit(data.studioUrl);
            }
        } catch (err) {
            setError(err.response?.data?.error || err.message || 'Import failed');
        } finally {
            setSaving(false);
        }
    }, [auth?.user, jobId]);

    const personas = Array.isArray(classroomRoles?.personas) ? classroomRoles.personas : [];

    return (
        <>
            <Head title="Generating lesson" />
            <div className="min-h-screen bg-gradient-to-b from-zinc-50 via-indigo-50/40 to-zinc-100 dark:from-zinc-950 dark:via-indigo-950/25 dark:to-zinc-900">
                <div className="pointer-events-none fixed inset-0 overflow-hidden">
                    <div className="absolute -left-32 top-20 h-72 w-72 rounded-full bg-indigo-400/15 blur-3xl dark:bg-indigo-600/10" />
                    <div className="absolute -right-24 bottom-32 h-80 w-80 rounded-full bg-violet-400/10 blur-3xl dark:bg-violet-600/10" />
                </div>
                <div className="relative mx-auto max-w-3xl px-4 py-10 sm:px-6 lg:px-8">
                    <header className="border-b border-zinc-200/80 pb-8 dark:border-zinc-800">
                        <p className="text-sm font-medium text-indigo-600 dark:text-indigo-400">Lesson generation</p>
                        <h1 className="mt-2 text-3xl font-semibold tracking-tight text-zinc-900 dark:text-zinc-50">
                            Building your lesson
                        </h1>
                        <p className="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                            This screen tracks each stage while the server works. You can leave and return using this URL — progress is saved on the job.
                        </p>
                        <p className="mt-3 font-mono text-xs text-zinc-500">
                            Job <span className="text-zinc-700 dark:text-zinc-300">{jobId}</span>
                        </p>
                        <nav className="mt-6 flex flex-wrap gap-3 text-sm">
                            <Link href="/" className="text-indigo-600 underline dark:text-indigo-400">
                                ← Home
                            </Link>
                            <Link href="/studio" className="text-zinc-600 underline dark:text-zinc-400">
                                Studio list
                            </Link>
                        </nav>
                    </header>

                    <section className="mt-8">
                        <div className="mb-4 flex items-center justify-between gap-4">
                            <div>
                                <p className="text-sm font-medium capitalize text-zinc-900 dark:text-zinc-100">{status}</p>
                            </div>
                            <div className="text-right">
                                <p className="text-2xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">{progress}%</p>
                                <p className="text-xs text-zinc-500">Overall progress</p>
                            </div>
                        </div>
                        <div
                            className="h-2 overflow-hidden rounded-full bg-zinc-200/90 dark:bg-zinc-800"
                            role="progressbar"
                            aria-valuenow={progress}
                            aria-valuemin={0}
                            aria-valuemax={100}
                        >
                            <div
                                className="h-full rounded-full bg-indigo-600 transition-all duration-500 dark:bg-indigo-500"
                                style={{ width: `${Math.min(100, Math.max(0, progress))}%` }}
                            />
                        </div>
                    </section>

                    <section className="mt-8">
                        <GenerationPreviewExperience
                            phase={phase}
                            status={status}
                            pipelineSteps={pipelineSteps}
                            phaseDetail={phaseDetail}
                            partialResult={partialResult}
                        />
                    </section>

                    {personas.length > 0 ? (
                        <section className="mt-10">
                            <h2 className="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                Your classroom roles
                            </h2>
                            <p className="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                                These personas are saved with the lesson when you import. You can edit them later in the studio.
                            </p>
                            <div className="mt-4 flex gap-4 overflow-x-auto pb-2">
                                {personas.map((p) => {
                                    const color =
                                        typeof p.accentColor === 'string' && /^#[0-9A-Fa-f]{6}$/.test(p.accentColor)
                                            ? p.accentColor
                                            : '#6366F1';
                                    const label =
                                        p.role === 'teacher' ? 'Teacher' : p.role === 'assistant' ? 'Assistant' : 'Student';
                                    return (
                                        <div
                                            key={p.id || p.name}
                                            className="w-56 flex-shrink-0 rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
                                        >
                                            <div
                                                className="mx-auto flex h-14 w-14 items-center justify-center rounded-full text-lg font-bold text-white"
                                                style={{ backgroundColor: color }}
                                            >
                                                {(p.name || '?')
                                                    .split(/\s+/)
                                                    .filter(Boolean)
                                                    .map((w) => w[0])
                                                    .join('')
                                                    .slice(0, 2)
                                                    .toUpperCase() || '?'}
                                            </div>
                                            <p className="mt-3 text-center text-xs font-medium uppercase tracking-wide text-zinc-500">{label}</p>
                                            <p className="mt-1 text-center font-semibold text-zinc-900 dark:text-zinc-50">{p.name || 'Unnamed'}</p>
                                            <p className="mt-2 line-clamp-4 text-center text-xs leading-relaxed text-zinc-600 dark:text-zinc-400">
                                                {p.bio || '—'}
                                            </p>
                                        </div>
                                    );
                                })}
                            </div>
                        </section>
                    ) : null}

                    {pollError ? <p className="mt-8 text-sm text-amber-700 dark:text-amber-400">{pollError}</p> : null}

                    {error ? (
                        <p className="mt-8 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200">
                            {error}
                        </p>
                    ) : null}

                    {status === 'completed' && auth?.user ? (
                        <div className="mt-10 flex flex-wrap gap-3">
                            <button
                                type="button"
                                disabled={saving}
                                onClick={saveToLibrary}
                                className="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-medium text-white shadow hover:bg-emerald-500 disabled:opacity-50"
                            >
                                {saving ? 'Saving…' : 'Save to library & open studio'}
                            </button>
                        </div>
                    ) : null}

                    {status === 'completed' && !auth?.user ? (
                        <p className="mt-10 text-sm text-zinc-600 dark:text-zinc-400">
                            <Link href="/login" className="text-indigo-600 underline dark:text-indigo-400">
                                Log in
                            </Link>{' '}
                            to import this job into your library.
                        </p>
                    ) : null}
                </div>
            </div>
        </>
    );
}
