import { useCallback, useEffect, useMemo, useRef } from 'react';

const ACTION_TYPES = [
    { value: 'cue', label: 'Cue' },
    { value: 'narration', label: 'Narration' },
    { value: 'highlight', label: 'Highlight' },
    { value: 'media', label: 'Media' },
];

function normalizeActions(raw) {
    if (!Array.isArray(raw)) {
        return [];
    }
    return raw
        .filter((a) => a && typeof a === 'object')
        .map((a, i) => ({
            id: typeof a.id === 'string' ? a.id : `act-${i}`,
            type: typeof a.type === 'string' ? a.type : 'cue',
            label: typeof a.label === 'string' ? a.label : '',
            narrationUrl: typeof a.narrationUrl === 'string' ? a.narrationUrl : '',
            payload: a.payload && typeof a.payload === 'object' ? a.payload : {},
        }));
}

function newAction() {
    return {
        id: crypto.randomUUID(),
        type: 'cue',
        label: 'New cue',
        narrationUrl: '',
        payload: {},
    };
}

export default function StudioSceneTimeline({
    scene,
    studioMode,
    playbackState,
    onPlaybackPatch,
    onActionsChange,
}) {
    const actions = useMemo(() => normalizeActions(scene?.actions), [scene?.actions]);
    const sceneId = scene?.id;
    const isPlayback = studioMode === 'playback';

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

    const addAction = useCallback(() => {
        onActionsChange([...actions, newAction()]);
    }, [actions, onActionsChange]);

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
        <section className="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-sm font-semibold text-zinc-900">Scene timeline</h2>
                {isPlayback ? (
                    <div className="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            onClick={() => setIndex(actionIndex - 1)}
                            disabled={actionIndex <= 0}
                            className="rounded-lg border border-zinc-300 px-2 py-1 text-xs font-medium disabled:opacity-40"
                        >
                            Prev
                        </button>
                        <button
                            type="button"
                            onClick={togglePlay}
                            disabled={actions.length === 0}
                            className="rounded-lg bg-zinc-900 px-3 py-1 text-xs font-medium text-white disabled:opacity-40"
                        >
                            {isPlaying ? 'Pause' : 'Play'}
                        </button>
                        <button
                            type="button"
                            onClick={() => setIndex(actionIndex + 1)}
                            disabled={actionIndex >= actions.length - 1 || actions.length === 0}
                            className="rounded-lg border border-zinc-300 px-2 py-1 text-xs font-medium disabled:opacity-40"
                        >
                            Next
                        </button>
                        <span className="text-xs text-zinc-500">
                            Step {actions.length ? actionIndex + 1 : 0}/{actions.length}
                        </span>
                    </div>
                ) : (
                    <button
                        type="button"
                        onClick={addAction}
                        className="rounded-lg border border-zinc-300 px-2 py-1 text-xs font-medium text-zinc-700 hover:bg-zinc-50"
                    >
                        Add action
                    </button>
                )}
            </div>

            {actions.length === 0 ? (
                <p className="mt-3 text-sm text-zinc-500">No timeline actions yet. Add cues or narration steps for playback.</p>
            ) : (
                <ol className="mt-3 space-y-2">
                    {actions.map((a, i) => {
                        const active = isPlayback && i === actionIndex;
                        return (
                            <li
                                key={a.id}
                                className={`rounded-lg border px-3 py-2 text-sm ${
                                    active ? 'border-indigo-500 bg-indigo-50' : 'border-zinc-200 bg-zinc-50'
                                }`}
                            >
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <button
                                        type="button"
                                        onClick={() => setIndex(i)}
                                        className="text-left font-medium text-zinc-900 hover:underline"
                                    >
                                        {i + 1}. {a.label || a.type}
                                    </button>
                                    {!isPlayback ? (
                                        <div className="flex gap-1">
                                            <button
                                                type="button"
                                                className="text-xs text-zinc-600 hover:underline"
                                                onClick={() => move(i, -1)}
                                                disabled={i === 0}
                                            >
                                                Up
                                            </button>
                                            <button
                                                type="button"
                                                className="text-xs text-zinc-600 hover:underline"
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
                                {!isPlayback ? (
                                    <div className="mt-2 grid gap-2 sm:grid-cols-2">
                                        <div>
                                            <label className="text-xs text-zinc-500">Label</label>
                                            <input
                                                className="mt-0.5 w-full rounded border border-zinc-300 px-2 py-1 text-xs"
                                                value={a.label}
                                                onChange={(e) => updateAction(i, { label: e.target.value })}
                                            />
                                        </div>
                                        <div>
                                            <label className="text-xs text-zinc-500">Type</label>
                                            <select
                                                className="mt-0.5 w-full rounded border border-zinc-300 px-2 py-1 text-xs"
                                                value={a.type}
                                                onChange={(e) => updateAction(i, { type: e.target.value })}
                                            >
                                                {ACTION_TYPES.map((t) => (
                                                    <option key={t.value} value={t.value}>
                                                        {t.label}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        {a.type === 'narration' ? (
                                            <div className="sm:col-span-2">
                                                <label className="text-xs text-zinc-500">Audio URL (TTS output in Phase 4)</label>
                                                <input
                                                    className="mt-0.5 w-full rounded border border-zinc-300 px-2 py-1 font-mono text-xs"
                                                    placeholder="https://…/clip.mp3"
                                                    value={a.narrationUrl}
                                                    onChange={(e) => updateAction(i, { narrationUrl: e.target.value })}
                                                />
                                            </div>
                                        ) : null}
                                    </div>
                                ) : null}
                                {a.type === 'narration' && a.narrationUrl ? (
                                    <div className="mt-2">
                                        <audio controls className="h-8 w-full max-w-md" src={a.narrationUrl} />
                                    </div>
                                ) : a.type === 'narration' && isPlayback && active ? (
                                    <p className="mt-1 text-xs text-zinc-400">No audio URL — generate TTS in Phase 4.</p>
                                ) : null}
                            </li>
                        );
                    })}
                </ol>
            )}
            {!isPlayback ? (
                <p className="mt-3 text-xs text-zinc-400">
                    Playback mode uses the same steps; state is saved in lesson <code className="text-zinc-500">meta.playbackState</code>.
                </p>
            ) : null}
        </section>
    );
}
