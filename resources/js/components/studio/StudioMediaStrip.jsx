function newItem() {
    return {
        id: crypto.randomUUID(),
        type: 'image',
        status: 'pending',
        label: 'Placeholder asset',
        url: '',
        error: '',
        sceneId: null,
    };
}

const STATUS_STYLES = {
    pending: 'bg-amber-100 text-amber-900',
    ready: 'bg-emerald-100 text-emerald-900',
    failed: 'bg-red-100 text-red-900',
};

export default function StudioMediaStrip({ items, onItemsChange }) {
    const list = Array.isArray(items) ? items : [];

    const addStub = () => {
        onItemsChange([...list, newItem()]);
    };

    const remove = (id) => {
        if (!window.confirm('Remove this media entry?')) {
            return;
        }
        onItemsChange(list.filter((x) => x.id !== id));
    };

    const markReady = (id) => {
        const url = window.prompt('Public or storage URL for this asset');
        if (!url) {
            return;
        }
        onItemsChange(
            list.map((x) => (x.id === id ? { ...x, status: 'ready', url, error: '' } : x)),
        );
    };

    const markFailed = (id) => {
        onItemsChange(
            list.map((x) => (x.id === id ? { ...x, status: 'failed', error: 'Simulated failure' } : x)),
        );
    };

    const retry = () => {
        window.alert('Retry will call generation providers in Phase 4.');
    };

    return (
        <section className="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-sm font-semibold text-zinc-900">Media library</h2>
                <button
                    type="button"
                    onClick={addStub}
                    className="rounded-lg border border-zinc-300 px-2 py-1 text-xs font-medium text-zinc-700 hover:bg-zinc-50"
                >
                    Add placeholder
                </button>
            </div>
            <p className="mt-1 text-xs text-zinc-500">
                Tracks generated/pending assets. Full provider wiring is Phase 4; entries persist in lesson{' '}
                <code className="text-zinc-600">meta.mediaLibrary</code>.
            </p>
            {list.length === 0 ? (
                <p className="mt-3 text-sm text-zinc-500">No media entries yet.</p>
            ) : (
                <ul className="mt-3 space-y-2">
                    {list.map((m) => (
                        <li
                            key={m.id}
                            className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm"
                        >
                            <div className="min-w-0 flex-1">
                                <p className="truncate font-medium text-zinc-900">{m.label || m.type}</p>
                                <span
                                    className={`mt-0.5 inline-block rounded px-1.5 py-0.5 text-xs ${STATUS_STYLES[m.status] || 'bg-zinc-200 text-zinc-800'}`}
                                >
                                    {m.status}
                                </span>
                                {m.url ? (
                                    <a
                                        href={m.url}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="mt-1 block truncate text-xs text-indigo-600 hover:underline"
                                    >
                                        {m.url}
                                    </a>
                                ) : null}
                                {m.error ? <p className="mt-1 text-xs text-red-600">{m.error}</p> : null}
                            </div>
                            <div className="flex flex-wrap gap-1">
                                {m.status === 'pending' ? (
                                    <>
                                        <button
                                            type="button"
                                            className="rounded border border-zinc-300 px-2 py-0.5 text-xs hover:bg-white"
                                            onClick={() => markReady(m.id)}
                                        >
                                            Mark ready
                                        </button>
                                        <button
                                            type="button"
                                            className="rounded border border-zinc-300 px-2 py-0.5 text-xs hover:bg-white"
                                            onClick={() => markFailed(m.id)}
                                        >
                                            Mark failed
                                        </button>
                                    </>
                                ) : null}
                                {m.status === 'failed' ? (
                                    <button
                                        type="button"
                                        className="rounded border border-zinc-300 px-2 py-0.5 text-xs hover:bg-white"
                                        onClick={retry}
                                    >
                                        Retry
                                    </button>
                                ) : null}
                                <button
                                    type="button"
                                    className="text-xs text-red-600 hover:underline"
                                    onClick={() => remove(m.id)}
                                >
                                    Remove
                                </button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
