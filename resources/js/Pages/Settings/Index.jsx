import { applyTheme, getStoredLocale, getStoredTheme, setStoredLocale } from '../../lib/uiPrefs.js';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';

const STRINGS = {
    en: {
        title: 'Settings',
        subtitle: 'Integrations, appearance, and verification. Server secrets stay in .env — never shown here.',
        integrations: 'Configured integrations',
        integrationsEmpty: 'No providers are enabled in the server environment yet. Set the appropriate API keys in .env and reload.',
        llmVerify: 'Verify LLM connection',
        baseUrl: 'Base URL',
        apiKey: 'API key (optional)',
        apiKeyHint: 'Stored in this browser only if you save below; chat APIs can also use server defaults.',
        model: 'Model',
        verify: 'Run check',
        verifying: 'Checking…',
        ok: 'Connection OK',
        fail: 'Check failed',
        saveClientKey: 'Remember key in browser',
        theme: 'Theme',
        light: 'Light',
        dark: 'Dark',
        locale: 'Language',
        keyboard: 'Keyboard',
        kSend: 'In the studio chat: ⌘ or Ctrl + Enter sends a message.',
        kScenes: 'In the studio: arrow keys change scenes when focus is not in a field.',
        verifyImage: 'Image providers',
        verifyVideo: 'Video providers',
        verifyPdf: 'PDF providers',
        back: 'Back to home',
        studio: 'Studio',
        toastError: 'Something went wrong',
    },
    'zh-CN': {
        title: '设置',
        subtitle: '集成、外观与连通性检测。服务器密钥仅在 .env 中配置 — 此处不会显示。',
        integrations: '已配置的集成',
        integrationsEmpty: '服务器环境尚未启用任何提供商。请在 .env 中配置相应 API 密钥后重载。',
        llmVerify: '验证大模型连接',
        baseUrl: 'Base URL',
        apiKey: 'API 密钥（可选）',
        apiKeyHint: '若保存，仅保存在本浏览器；对话也可使用服务器默认配置。',
        model: '模型',
        verify: '开始检测',
        verifying: '检测中…',
        ok: '连接正常',
        fail: '检测失败',
        saveClientKey: '在浏览器中记住密钥',
        theme: '主题',
        light: '浅色',
        dark: '深色',
        locale: '语言',
        keyboard: '快捷键',
        kSend: '工作室聊天：⌘ 或 Ctrl + Enter 发送。',
        kScenes: '工作室：焦点不在输入框时，方向键切换场景。',
        verifyImage: '图像提供商',
        verifyVideo: '视频提供商',
        verifyPdf: 'PDF 提供商',
        back: '返回首页',
        studio: '工作室',
        toastError: '出错了',
    },
};

function ProviderBlock({ title, data }) {
    const ids = data && typeof data === 'object' ? Object.keys(data) : [];
    if (ids.length === 0) {
        return (
            <div className="rounded-xl border border-dashed border-zinc-300 bg-zinc-50/80 p-4 dark:border-zinc-600 dark:bg-zinc-900/50">
                <p className="text-sm font-medium text-zinc-700 dark:text-zinc-200">{title}</p>
                <p className="mt-1 text-xs text-zinc-500">—</p>
            </div>
        );
    }
    return (
        <div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <p className="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{title}</p>
            <ul className="mt-2 space-y-2 text-sm text-zinc-700 dark:text-zinc-300">
                {ids.map((id) => {
                    const meta = data[id] || {};
                    return (
                        <li key={id} className="rounded-lg bg-zinc-50 px-2 py-1.5 dark:bg-zinc-800">
                            <span className="font-mono text-xs font-medium">{id}</span>
                            {meta.baseUrl ? (
                                <span className="mt-0.5 block truncate text-xs text-zinc-500">{meta.baseUrl}</span>
                            ) : null}
                            {Array.isArray(meta.models) && meta.models.length > 0 ? (
                                <span className="mt-0.5 block text-xs text-zinc-500">Models: {meta.models.join(', ')}</span>
                            ) : null}
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

export default function SettingsIndex() {
    const { auth } = usePage().props;
    const [locale, setLocale] = useState(() => getStoredLocale());
    const [theme, setTheme] = useState(() => getStoredTheme());
    const [catalog, setCatalog] = useState(null);
    const [loadError, setLoadError] = useState('');
    const [toast, setToast] = useState('');

    const [baseUrl, setBaseUrl] = useState('');
    const [apiKey, setApiKey] = useState('');
    const [model, setModel] = useState('');
    const [verifyBusy, setVerifyBusy] = useState(false);
    const [verifyResult, setVerifyResult] = useState(null);

    const [simpleResults, setSimpleResults] = useState({});

    const t = useCallback((key) => STRINGS[locale]?.[key] || STRINGS.en[key] || key, [locale]);

    useEffect(() => {
        let cancelled = false;
        (async () => {
            try {
                const res = await window.axios.get('/api/integrations');
                if (!res.data?.success) {
                    throw new Error(res.data?.error || 'Failed');
                }
                if (!cancelled) {
                    setCatalog(res.data);
                }
            } catch (e) {
                if (!cancelled) {
                    setLoadError(e.response?.data?.error || e.message || 'Failed to load');
                }
            }
        })();
        return () => {
            cancelled = true;
        };
    }, []);

    const onLocale = (next) => {
        const v = setStoredLocale(next);
        setLocale(v);
        setToast('');
    };

    const onTheme = (next) => {
        setTheme(next);
        applyTheme(next);
    };

    const verifyModel = async () => {
        setVerifyBusy(true);
        setVerifyResult(null);
        try {
            const res = await window.axios.post('/api/verify/model', {
                baseUrl: baseUrl || undefined,
                apiKey: apiKey || undefined,
                model: model || undefined,
            });
            const d = res.data || {};
            setVerifyResult({ ok: d.ok === true, ...d });
        } catch (e) {
            setVerifyResult({ ok: false, error: e.response?.data?.error || e.message });
            setToast(t('toastError'));
        } finally {
            setVerifyBusy(false);
        }
    };

    const verifySimple = async (key, path) => {
        try {
            const res = await window.axios.post(`/api/verify/${path}`);
            setSimpleResults((r) => ({ ...r, [key]: res.data }));
        } catch (e) {
            setSimpleResults((r) => ({
                ...r,
                [key]: { ok: false, message: e.response?.data?.error || e.message },
            }));
        }
    };

    const rememberKey = () => {
        try {
            localStorage.setItem('mytutor_client_llm_key', apiKey);
            setToast('Saved locally');
            setTimeout(() => setToast(''), 2000);
        } catch {
            setToast(t('toastError'));
        }
    };

    useEffect(() => {
        try {
            const k = localStorage.getItem('mytutor_client_llm_key');
            if (k) {
                setApiKey(k);
            }
        } catch {
            /* ignore */
        }
    }, []);

    const sections = useMemo(
        () => [
            { key: 'providers', title: 'LLM', data: catalog?.providers },
            { key: 'tts', title: 'TTS', data: catalog?.tts },
            { key: 'asr', title: 'ASR', data: catalog?.asr },
            { key: 'pdf', title: 'PDF', data: catalog?.pdf },
            { key: 'image', title: 'Image', data: catalog?.image },
            { key: 'video', title: 'Video', data: catalog?.video },
            { key: 'webSearch', title: 'Web search', data: catalog?.webSearch },
        ],
        [catalog],
    );

    const hasAnyProvider = sections.some((s) => s.data && Object.keys(s.data).length > 0);

    return (
        <>
            <Head title={t('title')} />
            <div className="min-h-screen bg-zinc-50 dark:bg-zinc-950">
                <div className="mx-auto max-w-3xl px-4 py-10 sm:px-6">
                    <header className="border-b border-zinc-200 pb-6 dark:border-zinc-800">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-100">{t('title')}</h1>
                                <p className="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{t('subtitle')}</p>
                            </div>
                            <div className="flex flex-wrap gap-2 text-sm">
                                <Link href="/" className="text-indigo-600 hover:underline dark:text-indigo-400">
                                    {t('back')}
                                </Link>
                                <Link href="/studio" className="text-zinc-600 hover:underline dark:text-zinc-400">
                                    {t('studio')}
                                </Link>
                            </div>
                        </div>
                    </header>

                    {toast ? (
                        <p className="mt-4 rounded-lg bg-zinc-900 px-3 py-2 text-sm text-white dark:bg-zinc-100 dark:text-zinc-900">
                            {toast}
                        </p>
                    ) : null}

                    <section className="mt-8 space-y-4">
                        <h2 className="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            {t('theme')} & {t('locale')}
                        </h2>
                        <div className="flex flex-wrap gap-4 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                            <div>
                                <label className="text-xs font-medium text-zinc-600 dark:text-zinc-400" htmlFor="theme-sel">
                                    {t('theme')}
                                </label>
                                <select
                                    id="theme-sel"
                                    className="mt-1 block rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-950 dark:text-zinc-100"
                                    value={theme}
                                    onChange={(e) => onTheme(e.target.value)}
                                >
                                    <option value="light">{t('light')}</option>
                                    <option value="dark">{t('dark')}</option>
                                </select>
                            </div>
                            <div>
                                <label className="text-xs font-medium text-zinc-600 dark:text-zinc-400" htmlFor="locale-sel">
                                    {t('locale')}
                                </label>
                                <select
                                    id="locale-sel"
                                    className="mt-1 block rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-950 dark:text-zinc-100"
                                    value={locale}
                                    onChange={(e) => onLocale(e.target.value)}
                                >
                                    <option value="en">English</option>
                                    <option value="zh-CN">中文（简体）</option>
                                </select>
                            </div>
                        </div>
                    </section>

                    <section className="mt-10">
                        <h2 className="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            {t('integrations')}
                        </h2>
                        {loadError ? (
                            <p className="mt-3 text-sm text-red-600 dark:text-red-400">{loadError}</p>
                        ) : !catalog ? (
                            <p className="mt-3 text-sm text-zinc-500">Loading…</p>
                        ) : !hasAnyProvider ? (
                            <p className="mt-3 rounded-xl border border-dashed border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                                {t('integrationsEmpty')}
                            </p>
                        ) : (
                            <div className="mt-4 grid gap-3 sm:grid-cols-2">
                                {sections.map((s) => (
                                    <ProviderBlock key={s.key} title={s.title} data={s.data} />
                                ))}
                            </div>
                        )}
                    </section>

                    <section className="mt-10 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                        <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{t('llmVerify')}</h2>
                        <p className="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{t('apiKeyHint')}</p>
                        <div className="mt-4 grid gap-3 sm:grid-cols-2">
                            <div className="sm:col-span-2">
                                <label className="text-xs text-zinc-600 dark:text-zinc-400" htmlFor="v-base">
                                    {t('baseUrl')}
                                </label>
                                <input
                                    id="v-base"
                                    className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 font-mono text-sm dark:border-zinc-600 dark:bg-zinc-950 dark:text-zinc-100"
                                    placeholder="https://api.openai.com/v1"
                                    value={baseUrl}
                                    onChange={(e) => setBaseUrl(e.target.value)}
                                    autoComplete="off"
                                />
                            </div>
                            <div className="sm:col-span-2">
                                <label className="text-xs text-zinc-600 dark:text-zinc-400" htmlFor="v-key">
                                    {t('apiKey')}
                                </label>
                                <input
                                    id="v-key"
                                    type="password"
                                    className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 font-mono text-sm dark:border-zinc-600 dark:bg-zinc-950 dark:text-zinc-100"
                                    value={apiKey}
                                    onChange={(e) => setApiKey(e.target.value)}
                                    autoComplete="off"
                                />
                            </div>
                            <div className="sm:col-span-2">
                                <label className="text-xs text-zinc-600 dark:text-zinc-400" htmlFor="v-model">
                                    {t('model')}
                                </label>
                                <input
                                    id="v-model"
                                    className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 font-mono text-sm dark:border-zinc-600 dark:bg-zinc-950 dark:text-zinc-100"
                                    placeholder="gpt-4o-mini"
                                    value={model}
                                    onChange={(e) => setModel(e.target.value)}
                                    autoComplete="off"
                                />
                            </div>
                        </div>
                        <div className="mt-4 flex flex-wrap gap-2">
                            <button
                                type="button"
                                disabled={verifyBusy}
                                onClick={verifyModel}
                                className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                            >
                                {verifyBusy ? t('verifying') : t('verify')}
                            </button>
                            <button
                                type="button"
                                onClick={rememberKey}
                                className="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-200 dark:hover:bg-zinc-800"
                            >
                                {t('saveClientKey')}
                            </button>
                        </div>
                        {verifyResult ? (
                            <div
                                className={`mt-4 rounded-lg border px-3 py-2 text-sm ${
                                    verifyResult.ok
                                        ? 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-100'
                                        : 'border-red-200 bg-red-50 text-red-900 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100'
                                }`}
                            >
                                {verifyResult.ok ? t('ok') : `${t('fail')}: ${verifyResult.error || verifyResult.body || JSON.stringify(verifyResult)}`}
                            </div>
                        ) : null}
                    </section>

                    <section className="mt-10 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                        <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Provider checks</h2>
                        <p className="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                            Server-side env configuration (no client secrets).
                        </p>
                        <div className="mt-4 flex flex-wrap gap-2">
                            <button
                                type="button"
                                onClick={() => verifySimple('image', 'image-provider')}
                                className="rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:text-zinc-200"
                            >
                                {t('verifyImage')}
                            </button>
                            <button
                                type="button"
                                onClick={() => verifySimple('video', 'video-provider')}
                                className="rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:text-zinc-200"
                            >
                                {t('verifyVideo')}
                            </button>
                            <button
                                type="button"
                                onClick={() => verifySimple('pdf', 'pdf-provider')}
                                className="rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:text-zinc-200"
                            >
                                {t('verifyPdf')}
                            </button>
                        </div>
                        {Object.keys(simpleResults).length > 0 ? (
                            <pre className="mt-3 max-h-40 overflow-auto rounded-lg bg-zinc-100 p-2 text-xs dark:bg-zinc-950 dark:text-zinc-300">
                                {JSON.stringify(simpleResults, null, 2)}
                            </pre>
                        ) : null}
                    </section>

                    <section className="mt-10 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                        <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{t('keyboard')}</h2>
                        <ul className="mt-3 list-inside list-disc space-y-1 text-sm text-zinc-600 dark:text-zinc-400">
                            <li>{t('kSend')}</li>
                            <li>{t('kScenes')}</li>
                        </ul>
                    </section>

                    {auth?.user ? (
                        <p className="mt-10 text-center text-sm text-zinc-500 dark:text-zinc-500">
                            <button
                                type="button"
                                className="text-indigo-600 hover:underline dark:text-indigo-400"
                                onClick={() => router.post('/logout')}
                            >
                                Log out
                            </button>
                        </p>
                    ) : null}
                </div>
            </div>
        </>
    );
}
