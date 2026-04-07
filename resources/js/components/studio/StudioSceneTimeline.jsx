import { useCallback, useEffect, useMemo, useRef } from 'react';

/** Phase 7.4 canonical types + legacy timeline types */
const TEACHING_TYPES = [
    { value: 'speech', label: 'Speech' },
    { value: 'spotlight', label: 'Spotlight' },
    { value: 'interact', label: 'Interact' },
];

const LEGACY_TYPES = [
    { value: 'cue', label: 'Cue (legacy)' },
    { value: 'narration', label: 'Narration (legacy)' },
    { value: 'highlight', label: 'Highlight (legacy)' },
    { value: 'media', label: 'Media (legacy)' },
];

const ALL_TYPE_OPTIONS = [...TEACHING_TYPES, ...LEGACY_TYPES];

export function collectCanvasElementIds(content) {
    if (!content || typeof content !== 'object') {
        return [];
    }
    const canvas = content.canvas;
    if (!canvas || typeof canvas !== 'object') {
        return [];
    }
    const elements = Array.isArray(canvas.elements) ? canvas.elements : [];
    const ids = [];
    for (const el of elements) {
        if (el && typeof el === 'object' && typeof el.id === 'string' && el.id !== '') {
            ids.push(el.id);
        }
    }
    return ids;
}

function normalizeActions(raw) {
    if (!Array.isArray(raw)) {
        return [];
    }
    return raw
        .filter((a) => a && typeof a === 'object')
        .map((a, i) => {
            const type = typeof a.type === 'string' ? a.type : 'cue';
            const target =
                a.target && typeof a.target === 'object'
                    ? {
                          kind: typeof a.target.kind === 'string' ? a.target.kind : 'element',
                          elementId: typeof a.target.elementId === 'string' ? a.target.elementId : '',
                          rect: a.target.rect && typeof a.target.rect === 'object' ? a.target.rect : undefined,
                      }
                    : { kind: 'element', elementId: '' };
            return {
                id: typeof a.id === 'string' ? a.id : `act-${i}`,
                type,
                label: typeof a.label === 'string' ? a.label : '',
                narrationUrl: typeof a.narrationUrl === 'string' ? a.narrationUrl : '',
                text: typeof a.text === 'string' ? a.text : '',
                personaId: typeof a.personaId === 'string' ? a.personaId : typeof a.payload?.personaId === 'string' ? a.payload.personaId : '',
                mode: typeof a.mode === 'string' ? a.mode : 'pause',
                prompt: typeof a.prompt === 'string' ? a.prompt : '',
                durationMs: typeof a.durationMs === 'number' && !Number.isNaN(a.durationMs) ? a.durationMs : 4000,
                ttsVoice: typeof a.ttsVoice === 'string' ? a.ttsVoice : '',
                ttsSpeed: typeof a.ttsSpeed === 'number' ? a.ttsSpeed : 1,
                target,
                payload: a.payload && typeof a.payload === 'object' ? a.payload : {},
            };
        });
}

function newUuid() {
    return typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID() : `act_${Date.now()}_${Math.random().toString(36).slice(2, 9)}`;
}

function newActionOfType(type) {
    const id = newUuid();
    switch (type) {
        case 'speech':
            return {
                id,
                type: 'speech',
                label: 'Speech',
                text: '',
                personaId: '',
                narrationUrl: '',
                ttsVoice: '',
                ttsSpeed: 1,
                mode: 'pause',
                prompt: '',
                durationMs: 4000,
                target: { kind: 'element', elementId: '' },
                payload: {},
            };
        case 'spotlight':
            return {
                id,
                type: 'spotlight',
                label: 'Spotlight',
                target: { kind: 'element', elementId: '' },
                durationMs: 4000,
                text: '',
                personaId: '',
                narrationUrl: '',
                mode: 'pause',
                prompt: '',
                ttsVoice: '',
                ttsSpeed: 1,
                payload: {},
            };
        case 'interact':
            return {
                id,
                type: 'interact',
                label: 'Pause',
                mode: 'pause',
                prompt: 'Take a moment to reflect or discuss.',
                text: '',
                personaId: '',
                narrationUrl: '',
                durationMs: 4000,
                target: { kind: 'element', elementId: '' },
                ttsVoice: '',
                ttsSpeed: 1,
                payload: {},
            };
        case 'narration':
            return {
                id,
                type: 'narration',
                label: 'Narration',
                narrationUrl: '',
                text: '',
                personaId: '',
                mode: 'pause',
                prompt: '',
                durationMs: 4000,
                target: { kind: 'element', elementId: '' },
                ttsVoice: '',
                ttsSpeed: 1,
                payload: {},
            };
        default:
            return {
                id,
                type: 'cue',
                label: 'New cue',
                narrationUrl: '',
                text: '',
                personaId: '',
                mode: 'pause',
                prompt: '',
                durationMs: 4000,
                target: { kind: 'element', elementId: '' },
                ttsVoice: '',
                ttsSpeed: 1,
                payload: {},
            };
    }
}

export default function StudioSceneTimeline({
    scene,
    studioMode,
    playbackState,
    onPlaybackPatch,
    onActionsChange,
    personas = [],
}) {
    const actions = useMemo(() => normalizeActions(scene?.actions), [scene?.actions]);
    const sceneId = scene?.id;
    const isPlayback = studioMode === 'playback';
    const sceneType = scene?.type ?? 'slide';
    const elementIds = useMemo(() => collectCanvasElementIds(scene?.content), [scene?.content]);

    const personaOptions = useMemo(() => (Array.isArray(personas) ? personas : []).filter((p) => p && typeof p === 'object'), [personas]);

    const actionIndex = useMemo(() => {
        if (!sceneId || playbackState?.sceneId !== sceneId) {
            return 0;
        }
        const i = playbackState.actionIndex;
        return typeof i === 'number' && i >= 0 ? Math.min(i, Math.max(0, actions.length - 1)) : 0;
    }, [playbackState, sceneId, actions.length]);

    const isPlaying =
        isPlayback && playbackState?.sceneId === sceneId && playbackState?.isPlaying === true;

    const tickRef = useRef(null);
    const actionIndexRef = useRef(actionIndex);
    actionIndexRef.current = actionIndex;

    useEffect(() => {
        if (!isPlaying || actions.length === 0) {
            return undefined;
        }
        tickRef.current = window.setInterval(() => {
            const cur = actionIndexRef.current;
            const nextIdx = cur + 1;
            if (nextIdx >= actions.length) {
                onPlaybackPatch({
                    sceneId,
                    actionIndex: Math.max(0, actions.length - 1),
                    isPlaying: false,
                });
            } else {
                onPlaybackPatch({ sceneId, actionIndex: nextIdx, isPlaying: true });
            }
        }, 2200);
        return () => {
            if (tickRef.current) {
                clearInterval(tickRef.current);
            }
        };
    }, [isPlaying, actions.length, sceneId, onPlaybackPatch]);

    /** Pause playback when landing on interact + pause (Phase 7.4 preview). */
    useEffect(() => {
        if (!isPlayback || !sceneId || actions.length === 0) {
            return;
        }
        const a = actions[actionIndex];
        if (a?.type === 'interact' && a.mode === 'pause' && isPlaying) {
            onPlaybackPatch({ sceneId, actionIndex, isPlaying: false });
        }
    }, [actionIndex, actions, isPlayback, isPlaying, onPlaybackPatch, sceneId]);

    const setIndex = useCallback(
        (i) => {
            if (!sceneId) {
                return;
            }
            const clamped = Math.max(0, Math.min(i, Math.max(0, actions.length - 1)));
            onPlaybackPatch({ sceneId, actionIndex: clamped, isPlaying: false });
        },
        [sceneId, actions.length, onPlaybackPatch],
    );

    const togglePlay = useCallback(() => {
        if (!sceneId || actions.length === 0) {
            return;
        }
        onPlaybackPatch({
            sceneId,
            actionIndex,
            isPlaying: !isPlaying,
        });
    }, [sceneId, actions.length, actionIndex, isPlaying, onPlaybackPatch]);

    const updateAction = useCallback(
        (index, patch) => {
            const next = actions.map((a, j) => (j === index ? { ...a, ...patch } : a));
            onActionsChange(next);
        },
        [actions, onActionsChange],
    );

    const patchTarget = useCallback(
        (index, targetPatch) => {
            const a = actions[index];
            if (!a) {
                return;
            }
            updateAction(index, { target: { ...a.target, ...targetPatch } });
        },
        [actions, updateAction],
    );

    const addAction = useCallback(
        (type) => {
            onActionsChange([...actions, newActionOfType(type)]);
        },
        [actions, onActionsChange],
    );

    const removeAction = useCallback(
        (index) => {
            onActionsChange(actions.filter((_, j) => j !== index));
        },
        [actions, onActionsChange],
    );

    const move = useCallback(
        (index, delta) => {
            const j = index + delta;
            if (j < 0 || j >= actions.length) {
                return;
            }
            const next = [...actions];
            [next[index], next[j]] = [next[j], next[index]];
            onActionsChange(next);
        },
        [actions, onActionsChange],
    );

    if (!scene) {
        return null;
    }

    return (
        <section className="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">Teaching timeline</h2>
                    <p className="text-xs text-zinc-500 dark:text-zinc-400">Speech, spotlight, and interact steps (Phase 7.4).</p>
                </div>
                {isPlayback ? (
                    <div className="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            onClick={() => setIndex(actionIndex - 1)}
                            disabled={actionIndex <= 0}
                            className="rounded-lg border border-zinc-300 px-2 py-1 text-xs font-medium disabled:opacity-40 dark:border-zinc-600"
                        >
                            Prev
                        </button>
                        <button
                            type="button"
                            onClick={togglePlay}
                            disabled={actions.length === 0}
                            className="rounded-lg bg-zinc-900 px-3 py-1 text-xs font-medium text-white disabled:opacity-40 dark:bg-zinc-100 dark:text-zinc-900"
                        >
                            {isPlaying ? 'Pause' : 'Play'}
                        </button>
                        <button
                            type="button"
                            onClick={() => setIndex(actionIndex + 1)}
                            disabled={actionIndex >= actions.length - 1 || actions.length === 0}
                            className="rounded-lg border border-zinc-300 px-2 py-1 text-xs font-medium disabled:opacity-40 dark:border-zinc-600"
                        >
                            Next
                        </button>
                        <span className="text-xs text-zinc-500">
                            Step {actions.length ? actionIndex + 1 : 0}/{actions.length}
                        </span>
                    </div>
                ) : (
                    <div className="flex flex-wrap gap-1">
                        {TEACHING_TYPES.map((t) => (
                            <button
                                key={t.value}
                                type="button"
                                onClick={() => addAction(t.value)}
                                className="rounded-lg border border-indigo-200 bg-indigo-50 px-2 py-1 text-xs font-medium text-indigo-900 hover:bg-indigo-100 dark:border-indigo-800 dark:bg-indigo-950/50 dark:text-indigo-100"
                            >
                                + {t.label}
                            </button>
                        ))}
                        <button
                            type="button"
                            onClick={() => addAction('cue')}
                            className="rounded-lg border border-zinc-300 px-2 py-1 text-xs font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-200"
                        >
                            + Cue
                        </button>
                    </div>
                )}
            </div>

            {actions.length === 0 ? (
                <p className="mt-3 text-sm text-zinc-500 dark:text-zinc-400">
                    No steps yet. Add speech (narration script), spotlight (canvas target), or interact (learner pause).
                </p>
            ) : (
                <ol className="mt-3 space-y-3">
                    {actions.map((a, i) => {
                        const active = isPlayback && i === actionIndex;
                        return (
                            <li
                                key={a.id}
                                className={`rounded-lg border px-3 py-2 text-sm ${
                                    active ? 'border-indigo-500 bg-indigo-50 dark:border-indigo-500 dark:bg-indigo-950/40' : 'border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800/50'
                                }`}
                            >
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <button
                                        type="button"
                                        onClick={() => setIndex(i)}
                                        className="text-left font-medium text-zinc-900 hover:underline dark:text-zinc-100"
                                    >
                                        {i + 1}. {a.label || a.type}
                                    </button>
                                    {!isPlayback ? (
                                        <div className="flex gap-1">
                                            <button
                                                type="button"
                                                className="text-xs text-zinc-600 hover:underline dark:text-zinc-400"
                                                onClick={() => move(i, -1)}
                                                disabled={i === 0}
                                            >
                                                Up
                                            </button>
                                            <button
                                                type="button"
                                                className="text-xs text-zinc-600 hover:underline dark:text-zinc-400"
                                                onClick={() => move(i, 1)}
                                                disabled={i === actions.length - 1}
                                            >
                                                Down
                                            </button>
                                            <button
                                                type="button"
                                                className="text-xs text-red-600 hover:underline"
                                                onClick={() => removeAction(i)}
                                            >
                                                Remove
                                            </button>
                                        </div>
                                    ) : null}
                                </div>

                                {isPlayback && active ? (
                                    <div className="mt-2 text-xs text-zinc-600 dark:text-zinc-300">
                                        {a.type === 'speech' || a.type === 'narration' ? (
                                            <p className="whitespace-pre-wrap">{a.text || a.label || '—'}</p>
                                        ) : null}
                                        {a.type === 'spotlight' ? (
                                            <p>
                                                Spotlight → {a.target?.kind === 'region' ? 'region' : `element ${a.target?.elementId || '?'}`}{' '}
                                                {a.durationMs ? `(${a.durationMs} ms)` : ''}
                                            </p>
                                        ) : null}
                                        {a.type === 'interact' ? (
                                            <p className="whitespace-pre-wrap">
                                                <strong>{a.mode === 'quiz_gate' ? 'Quiz gate' : 'Pause'}</strong>
                                                {a.prompt ? `: ${a.prompt}` : ''}
                                            </p>
                                        ) : null}
                                    </div>
                                ) : null}

                                {!isPlayback ? (
                                    <div className="mt-2 grid gap-2 sm:grid-cols-2">
                                        <div>
                                            <label className="text-xs text-zinc-500 dark:text-zinc-400">Label</label>
                                            <input
                                                className="mt-0.5 w-full rounded border border-zinc-300 px-2 py-1 text-xs dark:border-zinc-600 dark:bg-zinc-950 dark:text-zinc-100"
                                                value={a.label}
                                                onChange={(e) => updateAction(i, { label: e.target.value })}
                                            />
                                        </div>
                                        <div>
                                            <label className="text-xs text-zinc-500 dark:text-zinc-400">Type</label>
                                            <select
                                                className="mt-0.5 w-full rounded border border-zinc-300 px-2 py-1 text-xs dark:border-zinc-600 dark:bg-zinc-950 dark:text-zinc-100"
                                                value={a.type}
                                                onChange={(e) => {
                                                    const nv = e.target.value;
                                                    const fresh = newActionOfType(nv);
                                                    fresh.id = a.id;
                                                    onActionsChange(actions.map((x, j) => (j === i ? fresh : x)));
                                                }}
                                            >
                                                {ALL_TYPE_OPTIONS.map((t) => (
                                                    <option key={t.value} value={t.value}>
                                                        {t.label}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>

                                        {(a.type === 'speech' || a.type === 'narration') && (
                                            <>
                                                <div className="sm:col-span-2">
                                                    <label className="text-xs text-zinc-500 dark:text-zinc-400">Script / text</label>
                                                    <textarea
                                                        rows={2}
                                                        className="mt-0.5 w-full rounded border border-zinc-300 px-2 py-1 text-xs dark:border-zinc-600 dark:bg-zinc-950 dark:text-zinc-100"
                                                        value={a.text}
                                                        onChange={(e) => updateAction(i, { text: e.target.value })}
                                                    />
                                                </div>
                                                <div className="sm:col-span-2">
                                                    <label className="text-xs text-zinc-500 dark:text-zinc-400">Persona (optional)</label>
                                                    <select
                                                        className="mt-0.5 w-full rounded border border-zinc-300 px-2 py-1 text-xs dark:border-zinc-600 dark:bg-zinc-950 dark:text-zinc-100"
                                                        value={a.personaId || ''}
                                                        onChange={(e) => updateAction(i, { personaId: e.target.value })}
                                                    >
                                                        <option value="">— None —</option>
                                                        {personaOptions.map((p) => (
                                                            <option key={p.id} value={p.id}>
                                                                {(p.name || p.id) + (p.role ? ` (${p.role})` : '')}
                                                            </option>
                                                        ))}
                                                    </select>
                                                </div>
                                                <div className="sm:col-span-2">
                                                    <label className="text-xs text-zinc-500 dark:text-zinc-400">Audio URL (TTS)</label>
                                                    <input
                                                        className="mt-0.5 w-full rounded border border-zinc-300 px-2 py-1 font-mono text-xs dark:border-zinc-600 dark:bg-zinc-950 dark:text-zinc-100"
                                                        placeholder="https://…/clip.mp3"
                                                        value={a.narrationUrl}
                                                        onChange={(e) => updateAction(i, { narrationUrl: e.target.value })}
                                                    />
                                                </div>
                                            </>
                                        )}

                                        {a.type === 'spotlight' && (
                                            <>
                                                <div>
                                                    <label className="text-xs text-zinc-500 dark:text-zinc-400">Target kind</label>
                                                    <select
                                                        className="mt-0.5 w-full rounded border border-zinc-300 px-2 py-1 text-xs dark:border-zinc-600 dark:bg-zinc-950 dark:text-zinc-100"
                                                        value={a.target?.kind || 'element'}
                                                        onChange={(e) => patchTarget(i, { kind: e.target.value })}
                                                    >
                                                        <option value="element">Canvas element</option>
                                                        <option value="region">Region (rect)</option>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label className="text-xs text-zinc-500 dark:text-zinc-400">Duration (ms)</label>
                                                    <input
                                                        type="number"
                                                        min={500}
                                                        max={120000}
                                                        className="mt-0.5 w-full rounded border border-zinc-300 px-2 py-1 text-xs dark:border-zinc-600 dark:bg-zinc-950 dark:text-zinc-100"
                                                        value={a.durationMs}
                                                        onChange={(e) => updateAction(i, { durationMs: Number(e.target.value) || 4000 })}
                                                    />
                                                </div>
                                                {a.target?.kind === 'element' && sceneType === 'slide' ? (
                                                    <div className="sm:col-span-2">
                                                        <label className="text-xs text-zinc-500 dark:text-zinc-400">Element id</label>
                                                        <select
                                                            className="mt-0.5 w-full rounded border border-zinc-300 px-2 py-1 font-mono text-xs dark:border-zinc-600 dark:bg-zinc-950 dark:text-zinc-100"
                                                            value={a.target?.elementId || ''}
                                                            onChange={(e) => patchTarget(i, { elementId: e.target.value })}
                                                        >
                                                            <option value="">— Select —</option>
                                                            {elementIds.map((id) => (
                                                                <option key={id} value={id}>
                                                                    {id}
                                                                </option>
                                                            ))}
                                                        </select>
                                                        {elementIds.length === 0 ? (
                                                            <p className="mt-1 text-xs text-amber-700 dark:text-amber-400">
                                                                Add text elements on the slide to pick targets.
                                                            </p>
                                                        ) : null}
                                                    </div>
                                                ) : null}
                                                {a.target?.kind === 'region' ? (
                                                    <div className="sm:col-span-2 text-xs text-zinc-500 dark:text-zinc-400">
                                                        Region coordinates can be set in JSON later; classroom player (7.5) will read{' '}
                                                        <code className="text-zinc-600 dark:text-zinc-300">target.rect</code>.
                                                    </div>
                                                ) : null}
                                            </>
                                        )}

                                        {a.type === 'interact' && (
                                            <>
                                                <div>
                                                    <label className="text-xs text-zinc-500 dark:text-zinc-400">Mode</label>
                                                    <select
                                                        className="mt-0.5 w-full rounded border border-zinc-300 px-2 py-1 text-xs dark:border-zinc-600 dark:bg-zinc-950 dark:text-zinc-100"
                                                        value={a.mode}
                                                        onChange={(e) => updateAction(i, { mode: e.target.value })}
                                                    >
                                                        <option value="pause">Pause for learner</option>
                                                        <option value="quiz_gate">Quiz gate</option>
                                                    </select>
                                                </div>
                                                <div className="sm:col-span-2">
                                                    <label className="text-xs text-zinc-500 dark:text-zinc-400">Prompt</label>
                                                    <textarea
                                                        rows={2}
                                                        className="mt-0.5 w-full rounded border border-zinc-300 px-2 py-1 text-xs dark:border-zinc-600 dark:bg-zinc-950 dark:text-zinc-100"
                                                        value={a.prompt}
                                                        onChange={(e) => updateAction(i, { prompt: e.target.value })}
                                                    />
                                                </div>
                                            </>
                                        )}
                                    </div>
                                ) : null}
                                {(a.type === 'narration' || a.type === 'speech') && a.narrationUrl ? (
                                    <div className="mt-2">
                                        <audio controls className="h-8 w-full max-w-md" src={a.narrationUrl} />
                                    </div>
                                ) : null}
                            </li>
                        );
                    })}
                </ol>
            )}
            {!isPlayback ? (
                <p className="mt-3 text-xs text-zinc-400 dark:text-zinc-500">
                    Playback uses lesson <code className="text-zinc-500 dark:text-zinc-400">meta.playbackState</code>. Invalid steps are rejected when saving (e.g. unknown canvas id).
                </p>
            ) : null}
        </section>
    );
}
