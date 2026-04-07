import PublicLessonView from '../../components/public/PublicLessonView.jsx';
import { Head } from '@inertiajs/react';

export default function Public({ lesson }) {
    const title = lesson?.stage?.name || 'Published lesson';

    return (
        <>
            <Head title={title} />
            <div className="min-h-screen bg-zinc-100">
                <header className="border-b border-zinc-200 bg-white">
                    <div className="mx-auto max-w-6xl px-4 py-6 sm:px-6">
                        <p className="text-xs font-medium uppercase tracking-wide text-indigo-600">Shared lesson</p>
                        <h1 className="mt-1 text-3xl font-semibold tracking-tight text-zinc-900">{title}</h1>
                        {lesson?.stage?.description ? (
                            <p className="mt-3 max-w-3xl text-zinc-600">{lesson.stage.description}</p>
                        ) : null}
                        <p className="mt-4 text-xs text-zinc-400">
                            Read-only view · Scene navigation below · Quizzes use the same grading API as the studio.
                        </p>
                    </div>
                </header>
                <main className="mx-auto max-w-6xl px-4 py-8 sm:px-6">
                    <PublicLessonView lesson={lesson} />
                </main>
            </div>
        </>
    );
}
