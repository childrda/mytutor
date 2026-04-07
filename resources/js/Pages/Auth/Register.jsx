import { Head, Link, useForm } from '@inertiajs/react';

export default function Register() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    function submit(e) {
        e.preventDefault();
        post('/register');
    }

    return (
        <>
            <Head title="Register" />
            <div className="flex min-h-screen flex-col items-center justify-center bg-zinc-100 px-4">
                <div className="w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-8 shadow-sm">
                    <h1 className="text-xl font-semibold text-zinc-900">Create account</h1>
                    <p className="mt-1 text-sm text-zinc-600">Start building lessons on MyTutor</p>
                    <form className="mt-6 flex flex-col gap-4" onSubmit={submit}>
                        <div>
                            <label className="text-sm font-medium text-zinc-700" htmlFor="name">
                                Name
                            </label>
                            <input
                                id="name"
                                className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                required
                            />
                            {errors.name ? <p className="mt-1 text-sm text-red-600">{errors.name}</p> : null}
                        </div>
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
                                autoComplete="new-password"
                                required
                            />
                            {errors.password ? <p className="mt-1 text-sm text-red-600">{errors.password}</p> : null}
                        </div>
                        <div>
                            <label className="text-sm font-medium text-zinc-700" htmlFor="password_confirmation">
                                Confirm password
                            </label>
                            <input
                                id="password_confirmation"
                                type="password"
                                className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                                value={data.password_confirmation}
                                onChange={(e) => setData('password_confirmation', e.target.value)}
                                autoComplete="new-password"
                                required
                            />
                        </div>
                        <button
                            type="submit"
                            disabled={processing}
                            className="rounded-lg bg-indigo-600 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                        >
                            {processing ? 'Creating…' : 'Register'}
                        </button>
                    </form>
                    <p className="mt-6 text-center text-sm text-zinc-600">
                        Already have an account?{' '}
                        <Link href="/login" className="font-medium text-indigo-600 hover:underline">
                            Log in
                        </Link>
                    </p>
                </div>
            </div>
        </>
    );
}
