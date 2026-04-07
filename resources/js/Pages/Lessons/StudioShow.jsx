import SceneContentEditor, { defaultContentForSceneType } from '../../components/studio/SceneContentEditor.jsx';
import StudioChatPanel from '../../components/studio/StudioChatPanel.jsx';
import { getEffectiveSpotlight, normalizeActions } from '../../lib/teachingActionsPlayback.js';
import StudioClassroomRolesPanel from '../../components/studio/StudioClassroomRolesPanel.jsx';
import StudioMediaStrip from '../../components/studio/StudioMediaStrip.jsx';
import StudioPublishPanel from '../../components/studio/StudioPublishPanel.jsx';
import StudioSceneTimeline from '../../components/studio/StudioSceneTimeline.jsx';
import StudioWhiteboardPanel from '../../components/studio/StudioWhiteboardPanel.jsx';
import { Head, Link, router } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

const SCENE_TYPES = [
    { value: 'slide', label: 'Slide' },
    { value: 'quiz', label: 'Quiz' },
    { value: 'interactive', label: 'Interactive' },
    { value: 'pbl', label: 'PBL' },
];

function sortScenes(list) {
    return [...list].sort((a, b) => (a.order ?? 0) - (b.order ?? 0));
}

export default function StudioShow({ stage, scenes: initialScenes = [] }) {
    const lessonId = stage.id;
    const sortedInitial = useMemo(() => sortScenes(initialScenes), [initialScenes]);

    const [lessonForm, setLessonForm] = useState(() => {
        const firstId = sortedInitial[0]?.id ?? null;
        const ids = stage.agentIds;
        const agentIds = Array.isArray(ids) && ids.length > 0 ? [...ids] : ['tutor'];
        const baseMeta = { ...(stage.meta || {}) };
        const ps = baseMeta.playbackState && typeof baseMeta.playbackState === 'object' ? baseMeta.playbackState : {};
        baseMeta.playbackState = {
            ...ps,
            sceneId: typeof ps.sceneId === 'string' ? ps.sceneId : firstId,
            actionIndex: typeof ps.actionIndex === 'number' ? ps.actionIndex : 0,
            isPlaying: ps.isPlaying === true,
        };
        if (!Array.isArray(baseMeta.mediaLibrary)) {
            baseMeta.mediaLibrary = [];
        }
        return {
            name: stage.name ?? '',
            description: stage.description ?? '',
            language: stage.language ?? '',
            style: stage.style ?? '',
            currentSceneId: stage.currentSceneId ?? firstId,
            agentIds,
            meta: baseMeta,
        };
    });

    const [sceneRows, setSceneRows] = useState(() => sortedInitial);
    const sceneRowsRef = useRef(sceneRows);
    sceneRowsRef.current = sceneRows;
    const [newSceneType, setNewSceneType] = useState('slide');
    const [dirty, setDirty] = useState(false);
    const [saveState, setSaveState] = useState('idle');
    const [adding, setAdding] = useState(false);

    const lessonFormRef = useRef(lessonForm);
    lessonFormRef.current = lessonForm;
    const dirtyRef = useRef(false);
    dirtyRef.current = dirty;

    const lessonSaveTimer = useRef(null);
    const sceneTitleTimers = useRef({});
    const sceneContentTimers = useRef({});
    const sceneActionsTimers = useRef({});
    const sceneWhiteboardTimers = useRef({});
    const savedLabelTimer = useRef(null);
    const prevSceneIdRef = useRef(null);

    const studioMode = lessonForm.meta?.studioMode === 'playback' ? 'playback' : 'autonomous';
    const studioModeRef = useRef(studioMode);
    studioModeRef.current = studioMode;

    const currentScene = useMemo(() => {
        const id = lessonForm.currentSceneId;
        return sceneRows.find((s) => s.id === id) ?? sceneRows[0] ?? null;
    }, [sceneRows, lessonForm.currentSceneId]);

    const playbackStateForSlide = lessonForm.meta?.playbackState;
    const studioActions = useMemo(() => normalizeActions(currentScene?.actions), [currentScene?.actions]);
    const studioSafeActionIndex = useMemo(() => {
        if (studioMode !== 'playback' || !currentScene) {
            return 0;
        }
        if (playbackStateForSlide?.sceneId !== currentScene.id) {
            return 0;
        }
        const i = playbackStateForSlide?.actionIndex;
        if (studioActions.length === 0) {
            return 0;
        }
        return Math.min(Math.max(0, typeof i === 'number' ? i : 0), studioActions.length - 1);
    }, [studioMode, currentScene, playbackStateForSlide, studioActions.length]);

    const studioCurrentAction = studioActions[studioSafeActionIndex] ?? null;
    const studioSpotlight = useMemo(
        () => getEffectiveSpotlight(studioCurrentAction, studioActions, studioSafeActionIndex),
        [studioCurrentAction, studioActions, studioSafeActionIndex],
    );

    const flushLessonSave = useCallback(async () => {
        const f = lessonFormRef.current;
        setSaveState('saving');
        try {
            await window.axios.patch(`/tutor-api/lessons/${lessonId}`, {
                name: f.name,
                description: f.description === '' ? null : f.description,
                language: f.language === '' ? null : f.language,
                style: f.style === '' ? null : f.style,
                currentSceneId: f.currentSceneId,
                agentIds: Array.isArray(f.agentIds) ? f.agentIds : ['tutor'],
                meta: f.meta,
            });
            setSaveState('saved');
            setDirty(false);
            clearTimeout(savedLabelTimer.current);
            savedLabelTimer.current = setTimeout(() => setSaveState('idle'), 2200);
        } catch (e) {
            setSaveState('error');
            window.alert(e.response?.data?.error || e.message || 'Failed to save lesson');
        }
    }, [lessonId]);

    const scheduleLessonSave = useCallback(() => {
        setDirty(true);
        clearTimeout(lessonSaveTimer.current);
        lessonSaveTimer.current = setTimeout(() => {
            flushLessonSave();
        }, 650);
    }, [flushLessonSave]);

    const patchSceneTitle = useCallback(
        (sceneId, title) => {
            clearTimeout(sceneTitleTimers.current[sceneId]);
            sceneTitleTimers.current[sceneId] = setTimeout(async () => {
                try {
                    await window.axios.patch(`/tutor-api/lessons/${lessonId}/scenes/${sceneId}`, { title });
                } catch (e) {
                    window.alert(e.response?.data?.error || e.message || 'Failed to save scene');
                }
            }, 550);
        },
        [lessonId],
    );

    const patchSceneContent = useCallback(
        (sceneId, content) => {
            clearTimeout(sceneContentTimers.current[sceneId]);
            sceneContentTimers.current[sceneId] = setTimeout(async () => {
                try {
                    await window.axios.patch(`/tutor-api/lessons/${lessonId}/scenes/${sceneId}`, { content });
                } catch (e) {
                    window.alert(e.response?.data?.error || e.message || 'Failed to save scene content');
                }
            }, 650);
        },
        [lessonId],
    );

    const onSceneContentChange = useCallback(
        (nextContent) => {
            const id = lessonFormRef.current.currentSceneId;
            if (!id) {
                return;
            }
            setSceneRows((prev) => prev.map((s) => (s.id === id ? { ...s, content: nextContent } : s)));
            patchSceneContent(id, nextContent);
        },
        [patchSceneContent],
    );

    const patchSceneActions = useCallback(
        (sceneId, actions) => {
            clearTimeout(sceneActionsTimers.current[sceneId]);
            sceneActionsTimers.current[sceneId] = setTimeout(async () => {
                try {
                    await window.axios.patch(`/tutor-api/lessons/${lessonId}/scenes/${sceneId}`, { actions });
                } catch (e) {
                    window.alert(e.response?.data?.error || e.message || 'Failed to save scene actions');
                }
            }, 550);
        },
        [lessonId],
    );

    const patchSceneWhiteboard = useCallback(
        (sceneId, whiteboards) => {
            clearTimeout(sceneWhiteboardTimers.current[sceneId]);
            sceneWhiteboardTimers.current[sceneId] = setTimeout(async () => {
                try {
                    await window.axios.patch(`/tutor-api/lessons/${lessonId}/scenes/${sceneId}`, { whiteboards });
                } catch (e) {
                    window.alert(e.response?.data?.error || e.message || 'Failed to save whiteboard');
                }
            }, 550);
        },
        [lessonId],
    );

    const onSceneActionsChange = useCallback(
        (actions) => {
            const id = lessonFormRef.current.currentSceneId;
            if (!id) {
                return;
            }
            setSceneRows((prev) => prev.map((s) => (s.id === id ? { ...s, actions } : s)));
            patchSceneActions(id, actions);
        },
        [patchSceneActions],
    );

    const onSceneWhiteboardChange = useCallback(
        (whiteboards) => {
            const id = lessonFormRef.current.currentSceneId;
            if (!id) {
                return;
            }
            setSceneRows((prev) => prev.map((s) => (s.id === id ? { ...s, whiteboards } : s)));
            patchSceneWhiteboard(id, whiteboards);
        },
        [patchSceneWhiteboard],
    );

    const onPlaybackPatch = useCallback(
        (patch) => {
            setLessonForm((f) => ({
                ...f,
                meta: {
                    ...f.meta,
                    playbackState: { ...(f.meta?.playbackState || {}), ...patch },
                },
            }));
            scheduleLessonSave();
        },
        [scheduleLessonSave],
    );

    useEffect(() => {
        const prev = prevSceneIdRef.current;
        const cur = lessonForm.currentSceneId;
        if (prev && prev !== cur) {
            clearTimeout(sceneContentTimers.current[prev]);
            clearTimeout(sceneActionsTimers.current[prev]);
            clearTimeout(sceneWhiteboardTimers.current[prev]);
            const row = sceneRowsRef.current.find((s) => s.id === prev);
            if (row) {
                const payload = {};
                if (row.content !== undefined) {
                    payload.content = row.content;
                }
                if (row.actions !== undefined) {
                    payload.actions = row.actions;
                }
                if (row.whiteboards !== undefined) {
                    payload.whiteboards = row.whiteboards;
                }
                if (Object.keys(payload).length > 0) {
                    window.axios.patch(`/tutor-api/lessons/${lessonId}/scenes/${prev}`, payload).catch(() => {});
                }
            }
        }
        prevSceneIdRef.current = cur ?? null;
    }, [lessonForm.currentSceneId, lessonId]);

    useEffect(() => {
        const onBeforeUnload = (e) => {
            if (dirtyRef.current) {
                e.preventDefault();
                e.returnValue = '';
            }
        };
        window.addEventListener('beforeunload', onBeforeUnload);
        return () => window.removeEventListener('beforeunload', onBeforeUnload);
    }, []);

    useEffect(() => {
        return router.on('before', () => {
            if (!dirtyRef.current) {
                return;
            }
            if (!window.confirm('You have unsaved changes. Leave this lesson?')) {
                return false;
            }
        });
    }, []);

    useEffect(() => {
        return () => {
            clearTimeout(lessonSaveTimer.current);
            clearTimeout(savedLabelTimer.current);
            Object.values(sceneTitleTimers.current).forEach(clearTimeout);
            Object.values(sceneContentTimers.current).forEach(clearTimeout);
            Object.values(sceneActionsTimers.current).forEach(clearTimeout);
            Object.values(sceneWhiteboardTimers.current).forEach(clearTimeout);
        };
    }, []);

    const updateLessonField = useCallback(
        (patch) => {
            setLessonForm((f) => ({ ...f, ...patch }));
            scheduleLessonSave();
        },
        [scheduleLessonSave],
    );

    const setStudioMode = useCallback(
        (mode) => {
            setLessonForm((f) => ({
                ...f,
                meta: { ...f.meta, studioMode: mode },
            }));
            scheduleLessonSave();
        },
        [scheduleLessonSave],
    );

    const selectScene = useCallback(
        (id) => {
            setLessonForm((f) => {
                if (studioModeRef.current !== 'playback') {
                    return { ...f, currentSceneId: id };
                }
                return {
                    ...f,
                    currentSceneId: id,
                    meta: {
                        ...f.meta,
                        playbackState: {
                            ...(f.meta?.playbackState || {}),
                            sceneId: id,
                            actionIndex: 0,
                            isPlaying: false,
                        },
                    },
                };
            });
            scheduleLessonSave();
        },
        [scheduleLessonSave],
    );

    const moveScene = useCallback(
        async (index, delta) => {
            const ordered = sortScenes(sceneRows);
            const j = index + delta;
            if (j < 0 || j >= ordered.length) {
                return;
            }
            const snapshot = ordered.map((s) => ({ ...s }));
            const next = [...ordered];
            [next[index], next[j]] = [next[j], next[index]];
            const ids = next.map((s) => s.id);
            setSceneRows(next.map((s, i) => ({ ...s, order: i })));
            setDirty(true);
            try {
                const res = await window.axios.post(`/tutor-api/lessons/${lessonId}/scenes/reorder`, { sceneIds: ids });
                if (res.data?.scenes) {
                    setSceneRows(sortScenes(res.data.scenes));
                }
                setDirty(false);
            } catch (e) {
                window.alert(e.response?.data?.error || e.message || 'Reorder failed');
                setSceneRows(snapshot);
                setDirty(false);
            }
        },
        [lessonId, sceneRows],
    );

    const addScene = useCallback(async () => {
        setAdding(true);
        const type = newSceneType;
        const title = `New ${type}`;
        try {
            const res = await window.axios.post(`/tutor-api/lessons/${lessonId}/scenes`, {
                type,
                title,
                content: defaultContentForSceneType(type),
            });
            const scene = res.data?.scene;
            if (!scene) {
                throw new Error('Invalid response');
            }
            setSceneRows((prev) => sortScenes([...prev, scene]));
            const nextForm = { ...lessonFormRef.current, currentSceneId: scene.id };
            setLessonForm((f) => ({ ...f, currentSceneId: scene.id }));
            lessonFormRef.current = nextForm;
            await window.axios.patch(`/tutor-api/lessons/${lessonId}`, { currentSceneId: scene.id });
            setDirty(false);
        } catch (e) {
            window.alert(e.response?.data?.error || e.message || 'Could not add scene');
        } finally {
            setAdding(false);
        }
    }, [lessonId, newSceneType]);

    const removeScene = useCallback(
        async (sceneId) => {
            if (!window.confirm('Delete this scene?')) {
                return;
            }
            try {
                await window.axios.delete(`/tutor-api/lessons/${lessonId}/scenes/${sceneId}`);
            } catch (e) {
                window.alert(e.response?.data?.error || e.message || 'Delete failed');
                return;
            }
            const nextScenes = sceneRows.filter((s) => s.id !== sceneId);
            const newCurrent =
                lessonForm.currentSceneId === sceneId ? nextScenes[0]?.id ?? null : lessonForm.currentSceneId;
            setSceneRows(sortScenes(nextScenes));
            setLessonForm((f) => ({ ...f, currentSceneId: newCurrent }));
            try {
                await window.axios.patch(`/tutor-api/lessons/${lessonId}`, { currentSceneId: newCurrent });
            } catch {
                /* best-effort */
            }
            setDirty(false);
        },
        [lessonId, sceneRows, lessonForm.currentSceneId],
    );

    const onSceneTitleChange = useCallback(
        (sceneId, title) => {
            setSceneRows((prev) => prev.map((s) => (s.id === sceneId ? { ...s, title } : s)));
            patchSceneTitle(sceneId, title);
        },
        [patchSceneTitle],
    );

    const saveNow = useCallback(() => {
        clearTimeout(lessonSaveTimer.current);
        flushLessonSave();
    }, [flushLessonSave]);

    useEffect(() => {
        const onKey = (e) => {
            if (e.target.matches('input, textarea, select')) {
                return;
            }
            const ordered = sortScenes(sceneRows);
            if (ordered.length === 0) {
                return;
            }
            const idx = ordered.findIndex((s) => s.id === lessonForm.currentSceneId);
            if (e.key === 'ArrowDown' || e.key === 'ArrowRight') {
                const next = ordered[Math.min(idx + 1, ordered.length - 1)];
                if (next) {
                    selectScene(next.id);
                }
            }
            if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') {
                const next = ordered[Math.max(idx - 1, 0)];
                if (next) {
                    selectScene(next.id);
                }
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [sceneRows, lessonForm.currentSceneId, selectScene]);

    const saveLabel =
        saveState === 'saving' ? 'Saving…' : saveState === 'saved' ? 'Saved' : saveState === 'error' ? 'Save failed' : dirty ? 'Unsaved' : '';

    const lessonSettingsSection = (
        <section className="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
            <h2 className="text-sm font-semibold text-zinc-900">Lesson</h2>
            <div className="mt-4 grid gap-4 sm:grid-cols-2">
                <div className="sm:col-span-2">
                    <label className="text-xs font-medium text-zinc-600" htmlFor="lesson-name">
                        Title
                    </label>
                    <input
                        id="lesson-name"
                        className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                        value={lessonForm.name}
                        onChange={(e) => updateLessonField({ name: e.target.value })}
                    />
                </div>
                <div className="sm:col-span-2">
                    <label className="text-xs font-medium text-zinc-600" htmlFor="lesson-desc">
                        Description
                    </label>
                    <textarea
                        id="lesson-desc"
                        rows={3}
                        className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                        value={lessonForm.description}
                        onChange={(e) => updateLessonField({ description: e.target.value })}
                    />
                </div>
                <div>
                    <label className="text-xs font-medium text-zinc-600" htmlFor="lesson-lang">
                        Language
                    </label>
                    <input
                        id="lesson-lang"
                        className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                        placeholder="en"
                        value={lessonForm.language}
                        onChange={(e) => updateLessonField({ language: e.target.value })}
                    />
                </div>
                <div>
                    <label className="text-xs font-medium text-zinc-600" htmlFor="lesson-style">
                        Style
                    </label>
                    <input
                        id="lesson-style"
                        className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                        placeholder="e.g. friendly"
                        value={lessonForm.style}
                        onChange={(e) => updateLessonField({ style: e.target.value })}
                    />
                </div>
            </div>
            <p className="mt-4 text-xs text-zinc-400">
                Changes autosave after a short pause. Use arrow keys (when not typing) to switch scenes.
            </p>
        </section>
    );

    const mediaStripSection = (
        <StudioMediaStrip
            items={lessonForm.meta?.mediaLibrary}
            onItemsChange={(next) => {
                setLessonForm((f) => ({ ...f, meta: { ...f.meta, mediaLibrary: next } }));
                scheduleLessonSave();
            }}
        />
    );

    const publishSection = (
        <StudioPublishPanel
            lessonId={lessonId}
            lessonForm={lessonForm}
            sceneRows={sceneRows}
            onMetaPatch={(patch) => {
                setLessonForm((f) => ({ ...f, meta: { ...f.meta, ...patch } }));
                scheduleLessonSave();
            }}
        />
    );

    const classroomRolesSection = (
        <StudioClassroomRolesPanel
            classroomRoles={lessonForm.meta?.classroomRoles}
            onChange={(next) => {
                setLessonForm((f) => ({ ...f, meta: { ...f.meta, classroomRoles: next } }));
                scheduleLessonSave();
            }}
        />
    );

    const sceneTimelineSection = currentScene ? (
        <StudioSceneTimeline
            scene={currentScene}
            studioMode={studioMode}
            playbackState={lessonForm.meta?.playbackState}
            onPlaybackPatch={onPlaybackPatch}
            onActionsChange={onSceneActionsChange}
            personas={lessonForm.meta?.classroomRoles?.personas ?? []}
        />
    ) : null;

    const currentSceneSection = currentScene ? (
        <section className="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="text-sm font-semibold text-zinc-900">Current scene</h2>
                <span className="text-xs text-zinc-500">{currentScene.type}</span>
            </div>
            <div className="mt-4">
                <label className="text-xs font-medium text-zinc-600" htmlFor="scene-title">
                    Scene title
                </label>
                <input
                    id="scene-title"
                    className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                    value={currentScene.title}
                    onChange={(e) => onSceneTitleChange(currentScene.id, e.target.value)}
                />
            </div>
            <div
                className={`mt-4 border-t border-zinc-100 pt-4 ${
                    studioMode === 'playback' && currentScene.type === 'slide'
                        ? 'flex min-h-[min(52vh,520px)] flex-col'
                        : ''
                }`}
            >
                <SceneContentEditor
                    key={currentScene.id}
                    scene={currentScene}
                    onContentChange={onSceneContentChange}
                    language={lessonForm.language}
                    playbackMode={studioMode === 'playback'}
                    spotlightElementId={studioMode === 'playback' ? studioSpotlight.elementId : undefined}
                    spotlightRect={studioMode === 'playback' ? studioSpotlight.rect : undefined}
                    theaterMode={studioMode === 'playback' && currentScene.type === 'slide'}
                />
            </div>
            <div className="mt-6">
                <StudioWhiteboardPanel
                    scene={currentScene}
                    readOnly={studioMode === 'playback'}
                    onWhiteboardChange={onSceneWhiteboardChange}
                />
            </div>
        </section>
    ) : (
        <p className="rounded-xl border border-dashed border-zinc-300 bg-white p-8 text-center text-sm text-zinc-600">Add a scene to begin.</p>
    );

    return (
        <>
            <Head title={`Studio · ${lessonForm.name || 'Lesson'}`} />
            <div className="flex min-h-screen flex-col bg-zinc-100 lg:flex-row">
                <aside className="flex flex-col border-b border-zinc-200 bg-white lg:w-72 lg:shrink-0 lg:border-b-0 lg:border-r">
                    <div className="border-b border-zinc-100 p-4">
                        <p className="text-xs text-zinc-500">
                            <Link href="/studio" className="text-indigo-600 hover:underline">
                                Studio
                            </Link>
                            <span className="mx-1 text-zinc-300">/</span>
                            <Link href="/" className="text-indigo-600 hover:underline">
                                Home
                            </Link>
                            <span className="mx-1 text-zinc-300">·</span>
                            <Link href="/settings" className="text-indigo-600 hover:underline">
                                Settings
                            </Link>
                        </p>
                        <h1 className="mt-2 line-clamp-2 text-lg font-semibold text-zinc-900">{lessonForm.name || 'Untitled'}</h1>
                        <Link
                            href={`/classroom/${lessonId}`}
                            className="mt-2 inline-flex text-xs font-medium text-indigo-600 hover:underline"
                        >
                            Open classroom
                        </Link>
                    </div>

                    <div className="flex flex-1 flex-col gap-2 overflow-y-auto p-3">
                        <p className="text-xs font-semibold uppercase tracking-wide text-zinc-400">Scenes</p>
                        {sceneRows.length === 0 ? (
                            <p className="text-sm text-zinc-500">No scenes yet. Add one below.</p>
                        ) : (
                            <ul className="flex flex-col gap-1">
                                {sortScenes(sceneRows).map((s, index) => {
                                    const active = s.id === lessonForm.currentSceneId;
                                    return (
                                        <li key={s.id}>
                                            <button
                                                type="button"
                                                onClick={() => selectScene(s.id)}
                                                className={`w-full rounded-lg border px-3 py-2 text-left text-sm transition ${
                                                    active
                                                        ? 'border-indigo-500 bg-indigo-50 text-indigo-950'
                                                        : 'border-zinc-200 bg-zinc-50 text-zinc-800 hover:border-zinc-300'
                                                }`}
                                            >
                                                <span className="block font-medium line-clamp-2">{s.title || 'Untitled'}</span>
                                                <span className="text-xs text-zinc-500">
                                                    #{s.order} · {s.type}
                                                </span>
                                            </button>
                                            <div className="mt-1 flex flex-wrap gap-1">
                                                <button
                                                    type="button"
                                                    className="rounded border border-zinc-200 px-2 py-0.5 text-xs text-zinc-600 hover:bg-zinc-100"
                                                    onClick={() => moveScene(index, -1)}
                                                    disabled={index === 0}
                                                >
                                                    Up
                                                </button>
                                                <button
                                                    type="button"
                                                    className="rounded border border-zinc-200 px-2 py-0.5 text-xs text-zinc-600 hover:bg-zinc-100"
                                                    onClick={() => moveScene(index, 1)}
                                                    disabled={index === sceneRows.length - 1}
                                                >
                                                    Down
                                                </button>
                                                <button
                                                    type="button"
                                                    className="rounded border border-red-200 px-2 py-0.5 text-xs text-red-700 hover:bg-red-50"
                                                    onClick={() => removeScene(s.id)}
                                                >
                                                    Delete
                                                </button>
                                            </div>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}

                        <div className="mt-auto border-t border-zinc-100 pt-3">
                            <label className="text-xs font-medium text-zinc-600" htmlFor="new-scene-type">
                                New scene type
                            </label>
                            <div className="mt-1 flex gap-2">
                                <select
                                    id="new-scene-type"
                                    className="flex-1 rounded-lg border border-zinc-300 px-2 py-1.5 text-sm"
                                    value={newSceneType}
                                    onChange={(e) => setNewSceneType(e.target.value)}
                                >
                                    {SCENE_TYPES.map((t) => (
                                        <option key={t.value} value={t.value}>
                                            {t.label}
                                        </option>
                                    ))}
                                </select>
                                <button
                                    type="button"
                                    disabled={adding}
                                    onClick={addScene}
                                    className="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                                >
                                    Add
                                </button>
                            </div>
                        </div>
                    </div>
                </aside>

                <div className="flex min-h-0 min-w-0 flex-1 flex-col lg:flex-row">
                <main className="flex min-h-0 min-w-0 flex-1 flex-col">
                    <header className="border-b border-zinc-200 bg-white px-4 py-3 shadow-sm">
                        <div className="mx-auto flex max-w-3xl flex-wrap items-center justify-between gap-3">
                            <div className="inline-flex rounded-lg border border-zinc-300 bg-zinc-50 p-0.5 text-sm font-medium">
                                <button
                                    type="button"
                                    onClick={() => setStudioMode('autonomous')}
                                    className={`rounded-md px-3 py-1.5 transition ${
                                        studioMode === 'autonomous' ? 'bg-zinc-900 text-white shadow' : 'text-zinc-600 hover:text-zinc-900'
                                    }`}
                                >
                                    Autonomous
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setStudioMode('playback')}
                                    className={`rounded-md px-3 py-1.5 transition ${
                                        studioMode === 'playback' ? 'bg-zinc-900 text-white shadow' : 'text-zinc-600 hover:text-zinc-900'
                                    }`}
                                >
                                    Playback
                                </button>
                            </div>
                            <div className="flex items-center gap-3 text-sm">
                                {saveLabel ? (
                                    <span
                                        className={
                                            saveState === 'error'
                                                ? 'text-red-600'
                                                : saveState === 'saved'
                                                  ? 'text-emerald-600'
                                                  : 'text-zinc-500'
                                        }
                                    >
                                        {saveLabel}
                                    </span>
                                ) : null}
                                <button
                                    type="button"
                                    onClick={saveNow}
                                    className="rounded-lg border border-zinc-300 px-3 py-1.5 font-medium text-zinc-700 hover:bg-zinc-50"
                                >
                                    Save now
                                </button>
                            </div>
                        </div>
                    </header>

                    <div className="flex-1 overflow-y-auto px-4 py-6">
                        <div className="mx-auto max-w-3xl space-y-6">
                            {studioMode === 'playback' ? (
                                <>
                                    <div className="rounded-xl border border-indigo-200 bg-indigo-50/90 px-4 py-3 text-sm text-indigo-950 shadow-sm">
                                        <p className="font-semibold">Playback mode</p>
                                        <p className="mt-1.5 text-xs leading-relaxed text-indigo-950/85">
                                            Your slide or quiz is in <strong>Current scene</strong> below (read-only). Use the{' '}
                                            <strong>scene list on the left</strong> to change scenes, or arrow keys when you are not typing in a field. If this
                                            scene has <strong>actions</strong> (narration steps, etc.), use the timeline controls above the scene to step through
                                            them.                                             For a full-page presentation with transport and transcript, open{' '}
                                            <Link href={`/classroom/${lessonId}`} className="font-semibold text-indigo-700 underline">
                                                Classroom
                                            </Link>
                                            . You can also use <strong>Publish snapshot</strong> or <strong>Download HTML (ZIP)</strong> for sharing.
                                        </p>
                                    </div>
                                    {sceneTimelineSection}
                                    {currentSceneSection}
                                    {lessonSettingsSection}
                                    {classroomRolesSection}
                                    {mediaStripSection}
                                    {publishSection}
                                </>
                            ) : (
                                <>
                                    {lessonSettingsSection}
                                    {classroomRolesSection}
                                    {mediaStripSection}
                                    {publishSection}
                                    {sceneTimelineSection}
                                    {currentSceneSection}
                                </>
                            )}
                        </div>
                    </div>
                </main>
                <StudioChatPanel
                    lessonId={lessonId}
                    lessonName={lessonForm.name}
                    lessonLanguage={lessonForm.language}
                    lessonMeta={lessonForm.meta}
                    onLessonMetaPatch={(patch) => {
                        setLessonForm((f) => ({ ...f, meta: { ...f.meta, ...patch } }));
                        scheduleLessonSave();
                    }}
                    lessonAgentIds={lessonForm.agentIds}
                    onLessonAgentIdsChange={(ids) => updateLessonField({ agentIds: ids })}
                    currentScene={currentScene}
                />
                </div>
            </div>
        </>
    );
}
