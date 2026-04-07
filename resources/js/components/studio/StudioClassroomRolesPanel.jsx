import { useCallback, useMemo, useState } from 'react';

const ROLE_OPTIONS = [
    { value: 'teacher', label: 'Teacher' },
    { value: 'assistant', label: 'Assistant' },
    { value: 'student', label: 'Student' },
];

function initials(name) {
    const parts = String(name || '')
        .trim()
        .split(/\s+/)
        .filter(Boolean);
    if (parts.length === 0) {
        return '?';
    }
    if (parts.length === 1) {
        return parts[0].slice(0, 2).toUpperCase();
    }
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

function defaultPersona(role) {
    const id =
        typeof crypto !== 'undefined' && crypto.randomUUID
            ? crypto.randomUUID().replace(/-/g, '').slice(0, 26)
            : `p_${Date.now()}_${Math.random().toString(36).slice(2, 9)}`;
    return {
        id,
        role,
        name: role === 'teacher' ? 'Instructor' : role === 'assistant' ? 'Assistant' : 'Student',
        bio: '',
        accentColor: role === 'teacher' ? '#4F46E5' : role === 'assistant' ? '#0D9488' : '#64748B',
    };
}

export default function StudioClassroomRolesPanel({ classroomRoles, onChange }) {
    const personas = Array.isArray(classroomRoles?.personas) ? classroomRoles.personas : [];
    const [open, setOpen] = useState(personas.length > 0);

    const version = typeof classroomRoles?.version === 'number' ? classroomRoles.version : 1;

    const emit = useCallback(
        (nextPersonas) => {
            onChange({
                version,
                personas: nextPersonas,
            });
        },
        [onChange, version],
    );

    const addPersona = useCallback(() => {
        emit([...personas, defaultPersona('student')]);
    }, [emit, personas]);

    const removePersona = useCallback(
        (idx) => {
            if (personas.length <= 1) {
                return;
            }
            emit(personas.filter((_, i) => i !== idx));
        },
        [emit, personas],
    );

    const patchPersona = useCallback(
        (idx, patch) => {
            emit(personas.map((p, i) => (i === idx ? { ...p, ...patch } : p)));
        },
        [emit, personas],
    );

    const seedDefaults = useCallback(() => {
        emit([
            defaultPersona('teacher'),
            defaultPersona('assistant'),
            { ...defaultPersona('student'), name: 'Alex' },
            { ...defaultPersona('student'), name: 'Jordan', accentColor: '#7C3AED' },
        ]);
        setOpen(true);
    }, [emit]);

    const headerHint = useMemo(() => {
        if (personas.length === 0) {
            return 'Optional roster for classroom playback and narration (Phase 7).';
        }
        return `${personas.length} persona${personas.length === 1 ? '' : 's'} · autosaves with the lesson.`;
    }, [personas.length]);

    return (
        <section className="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
            <button
                type="button"
                onClick={() => setOpen((o) => !o)}
                className="flex w-full items-start justify-between gap-3 text-left"
            >
                <div>
                    <h2 className="text-sm font-semibold text-zinc-900">Classroom roles</h2>
                    <p className="mt-1 text-xs text-zinc-500">{headerHint}</p>
                </div>
                <span className="text-zinc-400">{open ? '▼' : '▶'}</span>
            </button>

            {open ? (
                <div className="mt-4 space-y-4">
                    {personas.length === 0 ? (
                        <div className="rounded-xl border border-dashed border-zinc-300 bg-zinc-50/80 p-4 text-sm text-zinc-600">
                            <p>No personas yet. Add a sample roster or create your own.</p>
                            <div className="mt-3 flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    onClick={seedDefaults}
                                    className="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500"
                                >
                                    Add sample roster
                                </button>
                                <button
                                    type="button"
                                    onClick={() => {
                                        emit([defaultPersona('teacher')]);
                                    }}
                                    className="rounded-lg border border-zinc-300 px-3 py-1.5 text-sm text-zinc-700 hover:bg-zinc-100"
                                >
                                    One blank persona
                                </button>
                            </div>
                        </div>
                    ) : (
                        <ul className="space-y-4">
                            {personas.map((p, idx) => (
                                <li
                                    key={p.id || idx}
                                    className="rounded-xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-700 dark:bg-zinc-900/40"
                                >
                                    <div className="flex flex-wrap items-start gap-3">
                                        <div
                                            className="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full text-sm font-bold text-white shadow-inner"
                                            style={{ backgroundColor: p.accentColor && /^#[0-9A-Fa-f]{6}$/.test(p.accentColor) ? p.accentColor : '#64748B' }}
                                        >
                                            {initials(p.name)}
                                        </div>
                                        <div className="min-w-0 flex-1 space-y-2">
                                            <div className="flex flex-wrap gap-2">
                                                <input
                                                    className="min-w-[8rem] flex-1 rounded-lg border border-zinc-300 px-2 py-1.5 text-sm"
                                                    placeholder="Name"
                                                    value={p.name || ''}
                                                    onChange={(e) => patchPersona(idx, { name: e.target.value })}
                                                />
                                                <select
                                                    className="rounded-lg border border-zinc-300 px-2 py-1.5 text-sm"
                                                    value={p.role || 'student'}
                                                    onChange={(e) => patchPersona(idx, { role: e.target.value })}
                                                >
                                                    {ROLE_OPTIONS.map((o) => (
                                                        <option key={o.value} value={o.value}>
                                                            {o.label}
                                                        </option>
                                                    ))}
                                                </select>
                                                <input
                                                    type="color"
                                                    className="h-9 w-14 cursor-pointer rounded border border-zinc-300 bg-white p-0.5"
                                                    value={/^#[0-9A-Fa-f]{6}$/.test(p.accentColor || '') ? p.accentColor : '#64748B'}
                                                    onChange={(e) => patchPersona(idx, { accentColor: e.target.value.toUpperCase() })}
                                                    title="Accent"
                                                />
                                            </div>
                                            <textarea
                                                rows={2}
                                                className="w-full rounded-lg border border-zinc-300 px-2 py-1.5 text-sm"
                                                placeholder="Short bio"
                                                value={p.bio || ''}
                                                onChange={(e) => patchPersona(idx, { bio: e.target.value })}
                                            />
                                            <div className="flex justify-end">
                                                <button
                                                    type="button"
                                                    disabled={personas.length <= 1}
                                                    onClick={() => removePersona(idx)}
                                                    className="text-xs text-red-600 hover:underline disabled:cursor-not-allowed disabled:opacity-40"
                                                >
                                                    Remove
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}

                    {personas.length > 0 ? (
                        <button
                            type="button"
                            onClick={addPersona}
                            className="rounded-lg border border-dashed border-zinc-300 px-3 py-2 text-sm text-zinc-600 hover:border-zinc-400 hover:bg-zinc-50"
                        >
                            + Add persona
                        </button>
                    ) : null}
                </div>
            ) : null}
        </section>
    );
}
