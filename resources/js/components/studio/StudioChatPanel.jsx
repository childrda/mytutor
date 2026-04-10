import { streamTutorChat } from '../../lib/tutorChatSse.js';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

const SESSION_TYPES = [
    { value: 'qa', label: 'Q&A' },
    { value: 'discussion', label: 'Discussion' },
    { value: 'lecture', label: 'Lecture' },
];

const DEFAULT_AGENTS = [
    { id: 'tutor', name: 'Tutor', color: '#4f46e5' },
    { id: 'socratic', name: 'Socratic coach', color: '#059669' },
    { id: 'lecturer', name: 'Lecturer', color: '#b45309' },
];

function slugify(name) {
    const s = name
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '');
    return s || 'agent';
}

const MAX_SCENE_SUMMARY_CHARS = 3200;

function summarizeContent(content) {
    if (!content || typeof content !== 'object') {
        return '';
    }
    try {
        const s = JSON.stringify(content);
        return s.length > 2400 ? `${s.slice(0, 2400)}…` : s;
    } catch {
        return '';
    }
}

function pushLine(lines, line) {
    const t = typeof line === 'string' ? line.trim() : '';
    if (t !== '') {
        lines.push(t);
    }
}

/** Human-readable digest of scene content so the model can ground vague questions (e.g. "help me"). */
function summarizeSceneContentForChat(scene) {
    if (!scene?.content || typeof scene.content !== 'object') {
        return summarizeContent(scene?.content);
    }
    const sceneType = typeof scene.type === 'string' ? scene.type : '';
    const c = scene.content;
    const contentType = typeof c.type === 'string' ? c.type : '';

    if (sceneType === 'slide' || contentType === 'slide' || (c.canvas && typeof c.canvas === 'object')) {
        const lines = [];
        const canvas = c.canvas && typeof c.canvas === 'object' ? c.canvas : {};
        const title = typeof canvas.title === 'string' ? canvas.title.trim() : '';
        const subtitle = typeof canvas.subtitle === 'string' ? canvas.subtitle.trim() : '';
        const footer = typeof canvas.footer === 'string' ? canvas.footer.trim() : '';
        pushLine(lines, title ? `Slide title: ${title}` : 'Slide (untitled)');
        pushLine(lines, subtitle ? `Subtitle: ${subtitle}` : '');
        pushLine(lines, footer ? `Footer: ${footer}` : '');
        const elements = Array.isArray(canvas.elements) ? canvas.elements : [];
        if (elements.length > 0) {
            lines.push('Slide elements (layout order; spotlight may emphasize one):');
        }
        let n = 0;
        for (const el of elements) {
            if (!el || typeof el !== 'object') {
                continue;
            }
            n += 1;
            const id = typeof el.id === 'string' && el.id ? el.id : '?';
            const et = typeof el.type === 'string' ? el.type : '';
            if (et === 'card') {
                const parts = [`  • Card #${n} [id=${id}]`];
                if (el.title) {
                    parts.push(String(el.title));
                }
                if (el.body) {
                    parts.push(String(el.body));
                }
                if (Array.isArray(el.bullets)) {
                    for (const b of el.bullets.slice(0, 8)) {
                        if (typeof b === 'string' && b.trim()) {
                            parts.push(`    – ${b.trim()}`);
                        }
                    }
                }
                if (el.caption) {
                    parts.push(`    Caption: ${el.caption}`);
                }
                lines.push(parts.join('\n'));
            } else if (et === 'image') {
                const alt = typeof el.alt === 'string' && el.alt.trim() ? el.alt.trim() : '';
                lines.push(`  • Image #${n} [id=${id}]${alt ? `: ${alt}` : ''}`);
            } else {
                const tx = typeof el.text === 'string' ? el.text.trim() : '';
                if (tx) {
                    lines.push(`  • Text #${n} [id=${id}]: ${tx}`);
                }
            }
        }
        let out = lines.filter(Boolean).join('\n');
        if (out.length > MAX_SCENE_SUMMARY_CHARS) {
            out = `${out.slice(0, MAX_SCENE_SUMMARY_CHARS)}…`;
        }
        return out || summarizeContent(c);
    }

    if (sceneType === 'quiz' || contentType === 'quiz') {
        const qs = Array.isArray(c.questions) ? c.questions : [];
        const lines = ['Quiz scene:'];
        qs.slice(0, 24).forEach((q, idx) => {
            if (!q || typeof q !== 'object') {
                return;
            }
            const prompt = typeof q.prompt === 'string' ? q.prompt.trim() : '';
            pushLine(lines, prompt ? `  Q${idx + 1}: ${prompt}` : `  Q${idx + 1}`);
            const opts = Array.isArray(q.options) ? q.options : [];
            opts.slice(0, 10).forEach((o) => {
                const lab = typeof o?.label === 'string' ? o.label.trim() : '';
                if (lab) {
                    lines.push(`    – ${lab}`);
                }
            });
        });
        let out = lines.join('\n');
        if (out.length > MAX_SCENE_SUMMARY_CHARS) {
            out = `${out.slice(0, MAX_SCENE_SUMMARY_CHARS)}…`;
        }
        return out || summarizeContent(c);
    }

    if (sceneType === 'interactive' || contentType === 'interactive') {
        const t = typeof c.title === 'string' ? c.title.trim() : '';
        const lines = [];
        pushLine(lines, t ? `Interactive activity: ${t}` : 'Interactive embed');
        let out = lines.join('\n');
        if (out.length > MAX_SCENE_SUMMARY_CHARS) {
            out = `${out.slice(0, MAX_SCENE_SUMMARY_CHARS)}…`;
        }
        return out || summarizeContent(c);
    }

    if (sceneType === 'pbl' || contentType === 'pbl') {
        const pc = c.projectConfig && typeof c.projectConfig === 'object' ? c.projectConfig : {};
        const lines = [];
        if (typeof c.brief === 'string' && c.brief.trim()) {
            pushLine(lines, `PBL brief: ${c.brief.trim().slice(0, 700)}`);
        }
        if (typeof pc.title === 'string' && pc.title.trim()) {
            pushLine(lines, `Project title: ${pc.title.trim()}`);
        }
        if (typeof pc.objective === 'string' && pc.objective.trim()) {
            pushLine(lines, `Objective: ${pc.objective.trim().slice(0, 500)}`);
        }
        let out = lines.filter(Boolean).join('\n');
        if (out.length > MAX_SCENE_SUMMARY_CHARS) {
            out = `${out.slice(0, MAX_SCENE_SUMMARY_CHARS)}…`;
        }
        return out || summarizeContent(c);
    }

    return summarizeContent(c);
}

function buildStoreState({
    lessonId,
    lessonName,
    scene,
    sessionType,
    language,
    scenePosition,
    teachingProgress,
}) {
    const state = {
        version: 1,
        lessonId,
        lessonName,
        sessionType,
        language: language || 'en',
        scene: scene
            ? {
                  id: scene.id,
                  title: scene.title,
                  type: scene.type,
                  contentSummary: summarizeSceneContentForChat(scene),
              }
            : null,
    };
    if (
        scenePosition &&
        typeof scenePosition.index === 'number' &&
        Number.isFinite(scenePosition.index) &&
        typeof scenePosition.total === 'number' &&
        Number.isFinite(scenePosition.total) &&
        scenePosition.total > 0
    ) {
        const total = Math.min(10_000, Math.floor(scenePosition.total));
        let index = Math.max(0, Math.floor(scenePosition.index));
        if (total > 0) {
            index = Math.min(index, total - 1);
        }
        state.scenePosition = { index, total };
    }
    if (teachingProgress && typeof teachingProgress === 'object') {
        const tp = { ...teachingProgress };
        if (typeof tp.stepCount === 'number' && Number.isFinite(tp.stepCount)) {
            const stepCount = Math.min(500, Math.max(1, Math.floor(tp.stepCount)));
            let stepIndex = 0;
            if (typeof tp.stepIndex === 'number' && Number.isFinite(tp.stepIndex)) {
                stepIndex = Math.min(Math.max(0, Math.floor(tp.stepIndex)), stepCount - 1);
            }
            tp.stepIndex = stepIndex;
            tp.stepCount = stepCount;
            if (typeof tp.transcriptHeadline === 'string') {
                tp.transcriptHeadline = tp.transcriptHeadline.trim().slice(0, 128);
            }
            if (typeof tp.transcriptSnippet === 'string') {
                tp.transcriptSnippet = tp.transcriptSnippet.trim().slice(0, 600);
            }
            if (typeof tp.spotlightElementId === 'string') {
                tp.spotlightElementId = tp.spotlightElementId.trim().slice(0, 128);
            }
            if (typeof tp.spotlightSummary === 'string') {
                tp.spotlightSummary = tp.spotlightSummary.trim().slice(0, 600);
            }
            state.teachingProgress = tp;
        }
    }
    return state;
}

function agentById(registry, id) {
    return registry.find((a) => a.id === id);
}

export default function StudioChatPanel({
    variant = 'studio',
    className = '',
    lessonId,
    lessonName,
    lessonLanguage,
    lessonMeta,
    onLessonMetaPatch,
    lessonAgentIds,
    onLessonAgentIdsChange,
    currentScene,
    /** @type {{ index: number, total: number } | null | undefined} */
    scenePosition,
    /** @type {Record<string, unknown> | null | undefined} */
    teachingProgress,
}) {
    const isClassroom = variant === 'classroom';
    const [sessionType, setSessionType] = useState('qa');
    const [messages, setMessages] = useState([]);
    const [composer, setComposer] = useState('');
    const [attachments, setAttachments] = useState([]);
    const [directorState, setDirectorState] = useState({
        turnCount: 0,
        agentResponses: [],
        whiteboardLedger: [],
    });
    const [status, setStatus] = useState('');
    const [error, setError] = useState('');
    const abortRef = useRef(null);
    const listEndRef = useRef(null);
    const streamingMsgIdRef = useRef(null);

    const generatedAgents = useMemo(() => {
        const g = lessonMeta?.generatedAgents;
        return Array.isArray(g) ? g.filter((a) => a && typeof a.id === 'string' && typeof a.name === 'string') : [];
    }, [lessonMeta]);

    const agentRegistry = useMemo(() => {
        const seen = new Set();
        const out = [];
        for (const a of [...DEFAULT_AGENTS, ...generatedAgents]) {
            if (seen.has(a.id)) {
                continue;
            }
            seen.add(a.id);
            out.push({
                ...a,
                color: typeof a.color === 'string' ? a.color : '#64748b',
            });
        }
        return out;
    }, [generatedAgents]);

    const selectedAgentIds = useMemo(() => {
        const raw = lessonAgentIds;
        const fromLesson = Array.isArray(raw) && raw.length > 0 ? raw : ['tutor'];
        const valid = fromLesson.filter((id) => agentRegistry.some((a) => a.id === id));
        if (valid.length > 0) {
            return valid;
        }
        const fallback = agentRegistry[0]?.id || 'tutor';
        return [fallback];
    }, [lessonAgentIds, agentRegistry]);

    const toggleAgent = useCallback(
        (id) => {
            const set = new Set(selectedAgentIds);
            if (set.has(id)) {
                if (set.size <= 1) {
                    return;
                }
                set.delete(id);
            } else {
                set.add(id);
            }
            onLessonAgentIdsChange([...set]);
        },
        [selectedAgentIds, onLessonAgentIdsChange],
    );

    const addGeneratedAgent = useCallback(() => {
        const name = window.prompt('Agent display name');
        if (!name || !name.trim()) {
            return;
        }
        const base = slugify(name);
        let id = base;
        let n = 1;
        while (agentRegistry.some((a) => a.id === id)) {
            id = `${base}-${n++}`;
        }
        const next = [...generatedAgents, { id, name: name.trim(), color: '#7c3aed' }];
        onLessonMetaPatch({ generatedAgents: next });
    }, [generatedAgents, agentRegistry, onLessonMetaPatch]);

    const removeGeneratedAgent = useCallback(
        (id) => {
            if (!window.confirm('Remove this custom agent?')) {
                return;
            }
            onLessonMetaPatch({ generatedAgents: generatedAgents.filter((a) => a.id !== id) });
            onLessonAgentIdsChange(selectedAgentIds.filter((x) => x !== id));
        },
        [generatedAgents, onLessonMetaPatch, onLessonAgentIdsChange, selectedAgentIds],
    );

    const stopStream = useCallback(() => {
        abortRef.current?.abort();
        abortRef.current = null;
        setStatus('');
    }, []);

    useEffect(() => {
        listEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages, status]);

    const send = useCallback(async () => {
        const text = composer.trim();
        if (!text && attachments.length === 0) {
            return;
        }

        const attachNote =
            attachments.length > 0
                ? `\n[Attachments: ${attachments.map((a) => a.name).join(', ')} — file bytes not sent to the model in this phase]`
                : '';
        const baseText = text || (attachments.length ? '(see attached files)' : '');
        const fullText = baseText + attachNote;
        const userMessage = {
            id: crypto.randomUUID(),
            role: 'user',
            content: fullText,
            parts: [{ type: 'text', text: fullText }],
            attachmentNames: attachments.map((a) => a.name),
        };

        setMessages((m) => [...m, userMessage]);
        setComposer('');
        setAttachments([]);
        setError('');
        setStatus('Connecting…');

        const controller = new AbortController();
        abortRef.current = controller;

        const uiMessages = [...messages, userMessage].map((msg) => {
            if (msg.parts && Array.isArray(msg.parts)) {
                return { role: msg.role, parts: msg.parts };
            }
            return { role: msg.role, content: msg.content };
        });

        const body = {
            messages: uiMessages,
            storeState: buildStoreState({
                lessonId,
                lessonName,
                scene: currentScene,
                sessionType,
                language: lessonLanguage,
                scenePosition,
                teachingProgress,
            }),
            config: {
                agentIds: selectedAgentIds,
                sessionType,
            },
            directorState,
            requiresApiKey: true,
        };

        try {
            await streamTutorChat({
                url: '/api/chat',
                body,
                signal: controller.signal,
                onEvent: (evt) => {
                    const { type, data } = evt;
                    if (type === 'thinking') {
                        setStatus('Planning…');
                        return;
                    }
                    if (type === 'agent_start') {
                        setStatus(`${data?.agentName || 'Assistant'} is typing…`);
                        const mid = data?.messageId || crypto.randomUUID();
                        streamingMsgIdRef.current = mid;
                        setMessages((m) => [
                            ...m,
                            {
                                id: mid,
                                role: 'assistant',
                                content: '',
                                agentId: data?.agentId,
                                agentName: data?.agentName,
                                streaming: true,
                                actions: [],
                            },
                        ]);
                        return;
                    }
                    if (type === 'text_delta' && data?.messageId) {
                        const chunk = typeof data.content === 'string' ? data.content : '';
                        setMessages((m) =>
                            m.map((msg) =>
                                msg.id === data.messageId ? { ...msg, content: msg.content + chunk } : msg,
                            ),
                        );
                        return;
                    }
                    if (type === 'action') {
                        const mid = streamingMsgIdRef.current;
                        if (mid && data) {
                            setMessages((m) =>
                                m.map((msg) =>
                                    msg.id === mid
                                        ? { ...msg, actions: [...(msg.actions || []), data] }
                                        : msg,
                                ),
                            );
                        }
                        return;
                    }
                    if (type === 'agent_end') {
                        setMessages((m) =>
                            m.map((msg) =>
                                msg.id === data?.messageId ? { ...msg, streaming: false } : msg,
                            ),
                        );
                        return;
                    }
                    if (type === 'done') {
                        setStatus('');
                        if (data?.directorState && typeof data.directorState === 'object') {
                            setDirectorState((prev) => ({
                                ...prev,
                                ...data.directorState,
                                turnCount: (prev.turnCount || 0) + 1,
                            }));
                        }
                        return;
                    }
                    if (type === 'error') {
                        setError(typeof data?.message === 'string' ? data.message : 'Stream error');
                        setStatus('');
                    }
                },
            });
        } catch (e) {
            if (e.name === 'AbortError') {
                setError('Stopped');
            } else {
                setError(e.message || 'Request failed');
            }
            setStatus('');
        } finally {
            abortRef.current = null;
            streamingMsgIdRef.current = null;
        }
    }, [
        composer,
        attachments,
        messages,
        lessonId,
        lessonName,
        currentScene,
        sessionType,
        lessonLanguage,
        directorState,
        selectedAgentIds,
        scenePosition,
        teachingProgress,
    ]);

    const onPickFiles = (ev) => {
        const files = [...(ev.target.files || [])];
        ev.target.value = '';
        setAttachments((a) => [
            ...a,
            ...files.map((f) => ({
                name: f.name,
                size: f.size,
            })),
        ]);
    };

    const shellAside = isClassroom
        ? `flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden border-t border-zinc-800 bg-zinc-950 text-zinc-100 lg:border-l lg:border-t-0`
        : `flex max-h-[50vh] flex-col border-t border-zinc-200 bg-white lg:max-h-none lg:w-[22rem] lg:shrink-0 lg:border-l lg:border-t-0`;

    return (
        <aside className={`${shellAside} ${className}`.trim()}>
            <div className={`border-b px-3 py-2 ${isClassroom ? 'border-zinc-800' : 'border-zinc-100'}`}>
                <h2 className={`text-sm font-semibold ${isClassroom ? 'text-zinc-100' : 'text-zinc-900'}`}>Agents & chat</h2>
                <p className={`text-xs ${isClassroom ? 'text-zinc-500' : 'text-zinc-500'}`}>Streaming via POST /api/chat (SSE)</p>
            </div>

            <div
                className={`max-h-40 overflow-y-auto border-b px-3 py-2 ${isClassroom ? 'border-zinc-800' : 'border-zinc-100'}`}
            >
                <p className={`text-xs font-medium ${isClassroom ? 'text-zinc-400' : 'text-zinc-600'}`}>Session</p>
                <select
                    className={`mt-1 w-full rounded-lg border px-2 py-1.5 text-sm ${
                        isClassroom
                            ? 'border-zinc-700 bg-zinc-900 text-zinc-100'
                            : 'border-zinc-300 bg-white text-zinc-900'
                    }`}
                    value={sessionType}
                    onChange={(e) => setSessionType(e.target.value)}
                >
                    {SESSION_TYPES.map((s) => (
                        <option key={s.value} value={s.value}>
                            {s.label}
                        </option>
                    ))}
                </select>
                <p className={`mt-2 text-xs font-medium ${isClassroom ? 'text-zinc-400' : 'text-zinc-600'}`}>Active agents</p>
                <ul className="mt-1 space-y-1">
                    {agentRegistry.map((a) => {
                        const custom = generatedAgents.some((g) => g.id === a.id);
                        return (
                            <li key={a.id} className="flex items-center gap-2 text-sm">
                                <label className="flex flex-1 cursor-pointer items-center gap-2">
                                    <input
                                        type="checkbox"
                                        checked={selectedAgentIds.includes(a.id)}
                                        onChange={() => toggleAgent(a.id)}
                                        className={`rounded ${isClassroom ? 'border-zinc-600' : 'border-zinc-300'}`}
                                    />
                                    <span
                                        className="h-6 w-6 shrink-0 rounded-full"
                                        style={{ backgroundColor: a.color }}
                                        title={a.name}
                                    />
                                    <span className={`truncate ${isClassroom ? 'text-zinc-200' : 'text-zinc-800'}`}>{a.name}</span>
                                </label>
                                {custom ? (
                                    <button
                                        type="button"
                                        className="text-xs text-red-400 hover:underline"
                                        onClick={() => removeGeneratedAgent(a.id)}
                                    >
                                        ×
                                    </button>
                                ) : null}
                            </li>
                        );
                    })}
                </ul>
                <button
                    type="button"
                    onClick={addGeneratedAgent}
                    className={`mt-2 text-xs font-medium hover:underline ${isClassroom ? 'text-indigo-400' : 'text-indigo-600'}`}
                >
                    + Add custom agent
                </button>
                <p className={`mt-2 text-xs ${isClassroom ? 'text-zinc-500' : 'text-zinc-400'}`}>
                    Custom agents are stored in lesson meta (server). Lesson{' '}
                    <code className={isClassroom ? 'text-zinc-400' : 'text-zinc-500'}>agentIds</code> sync with your selection.
                </p>
            </div>

            <div className="min-h-0 flex-1 overflow-y-auto px-3 py-2">
                {messages.length === 0 ? (
                    <p className={`text-sm ${isClassroom ? 'text-zinc-500' : 'text-zinc-500'}`}>
                        Ask about this lesson or scene. Messages include scene context.
                    </p>
                ) : (
                    <ul className="space-y-3">
                        {messages.map((msg) => {
                            const agent =
                                msg.role === 'assistant' && msg.agentId
                                    ? agentById(agentRegistry, msg.agentId)
                                    : null;
                            return (
                                <li
                                    key={msg.id}
                                    className={`rounded-lg px-2 py-2 text-sm ${
                                        msg.role === 'user'
                                            ? isClassroom
                                                ? 'ml-4 bg-indigo-950/80 text-indigo-100 ring-1 ring-indigo-800/50'
                                                : 'ml-4 bg-indigo-50 text-indigo-950'
                                            : isClassroom
                                              ? 'mr-4 bg-zinc-800/90 text-zinc-100'
                                              : 'mr-4 bg-zinc-100 text-zinc-900'
                                    }`}
                                >
                                    {msg.role === 'assistant' ? (
                                        <div
                                            className={`mb-1 flex items-center gap-2 text-xs ${isClassroom ? 'text-zinc-400' : 'text-zinc-500'}`}
                                        >
                                            <span
                                                className="h-4 w-4 rounded-full"
                                                style={{ backgroundColor: agent?.color || '#64748b' }}
                                            />
                                            <span>{msg.agentName || agent?.name || msg.agentId || 'Assistant'}</span>
                                            {msg.streaming ? <span className="animate-pulse">…</span> : null}
                                        </div>
                                    ) : null}
                                    <div className="whitespace-pre-wrap break-words">{msg.content}</div>
                                    {msg.attachmentNames?.length ? (
                                        <p className={`mt-1 text-xs ${isClassroom ? 'text-zinc-500' : 'text-zinc-500'}`}>
                                            📎 {msg.attachmentNames.join(', ')}
                                        </p>
                                    ) : null}
                                    {msg.actions && msg.actions.length > 0 ? (
                                        <ul
                                            className={`mt-2 space-y-1 border-t pt-2 ${isClassroom ? 'border-zinc-600' : 'border-zinc-200'}`}
                                        >
                                            {msg.actions.map((act, i) => (
                                                <li
                                                    key={i}
                                                    className={`font-mono text-xs ${isClassroom ? 'text-amber-200/90' : 'text-amber-800'}`}
                                                >
                                                    <span
                                                        className={`font-sans font-medium ${isClassroom ? 'text-zinc-400' : 'text-zinc-600'}`}
                                                    >
                                                        Action:{' '}
                                                    </span>
                                                    {JSON.stringify(act)}
                                                </li>
                                            ))}
                                        </ul>
                                    ) : null}
                                </li>
                            );
                        })}
                    </ul>
                )}
                <div ref={listEndRef} />
            </div>

            {error ? (
                <p className={`px-3 py-1 text-xs ${isClassroom ? 'text-red-400' : 'text-red-600'}`}>{error}</p>
            ) : null}
            {status ? (
                <p className={`px-3 py-1 text-xs ${isClassroom ? 'text-zinc-500' : 'text-zinc-500'}`}>{status}</p>
            ) : null}

            <div className={`border-t p-3 ${isClassroom ? 'border-zinc-800' : 'border-zinc-100'}`}>
                <div className="mb-2 flex flex-wrap gap-2">
                    <label
                        className={`cursor-pointer rounded border px-2 py-1 text-xs ${
                            isClassroom
                                ? 'border-zinc-600 text-zinc-300 hover:bg-zinc-800'
                                : 'border-zinc-300 text-zinc-600 hover:bg-zinc-50'
                        }`}
                    >
                        Attach
                        <input type="file" className="hidden" multiple onChange={onPickFiles} />
                    </label>
                    {status ? (
                        <button
                            type="button"
                            onClick={stopStream}
                            className={`rounded border px-2 py-1 text-xs font-medium ${
                                isClassroom
                                    ? 'border-red-900/60 text-red-300 hover:bg-red-950/50'
                                    : 'border-red-200 text-red-700 hover:bg-red-50'
                            }`}
                        >
                            Stop
                        </button>
                    ) : null}
                </div>
                {attachments.length > 0 ? (
                    <p className={`mb-2 text-xs ${isClassroom ? 'text-zinc-500' : 'text-zinc-500'}`}>
                        {attachments.map((a) => a.name).join(', ')}
                    </p>
                ) : null}
                <textarea
                    className={`w-full resize-none rounded-lg border px-3 py-2 text-sm ${
                        isClassroom
                            ? 'border-zinc-700 bg-zinc-900 text-zinc-100 placeholder:text-zinc-500'
                            : 'border-zinc-300 bg-white text-zinc-900'
                    }`}
                    rows={3}
                    placeholder="Message…"
                    value={composer}
                    onChange={(e) => setComposer(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
                            e.preventDefault();
                            send();
                        }
                    }}
                />
                <button
                    type="button"
                    onClick={send}
                    disabled={!!status}
                    className={`mt-2 w-full rounded-lg py-2 text-sm font-medium disabled:opacity-50 ${
                        isClassroom
                            ? 'bg-indigo-600 text-white hover:bg-indigo-500'
                            : 'bg-zinc-900 text-white hover:bg-zinc-800'
                    }`}
                >
                    Send
                </button>
                <p className={`mt-1 text-xs ${isClassroom ? 'text-zinc-500' : 'text-zinc-400'}`}>⌘/Ctrl + Enter to send</p>
            </div>
        </aside>
    );
}
