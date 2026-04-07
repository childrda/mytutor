import ClassroomScenesDrawer, {
    ClassroomSceneNavButton,
} from '../../components/classroom/ClassroomScenesDrawer.jsx';
import { SceneContentView } from '../../components/studio/SceneContentEditor.jsx';
import StudioChatPanel from '../../components/studio/StudioChatPanel.jsx';
import { normalizeWhiteboard } from '../../components/studio/StudioWhiteboardPanel.jsx';
import { useClassroomSpeechAudio } from '../../hooks/useClassroomSpeechAudio.js';
import { getEffectiveSpotlight, normalizeActions } from '../../lib/teachingActionsPlayback.js';
import { Head, Link, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

const SPLIT_STORAGE_KEY = 'classroomRailSplitPct';
const SPLIT_MIN = 24;
const SPLIT_MAX = 58;

function readInitialSplitPct() {
    if (typeof window === 'undefined') {
        return 36;
    }
    const raw = window.sessionStorage.getItem(SPLIT_STORAGE_KEY);
    const n = raw !== null ? Number(raw) : NaN;
    if (Number.isFinite(n) && n >= SPLIT_MIN && n <= SPLIT_MAX) {
        return Math.round(n);
    }
    return 36;
}

function sortScenes(list) {
    return [...list].sort((a, b) => (a.order ?? 0) - (b.order ?? 0));
}

function durationMsForAction(a) {
    if (!a) {
        return 2200;
    }
    if (a.type === 'spotlight') {
        return Math.min(120000, Math.max(400, a.durationMs || 4000));
    }
    if (a.type === 'speech' || a.type === 'narration') {
        const t = (a.text || a.label || '').trim();
        const n = t.length;
        return Math.min(24000, Math.max(1600, n * 45 + 800));
    }
    if (a.type === 'interact') {
        return 0;
    }
    return 2200;
}

function personaLabel(personas, personaId) {
    if (!personaId) {
        return null;
    }
    const p = personas.find((x) => x && x.id === personaId);
    if (!p) {
        return personaId;
    }
    return typeof p.name === 'string' && p.name.trim() !== '' ? p.name : personaId;
}

function defaultPlaybackState(sortedScenes, meta) {
    const firstId = sortedScenes[0]?.id ?? null;
    const cps = meta?.classroomPlaybackState;
    let sceneId = typeof cps?.sceneId === 'string' ? cps.sceneId : firstId;
    if (sceneId && !sortedScenes.some((s) => s.id === sceneId)) {
        sceneId = firstId;
    }
    return {
        sceneId,
        actionIndex: typeof cps?.actionIndex === 'number' && cps.actionIndex >= 0 ? cps.actionIndex : 0,
        isPlaying: cps?.isPlaying === true,
        speed: typeof cps?.speed === 'number' && cps.speed > 0 ? cps.speed : 1,
        loop: cps?.loop === true,
    };
}

export default function ClassroomShow({ stage, scenes: initialScenes = [] }) {
    const page = usePage();
    const serverTtsAvailable = page.props.tutor?.classroomServerTts !== false;

    const lessonId = stage.id;
    const sortedScenes = useMemo(() => sortScenes(initialScenes), [initialScenes]);
    const [lessonMeta, setLessonMeta] = useState(() => ({ ...(stage.meta || {}) }));
    const [agentIds, setAgentIds] = useState(() =>
        Array.isArray(stage.agentIds) && stage.agentIds.length > 0 ? [...stage.agentIds] : ['tutor'],
    );
    const personas = useMemo(
        () =>
            Array.isArray(lessonMeta?.classroomRoles?.personas)
                ? lessonMeta.classroomRoles.personas.filter((p) => p && typeof p === 'object')
                : [],
        [lessonMeta?.classroomRoles?.personas],
    );

    const metaRef = useRef({ ...(stage.meta || {}) });

    const [splitRailPct, setSplitRailPct] = useState(readInitialSplitPct);
    const splitDragRef = useRef({ active: false, startX: 0, startPct: 36 });
    const [isLg, setIsLg] = useState(false);
    const [scenesDrawerOpen, setScenesDrawerOpen] = useState(false);

    useEffect(() => {
        const mq = window.matchMedia('(min-width: 1024px)');
        const apply = () => setIsLg(mq.matches);
        apply();
        mq.addEventListener('change', apply);
        return () => mq.removeEventListener('change', apply);
    }, []);

    useEffect(() => {
        const onMove = (e) => {
            if (!splitDragRef.current.active) {
                return;
            }
            const row = document.getElementById('classroom-split-row');
            if (!row) {
                return;
            }
            const w = row.getBoundingClientRect().width;
            if (w < 80) {
                return;
            }
            const dx = splitDragRef.current.startX - e.clientX;
            const deltaPct = (dx / w) * 100;
            const next = Math.round(
                Math.min(SPLIT_MAX, Math.max(SPLIT_MIN, splitDragRef.current.startPct + deltaPct)),
            );
            setSplitRailPct(next);
        };
        const onUp = () => {
            if (!splitDragRef.current.active) {
                return;
            }
            splitDragRef.current.active = false;
            document.body.style.cursor = '';
            document.body.style.userSelect = '';
            setSplitRailPct((p) => {
                try {
                    window.sessionStorage.setItem(SPLIT_STORAGE_KEY, String(p));
                } catch {
                    /* ignore */
                }
                return p;
            });
        };
        window.addEventListener('mousemove', onMove);
        window.addEventListener('mouseup', onUp);
        return () => {
            window.removeEventListener('mousemove', onMove);
            window.removeEventListener('mouseup', onUp);
        };
    }, []);

    const onSplitMouseDown = useCallback((e) => {
        e.preventDefault();
        splitDragRef.current = { active: true, startX: e.clientX, startPct: splitRailPct };
        document.body.style.cursor = 'col-resize';
        document.body.style.userSelect = 'none';
    }, [splitRailPct]);

    const [playback, setPlayback] = useState(() => defaultPlaybackState(sortedScenes, stage.meta));
    const playbackRef = useRef(playback);
    playbackRef.current = playback;

    const scenesRef = useRef(sortedScenes);
    scenesRef.current = sortedScenes;

    useEffect(() => {
        scenesRef.current = sortedScenes;
    }, [sortedScenes]);

    const persistTimer = useRef(null);
    const schedulePersist = useCallback(() => {
        clearTimeout(persistTimer.current);
        persistTimer.current = setTimeout(async () => {
            const m = metaRef.current;
            const p = playbackRef.current;
            const nextMeta = {
                ...m,
                classroomPlaybackState: {
                    sceneId: p.sceneId,
                    actionIndex: p.actionIndex,
                    isPlaying: p.isPlaying,
                    speed: p.speed,
                    loop: p.loop,
                },
            };
            try {
                await window.axios.patch(`/tutor-api/lessons/${lessonId}`, { meta: nextMeta });
                metaRef.current = nextMeta;
                setLessonMeta(nextMeta);
            } catch (e) {
                console.error(e);
            }
        }, 900);
    }, [lessonId]);

    const onLessonMetaPatch = useCallback(
        (patch) => {
            metaRef.current = { ...metaRef.current, ...patch };
            setLessonMeta((prev) => ({ ...prev, ...patch }));
            schedulePersist();
        },
        [schedulePersist],
    );

    const onLessonAgentIdsChange = useCallback(
        (ids) => {
            setAgentIds(ids);
            window.axios.patch(`/tutor-api/lessons/${lessonId}`, { agentIds: ids }).catch((e) => console.error(e));
        },
        [lessonId],
    );

    useEffect(() => {
        return () => clearTimeout(persistTimer.current);
    }, []);

    const patchPlayback = useCallback(
        (patch) => {
            setPlayback((prev) => {
                const next = { ...prev, ...patch };
                playbackRef.current = next;
                return next;
            });
            schedulePersist();
        },
        [schedulePersist],
    );

    useEffect(() => {
        if (sortedScenes.length === 0) {
            return;
        }
        const sid = playbackRef.current.sceneId;
        if (sid && sortedScenes.some((s) => s.id === sid)) {
            return;
        }
        patchPlayback({
            sceneId: sortedScenes[0].id,
            actionIndex: 0,
            isPlaying: false,
        });
    }, [sortedScenes, patchPlayback]);

    const currentScene = useMemo(() => {
        const id = playback.sceneId;
        return sortedScenes.find((s) => s.id === id) ?? sortedScenes[0] ?? null;
    }, [sortedScenes, playback.sceneId]);

    const sceneIndex = useMemo(() => {
        if (!currentScene) {
            return -1;
        }
        return sortedScenes.findIndex((s) => s.id === currentScene.id);
    }, [sortedScenes, currentScene]);

    const actions = useMemo(() => normalizeActions(currentScene?.actions), [currentScene?.actions]);
    const safeActionIndex = useMemo(() => {
        if (actions.length === 0) {
            return 0;
        }
        return Math.min(Math.max(0, playback.actionIndex), actions.length - 1);
    }, [actions.length, playback.actionIndex]);

    const currentAction = actions[safeActionIndex] ?? null;

    const advanceAfterCurrentStep = useCallback(() => {
        const ord = sortScenes(scenesRef.current);
        const p = playbackRef.current;
        const idx = ord.findIndex((s) => s.id === p.sceneId);
        const scene = idx >= 0 ? ord[idx] : ord[0];
        const acts = normalizeActions(scene?.actions);
        const ai = Math.min(Math.max(0, p.actionIndex), Math.max(0, acts.length - 1));
        if (acts.length === 0) {
            patchPlayback({ isPlaying: false });
            return;
        }
        if (ai >= acts.length - 1) {
            if (p.loop) {
                patchPlayback({ actionIndex: 0, isPlaying: true });
            } else if (idx >= 0 && idx < ord.length - 1) {
                const nextScene = ord[idx + 1];
                patchPlayback({ sceneId: nextScene.id, actionIndex: 0, isPlaying: true });
            } else {
                patchPlayback({ isPlaying: false });
            }
        } else {
            patchPlayback({ actionIndex: ai + 1, isPlaying: true });
        }
    }, [patchPlayback]);

    const { audioRef, speechPhase, isSpeech } = useClassroomSpeechAudio({
        sceneId: currentScene?.id,
        actionIndex: safeActionIndex,
        isPlaying: playback.isPlaying,
        currentAction,
        transportSpeed: playback.speed,
        onAdvance: advanceAfterCurrentStep,
        serverTtsAvailable,
    });

    const effectiveSpotlight = useMemo(
        () => getEffectiveSpotlight(currentAction, actions, safeActionIndex),
        [currentAction, actions, safeActionIndex],
    );

    const transcript = useMemo(() => {
        const a = currentAction;
        if (!a) {
            return { headline: 'No steps', body: 'Add teaching actions in Studio to script this lesson.', speaker: null };
        }
        if (a.type === 'speech' || a.type === 'narration') {
            const body = (a.text || a.label || '').trim() || '—';
            return {
                headline: 'Speech',
                body,
                speaker: personaLabel(personas, a.personaId),
            };
        }
        if (a.type === 'spotlight') {
            return {
                headline: 'Spotlight',
                body:
                    a.target?.kind === 'region'
                        ? 'Highlighting a region on the slide.'
                        : `Highlighting canvas element ${a.target?.elementId || '…'}.`,
                speaker: null,
            };
        }
        if (a.type === 'interact') {
            return {
                headline: a.mode === 'quiz_gate' ? 'Quiz gate' : 'Pause',
                body: (a.prompt || 'Take a moment to reflect or discuss.').trim(),
                speaker: null,
            };
        }
        return {
            headline: a.label || a.type,
            body: '—',
            speaker: null,
        };
    }, [currentAction, personas]);

    useEffect(() => {
        if (!playback.isPlaying || !currentScene || actions.length === 0) {
            return undefined;
        }
        const a = actions[safeActionIndex];
        if (a?.type === 'interact') {
            patchPlayback({ isPlaying: false });
            return undefined;
        }
        if (a?.type === 'speech' || a?.type === 'narration') {
            return undefined;
        }
        const base = durationMsForAction(a);
        const ms = Math.max(200, base / playback.speed);
        const tid = window.setTimeout(() => advanceAfterCurrentStep(), ms);
        return () => clearTimeout(tid);
    }, [
        playback.isPlaying,
        playback.speed,
        playback.loop,
        currentScene,
        actions,
        safeActionIndex,
        patchPlayback,
        advanceAfterCurrentStep,
    ]);

    const goPrevScene = () => {
        if (sceneIndex <= 0) {
            return;
        }
        const prev = sortedScenes[sceneIndex - 1];
        patchPlayback({ sceneId: prev.id, actionIndex: 0, isPlaying: false });
    };

    const goNextScene = () => {
        if (sceneIndex < 0 || sceneIndex >= sortedScenes.length - 1) {
            return;
        }
        const next = sortedScenes[sceneIndex + 1];
        patchPlayback({ sceneId: next.id, actionIndex: 0, isPlaying: false });
    };

    const goPrevAction = () => {
        if (safeActionIndex <= 0) {
            return;
        }
        patchPlayback({ actionIndex: safeActionIndex - 1, isPlaying: false });
    };

    const goNextAction = () => {
        if (actions.length === 0 || safeActionIndex >= actions.length - 1) {
            return;
        }
        patchPlayback({ actionIndex: safeActionIndex + 1, isPlaying: false });
    };

    const togglePlay = () => {
        const a = actions[safeActionIndex];
        if (!playback.isPlaying && a?.type === 'interact') {
            if (safeActionIndex < actions.length - 1) {
                patchPlayback({ actionIndex: safeActionIndex + 1, isPlaying: true });
            }
            return;
        }
        patchPlayback({ isPlaying: !playback.isPlaying });
    };

    const wb = currentScene ? normalizeWhiteboard(currentScene.whiteboards) : { paths: [] };

    const title = stage.name || 'Classroom';

    const transcriptActive =
        playback.isPlaying &&
        (isSpeech || speechPhase === 'loading' || speechPhase === 'playing' || speechPhase === 'fallback');

    const railAsideStyle = isLg
        ? { flex: `0 0 ${splitRailPct}%`, minWidth: 260, maxWidth: 'min(70%, 720px)' }
        : undefined;

    return (
        <>
            <Head title={`Classroom · ${title}`} />
            <audio ref={audioRef} className="hidden" playsInline preload="none" />
            <div className="flex h-[100dvh] min-h-0 flex-col bg-zinc-950 text-zinc-50">
                <header className="flex shrink-0 flex-wrap items-center justify-between gap-3 border-b border-zinc-800 px-4 py-3">
                    <div className="min-w-0">
                        <p className="text-xs font-medium uppercase tracking-wide text-indigo-400">Classroom</p>
                        <h1 className="truncate text-lg font-semibold text-white">{title}</h1>
                        {currentScene ? (
                            <p className="truncate text-xs text-zinc-400">
                                Scene {sceneIndex >= 0 ? sceneIndex + 1 : 0}/{sortedScenes.length} ·{' '}
                                {currentScene.title || currentScene.type}
                            </p>
                        ) : null}
                    </div>
                    <div className="flex flex-wrap items-center gap-2 text-sm">
                        {stage.language ? (
                            <span className="rounded-md border border-zinc-700 px-2 py-1 text-xs text-zinc-300">
                                {stage.language}
                            </span>
                        ) : null}
                        <Link
                            href={`/studio/${lessonId}`}
                            className="rounded-lg border border-zinc-600 px-3 py-1.5 text-zinc-200 hover:bg-zinc-800"
                        >
                            Studio
                        </Link>
                        <Link href="/studio" className="rounded-lg border border-zinc-600 px-3 py-1.5 text-zinc-200 hover:bg-zinc-800">
                            All lessons
                        </Link>
                    </div>
                </header>

                <div
                    id="classroom-split-row"
                    className="flex min-h-0 flex-1 flex-col overflow-hidden lg:flex-row lg:items-stretch"
                >
                    <main className="min-h-0 min-w-0 flex-1 overflow-hidden p-2 lg:flex-[1_1_0%] lg:p-4">
                        {currentScene ? (
                            <article className="mx-auto flex h-full min-h-0 w-full max-w-6xl flex-col overflow-hidden rounded-xl border border-zinc-800/80 bg-zinc-900/50 shadow-xl lg:rounded-2xl lg:border-zinc-800 lg:bg-zinc-900">
                                <div
                                    className={`flex min-h-0 flex-1 flex-col overflow-hidden lg:p-2 ${
                                        currentScene.type === 'slide' ? '' : 'overflow-y-auto'
                                    }`}
                                >
                                    <SceneContentView
                                        scene={currentScene}
                                        language={stage.language}
                                        spotlightElementId={effectiveSpotlight.elementId}
                                        spotlightRect={effectiveSpotlight.rect}
                                        theaterMode={currentScene.type === 'slide'}
                                    />
                                </div>
                                {wb.paths.length > 0 ? (
                                    <div className="shrink-0 border-t border-zinc-800 px-3 py-3 lg:px-4">
                                        <p className="text-xs font-semibold text-zinc-400">Whiteboard</p>
                                        <div className="mt-2 max-h-48 overflow-hidden rounded-xl border border-zinc-800 bg-zinc-950">
                                            <svg viewBox="0 0 800 450" className="h-auto w-full">
                                                <rect width="800" height="450" fill="#09090b" />
                                                {wb.paths.map((p) => (
                                                    <path
                                                        key={p.id}
                                                        d={p.d}
                                                        fill="none"
                                                        stroke={p.color || '#fbbf24'}
                                                        strokeWidth={p.strokeWidth || 3}
                                                        strokeLinecap="round"
                                                        strokeLinejoin="round"
                                                    />
                                                ))}
                                            </svg>
                                        </div>
                                    </div>
                                ) : null}
                            </article>
                        ) : (
                            <p className="p-6 text-center text-sm text-zinc-500">This lesson has no scenes yet.</p>
                        )}
                    </main>

                    <div
                        role="separator"
                        aria-orientation="vertical"
                        aria-label="Resize slide and side panel"
                        title="Drag to resize"
                        onMouseDown={onSplitMouseDown}
                        className="hidden shrink-0 cursor-col-resize touch-none bg-zinc-800 hover:bg-indigo-600/70 active:bg-indigo-500 lg:block lg:w-1.5"
                    />

                    <aside
                        className={`flex min-h-0 flex-col overflow-hidden border-t border-zinc-800 bg-zinc-900/95 lg:min-h-0 lg:border-l lg:border-t-0 ${
                            isLg ? '' : 'max-h-[min(48vh,440px)] min-h-[160px] shrink-0'
                        }`}
                        style={railAsideStyle}
                    >
                        <div className="flex min-h-0 min-w-0 flex-1 flex-col lg:flex-row lg:overflow-hidden">
                            <div
                                className={`flex min-h-0 shrink-0 flex-col overflow-y-auto border-zinc-800 p-4 lg:min-h-0 lg:w-1/2 lg:flex-1 lg:border-r ${
                                    transcriptActive ? 'ring-2 ring-inset ring-indigo-500/25' : ''
                                }`}
                            >
                                <p className="text-xs font-semibold uppercase tracking-wide text-zinc-500">Transcript</p>
                                <p className="mt-0.5 text-[10px] text-zinc-600">
                                    Scripted narration and slide spotlight cues from the scene timeline.
                                </p>
                                {!serverTtsAvailable ? (
                                    <p className="mt-2 text-[10px] text-zinc-500">
                                        Server TTS is off — narration uses your browser voice when you press Play.
                                    </p>
                                ) : null}
                                {speechPhase === 'loading' ? (
                                    <p className="mt-2 text-xs text-indigo-300/90">Preparing narration…</p>
                                ) : null}
                                {transcript.speaker ? (
                                    <p className="mt-2 text-sm font-medium text-indigo-300">{transcript.speaker}</p>
                                ) : null}
                                <p className="mt-1 text-xs text-zinc-500">{transcript.headline}</p>
                                <p className="mt-2 whitespace-pre-wrap text-sm leading-relaxed text-zinc-200">
                                    {transcript.body}
                                </p>
                                {actions.length > 0 ? (
                                    <p className="mt-4 text-xs text-zinc-500">
                                        Step {safeActionIndex + 1}/{actions.length}
                                        {playback.isPlaying ? ' · Playing' : ' · Paused'}
                                    </p>
                                ) : null}
                            </div>
                            <StudioChatPanel
                                variant="classroom"
                                className="min-h-0 lg:min-w-0 lg:w-1/2 lg:flex-1"
                                lessonId={lessonId}
                                lessonName={stage.name ?? ''}
                                lessonLanguage={stage.language ?? 'en'}
                                lessonMeta={lessonMeta}
                                onLessonMetaPatch={onLessonMetaPatch}
                                lessonAgentIds={agentIds}
                                onLessonAgentIdsChange={onLessonAgentIdsChange}
                                currentScene={currentScene}
                            />
                        </div>
                    </aside>
                </div>

                <ClassroomScenesDrawer
                    open={scenesDrawerOpen}
                    onClose={() => setScenesDrawerOpen(false)}
                    scenes={sortedScenes}
                    currentSceneId={currentScene?.id}
                    onSelectScene={(id) => patchPlayback({ sceneId: id, actionIndex: 0, isPlaying: false })}
                />

                <footer className="shrink-0 border-t border-zinc-800 bg-zinc-900 px-4 py-3">
                    <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3">
                        <div className="flex flex-wrap items-center gap-2 sm:gap-3">
                            <ClassroomSceneNavButton
                                open={scenesDrawerOpen}
                                onOpen={() => setScenesDrawerOpen(true)}
                                sceneIndex={sceneIndex >= 0 ? sceneIndex : 0}
                                sceneCount={sortedScenes.length}
                                disabled={sortedScenes.length === 0}
                            />
                            <button
                                type="button"
                                onClick={goPrevScene}
                                disabled={sceneIndex <= 0}
                                className="rounded-lg border border-zinc-600 px-3 py-2 text-sm font-medium text-zinc-200 disabled:opacity-40"
                            >
                                Prev scene
                            </button>
                            <button
                                type="button"
                                onClick={goPrevAction}
                                disabled={safeActionIndex <= 0 || actions.length === 0}
                                className="rounded-lg border border-zinc-600 px-3 py-2 text-sm font-medium text-zinc-200 disabled:opacity-40"
                            >
                                Prev step
                            </button>
                            <button
                                type="button"
                                onClick={togglePlay}
                                disabled={!currentScene || actions.length === 0}
                                className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-40"
                            >
                                {!playback.isPlaying && currentAction?.type === 'interact' && safeActionIndex < actions.length - 1
                                    ? 'Continue'
                                    : playback.isPlaying
                                      ? 'Pause'
                                      : 'Play'}
                            </button>
                            <button
                                type="button"
                                onClick={goNextAction}
                                disabled={actions.length === 0 || safeActionIndex >= actions.length - 1}
                                className="rounded-lg border border-zinc-600 px-3 py-2 text-sm font-medium text-zinc-200 disabled:opacity-40"
                            >
                                Next step
                            </button>
                            <button
                                type="button"
                                onClick={goNextScene}
                                disabled={sceneIndex < 0 || sceneIndex >= sortedScenes.length - 1}
                                className="rounded-lg border border-zinc-600 px-3 py-2 text-sm font-medium text-zinc-200 disabled:opacity-40"
                            >
                                Next scene
                            </button>
                        </div>
                        <div className="flex flex-wrap items-center gap-3 text-sm text-zinc-300">
                            <label className="flex items-center gap-2">
                                <span className="text-xs text-zinc-500">Speed</span>
                                <select
                                    className="rounded-md border border-zinc-600 bg-zinc-950 px-2 py-1.5 text-sm"
                                    value={String(playback.speed)}
                                    onChange={(e) => patchPlayback({ speed: Number(e.target.value) || 1 })}
                                >
                                    {[0.5, 1, 1.5, 2].map((s) => (
                                        <option key={s} value={String(s)}>
                                            {s}×
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="flex cursor-pointer items-center gap-2 text-xs">
                                <input
                                    type="checkbox"
                                    checked={playback.loop}
                                    onChange={(e) => patchPlayback({ loop: e.target.checked })}
                                    className="rounded border-zinc-600"
                                />
                                Loop scene
                            </label>
                        </div>
                    </div>
                </footer>
            </div>
        </>
    );
}
