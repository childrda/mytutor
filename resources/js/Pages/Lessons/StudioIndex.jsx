import { Head, Link, router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

export default function StudioIndex() {
    const { auth } = usePage().props;
    const [lessons, setLessons] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [creating, setCreating] = useState(false);

    const loadLessons = useCallback(async () => {
        setLoading(true);
        setError('');
        try {
            const res = await window.axios.get('/tutor-api/lessons');
            if (!res.data.success) {
                throw new Error(res.data.error || 'Failed to load lessons');
            }
            setLessons(res.data.lessons ?? []);
        } catch (e) {
            setError(e.response?.data?.error || e.message || 'Failed to load');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        loadLessons();
    }, [loadLessons]);

    async function createLesson() {
        const name = window.prompt('Lesson name', 'Untitled lesson');
        if (!name) {
            return;
        }
        setCreating(true);
        setError('');
        try {
            const res = await window.axios.post('/tutor-api/lessons', { name });
            if (!res.data.success) {
                throw new Error(res.data.error || 'Create failed');
            }
            await loadLessons();
        } catch (e) {
            setError(e.response?.data?.error || e.message || 'Create failed');
        } finally {
            setCreating(false);
        }
    }

    return (
        <>
            <Head title="Lesson studio" />
            <div className="mx-auto min-h-screen max-w-3xl px-6 py-10">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold text-zinc-900">Lesson studio</h1>
                        <p className="text-sm text-zinc-600">
                            Signed in as {auth?.user?.email}{' '}
                            <button
                                type="button"
                                className="text-indigo-600 underline"
                                onClick={() => router.post('/logout')}
                            >
                                Log out
                            </button>
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Link href="/" className="rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50">
                            Home
                        </Link>
                        <Link href="/settings" className="rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50">
                            Settings
                        </Link>
                        <button
                            type="button"
                            disabled={creating}
                            onClick={createLesson}
                            className="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                        >
                            New lesson
                        </button>
                    </div>
                </header>

                {error ? <p className="mt-4 text-sm text-red-600">{error}</p> : null}

                <section className="mt-8">
                    {loading ? (
                        <p className="text-sm text-zinc-500">Loading…</p>
                    ) : lessons.length === 0 ? (
                        <p className="text-sm text-zinc-600">No lessons yet. Create one to begin Phase 2.</p>
                    ) : (
                        <ul className="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white">
                            {lessons.map((lesson) => (
                                <li key={lesson.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                    <div>
                                        <p className="font-medium text-zinc-900">{lesson.name}</p>
                                        <p className="text-xs text-zinc-500">{lesson.id}</p>
                                    </div>
                                    <Link
                                        href={`/studio/${lesson.id}`}
                                        className="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500"
                                    >
                                        Open
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}
