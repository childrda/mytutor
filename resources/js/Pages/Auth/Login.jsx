import { Head, Link, useForm } from '@inertiajs/react';

export default function Login() {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    function submit(e) {
        e.preventDefault();
        post('/login');
    }

    return (
        <>
            <Head title="Log in" />
            <div className="flex min-h-screen flex-col items-center justify-center bg-zinc-100 px-4">
                <div className="w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-8 shadow-sm">
                    <h1 className="text-xl font-semibold text-zinc-900">MyTutor</h1>
                    <p className="mt-1 text-sm text-zinc-600">Sign in to manage your lessons</p>
                    <form className="mt-6 flex flex-col gap-4" onSubmit={submit}>
                        <div>
                            <label className="text-sm font-medium text-zinc-700" htmlFor="email">
                                Email
                            </label>
                            <input
                                id="email"
                                type="email"
                                className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                autoComplete="username"
                                required
                            />
                            {errors.email ? <p className="mt-1 text-sm text-red-600">{errors.email}</p> : null}
                        </div>
                        <div>
                            <label className="text-sm font-medium text-zinc-700" htmlFor="password">
                                Password
                            </label>
                            <input
                                id="password"
                                type="password"
                                className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                autoComplete="current-password"
                                required
                            />
                            {errors.password ? <p className="mt-1 text-sm text-red-600">{errors.password}</p> : null}
                        </div>
                        <label className="flex items-center gap-2 text-sm text-zinc-600">
                            <input
                                type="checkbox"
                                checked={data.remember}
                                onChange={(e) => setData('remember', e.target.checked)}
                            />
                            Remember me
                        </label>
                        <button
                            type="submit"
                            disabled={processing}
                            className="rounded-lg bg-indigo-600 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                        >
                            {processing ? 'Signing in…' : 'Sign in'}
                        </button>
                    </form>
                    <p className="mt-6 text-center text-sm text-zinc-600">
                        No account?{' '}
                        <Link href="/register" className="font-medium text-indigo-600 hover:underline">
                            Register
                        </Link>
                    </p>
                </div>
            </div>
        </>
    );
}
