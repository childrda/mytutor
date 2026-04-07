import {
    SlideCanvasLayers,
    normalizeSceneContent,
} from '../studio/SceneContentEditor.jsx';
import { useCallback, useEffect, useMemo } from 'react';

function SceneThumb({ scene }) {
    const norm = useMemo(() => normalizeSceneContent(scene.type, scene.content), [scene.type, scene.content]);
    if (scene.type !== 'slide' || norm.type !== 'slide') {
        const label = scene.type === 'quiz' ? 'Quiz' : scene.type === 'interactive' ? 'Embed' : scene.type === 'pbl' ? 'PBL' : '•';
        return (
            <div className="flex h-[3.5rem] w-[4.5rem] shrink-0 items-center justify-center rounded-md border border-zinc-600 bg-zinc-800 text-[10px] font-semibold uppercase tracking-wide text-zinc-400">
                {label}
            </div>
        );
    }
    const c = norm.canvas;
    const TW = 112;
    const TH = 63;
    const s = Math.min(TW / c.width, TH / c.height);
    return (
        <div
            className="relative shrink-0 overflow-hidden rounded-md border border-zinc-600 bg-white shadow-inner"
            style={{ width: TW, height: TH }}
        >
            <div
                style={{
                    width: c.width,
                    height: c.height,
                    transform: `scale(${s})`,
                    transformOrigin: 'top left',
                }}
            >
                <SlideCanvasLayers canvas={c} readOnly />
            </div>
        </div>
    );
}

function WaffleIcon({ className }) {
    return (
        <svg className={className} width="18" height="18" viewBox="0 0 18 18" fill="currentColor" aria-hidden>
            <rect x="1" y="1" width="6" height="6" rx="1" />
            <rect x="11" y="1" width="6" height="6" rx="1" />
            <rect x="1" y="11" width="6" height="6" rx="1" />
            <rect x="11" y="11" width="6" height="6" rx="1" />
        </svg>
    );
}

export function ClassroomSceneNavButton({ open, onOpen, sceneIndex, sceneCount, disabled }) {
    return (
        <div className="flex items-center gap-2 border-zinc-700 sm:border-r sm:pr-3">
            <button
                type="button"
                aria-expanded={open}
                aria-controls="classroom-scenes-drawer"
                aria-label="Open scene list"
                title="Scenes"
                disabled={disabled || sceneCount === 0}
                onClick={onOpen}
                className="rounded-lg border border-zinc-600 p-2 text-zinc-300 hover:bg-zinc-800 disabled:cursor-not-allowed disabled:opacity-40"
            >
                <WaffleIcon className="block" />
            </button>
            <span className="min-w-[3.25rem] text-sm tabular-nums text-zinc-400">
                {sceneCount === 0 ? '—' : `${sceneIndex + 1} / ${sceneCount}`}
            </span>
        </div>
    );
}

/**
 * Slide-out scene list with thumbnails (classroom playback).
 */
export default function ClassroomScenesDrawer({ open, onClose, scenes, currentSceneId, onSelectScene }) {
    const onKey = useCallback(
        (e) => {
            if (e.key === 'Escape') {
                onClose();
            }
        },
        [onClose],
    );

    useEffect(() => {
        if (!open) {
            return undefined;
        }
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [open, onKey]);

    if (!open) {
        return null;
    }

    return (
        <>
            <button
                type="button"
                className="fixed inset-0 z-40 cursor-default bg-black/55 lg:bg-black/40"
                aria-label="Close scene list"
                onClick={onClose}
            />
            <aside
                id="classroom-scenes-drawer"
                className="fixed left-0 top-0 z-50 flex h-full w-[min(20rem,92vw)] flex-col border-r border-zinc-800 bg-zinc-950 shadow-2xl"
                role="dialog"
                aria-modal="true"
                aria-labelledby="classroom-drawer-title"
            >
                <div className="flex items-center justify-between border-b border-zinc-800 px-4 py-3">
                    <div>
                        <h2 id="classroom-drawer-title" className="text-sm font-semibold text-white">
                            Scenes
                        </h2>
                        <p className="text-xs text-zinc-500">Jump to any scene</p>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-md border border-zinc-700 px-2 py-1 text-xs text-zinc-300 hover:bg-zinc-800"
                    >
                        Close
                    </button>
                </div>
                <ul className="min-h-0 flex-1 space-y-1 overflow-y-auto p-2">
                    {scenes.map((s, i) => {
                        const active = s.id === currentSceneId;
                        const title = (s.title || `${s.type} ${i + 1}`).trim();
                        return (
                            <li key={s.id}>
                                <button
                                    type="button"
                                    onClick={() => {
                                        onSelectScene(s.id);
                                        onClose();
                                    }}
                                    className={`flex w-full items-start gap-3 rounded-xl border px-2 py-2 text-left transition ${
                                        active
                                            ? 'border-indigo-500 bg-indigo-950/50 ring-1 ring-indigo-500/40'
                                            : 'border-transparent hover:bg-zinc-900'
                                    }`}
                                >
                                    <span className="mt-1 w-6 shrink-0 text-center text-xs font-bold text-zinc-500">{i + 1}</span>
                                    <SceneThumb scene={s} />
                                    <span className="min-w-0 flex-1 pt-0.5 text-sm font-medium leading-snug text-zinc-100 line-clamp-3">
                                        {title}
                                    </span>
                                </button>
                            </li>
                        );
                    })}
                </ul>
            </aside>
        </>
    );
}
