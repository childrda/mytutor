import { useCallback, useEffect, useRef, useState } from 'react';

export function normalizeWhiteboard(raw) {
    if (!raw || typeof raw !== 'object') {
        return { version: 1, paths: [] };
    }
    if (raw.version === 1 && Array.isArray(raw.paths)) {
        return raw;
    }
    return { version: 1, paths: [] };
}

export default function StudioWhiteboardPanel({ scene, readOnly, onWhiteboardChange }) {
    const [open, setOpen] = useState(true);
    const [color, setColor] = useState('#1e293b');
    const [strokeWidth, setStrokeWidth] = useState(3);
    const drawing = useRef(null);
    const svgRef = useRef(null);

    const wb = normalizeWhiteboard(scene?.whiteboards);
    const paths = wb.paths;

    const commitPaths = useCallback(
        (nextPaths) => {
            onWhiteboardChange({ version: 1, paths: nextPaths });
        },
        [onWhiteboardChange],
    );

    const clearAll = useCallback(() => {
        if (!window.confirm('Clear the whole whiteboard?')) {
            return;
        }
        commitPaths([]);
    }, [commitPaths]);

    const undo = useCallback(() => {
        if (paths.length === 0) {
            return;
        }
        commitPaths(paths.slice(0, -1));
    }, [paths, commitPaths]);

    const toSvgPoint = (ev) => {
        const svg = svgRef.current;
        if (!svg) {
            return { x: 0, y: 0 };
        }
        const pt = svg.createSVGPoint();
        pt.x = ev.clientX;
        pt.y = ev.clientY;
        const ctm = svg.getScreenCTM();
        if (!ctm) {
            return { x: 0, y: 0 };
        }
        const p = pt.matrixTransform(ctm.inverse());
        return { x: p.x, y: p.y };
    };

    const onPointerDown = (ev) => {
        if (readOnly) {
            return;
        }
        ev.currentTarget.setPointerCapture(ev.pointerId);
        const { x, y } = toSvgPoint(ev);
        drawing.current = { d: `M ${x.toFixed(1)} ${y.toFixed(1)}`, color, strokeWidth };
    };

    const onPointerMove = (ev) => {
        if (readOnly || !drawing.current) {
            return;
        }
        const { x, y } = toSvgPoint(ev);
        drawing.current.d += ` L ${x.toFixed(1)} ${y.toFixed(1)}`;
    };

    const onPointerUp = (ev) => {
        if (readOnly || !drawing.current) {
            return;
        }
        try {
            ev.currentTarget.releasePointerCapture(ev.pointerId);
        } catch {
            /* ignore */
        }
        const line = {
            id: crypto.randomUUID(),
            d: drawing.current.d,
            color: drawing.current.color,
            strokeWidth: drawing.current.strokeWidth,
        };
        drawing.current = null;
        commitPaths([...paths, line]);
    };

    useEffect(() => {
        drawing.current = null;
    }, [scene?.id]);

    return (
        <section className="rounded-2xl border border-zinc-200 bg-white shadow-sm">
            <button
                type="button"
                onClick={() => setOpen((o) => !o)}
                className="flex w-full items-center justify-between px-4 py-3 text-left text-sm font-semibold text-zinc-900"
            >
                Whiteboard
                <span className="text-xs font-normal text-zinc-500">{open ? 'Hide' : 'Show'}</span>
            </button>
            {open ? (
                <div className="border-t border-zinc-100 px-4 pb-4">
                    {!readOnly ? (
                        <div className="mt-3 flex flex-wrap items-center gap-3">
                            <label className="flex items-center gap-1 text-xs text-zinc-600">
                                Color
                                <input type="color" value={color} onChange={(e) => setColor(e.target.value)} className="h-8 w-10 cursor-pointer" />
                            </label>
                            <label className="flex items-center gap-1 text-xs text-zinc-600">
                                Width
                                <input
                                    type="range"
                                    min={1}
                                    max={12}
                                    value={strokeWidth}
                                    onChange={(e) => setStrokeWidth(Number(e.target.value))}
                                />
                            </label>
                            <button
                                type="button"
                                onClick={undo}
                                className="rounded border border-zinc-300 px-2 py-1 text-xs text-zinc-700 hover:bg-zinc-50"
                            >
                                Undo stroke
                            </button>
                            <button
                                type="button"
                                onClick={clearAll}
                                className="rounded border border-red-200 px-2 py-1 text-xs text-red-700 hover:bg-red-50"
                            >
                                Clear all
                            </button>
                        </div>
                    ) : (
                        <p className="mt-2 text-xs text-zinc-500">Playback — whiteboard is read-only.</p>
                    )}
                    <div className="mt-3 overflow-hidden rounded-xl border border-zinc-200 bg-zinc-50">
                        <svg
                            ref={svgRef}
                            viewBox="0 0 800 450"
                            className={`h-auto w-full touch-none ${readOnly ? '' : 'cursor-crosshair'}`}
                            onPointerDown={onPointerDown}
                            onPointerMove={onPointerMove}
                            onPointerUp={onPointerUp}
                            onPointerCancel={onPointerUp}
                        >
                            <rect width="800" height="450" fill="#fafafa" />
                            {paths.map((p) => (
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
                    <p className="mt-2 text-xs text-zinc-400">
                        Freehand SVG paths stored in <code className="text-zinc-500">scene.whiteboards</code>.
                    </p>
                </div>
            ) : null}
        </section>
    );
}
