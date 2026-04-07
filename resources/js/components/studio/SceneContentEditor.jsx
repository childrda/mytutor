import { useCallback, useMemo, useState } from 'react';

function newId() {
    return crypto.randomUUID();
}

export function defaultContentForSceneType(type) {
    switch (type) {
        case 'quiz':
            return { type: 'quiz', questions: [] };
        case 'interactive':
            return { type: 'interactive', title: '', url: '', embedHtml: '' };
        case 'pbl':
            return {
                type: 'pbl',
                brief: '',
                projectConfig: {
                    title: '',
                    objective: '',
                    deliverables: [],
                    assessmentNotes: '',
                },
            };
        default:
            return {
                type: 'slide',
                canvas: { title: 'New slide', width: 1000, height: 562.5, elements: [] },
            };
    }
}

export function normalizeSceneContent(sceneType, raw) {
    const c = raw && typeof raw === 'object' ? raw : {};
    switch (sceneType) {
        case 'quiz':
            return normalizeQuiz(c);
        case 'interactive':
            return normalizeInteractive(c);
        case 'pbl':
            return normalizePbl(c);
        default:
            return normalizeSlide(c);
    }
}

function normalizeSlide(c) {
    const canvas = c.canvas && typeof c.canvas === 'object' ? c.canvas : {};
    const rawEl = Array.isArray(canvas.elements) ? canvas.elements : [];
    const elements = rawEl.map((el) => {
        const e = el && typeof el === 'object' ? el : {};
        const hasText = typeof e.text === 'string' && e.text.trim() !== '';
        const type = hasText ? 'text' : typeof e.type === 'string' && e.type !== '' ? e.type : 'text';
        return {
            ...e,
            type,
            id: typeof e.id === 'string' ? e.id : newId(),
        };
    });
    return {
        type: 'slide',
        canvas: {
            title: typeof canvas.title === 'string' ? canvas.title : 'Slide',
            width: typeof canvas.width === 'number' ? canvas.width : 1000,
            height: typeof canvas.height === 'number' ? canvas.height : 562.5,
            elements,
        },
        theme: c.theme && typeof c.theme === 'object' ? c.theme : undefined,
    };
}

function normalizeQuestion(q, i) {
    const o = q && typeof q === 'object' ? q : {};
    const options = Array.isArray(o.options)
        ? o.options.map((opt, j) => ({
              id: typeof opt?.id === 'string' ? opt.id : newId(),
              label: typeof opt?.label === 'string' ? opt.label : '',
          }))
        : [];
    const t = o.type;
    const type = t === 'multiple' || t === 'short_answer' ? t : 'single';
    return {
        id: typeof o.id === 'string' ? o.id : `q-${i}`,
        type,
        prompt: typeof o.prompt === 'string' ? o.prompt : '',
        points: typeof o.points === 'number' && o.points > 0 ? o.points : 1,
        options,
        correctIds: Array.isArray(o.correctIds) ? o.correctIds.filter((x) => typeof x === 'string') : [],
        gradingHint: typeof o.gradingHint === 'string' ? o.gradingHint : '',
    };
}

function normalizeQuiz(c) {
    return {
        type: 'quiz',
        questions: Array.isArray(c.questions) ? c.questions.map(normalizeQuestion) : [],
    };
}

function normalizeInteractive(c) {
    return {
        type: 'interactive',
        title: typeof c.title === 'string' ? c.title : '',
        url: typeof c.url === 'string' ? c.url : '',
        embedHtml: typeof c.embedHtml === 'string' ? c.embedHtml : '',
    };
}

function normalizePbl(c) {
    const pc = c.projectConfig && typeof c.projectConfig === 'object' ? c.projectConfig : {};
    const del = Array.isArray(pc.deliverables) ? pc.deliverables.filter((x) => typeof x === 'string') : [];
    return {
        type: 'pbl',
        brief: typeof c.brief === 'string' ? c.brief : '',
        projectConfig: {
            title: typeof pc.title === 'string' ? pc.title : '',
            objective: typeof pc.objective === 'string' ? pc.objective : '',
            deliverables: del,
            assessmentNotes: typeof pc.assessmentNotes === 'string' ? pc.assessmentNotes : '',
        },
    };
}

function newTextElement() {
    return {
        type: 'text',
        id: newId(),
        x: 48,
        y: 48,
        width: 420,
        height: 96,
        text: '',
        fontSize: 22,
    };
}

function newQuizQuestion() {
    const a = newId();
    const b = newId();
    return {
        id: newId(),
        type: 'single',
        prompt: '',
        points: 1,
        options: [
            { id: a, label: '' },
            { id: b, label: '' },
        ],
        correctIds: [],
        gradingHint: '',
    };
}

function SlideEditor({ content, onChange, readOnly }) {
    const canvas = content.canvas;
    const setCanvas = (patch) => {
        onChange({ ...content, canvas: { ...canvas, ...patch } });
    };

    const updateElement = (id, patch) => {
        const elements = canvas.elements.map((el) => (el.id === id ? { ...el, ...patch } : el));
        setCanvas({ elements });
    };

    const addText = () => {
        setCanvas({ elements: [...canvas.elements, newTextElement()] });
    };

    const removeElement = (id) => {
        setCanvas({ elements: canvas.elements.filter((el) => el.id !== id) });
    };

    const scale = 0.42;
    const w = canvas.width * scale;
    const h = canvas.height * scale;

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-2">
                <span className="text-xs font-medium text-zinc-500">Slide canvas</span>
                {!readOnly ? (
                    <span
                        className="rounded border border-dashed border-zinc-300 px-2 py-1 text-xs text-zinc-400"
                        title="Phase 4"
                    >
                        AI layout (Phase 4)
                    </span>
                ) : null}
            </div>
            <div>
                <label className="text-xs font-medium text-zinc-600">Canvas title</label>
                <input
                    className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                    value={canvas.title}
                    disabled={readOnly}
                    onChange={(e) => setCanvas({ title: e.target.value })}
                />
            </div>
            <div>
                <p className="text-xs font-medium text-zinc-600">Preview ({canvas.width}×{canvas.height})</p>
                <div
                    className="mt-2 overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-inner"
                    style={{ width: w, height: h }}
                >
                    <div
                        className="relative bg-gradient-to-br from-zinc-50 to-zinc-100"
                        style={{
                            width: canvas.width,
                            height: canvas.height,
                            transform: `scale(${scale})`,
                            transformOrigin: 'top left',
                        }}
                    >
                        <p className="absolute left-8 top-6 text-2xl font-semibold text-zinc-800">{canvas.title}</p>
                        {canvas.elements.map((el) => {
                            const isTextBlock =
                                el.type === 'text' ||
                                (typeof el.text === 'string' && el.text.trim() !== '' && el.type !== 'image');
                            if (!isTextBlock) {
                                return null;
                            }
                            return (
                                <div
                                    key={el.id}
                                    className="absolute border border-dashed border-indigo-200 bg-white/80 px-2 py-1 text-zinc-800"
                                    style={{
                                        left: el.x,
                                        top: el.y,
                                        width: el.width,
                                        minHeight: el.height,
                                        fontSize: el.fontSize,
                                    }}
                                >
                                    {el.text || '…'}
                                </div>
                            );
                        })}
                    </div>
                </div>
            </div>
            {!readOnly ? (
                <div className="space-y-3">
                    <div className="flex items-center justify-between">
                        <span className="text-xs font-semibold text-zinc-700">Text elements</span>
                        <button
                            type="button"
                            onClick={addText}
                            className="rounded-lg bg-zinc-900 px-2 py-1 text-xs font-medium text-white hover:bg-zinc-800"
                        >
                            Add text
                        </button>
                    </div>
                    {canvas.elements.length === 0 ? (
                        <p className="text-sm text-zinc-500">No elements yet. Add text blocks for your slide.</p>
                    ) : (
                        <ul className="space-y-3">
                            {canvas.elements.map((el) => {
                                const editableText =
                                    el.type === 'text' ||
                                    (typeof el.text === 'string' && el.text.trim() !== '' && el.type !== 'image');
                                if (!editableText) {
                                    return (
                                        <li key={el.id} className="rounded-lg border border-zinc-200 p-2 text-xs text-zinc-500">
                                            Unsupported element type: {el.type}
                                        </li>
                                    );
                                }
                                return (
                                    <li key={el.id} className="rounded-lg border border-zinc-200 p-3">
                                        <div className="flex justify-end">
                                            <button
                                                type="button"
                                                onClick={() => removeElement(el.id)}
                                                className="text-xs text-red-600 hover:underline"
                                            >
                                                Remove
                                            </button>
                                        </div>
                                        <div className="mt-2 grid gap-2 sm:grid-cols-2">
                                            <div>
                                                <label className="text-xs text-zinc-500">Text</label>
                                                <textarea
                                                    className="mt-1 w-full rounded border border-zinc-300 px-2 py-1 text-sm"
                                                    rows={2}
                                                    value={el.text}
                                                    onChange={(e) => updateElement(el.id, { text: e.target.value })}
                                                />
                                            </div>
                                            <div className="grid grid-cols-2 gap-2">
                                                <div>
                                                    <label className="text-xs text-zinc-500">X</label>
                                                    <input
                                                        type="number"
                                                        className="mt-1 w-full rounded border border-zinc-300 px-2 py-1 text-sm"
                                                        value={el.x}
                                                        onChange={(e) => updateElement(el.id, { x: Number(e.target.value) || 0 })}
                                                    />
                                                </div>
                                                <div>
                                                    <label className="text-xs text-zinc-500">Y</label>
                                                    <input
                                                        type="number"
                                                        className="mt-1 w-full rounded border border-zinc-300 px-2 py-1 text-sm"
                                                        value={el.y}
                                                        onChange={(e) => updateElement(el.id, { y: Number(e.target.value) || 0 })}
                                                    />
                                                </div>
                                                <div>
                                                    <label className="text-xs text-zinc-500">Size</label>
                                                    <input
                                                        type="number"
                                                        className="mt-1 w-full rounded border border-zinc-300 px-2 py-1 text-sm"
                                                        value={el.fontSize}
                                                        onChange={(e) =>
                                                            updateElement(el.id, { fontSize: Number(e.target.value) || 16 })
                                                        }
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>
            ) : null}
        </div>
    );
}

function QuizEditor({ content, onChange, language, readOnly }) {
    const [answers, setAnswers] = useState({});
    const [shortDraft, setShortDraft] = useState({});
    const [gradeResult, setGradeResult] = useState({});
    const [grading, setGrading] = useState({});

    const setQuestions = (next) => onChange({ ...content, questions: next });

    const updateQuestion = (i, patch) => {
        const next = content.questions.map((q, j) => (j === i ? { ...q, ...patch } : q));
        setQuestions(next);
    };

    const addQuestion = () => setQuestions([...content.questions, newQuizQuestion()]);

    const removeQuestion = (i) => setQuestions(content.questions.filter((_, j) => j !== i));

    const toggleCorrect = (qIndex, optionId, q) => {
        const isMulti = q.type === 'multiple';
        let nextIds = [...q.correctIds];
        if (isMulti) {
            if (nextIds.includes(optionId)) {
                nextIds = nextIds.filter((id) => id !== optionId);
            } else {
                nextIds.push(optionId);
            }
        } else {
            nextIds = [optionId];
        }
        updateQuestion(qIndex, { correctIds: nextIds });
    };

    const gradeShort = async (q) => {
        const draft = shortDraft[q.id] ?? '';
        if (!draft.trim()) {
            return;
        }
        setGrading((g) => ({ ...g, [q.id]: true }));
        try {
            const res = await window.axios.post('/api/quiz-grade', {
                question: q.prompt,
                userAnswer: draft,
                points: q.points,
                commentPrompt: q.gradingHint || undefined,
                language: language || 'en',
            });
            if (res.data?.success) {
                setGradeResult((r) => ({
                    ...r,
                    [q.id]: { score: res.data.score, max: res.data.maxPoints, comment: res.data.comment },
                }));
            }
        } catch (e) {
            window.alert(e.response?.data?.error || e.message || 'Grading failed');
        } finally {
            setGrading((g) => ({ ...g, [q.id]: false }));
        }
    };

    const checkMc = (q) => {
        const sel = answers[q.id];
        const correct = [...q.correctIds].sort().join(',');
        const mine = (Array.isArray(sel) ? sel : sel ? [sel] : []).sort().join(',');
        return correct === mine && correct !== '';
    };

    if (readOnly) {
        return (
            <div className="space-y-6">
                <p className="text-xs text-zinc-500">Playback — answer questions below.</p>
                {content.questions.map((q, i) => (
                    <div key={q.id} className="rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                        <p className="text-sm font-medium text-zinc-900">
                            {i + 1}. {q.prompt || '(No prompt)'}
                        </p>
                        <p className="text-xs text-zinc-500">{q.points} pt(s)</p>
                        {q.type === 'short_answer' ? (
                            <div className="mt-3 space-y-2">
                                <textarea
                                    className="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                                    rows={3}
                                    placeholder="Your answer"
                                    value={shortDraft[q.id] ?? ''}
                                    onChange={(e) => setShortDraft((d) => ({ ...d, [q.id]: e.target.value }))}
                                />
                                <button
                                    type="button"
                                    disabled={grading[q.id]}
                                    onClick={() => gradeShort(q)}
                                    className="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                                >
                                    {grading[q.id] ? 'Grading…' : 'Grade with AI'}
                                </button>
                                {gradeResult[q.id] ? (
                                    <p className="text-sm text-zinc-700">
                                        Score: {gradeResult[q.id].score} / {gradeResult[q.id].max} — {gradeResult[q.id].comment}
                                    </p>
                                ) : null}
                            </div>
                        ) : (
                            <ul className="mt-3 space-y-2">
                                {q.options.map((opt) => (
                                    <li key={opt.id}>
                                        <label className="flex cursor-pointer items-start gap-2 text-sm">
                                            <input
                                                type={q.type === 'multiple' ? 'checkbox' : 'radio'}
                                                name={`pq-${q.id}`}
                                                checked={
                                                    q.type === 'multiple'
                                                        ? (answers[q.id] || []).includes(opt.id)
                                                        : answers[q.id] === opt.id
                                                }
                                                onChange={() => {
                                                    if (q.type === 'multiple') {
                                                        const cur = answers[q.id] || [];
                                                        const next = cur.includes(opt.id)
                                                            ? cur.filter((x) => x !== opt.id)
                                                            : [...cur, opt.id];
                                                        setAnswers((a) => ({ ...a, [q.id]: next }));
                                                    } else {
                                                        setAnswers((a) => ({ ...a, [q.id]: opt.id }));
                                                    }
                                                }}
                                                className="mt-1"
                                            />
                                            <span>{opt.label || '(empty option)'}</span>
                                        </label>
                                    </li>
                                ))}
                                <li className="pt-2">
                                    <button
                                        type="button"
                                        onClick={() => {
                                            const ok = checkMc(q);
                                            window.alert(ok ? 'Correct!' : 'Not quite — try again.');
                                        }}
                                        className="text-sm font-medium text-indigo-600 hover:underline"
                                    >
                                        Check answer
                                    </button>
                                </li>
                            </ul>
                        )}
                    </div>
                ))}
            </div>
        );
    }

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="text-xs font-medium text-zinc-500">Quiz builder</span>
                <span
                    className="rounded border border-dashed border-zinc-300 px-2 py-1 text-xs text-zinc-400"
                    title="Phase 4"
                >
                    Generate questions (Phase 4)
                </span>
            </div>
            {content.questions.length === 0 ? (
                <p className="text-sm text-zinc-500">No questions yet.</p>
            ) : null}
            <ul className="space-y-6">
                {content.questions.map((q, i) => (
                    <li key={q.id} className="rounded-xl border border-zinc-200 p-4">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <span className="text-xs font-semibold text-zinc-500">Question {i + 1}</span>
                            <button type="button" onClick={() => removeQuestion(i)} className="text-xs text-red-600 hover:underline">
                                Remove
                            </button>
                        </div>
                        <div className="mt-3 grid gap-3 sm:grid-cols-2">
                            <div className="sm:col-span-2">
                                <label className="text-xs text-zinc-500">Prompt</label>
                                <textarea
                                    className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                                    rows={2}
                                    value={q.prompt}
                                    onChange={(e) => updateQuestion(i, { prompt: e.target.value })}
                                />
                            </div>
                            <div>
                                <label className="text-xs text-zinc-500">Type</label>
                                <select
                                    className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                                    value={q.type}
                                    onChange={(e) =>
                                        updateQuestion(i, {
                                            type: e.target.value,
                                            correctIds: [],
                                        })
                                    }
                                >
                                    <option value="single">Single choice</option>
                                    <option value="multiple">Multiple choice</option>
                                    <option value="short_answer">Short answer (AI graded)</option>
                                </select>
                            </div>
                            <div>
                                <label className="text-xs text-zinc-500">Points</label>
                                <input
                                    type="number"
                                    min={1}
                                    className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                                    value={q.points}
                                    onChange={(e) => updateQuestion(i, { points: Math.max(1, Number(e.target.value) || 1) })}
                                />
                            </div>
                            {q.type === 'short_answer' ? (
                                <div className="sm:col-span-2">
                                    <label className="text-xs text-zinc-500">Grading hint (optional)</label>
                                    <textarea
                                        className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                                        rows={2}
                                        value={q.gradingHint}
                                        onChange={(e) => updateQuestion(i, { gradingHint: e.target.value })}
                                    />
                                </div>
                            ) : null}
                        </div>
                        {q.type !== 'short_answer' ? (
                            <div className="mt-4">
                                <p className="text-xs font-medium text-zinc-600">Options (mark correct)</p>
                                <ul className="mt-2 space-y-2">
                                    {q.options.map((opt) => (
                                        <li key={opt.id} className="flex items-center gap-2">
                                            <input
                                                type={q.type === 'multiple' ? 'checkbox' : 'radio'}
                                                name={`correct-${q.id}`}
                                                checked={q.correctIds.includes(opt.id)}
                                                onChange={() => toggleCorrect(i, opt.id, q)}
                                                className="shrink-0"
                                            />
                                            <input
                                                className="flex-1 rounded border border-zinc-300 px-2 py-1 text-sm"
                                                value={opt.label}
                                                onChange={(e) => {
                                                    const opts = q.options.map((o) =>
                                                        o.id === opt.id ? { ...o, label: e.target.value } : o,
                                                    );
                                                    updateQuestion(i, { options: opts });
                                                }}
                                            />
                                        </li>
                                    ))}
                                </ul>
                                <button
                                    type="button"
                                    onClick={() =>
                                        updateQuestion(i, {
                                            options: [...q.options, { id: newId(), label: '' }],
                                        })
                                    }
                                    className="mt-2 text-xs font-medium text-indigo-600 hover:underline"
                                >
                                    Add option
                                </button>
                            </div>
                        ) : null}
                    </li>
                ))}
            </ul>
            <button
                type="button"
                onClick={addQuestion}
                className="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50"
            >
                Add question
            </button>
        </div>
    );
}

function InteractiveEditor({ content, onChange, readOnly }) {
    const set = (patch) => onChange({ ...content, ...patch });

    return (
        <div className="space-y-4">
            <div>
                <label className="text-xs font-medium text-zinc-600">Title</label>
                <input
                    className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                    value={content.title}
                    disabled={readOnly}
                    onChange={(e) => set({ title: e.target.value })}
                />
            </div>
            <div>
                <label className="text-xs font-medium text-zinc-600">URL (embed)</label>
                <input
                    className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                    placeholder="https://…"
                    value={content.url}
                    disabled={readOnly}
                    onChange={(e) => set({ url: e.target.value })}
                />
            </div>
            <div>
                <label className="text-xs font-medium text-zinc-600">Embedded HTML (optional)</label>
                <textarea
                    className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 font-mono text-xs"
                    rows={6}
                    placeholder="<div>…</div>"
                    value={content.embedHtml}
                    disabled={readOnly}
                    onChange={(e) => set({ embedHtml: e.target.value })}
                />
                <p className="mt-1 text-xs text-zinc-400">If both are set, URL preview is shown first.</p>
            </div>
            <div>
                <p className="text-xs font-medium text-zinc-600">Preview</p>
                <div className="mt-2 aspect-video w-full overflow-hidden rounded-lg border border-zinc-200 bg-zinc-100">
                    {content.url ? (
                        <iframe
                            title={content.title || 'Interactive'}
                            className="h-full w-full"
                            sandbox="allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox"
                            src={content.url}
                        />
                    ) : content.embedHtml ? (
                        <iframe
                            title={content.title || 'Embedded'}
                            className="h-full w-full"
                            sandbox="allow-scripts allow-same-origin"
                            srcDoc={content.embedHtml}
                        />
                    ) : (
                        <div className="flex h-full items-center justify-center text-sm text-zinc-500">No URL or HTML to preview</div>
                    )}
                </div>
            </div>
        </div>
    );
}

function PblEditor({ content, onChange, readOnly }) {
    const pc = content.projectConfig;
    const setPc = (patch) => onChange({ ...content, projectConfig: { ...pc, ...patch } });

    const setDeliverables = (lines) => {
        const arr = lines.split('\n').map((s) => s.trim()).filter(Boolean);
        setPc({ deliverables: arr });
    };

    return (
        <div className="space-y-4">
            <p className="text-xs text-zinc-500">
                Project brief for learners. PBL chat routing is wired in Phase 3 (<code className="text-zinc-600">/api/project-tutor/chat</code>).
            </p>
            <div>
                <label className="text-xs font-medium text-zinc-600">Short brief</label>
                <textarea
                    className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                    rows={2}
                    value={content.brief}
                    disabled={readOnly}
                    onChange={(e) => onChange({ ...content, brief: e.target.value })}
                />
            </div>
            <div>
                <label className="text-xs font-medium text-zinc-600">Project title</label>
                <input
                    className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                    value={pc.title}
                    disabled={readOnly}
                    onChange={(e) => setPc({ title: e.target.value })}
                />
            </div>
            <div>
                <label className="text-xs font-medium text-zinc-600">Objective</label>
                <textarea
                    className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                    rows={3}
                    value={pc.objective}
                    disabled={readOnly}
                    onChange={(e) => setPc({ objective: e.target.value })}
                />
            </div>
            <div>
                <label className="text-xs font-medium text-zinc-600">Deliverables (one per line)</label>
                <textarea
                    className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                    rows={4}
                    value={pc.deliverables.join('\n')}
                    disabled={readOnly}
                    onChange={(e) => setDeliverables(e.target.value)}
                />
            </div>
            <div>
                <label className="text-xs font-medium text-zinc-600">Assessment notes</label>
                <textarea
                    className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                    rows={2}
                    value={pc.assessmentNotes}
                    disabled={readOnly}
                    onChange={(e) => setPc({ assessmentNotes: e.target.value })}
                />
            </div>
            <button
                type="button"
                disabled
                className="cursor-not-allowed rounded border border-dashed border-zinc-300 px-3 py-1.5 text-xs text-zinc-400"
            >
                Open PBL chat (Phase 3)
            </button>
        </div>
    );
}

export default function SceneContentEditor({ scene, onContentChange, language, playbackMode }) {
    const readOnly = playbackMode === true;

    const normalized = useMemo(
        () => normalizeSceneContent(scene.type, scene.content),
        [scene.type, scene.content],
    );

    const push = useCallback(
        (next) => {
            onContentChange(next);
        },
        [onContentChange],
    );

    switch (scene.type) {
        case 'quiz':
            return <QuizEditor content={normalized} onChange={push} language={language} readOnly={readOnly} />;
        case 'interactive':
            return <InteractiveEditor content={normalized} onChange={push} readOnly={readOnly} />;
        case 'pbl':
            return <PblEditor content={normalized} onChange={push} readOnly={readOnly} />;
        default:
            return <SlideEditor content={normalized} onChange={push} readOnly={readOnly} />;
    }
}

/** Read-only viewer for published / shared lessons (quiz playback, slide preview, etc.). */
export function SceneContentView({ scene, language }) {
    return (
        <SceneContentEditor
            scene={scene}
            onContentChange={() => {}}
            language={language || 'en'}
            playbackMode
        />
    );
}
