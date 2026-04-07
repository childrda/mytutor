import { SceneContentView } from '../studio/SceneContentEditor.jsx';
import { normalizeWhiteboard } from '../studio/StudioWhiteboardPanel.jsx';
import { useMemo, useState } from 'react';

const DEFAULT_AGENT_LABELS = {
    tutor: 'Tutor',
    socratic: 'Socratic coach',
    lecturer: 'Lecturer',
};

function sortScenes(list) {
    return [...list].sort((a, b) => (a.order ?? 0) - (b.order ?? 0));
}

function agentLabel(id, meta) {
    const custom = Array.isArray(meta?.generatedAgents)
        ? meta.generatedAgents.find((a) => a?.id === id)
        : null;
    if (custom?.name) {
        return custom.name;
    }
    return DEFAULT_AGENT_LABELS[id] || id;
}

export default function PublicLessonView({ lesson }) {
    const stage = lesson?.stage || {};
    const scenes = useMemo(() => sortScenes(lesson?.scenes || []), [lesson?.scenes]);
    const [currentId, setCurrentId] = useState(() => sortScenes(lesson?.scenes || [])[0]?.id ?? '');

    const current = scenes.find((s) => s.id === currentId) ?? scenes[0] ?? null;

    const agentIds = Array.isArray(stage.agentIds) ? stage.agentIds : [];

    if (scenes.length === 0) {
        return (
            <p className="rounded-xl border border-dashed border-zinc-300 bg-white p-8 text-center text-sm text-zinc-600">
                This lesson has no scenes yet.
            </p>
        );
    }

    return (
        <div className="flex min-h-[70vh] flex-col gap-6 lg:flex-row">
            <nav className="lg:w-64 lg:shrink-0">
                <p className="text-xs font-semibold uppercase tracking-wide text-zinc-400">Scenes</p>
                <ul className="mt-2 space-y-1">
                    {scenes.map((s, i) => (
                        <li key={s.id}>
                            <button
                                type="button"
                                onClick={() => setCurrentId(s.id)}
                                className={`w-full rounded-lg border px-3 py-2 text-left text-sm transition ${
                                    current?.id === s.id
                                        ? 'border-indigo-500 bg-indigo-50 text-indigo-950'
                                        : 'border-zinc-200 bg-white text-zinc-800 hover:border-zinc-300'
                                }`}
                            >
                                <span className="font-medium">{s.title || `Scene ${i + 1}`}</span>
                                <span className="mt-0.5 block text-xs text-zinc-500">{s.type}</span>
                            </button>
                        </li>
                    ))}
                </ul>
                <div className="mt-4 lg:hidden">
                    <label className="text-xs text-zinc-500" htmlFor="pub-scene">
                        Jump to scene
                    </label>
                    <select
                        id="pub-scene"
                        className="mt-1 w-full rounded-lg border border-zinc-300 px-2 py-2 text-sm"
                        value={current?.id}
                        onChange={(e) => setCurrentId(e.target.value)}
                    >
                        {scenes.map((s, i) => (
                            <option key={s.id} value={s.id}>
                                {i + 1}. {s.title || s.type}
                            </option>
                        ))}
                    </select>
                </div>
            </nav>

            <div className="min-w-0 flex-1 space-y-4">
                {agentIds.length > 0 ? (
                    <div className="flex flex-wrap items-center gap-2 rounded-xl border border-zinc-200 bg-white px-3 py-2">
                        <span className="text-xs font-medium text-zinc-500">Agents</span>
                        {agentIds.map((id) => (
                            <span
                                key={id}
                                className="rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-700"
                            >
                                {agentLabel(id, stage.meta)}
                            </span>
                        ))}
                    </div>
                ) : null}

                {current ? (
                    <article className="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                        <header className="border-b border-zinc-100 pb-4">
                            <p className="text-xs uppercase tracking-wide text-zinc-400">{current.type}</p>
                            <h2 className="mt-1 text-xl font-semibold text-zinc-900">{current.title || 'Scene'}</h2>
                        </header>
                        <div className="pt-4">
                            <SceneContentView scene={current} language={stage.language} />
                        </div>
                        {(() => {
                            const wb = normalizeWhiteboard(current.whiteboards);
                            if (!wb.paths.length) {
                                return null;
                            }
                            return (
                                <div className="mt-6 border-t border-zinc-100 pt-4">
                                    <p className="text-xs font-semibold text-zinc-600">Whiteboard</p>
                                    <div className="mt-2 overflow-hidden rounded-xl border border-zinc-200 bg-zinc-50">
                                        <svg viewBox="0 0 800 450" className="h-auto w-full">
                                            <rect width="800" height="450" fill="#fafafa" />
                                            {wb.paths.map((p) => (
                                                <path
                                                    key={p.id}
                                                    d={p.d}
                                                    fill="none"
                                                    stroke={p.color || '#000'}
                                                    strokeWidth={p.strokeWidth || 3}
                                                    strokeLinecap="round"
                                                    strokeLinejoin="round"
                                                />
                                            ))}
                                        </svg>
                                    </div>
                                </div>
                            );
                        })()}
                    </article>
                ) : null}
            </div>
        </div>
    );
}
