import { useEffect, useMemo, useState } from 'react';

const PHASE_ORDER = ['queued', 'classroom_roles', 'course_outline', 'page_content', 'teaching_actions', 'completed'];

function rank(phase) {
    const i = PHASE_ORDER.indexOf(phase);
    return i === -1 ? -1 : i;
}

function stepState(jobPhase, stepPhase) {
    if (jobPhase === 'failed') {
        return 'pending';
    }
    if (jobPhase === 'completed') {
        return 'done';
    }
    const r = rank(jobPhase);
    const sr = rank(stepPhase);
    if (r > sr) {
        return 'done';
    }
    if (r === sr) {
        return 'active';
    }
    return 'pending';
}

const CONTENT_MOCKS = [
    { label: 'SLIDE', sub: 'Canvas layout', tone: 'from-violet-500/20 to-indigo-500/10' },
    { label: 'QUIZ', sub: 'Check understanding', tone: 'from-amber-500/20 to-orange-500/10' },
    { label: 'SLIDE', sub: 'Key ideas', tone: 'from-sky-500/20 to-cyan-500/10' },
];

function truncate(s, max) {
    if (typeof s !== 'string' || s.length <= max) {
        return s || '';
    }
    return `${s.slice(0, max - 1)}…`;
}

function formatImageIssueLine(issue) {
    if (!issue || typeof issue !== 'object') {
        return '';
    }
    const msg = typeof issue.message === 'string' ? issue.message : '';
    if (!msg) {
        return '';
    }
    const bits = [];
    if (typeof issue.slideTitle === 'string' && issue.slideTitle.trim()) {
        bits.push(`Slide: ${issue.slideTitle.trim()}`);
    }
    if (typeof issue.placeholderId === 'string' && issue.placeholderId.trim()) {
        bits.push(`Placeholder: ${issue.placeholderId.trim()}`);
    }
    const prefix = bits.length ? `${bits.join(' · ')} — ` : '';
    return `${prefix}${msg}`;
}

function CentralVisual({ phase, status }) {
    const [mockIdx, setMockIdx] = useState(0);

    useEffect(() => {
        if (phase !== 'page_content' || status === 'completed') {
            return undefined;
        }
        const id = setInterval(() => setMockIdx((i) => (i + 1) % CONTENT_MOCKS.length), 3200);
        return () => clearInterval(id);
    }, [phase, status]);

    if (phase === 'failed') {
        return (
            <div className="flex min-h-[168px] flex-col items-center justify-center rounded-xl border border-red-200/80 bg-red-50/50 px-4 py-6 text-center dark:border-red-900/50 dark:bg-red-950/20">
                <p className="text-sm font-medium text-red-800 dark:text-red-200">Generation stopped</p>
                <p className="mt-1 text-xs text-red-700/80 dark:text-red-300/80">See the message below for details.</p>
            </div>
        );
    }

    if (phase === 'queued') {
        return (
            <div className="flex min-h-[168px] flex-col items-center justify-center rounded-xl border border-dashed border-zinc-300 bg-zinc-50/80 dark:border-zinc-600 dark:bg-zinc-800/40">
                <div className="h-2 w-2 animate-pulse rounded-full bg-indigo-500" />
                <p className="mt-4 text-sm font-medium text-zinc-700 dark:text-zinc-200">Queued</p>
                <p className="mt-1 max-w-xs text-center text-xs text-zinc-500 dark:text-zinc-400">Your job will start shortly on the server.</p>
            </div>
        );
    }

    if (phase === 'classroom_roles') {
        return (
            <div className="relative min-h-[168px] overflow-hidden rounded-xl border border-indigo-200/60 bg-gradient-to-br from-indigo-50 to-white dark:border-indigo-800/40 dark:from-indigo-950/50 dark:to-zinc-900">
                <div className="absolute inset-0 bg-[radial-gradient(circle_at_30%_20%,rgba(99,102,241,0.12),transparent_50%)]" />
                <div className="relative flex h-full min-h-[168px] flex-col items-center justify-center px-4">
                    <div className="flex gap-2">
                        {[0, 1, 2].map((i) => (
                            <span
                                key={i}
                                className="h-10 w-10 animate-pulse rounded-full bg-indigo-200/80 dark:bg-indigo-700/50"
                                style={{ animationDelay: `${i * 200}ms` }}
                            />
                        ))}
                    </div>
                    <p className="mt-5 text-sm font-medium text-indigo-900 dark:text-indigo-100">Meeting your teaching team</p>
                    <p className="mt-1 text-center text-xs text-indigo-800/70 dark:text-indigo-200/60">
                        Generating teacher, assistant, and student personas…
                    </p>
                </div>
            </div>
        );
    }

    if (phase === 'course_outline') {
        return (
            <div className="min-h-[168px] rounded-xl border border-zinc-200 bg-gradient-to-b from-white to-zinc-50 dark:border-zinc-700 dark:from-zinc-900 dark:to-zinc-950">
                <div className="flex h-10 items-center border-b border-zinc-200 px-3 dark:border-zinc-700">
                    <span className="h-2 w-2 rounded-full bg-zinc-300 dark:bg-zinc-600" />
                    <span className="ml-2 h-2 w-2 rounded-full bg-zinc-300 dark:bg-zinc-600" />
                    <span className="ml-2 h-2 w-2 rounded-full bg-zinc-300 dark:bg-zinc-600" />
                    <span className="ml-auto font-mono text-[10px] text-zinc-400">outline.md</span>
                </div>
                <div className="space-y-2 p-4">
                    <div className="h-2 w-[75%] max-w-[240px] animate-pulse rounded bg-zinc-200 dark:bg-zinc-700" />
                    <div className="h-2 w-[50%] max-w-[160px] animate-pulse rounded bg-zinc-200 dark:bg-zinc-700" />
                    <div className="h-2 w-[83%] max-w-[280px] animate-pulse rounded bg-zinc-200 dark:bg-zinc-700" />
                    <p className="pt-3 text-xs text-zinc-500 dark:text-zinc-400">Outlining scenes and learning objectives…</p>
                </div>
            </div>
        );
    }

    if (phase === 'page_content') {
        const m = CONTENT_MOCKS[mockIdx];
        return (
            <div
                className={`flex min-h-[168px] flex-col items-center justify-center rounded-xl border border-zinc-200 bg-gradient-to-br px-6 py-8 transition-all duration-700 dark:border-zinc-700 ${m.tone}`}
            >
                <span className="rounded-lg bg-white/80 px-3 py-1 font-mono text-xs font-bold tracking-widest text-zinc-800 shadow-sm dark:bg-zinc-900/80 dark:text-zinc-100">
                    {m.label}
                </span>
                <p className="mt-3 text-sm font-medium text-zinc-800 dark:text-zinc-100">{m.sub}</p>
                <p className="mt-1 text-center text-xs text-zinc-600 dark:text-zinc-400">Filling slides and quizzes from the outline…</p>
            </div>
        );
    }

    if (phase === 'teaching_actions') {
        return (
            <div className="flex min-h-[168px] flex-col items-center justify-center rounded-xl border border-emerald-200/70 bg-gradient-to-br from-emerald-50/90 to-white dark:border-emerald-900/40 dark:from-emerald-950/30 dark:to-zinc-900">
                <div className="flex h-[72px] items-end gap-1.5">
                    {[40, 64, 36, 72, 48].map((h, i) => (
                        <span
                            key={i}
                            className="inline-block w-2 origin-bottom rounded-sm bg-emerald-400/80 dark:bg-emerald-600/70"
                            style={{
                                height: `${h}px`,
                                animation: 'generation-bar-pulse 1.2s ease-in-out infinite',
                                animationDelay: `${i * 120}ms`,
                            }}
                        />
                    ))}
                </div>
                <p className="mt-5 text-sm font-medium text-emerald-900 dark:text-emerald-100">Teaching timeline</p>
                <p className="mt-1 text-center text-xs text-emerald-800/75 dark:text-emerald-200/60">
                    Adding narration, spotlight hooks, and pauses…
                </p>
            </div>
        );
    }

    return (
        <div className="flex min-h-[168px] flex-col items-center justify-center rounded-xl border border-emerald-200/60 bg-emerald-50/40 dark:border-emerald-900/40 dark:bg-emerald-950/20">
            <span className="text-2xl" aria-hidden>
                ✓
            </span>
            <p className="mt-2 text-sm font-medium text-emerald-900 dark:text-emerald-100">Lesson ready</p>
        </div>
    );
}

function OutlineDoc({ outline, streaming }) {
    const rows = useMemo(() => {
        if (!Array.isArray(outline)) {
            return [];
        }
        return [...outline].sort((a, b) => (a.order ?? 0) - (b.order ?? 0));
    }, [outline]);

    if (rows.length === 0) {
        return null;
    }

    return (
        <div className="mt-6 rounded-xl border border-zinc-200 bg-white/90 shadow-inner dark:border-zinc-700 dark:bg-zinc-950/50">
            <div className="flex items-center justify-between border-b border-zinc-100 px-4 py-2 dark:border-zinc-800">
                <div className="flex items-center gap-2">
                    <h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Course outline</h3>
                    {streaming ? (
                        <span className="inline-flex items-center gap-1 rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold text-indigo-800 dark:bg-indigo-900/50 dark:text-indigo-200">
                            <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-indigo-500" />
                            Streaming
                        </span>
                    ) : null}
                </div>
                <span className="rounded-full bg-zinc-100 px-2 py-0.5 text-[10px] font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                    {rows.length} scenes
                </span>
            </div>
            <ul className="max-h-64 divide-y divide-zinc-100 overflow-y-auto dark:divide-zinc-800">
                {rows.map((row, idx) => {
                    const type = row.type === 'quiz' ? 'quiz' : 'slide';
                    const title = typeof row.title === 'string' ? row.title : `Scene ${idx + 1}`;
                    const objective = typeof row.objective === 'string' ? row.objective : '';
                    const notes = typeof row.notes === 'string' ? row.notes : '';
                    const detail = objective || notes;
                    return (
                        <li key={row.id || idx} className="px-4 py-3">
                            <div className="flex items-start gap-3">
                                <span className="mt-0.5 flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full bg-zinc-100 text-[11px] font-bold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                    {idx + 1}
                                </span>
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <p className="font-medium text-zinc-900 dark:text-zinc-50">{title}</p>
                                        <span
                                            className={`rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase ${
                                                type === 'quiz'
                                                    ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200'
                                                    : 'bg-violet-100 text-violet-800 dark:bg-violet-900/40 dark:text-violet-200'
                                            }`}
                                        >
                                            {type}
                                        </span>
                                    </div>
                                    {detail ? (
                                        <p className="mt-1 text-xs leading-relaxed text-zinc-600 dark:text-zinc-400">{truncate(detail, 220)}</p>
                                    ) : null}
                                </div>
                            </div>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

function ScenesStrip({ scenes }) {
    const list = Array.isArray(scenes) ? scenes : [];
    if (list.length === 0) {
        return null;
    }

    return (
        <div className="mt-4">
            <h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Draft pages</h3>
            <div className="mt-2 flex gap-2 overflow-x-auto pb-1">
                {list.map((sc, i) => {
                    const t = sc?.type === 'quiz' ? 'quiz' : 'slide';
                    const title = typeof sc?.title === 'string' ? sc.title : `Scene ${i + 1}`;
                    return (
                        <div
                            key={sc?.id || i}
                            className="w-36 flex-shrink-0 rounded-lg border border-zinc-200 bg-zinc-50/90 px-2 py-2 dark:border-zinc-700 dark:bg-zinc-800/60"
                        >
                            <span
                                className={`inline-block rounded px-1 py-0.5 text-[9px] font-bold uppercase ${
                                    t === 'quiz' ? 'bg-amber-200/80 text-amber-900' : 'bg-violet-200/80 text-violet-900 dark:bg-violet-900/50 dark:text-violet-100'
                                }`}
                            >
                                {t}
                            </span>
                            <p className="mt-1 line-clamp-2 text-[11px] font-medium leading-snug text-zinc-800 dark:text-zinc-100">{title}</p>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

export default function GenerationPreviewExperience({ phase, status, pipelineSteps, phaseDetail, partialResult }) {
    const steps = useMemo(() => {
        if (pipelineSteps?.length) {
            return pipelineSteps;
        }
        return [
            { phase: 'classroom_roles', title: 'Classroom roles' },
            { phase: 'course_outline', title: 'Course outline' },
            { phase: 'page_content', title: 'Page content' },
            { phase: 'teaching_actions', title: 'Teaching actions' },
        ];
    }, [pipelineSteps]);

    const activeStep = steps.find((s) => stepState(phase, s.phase) === 'active');
    const headline = activeStep?.title ?? (phase === 'completed' ? 'Complete' : 'Progress');
    const sub =
        phaseDetail && typeof phaseDetail.message === 'string'
            ? phaseDetail.message
            : phase === 'completed'
              ? 'Your lesson is ready to import.'
              : null;

    const outline = partialResult?.outline;
    const scenes = partialResult?.scenes;
    const imageIssues = Array.isArray(partialResult?.imageGenerationIssues) ? partialResult.imageGenerationIssues : [];
    const imageErrors = imageIssues.filter((i) => i?.severity !== 'warning');
    const imageWarnings = imageIssues.filter((i) => i?.severity === 'warning');

    return (
        <div className="rounded-2xl border border-zinc-200/80 bg-white/95 p-6 shadow-xl shadow-zinc-200/40 backdrop-blur-sm dark:border-zinc-700/80 dark:bg-zinc-900/95 dark:shadow-black/40">
            <div className="flex flex-wrap items-center justify-center gap-2 sm:gap-3">
                {steps.map((s, idx) => {
                    const st = stepState(phase, s.phase);
                    return (
                        <div key={s.phase} className="flex items-center gap-2 sm:gap-3">
                            {idx > 0 ? <span className="hidden h-px w-4 bg-zinc-200 sm:block dark:bg-zinc-700" /> : null}
                            <div className="flex flex-col items-center gap-1">
                                <span
                                    className={`h-2.5 w-2.5 rounded-full transition-colors ${
                                        st === 'done'
                                            ? 'bg-emerald-500'
                                            : st === 'active'
                                              ? 'scale-125 bg-indigo-500 ring-2 ring-indigo-300/50 dark:ring-indigo-600/50'
                                              : 'bg-zinc-300 dark:bg-zinc-600'
                                    }`}
                                    title={s.title}
                                />
                                <span className="hidden text-[10px] text-zinc-500 sm:block dark:text-zinc-400">{s.title}</span>
                            </div>
                        </div>
                    );
                })}
            </div>

            <div className="mt-6">
                <h2 className="text-center text-lg font-semibold text-zinc-900 dark:text-zinc-50">{headline}</h2>
                {sub ? <p className="mt-1 text-center text-sm text-zinc-600 dark:text-zinc-400">{sub}</p> : null}
            </div>

            <div className="mt-6">
                <CentralVisual phase={phase} status={status} />
            </div>

            {imageErrors.length > 0 ? (
                <div
                    className="mt-6 rounded-xl border border-red-200/90 bg-red-50/90 px-4 py-3 dark:border-red-900/60 dark:bg-red-950/35"
                    role="alert"
                >
                    <p className="text-sm font-semibold text-red-900 dark:text-red-100">Some slide images did not generate</p>
                    <p className="mt-1 text-xs text-red-800/90 dark:text-red-200/85">
                        The lesson is still saved, but one or more images failed. Check the reasons below, then fix placeholders in the
                        studio or adjust your prompt.
                    </p>
                    <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-red-900 dark:text-red-100">
                        {imageErrors.map((issue, idx) => (
                            <li key={idx}>{formatImageIssueLine(issue)}</li>
                        ))}
                    </ul>
                </div>
            ) : null}

            {imageWarnings.length > 0 ? (
                <div className="mt-6 rounded-xl border border-amber-200/90 bg-amber-50/90 px-4 py-3 dark:border-amber-900/50 dark:bg-amber-950/30">
                    <p className="text-sm font-semibold text-amber-950 dark:text-amber-100">Heads up: slide images need attention</p>
                    <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-amber-950/95 dark:text-amber-100/95">
                        {imageWarnings.map((issue, idx) => (
                            <li key={idx}>{formatImageIssueLine(issue)}</li>
                        ))}
                    </ul>
                </div>
            ) : null}

            <OutlineDoc outline={outline} streaming={partialResult?.outlineStreaming === true} />

            {Array.isArray(scenes) && scenes.length > 0 ? <ScenesStrip scenes={scenes} /> : null}

            {status !== 'completed' && phase !== 'failed' ? (
                <div className="mt-6 flex items-center justify-center gap-2 border-t border-zinc-100 pt-4 text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                    <span className="inline-flex h-1.5 w-1.5 animate-pulse rounded-full bg-indigo-500" />
                    <span>AI is working on your lesson</span>
                </div>
            ) : null}
        </div>
    );
}
