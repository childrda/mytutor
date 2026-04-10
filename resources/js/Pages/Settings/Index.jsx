import { applyTheme, getStoredTheme } from '../../lib/uiPrefs.js';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import ProviderBrandIcon from '../../components/settings/ProviderBrandIcon.jsx';

const STRINGS = {
    en: {
        title: 'Settings',
        subtitle:
            'Integrations, appearance, active registry providers (signed-in), and verification. API keys stay in server .env — not shown here; active provider keys can also be saved in the database from this page when env vars are unset.',
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
        keyboard: 'Keyboard',
        kSend: 'In the studio chat: ⌘ or Ctrl + Enter sends a message.',
        kScenes: 'In the studio: arrow keys change scenes when focus is not in a field.',
        verifyImage: 'Image providers',
        verifyVideo: 'Video providers',
        verifyPdf: 'PDF providers',
        back: 'Back to home',
        studio: 'Studio',
        toastError: 'Something went wrong',
        registryTitle: 'Active registry providers',
        registrySubtitle:
            'App-wide selection when TUTOR_ACTIVE_* env vars are unset. If an env var is set, it always wins. Queue workers still need a restart to pick up .env changes.',
        registrySave: 'Save selections',
        registrySaving: 'Saving…',
        registrySaved: 'Saved',
        registryEnvOverride: 'Server env overrides this row (effective value shown below).',
        registryEffective: 'Active now',
        registryNone: 'None (legacy)',
        registryLoginHint: 'Sign in to choose active providers for this server.',
        registryLoadError: 'Could not load registry selections',
        catalogTitle: 'Model catalog',
        catalogSubtitle:
            'Per service: pick a provider, set base URL and models in models.json (signed-in). API keys stay in server .env only — referenced by provider env_key. File must be writable; restart queue workers after edits.',
        catalogCapability: 'Capability',
        catalogServices: 'Service',
        catalogProvidersColumn: 'Providers',
        catalogDetailColumn: 'Provider setup',
        catalogProviderBaseUrl: 'Base URL',
        catalogProviderBaseHint: 'Stored in models.json on every row for this provider under this service.',
        catalogSaveBaseUrl: 'Save base URL',
        catalogBaseUrlConflict: 'Rows use different base_url values; saving applies one value to all of them.',
        catalogServerKeyOk: 'Server .env has a non-empty value for {key}.',
        catalogServerKeyMissing: 'No value in .env for {key} yet — add it to your server environment and reload PHP.',
        catalogModelsForProvider: 'Models',
        catalogAddModelVariant: 'Add model',
        catalogVariantId: 'Registry id (slug)',
        catalogVariantName: 'Display name',
        catalogVariantApiId: 'Vendor model id (optional)',
        catalogCreateVariant: 'Add',
        catalogDeleteBundle: 'Delete provider bundle',
        catalogDeleteBundleHelp:
            'Removes every models.json row in this group (same provider + base URL). Clear Active registry first if any row id is selected.',
        catalogDeleteBundleConfirm:
            'Delete all {n} model row(s) in this group? Active registry keys that point at any of them will block the action.',
        catalogAdvancedJson: 'Advanced: row JSON',
        catalogEnvKeyNote: 'Never put API keys in models.json.',
        catalogModelsList: 'Models',
        catalogSelectHint: 'Select a model for advanced JSON or test.',
        catalogAddStub: 'Add stub model',
        catalogNewId: 'New id (slug)',
        catalogNewName: 'Display name',
        catalogNewProvider: 'Provider',
        catalogNewNote: 'Stub note',
        catalogCreate: 'Create stub',
        catalogCreating: 'Creating…',
        catalogDelete: 'Delete',
        catalogTest: 'Test connection',
        catalogTesting: 'Testing…',
        catalogTestResultTitle: 'Connection test result',
        catalogSaveJson: 'Save JSON',
        catalogSaving: 'Saving…',
        catalogJsonLabel: 'Model JSON (source shape)',
        catalogLoadError: 'Could not load model catalog',
        catalogTestOverrides: 'Test overrides (optional)',
        catalogTestModel: 'Model id for probe',
        catalogRefreshList: 'Refresh list',
        catalogVariantNeedProvider: 'Select a provider in the middle list first.',
        catalogVariantNeedFields: 'Enter registry id (slug) and display name.',
    },
};

const REGISTRY_CAP_LABELS = {
    llm: 'LLM',
    image: 'Image',
    tts: 'TTS',
    asr: 'ASR',
    pdf: 'PDF',
    video: 'Video',
    web_search: 'Web search',
};

const CATALOG_SERVICE_LABELS = {
    llm: 'LLM',
    image: 'Image generation',
    tts: 'Text-to-speech',
    asr: 'Speech recognition',
    pdf: 'PDF parsing',
    video: 'Video generation',
    web_search: 'Web search',
};

const CATALOG_CAPABILITIES = ['llm', 'image', 'tts', 'asr', 'pdf', 'video', 'web_search'];

function firstValidationMessage(errors) {
    if (!errors || typeof errors !== 'object') {
        return null;
    }
    for (const v of Object.values(errors)) {
        if (Array.isArray(v) && v[0]) {
            return String(v[0]);
        }
        if (typeof v === 'string' && v) {
            return v;
        }
    }
    return null;
}

/** Laravel JSON error / validation → single user-visible string */
function formatCatalogApiError(error, tToastError) {
    const d = error?.response?.data;
    if (d?.error && typeof d.error === 'string') {
        return d.error;
    }
    if (d?.message && typeof d.message === 'string') {
        return d.message;
    }
    const vm = firstValidationMessage(d?.errors);
    if (vm) {
        return vm;
    }
    if (error?.message) {
        return error.message;
    }
    return tToastError;
}

/** Provider + normalized base_url bucket; use JSON — not \\0 — so selection state always matches list rows. */
function catalogGroupKey(providerId, normalizedBaseUrl) {
    return JSON.stringify([providerId, normalizedBaseUrl]);
}

function catalogGroupKeyProviderPart(storedKey) {
    if (!storedKey || typeof storedKey !== 'string') {
        return null;
    }
    try {
        const a = JSON.parse(storedKey);
        if (Array.isArray(a) && typeof a[0] === 'string' && a[0] !== '') {
            return a[0];
        }
    } catch {
        if (storedKey.includes('\0')) {
            const p = storedKey.split('\0')[0];
            return p || null;
        }
    }
    return null;
}

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

    const [registryPayload, setRegistryPayload] = useState(null);
    const [registrySelections, setRegistrySelections] = useState({});
    const [registryLoadError, setRegistryLoadError] = useState('');
    const [registrySaveBusy, setRegistrySaveBusy] = useState(false);

    const [catalogProvidersRoot, setCatalogProvidersRoot] = useState(null);
    const [catalogModelsDoc, setCatalogModelsDoc] = useState(null);
    const [catalogLoadErrorCatalog, setCatalogLoadErrorCatalog] = useState('');
    const [catalogCap, setCatalogCap] = useState('llm');
    const [selectedModelId, setSelectedModelId] = useState('');
    const [selectedModelJson, setSelectedModelJson] = useState('');
    const [catalogStub, setCatalogStub] = useState({ id: '', displayName: '', provider: 'openai', note: '' });
    const [catalogCreateBusy, setCatalogCreateBusy] = useState(false);
    const [catalogSaveJsonBusy, setCatalogSaveJsonBusy] = useState(false);
    const [catalogTestBusy, setCatalogTestBusy] = useState(false);
    const [catalogTestResult, setCatalogTestResult] = useState(null);
    const [testOverrides, setTestOverrides] = useState({ model: '', apiKey: '', baseUrl: '' });
    const [catalogEnvKeyConfigured, setCatalogEnvKeyConfigured] = useState({});
    const [catalogSelectedGroupKey, setCatalogSelectedGroupKey] = useState('');
    const [catalogProviderBaseDraft, setCatalogProviderBaseDraft] = useState('');
    const [catalogVariant, setCatalogVariant] = useState({ id: '', displayName: '', apiModelId: '' });
    const [catalogProviderBaseBusy, setCatalogProviderBaseBusy] = useState(false);
    const [catalogVariantBusy, setCatalogVariantBusy] = useState(false);
    const [catalogBundleBusy, setCatalogBundleBusy] = useState(false);

    const t = useCallback((key) => STRINGS.en[key] || key, []);

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

    useEffect(() => {
        if (!auth?.user) {
            setRegistryPayload(null);
            setRegistrySelections({});
            setRegistryLoadError('');
            return;
        }
        let cancelled = false;
        (async () => {
            try {
                const res = await window.axios.get('/settings/catalog/active');
                if (!res.data?.success) {
                    throw new Error(res.data?.message || res.data?.error || 'Failed');
                }
                if (cancelled) {
                    return;
                }
                setRegistryPayload(res.data);
                setRegistryLoadError('');
                const caps = res.data.capabilities || [];
                const nextSel = {};
                for (const c of caps) {
                    nextSel[c] = res.data.database?.[c] ?? '';
                }
                setRegistrySelections(nextSel);
            } catch (e) {
                if (!cancelled) {
                    setRegistryLoadError(e.response?.data?.message || e.message || t('registryLoadError'));
                }
            }
        })();
        return () => {
            cancelled = true;
        };
    }, [auth?.user, t]);

    const saveRegistryActives = async () => {
        if (!registryPayload?.capabilities) {
            return;
        }
        setRegistrySaveBusy(true);
        try {
            const active = {};
            for (const c of registryPayload.capabilities) {
                const v = registrySelections[c];
                active[c] = v === '' || v === undefined ? null : v;
            }
            const res = await window.axios.put('/settings/catalog/active', { active });
            if (!res.data?.success) {
                throw new Error(res.data?.message || res.data?.error || 'Failed');
            }
            setToast(t('registrySaved'));
            setTimeout(() => setToast(''), 2500);
            const show = await window.axios.get('/settings/catalog/active');
            if (show.data?.success) {
                setRegistryPayload(show.data);
            }
        } catch (e) {
            setToast(e.response?.data?.message || e.message || t('toastError'));
            setTimeout(() => setToast(''), 3000);
        } finally {
            setRegistrySaveBusy(false);
        }
    };

    const refreshCatalogModelsAndRegistry = useCallback(async () => {
        if (!auth?.user) {
            return;
        }
        try {
            const [p, m, r] = await Promise.all([
                window.axios.get('/settings/catalog/providers'),
                window.axios.get('/settings/catalog/models'),
                window.axios.get('/settings/catalog/active'),
            ]);
            if (p.data?.success) {
                setCatalogProvidersRoot(p.data.providers);
                setCatalogEnvKeyConfigured(p.data.env_key_configured || {});
            }
            if (m.data?.success) {
                setCatalogModelsDoc(m.data.models);
            }
            if (r.data?.success) {
                setRegistryPayload(r.data);
                const caps = r.data.capabilities || [];
                const nextSel = {};
                for (const c of caps) {
                    nextSel[c] = r.data.database?.[c] ?? '';
                }
                setRegistrySelections(nextSel);
            }
        } catch {
            /* background refresh */
        }
    }, [auth?.user]);

    const providerOptionsForCap = useMemo(() => {
        const root = catalogProvidersRoot;
        if (!root || typeof root !== 'object') {
            return [];
        }
        const map = root.providers;
        if (!map || typeof map !== 'object') {
            return [];
        }
        return Object.entries(map)
            .filter(([k, v]) => typeof k === 'string' && k && !k.startsWith('_'))
            .filter(([, v]) => Array.isArray(v.capabilities) && v.capabilities.includes(catalogCap))
            .map(([k, v]) => ({ id: k, name: typeof v.name === 'string' ? v.name : k }))
            .sort((a, b) => a.name.localeCompare(b.name));
    }, [catalogProvidersRoot, catalogCap]);

    const catalogRows = useMemo(() => {
        const list = catalogModelsDoc?.[catalogCap];
        return Array.isArray(list) ? list.filter((r) => r && r.id) : [];
    }, [catalogModelsDoc, catalogCap]);

    const catalogProviderGroups = useMemo(() => {
        const map = new Map();
        for (const row of catalogRows) {
            const rawPid = row.provider;
            const pid =
                typeof rawPid === 'string' && rawPid.trim() !== ''
                    ? rawPid.trim()
                    : '_unknown';
            const bu =
                typeof row.base_url === 'string' ? row.base_url.trim().replace(/\/+$/, '') : '';
            const groupKey = catalogGroupKey(pid, bu);
            if (!map.has(groupKey)) {
                map.set(groupKey, { groupKey, providerId: pid, baseUrl: bu, rows: [] });
            }
            map.get(groupKey).rows.push(row);
        }
        return Array.from(map.values()).sort((a, b) => {
            const c = a.providerId.localeCompare(b.providerId);
            return c !== 0 ? c : a.baseUrl.localeCompare(b.baseUrl);
        });
    }, [catalogRows]);

    const rowsForSelectedGroup = useMemo(() => {
        const g = catalogProviderGroups.find((x) => x.groupKey === catalogSelectedGroupKey);
        return g ? g.rows : [];
    }, [catalogProviderGroups, catalogSelectedGroupKey]);

    const catalogSelectedProviderId = rowsForSelectedGroup[0]?.provider || '';

    const catalogBaseConflict = useMemo(() => {
        const bases = new Set(
            rowsForSelectedGroup
                .map((r) =>
                    typeof r.base_url === 'string' ? r.base_url.trim().replace(/\/+$/, '') : '',
                )
                .filter(Boolean),
        );
        return bases.size > 1;
    }, [rowsForSelectedGroup]);

    const providerCatalogDisplayName = useCallback(
        (pid) => {
            const map = catalogProvidersRoot?.providers;
            const meta = map?.[pid];
            return typeof meta?.name === 'string' ? meta.name : pid;
        },
        [catalogProvidersRoot],
    );

    const providerCatalogIcon = useCallback(
        (pid) => {
            const map = catalogProvidersRoot?.providers;
            const meta = map?.[pid];
            return typeof meta?.icon === 'string' ? meta.icon : pid;
        },
        [catalogProvidersRoot],
    );

    const providerCatalogEnvKey = useCallback(
        (pid) => {
            const map = catalogProvidersRoot?.providers;
            const meta = map?.[pid];
            const ek = meta?.env_key;
            if (typeof ek !== 'string' || ek === '' || ek === '{env_key}') {
                return '';
            }
            return ek;
        },
        [catalogProvidersRoot],
    );

    const catalogLlmRequestPreviewUrl = useMemo(() => {
        if (catalogCap !== 'llm') {
            return '';
        }
        const row = rowsForSelectedGroup[0];
        const b = catalogProviderBaseDraft.trim().replace(/\/+$/, '');
        if (!b || !row?.endpoint || typeof row.endpoint !== 'string') {
            return '';
        }
        let ep = row.endpoint.replace(/\{base_url(?:\|[^}]*)?\}/g, '');
        const suffix = ep.startsWith('/') ? ep : `/${ep}`;
        const pathOnly = suffix
            .replace(/\{model\|([^}]+)\}/g, '$1')
            .replace(/\{model\}/g, 'model');
        return `${b}${pathOnly}`;
    }, [catalogCap, rowsForSelectedGroup, catalogProviderBaseDraft]);

    useEffect(() => {
        const keys = catalogProviderGroups.map((g) => g.groupKey);
        if (keys.length === 0) {
            setCatalogSelectedGroupKey('');
            return;
        }
        setCatalogSelectedGroupKey((cur) => {
            if (cur && keys.includes(cur)) {
                return cur;
            }
            // After "Save base URL", group keys change (e.g. openai + "" → openai + https://…).
            // Stay on the same provider instead of jumping to keys[0] (often another provider alphabetically).
            if (cur && typeof cur === 'string') {
                const prevPid = catalogGroupKeyProviderPart(cur);
                if (prevPid) {
                    const same = catalogProviderGroups.find((g) => g.providerId === prevPid);
                    if (same && keys.includes(same.groupKey)) {
                        return same.groupKey;
                    }
                }
            }
            return keys[0];
        });
    }, [catalogCap, catalogProviderGroups]);

    useEffect(() => {
        if (!catalogSelectedGroupKey) {
            setCatalogProviderBaseDraft('');
            return;
        }
        const bases = [
            ...new Set(
                rowsForSelectedGroup
                    .map((r) =>
                        typeof r.base_url === 'string' ? r.base_url.trim().replace(/\/+$/, '') : '',
                    )
                    .filter(Boolean),
            ),
        ];
        if (bases.length === 1) {
            setCatalogProviderBaseDraft(bases[0]);
        } else if (bases.length === 0) {
            setCatalogProviderBaseDraft('');
        } else {
            setCatalogProviderBaseDraft(bases[0]);
        }
    }, [catalogSelectedGroupKey, rowsForSelectedGroup]);

    useEffect(() => {
        if (!selectedModelId) {
            return;
        }
        if (!rowsForSelectedGroup.some((r) => r.id === selectedModelId)) {
            setSelectedModelId('');
        }
    }, [catalogSelectedGroupKey, rowsForSelectedGroup, selectedModelId]);

    useEffect(() => {
        if (!auth?.user) {
            setCatalogProvidersRoot(null);
            setCatalogEnvKeyConfigured({});
            setCatalogModelsDoc(null);
            setCatalogLoadErrorCatalog('');
            return;
        }
        let cancelled = false;
        (async () => {
            try {
                const [pRes, mRes] = await Promise.all([
                    window.axios.get('/settings/catalog/providers'),
                    window.axios.get('/settings/catalog/models'),
                ]);
                if (cancelled) {
                    return;
                }
                if (!pRes.data?.success || !mRes.data?.success) {
                    throw new Error('Failed');
                }
                setCatalogProvidersRoot(pRes.data.providers);
                setCatalogEnvKeyConfigured(pRes.data.env_key_configured || {});
                setCatalogModelsDoc(mRes.data.models);
                setCatalogLoadErrorCatalog('');
            } catch (e) {
                if (!cancelled) {
                    setCatalogLoadErrorCatalog(e.response?.data?.error || e.message || t('catalogLoadError'));
                }
            }
        })();
        return () => {
            cancelled = true;
        };
    }, [auth?.user, t]);

    useEffect(() => {
        setSelectedModelId('');
        setCatalogTestResult(null);
    }, [catalogCap]);

    useEffect(() => {
        const first = providerOptionsForCap[0]?.id;
        if (!first) {
            return;
        }
        setCatalogStub((s) => {
            if (providerOptionsForCap.some((p) => p.id === s.provider)) {
                return s;
            }
            return { ...s, provider: first };
        });
    }, [catalogCap, providerOptionsForCap]);

    useEffect(() => {
        if (!selectedModelId || !catalogModelsDoc) {
            setSelectedModelJson('');
            return;
        }
        const list = Array.isArray(catalogModelsDoc[catalogCap]) ? catalogModelsDoc[catalogCap] : [];
        const row = list.find((r) => r && r.id === selectedModelId);
        setSelectedModelJson(row ? JSON.stringify(row, null, 2) : '');
        setCatalogTestResult(null);
    }, [selectedModelId, catalogCap, catalogModelsDoc]);

    const createCatalogStub = async () => {
        const id = catalogStub.id.trim();
        const display_name = catalogStub.displayName.trim();
        const provider = catalogStub.provider.trim();
        const note = catalogStub.note.trim();
        if (!id || !display_name || !provider || !note) {
            setToast(t('toastError'));
            setTimeout(() => setToast(''), 2500);
            return;
        }
        setCatalogCreateBusy(true);
        try {
            const res = await window.axios.post(`/settings/catalog/models/${catalogCap}`, {
                id,
                provider,
                display_name,
                _note: note,
            });
            if (!res.data?.success) {
                throw new Error(res.data?.error || 'Failed');
            }
            setToast(t('registrySaved'));
            setTimeout(() => setToast(''), 2500);
            setCatalogStub((s) => ({ ...s, id: '', displayName: '', note: '' }));
            await refreshCatalogModelsAndRegistry();
            setSelectedModelId(id);
        } catch (e) {
            setToast(e.response?.data?.error || e.message || t('toastError'));
            setTimeout(() => setToast(''), 3500);
        } finally {
            setCatalogCreateBusy(false);
        }
    };

    const deleteCatalogModel = async (id) => {
        if (!window.confirm(`Delete model "${id}" in ${catalogCap}?`)) {
            return;
        }
        try {
            const res = await window.axios.delete(`/settings/catalog/models/${catalogCap}/${encodeURIComponent(id)}`);
            if (!res.data?.success) {
                throw new Error(res.data?.error || 'Failed');
            }
            setToast(t('registrySaved'));
            setTimeout(() => setToast(''), 2000);
            if (selectedModelId === id) {
                setSelectedModelId('');
            }
            await refreshCatalogModelsAndRegistry();
        } catch (e) {
            setToast(e.response?.data?.error || e.message || t('toastError'));
            setTimeout(() => setToast(''), 3500);
        }
    };

    const saveCatalogModelJson = async () => {
        if (!selectedModelId) {
            return;
        }
        let parsed;
        try {
            parsed = JSON.parse(selectedModelJson);
        } catch {
            setToast(t('toastError'));
            setTimeout(() => setToast(''), 2500);
            return;
        }
        if (parsed.id !== selectedModelId) {
            setToast('JSON id must match selected model');
            setTimeout(() => setToast(''), 3000);
            return;
        }
        setCatalogSaveJsonBusy(true);
        try {
            const res = await window.axios.put(
                `/settings/catalog/models/${catalogCap}/${encodeURIComponent(selectedModelId)}`,
                parsed,
            );
            if (!res.data?.success) {
                throw new Error(res.data?.error || 'Failed');
            }
            setToast(t('registrySaved'));
            setTimeout(() => setToast(''), 2000);
            await refreshCatalogModelsAndRegistry();
        } catch (e) {
            setToast(e.response?.data?.error || e.message || t('toastError'));
            setTimeout(() => setToast(''), 3500);
        } finally {
            setCatalogSaveJsonBusy(false);
        }
    };

    const testCatalogModel = async (maybeExplicitRowId) => {
        // Row button passes a string id; Advanced uses onClick={() => testCatalogModel()} so we never treat a DOM event as an id.
        const explicitRowId = typeof maybeExplicitRowId === 'string' ? maybeExplicitRowId : '';
        const id = explicitRowId || selectedModelId;
        if (!id) {
            return;
        }
        if (explicitRowId) {
            setSelectedModelId(explicitRowId);
        }
        setCatalogTestBusy(true);
        setCatalogTestResult(null);
        try {
            const body = {};
            // Row "Test connection" should use only that models.json row (+ .env); ignore leftover Advanced override fields.
            if (!explicitRowId) {
                if (testOverrides.model.trim()) {
                    body.model = testOverrides.model.trim();
                }
                if (testOverrides.apiKey.trim()) {
                    body.apiKey = testOverrides.apiKey.trim();
                }
                if (testOverrides.baseUrl.trim()) {
                    body.baseUrl = testOverrides.baseUrl.trim();
                }
            }
            const res = await window.axios.post(
                `/settings/catalog/models/${catalogCap}/${encodeURIComponent(id)}/test`,
                body,
            );
            setCatalogTestResult(res.data || {});
        } catch (e) {
            setCatalogTestResult({
                success: false,
                ok: false,
                error: e.response?.data?.error || e.message,
            });
        } finally {
            setCatalogTestBusy(false);
        }
    };

    const saveCatalogProviderBase = async () => {
        const grp = catalogProviderGroups.find((x) => x.groupKey === catalogSelectedGroupKey);
        const rows = grp?.rows ?? rowsForSelectedGroup;
        const provider = grp?.providerId || catalogSelectedProviderId;
        if (!provider || !rows?.length) {
            setToast(
                !catalogSelectedGroupKey
                    ? 'Select a provider group in the list first.'
                    : 'No model rows in this group — cannot save base URL. Click the provider in the list again.',
            );
            setTimeout(() => setToast(''), 5000);
            return;
        }
        setCatalogProviderBaseBusy(true);
        try {
            const res = await window.axios.post(
                `/settings/catalog/models/${catalogCap}/provider-base-url`,
                {
                    provider,
                    base_url: catalogProviderBaseDraft.trim(),
                    row_ids: rows.map((r) => r.id).filter(Boolean),
                },
            );
            if (!res.data?.success) {
                throw new Error(res.data?.error || 'Failed');
            }
            if (res.data?.models && typeof res.data.models === 'object') {
                setCatalogModelsDoc(res.data.models);
            }
            setToast(t('registrySaved'));
            setTimeout(() => setToast(''), 2000);
            await refreshCatalogModelsAndRegistry();
        } catch (e) {
            setToast(e.response?.data?.error || e.message || t('toastError'));
            setTimeout(() => setToast(''), 3500);
        } finally {
            setCatalogProviderBaseBusy(false);
        }
    };

    const createCatalogVariant = async () => {
        const grp = catalogProviderGroups.find((x) => x.groupKey === catalogSelectedGroupKey);
        const provider = grp?.providerId || catalogSelectedProviderId;
        if (!provider) {
            setToast(t('catalogVariantNeedProvider'));
            setTimeout(() => setToast(''), 4500);
            return;
        }
        const id = catalogVariant.id.trim();
        const display_name = catalogVariant.displayName.trim();
        if (!id || !display_name) {
            setToast(t('catalogVariantNeedFields'));
            setTimeout(() => setToast(''), 4000);
            return;
        }
        setCatalogVariantBusy(true);
        try {
            const body = {
                id,
                provider,
                display_name,
            };
            const apiId = catalogVariant.apiModelId.trim();
            if (apiId) {
                body.api_model_id = apiId;
            }
            const tb = (grp?.rows ?? rowsForSelectedGroup)[0]?.base_url;
            if (typeof tb === 'string' && tb.trim() !== '') {
                body.template_base_url = tb.trim();
            }
            const res = await window.axios.post(`/settings/catalog/models/${catalogCap}/variant`, body);
            if (!res.data?.success) {
                throw new Error(res.data?.error || 'Failed');
            }
            if (res.data?.models && typeof res.data.models === 'object') {
                setCatalogModelsDoc(res.data.models);
            }
            setToast(t('registrySaved'));
            setTimeout(() => setToast(''), 2000);
            setCatalogVariant({ id: '', displayName: '', apiModelId: '' });
            await refreshCatalogModelsAndRegistry();
            setSelectedModelId(id);
        } catch (e) {
            setToast(formatCatalogApiError(e, t('toastError')));
            setTimeout(() => setToast(''), 5000);
        } finally {
            setCatalogVariantBusy(false);
        }
    };

    const deleteCatalogProviderBundle = async () => {
        if (rowsForSelectedGroup.length === 0) {
            return;
        }
        const n = rowsForSelectedGroup.length;
        const msg = (t('catalogDeleteBundleConfirm') || '').replace(/\{n\}/g, String(n));
        if (!window.confirm(msg)) {
            return;
        }
        setCatalogBundleBusy(true);
        try {
            const res = await window.axios.post(`/settings/catalog/models/${catalogCap}/delete-bundle`, {
                row_ids: rowsForSelectedGroup.map((r) => r.id),
            });
            if (!res.data?.success) {
                throw new Error(res.data?.error || 'Failed');
            }
            setToast(t('registrySaved'));
            setTimeout(() => setToast(''), 2000);
            setSelectedModelId('');
            setCatalogSelectedGroupKey('');
            await refreshCatalogModelsAndRegistry();
        } catch (e) {
            setToast(e.response?.data?.error || e.message || t('toastError'));
            setTimeout(() => setToast(''), 4000);
        } finally {
            setCatalogBundleBusy(false);
        }
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
                <div className="mx-auto max-w-6xl px-4 py-10 sm:px-6">
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
                        <p
                            role="status"
                            className="fixed left-1/2 top-4 z-[100] max-w-[min(100%,28rem)] -translate-x-1/2 rounded-lg bg-zinc-900 px-4 py-2.5 text-center text-sm text-white shadow-lg dark:bg-zinc-100 dark:text-zinc-900"
                        >
                            {toast}
                        </p>
                    ) : null}

                    <section className="mt-8 space-y-4">
                        <h2 className="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            {t('theme')}
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
                        <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{t('registryTitle')}</h2>
                        <p className="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{t('registrySubtitle')}</p>
                        {!auth?.user ? (
                            <p className="mt-3 text-sm text-zinc-600 dark:text-zinc-400">{t('registryLoginHint')}</p>
                        ) : registryLoadError ? (
                            <p className="mt-3 text-sm text-red-600 dark:text-red-400">{registryLoadError}</p>
                        ) : !registryPayload ? (
                            <p className="mt-3 text-sm text-zinc-500">Loading…</p>
                        ) : (
                            <>
                                <div className="mt-4 space-y-4">
                                    {registryPayload.capabilities.map((cap) => {
                                        const keys = registryPayload.providerKeys?.[cap] || [];
                                        const envOn = Boolean(registryPayload.configLayer?.[cap]);
                                        const eff = registryPayload.effective?.[cap];
                                        const label = REGISTRY_CAP_LABELS[cap] || cap;
                                        return (
                                            <div key={cap} className="rounded-lg border border-zinc-100 p-3 dark:border-zinc-800">
                                                <label className="text-xs font-medium text-zinc-600 dark:text-zinc-400" htmlFor={`reg-${cap}`}>
                                                    {label}
                                                </label>
                                                <select
                                                    id={`reg-${cap}`}
                                                    className="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-950 dark:text-zinc-100"
                                                    value={registrySelections[cap] ?? ''}
                                                    disabled={envOn}
                                                    onChange={(e) =>
                                                        setRegistrySelections((s) => ({ ...s, [cap]: e.target.value }))
                                                    }
                                                >
                                                    <option value="">{t('registryNone')}</option>
                                                    {keys.map((k) => (
                                                        <option key={k} value={k}>
                                                            {k}
                                                        </option>
                                                    ))}
                                                </select>
                                                {envOn ? (
                                                    <p className="mt-1 text-xs text-amber-700 dark:text-amber-300">{t('registryEnvOverride')}</p>
                                                ) : null}
                                                <p className="mt-1 text-xs text-zinc-500">
                                                    {t('registryEffective')}:{' '}
                                                    <span className="font-mono text-zinc-700 dark:text-zinc-300">
                                                        {eff === null || eff === undefined || eff === '' ? '—' : eff}
                                                    </span>
                                                </p>
                                            </div>
                                        );
                                    })}
                                </div>
                                <div className="mt-4">
                                    <button
                                        type="button"
                                        disabled={registrySaveBusy}
                                        onClick={saveRegistryActives}
                                        className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                                    >
                                        {registrySaveBusy ? t('registrySaving') : t('registrySave')}
                                    </button>
                                </div>
                            </>
                        )}
                    </section>

                    <section className="mt-10 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                        <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{t('catalogTitle')}</h2>
                        <p className="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{t('catalogSubtitle')}</p>
                        {!auth?.user ? (
                            <p className="mt-3 text-sm text-zinc-600 dark:text-zinc-400">{t('registryLoginHint')}</p>
                        ) : catalogLoadErrorCatalog ? (
                            <p className="mt-3 text-sm text-red-600 dark:text-red-400">{catalogLoadErrorCatalog}</p>
                        ) : !catalogModelsDoc || !catalogProvidersRoot ? (
                            <p className="mt-3 text-sm text-zinc-500">Loading…</p>
                        ) : (
                            <div className="mt-4 space-y-6">
                                <div className="flex flex-wrap items-center justify-end gap-2">
                                    <button
                                        type="button"
                                        onClick={() => refreshCatalogModelsAndRegistry()}
                                        className="rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:text-zinc-200"
                                    >
                                        {t('catalogRefreshList')}
                                    </button>
                                </div>

                                <div className="grid gap-4 lg:grid-cols-12 lg:gap-3">
                                    <div className="lg:col-span-2">
                                        <p className="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                            {t('catalogServices')}
                                        </p>
                                        <ul className="mt-2 space-y-1">
                                            {CATALOG_CAPABILITIES.map((c) => {
                                                const on = c === catalogCap;
                                                return (
                                                    <li key={c}>
                                                        <button
                                                            type="button"
                                                            onClick={() => setCatalogCap(c)}
                                                            className={`w-full rounded-lg px-3 py-2 text-left text-sm ${
                                                                on
                                                                    ? 'bg-indigo-600 font-medium text-white'
                                                                    : 'border border-zinc-200 bg-zinc-50 text-zinc-800 hover:bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 dark:hover:bg-zinc-800'
                                                            }`}
                                                        >
                                                            {CATALOG_SERVICE_LABELS[c] || REGISTRY_CAP_LABELS[c] || c}
                                                        </button>
                                                    </li>
                                                );
                                            })}
                                        </ul>
                                    </div>

                                    <div className="lg:col-span-3">
                                        <p className="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                            {t('catalogProvidersColumn')}
                                        </p>
                                        <ul className="mt-2 max-h-[28rem] space-y-1 overflow-y-auto">
                                            {catalogProviderGroups.map((g) => {
                                                const on = g.groupKey === catalogSelectedGroupKey;
                                                const label = providerCatalogDisplayName(g.providerId);
                                                return (
                                                    <li key={g.groupKey}>
                                                        <button
                                                            type="button"
                                                            onClick={() => setCatalogSelectedGroupKey(g.groupKey)}
                                                            className={`flex w-full items-start gap-2 rounded-lg border px-3 py-2 text-left text-sm ${
                                                                on
                                                                    ? 'border-indigo-500 bg-indigo-50 dark:border-indigo-400 dark:bg-indigo-950/40'
                                                                    : 'border-zinc-200 bg-white hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:bg-zinc-800'
                                                            }`}
                                                        >
                                                            <ProviderBrandIcon
                                                                icon={providerCatalogIcon(g.providerId)}
                                                                label={label}
                                                            />
                                                            <div className="min-w-0 flex-1">
                                                                <span className="font-medium text-zinc-900 dark:text-zinc-100">
                                                                    {label}
                                                                </span>
                                                                <span className="mt-0.5 block font-mono text-xs text-zinc-500">
                                                                    {g.providerId} · {g.rows.length}{' '}
                                                                    {g.rows.length === 1 ? 'model' : 'models'}
                                                                </span>
                                                                {g.baseUrl ? (
                                                                    <span className="mt-0.5 block truncate font-mono text-[10px] text-zinc-400">
                                                                        {g.baseUrl}
                                                                    </span>
                                                                ) : null}
                                                            </div>
                                                        </button>
                                                    </li>
                                                );
                                            })}
                                        </ul>
                                        {catalogProviderGroups.length === 0 ? (
                                            <p className="mt-2 text-xs text-zinc-500">No providers in this service yet.</p>
                                        ) : null}
                                    </div>

                                    <div className="lg:col-span-7 rounded-xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-700 dark:bg-zinc-950/30">
                                        {!catalogSelectedGroupKey ? (
                                            <p className="text-sm text-zinc-500">Select a provider.</p>
                                        ) : (
                                            <>
                                                <div className="flex flex-wrap items-start justify-between gap-3 border-b border-zinc-200 pb-3 dark:border-zinc-700">
                                                    <div className="flex min-w-0 flex-1 items-start gap-3">
                                                        <ProviderBrandIcon
                                                            icon={providerCatalogIcon(catalogSelectedProviderId)}
                                                            label={providerCatalogDisplayName(catalogSelectedProviderId)}
                                                            sizeClass="h-11 w-11"
                                                            textClass="text-sm"
                                                        />
                                                        <div className="min-w-0">
                                                            <h3 className="text-base font-semibold text-zinc-900 dark:text-zinc-100">
                                                                {providerCatalogDisplayName(catalogSelectedProviderId)}
                                                            </h3>
                                                            <p className="font-mono text-xs text-zinc-500">
                                                                {catalogSelectedProviderId}
                                                            </p>
                                                        </div>
                                                    </div>
                                                    <div
                                                        className={`max-w-[min(100%,18rem)] rounded-lg px-3 py-2 text-xs leading-snug ${
                                                            catalogEnvKeyConfigured[catalogSelectedProviderId]
                                                                ? 'bg-sky-100 text-sky-900 dark:bg-sky-950/60 dark:text-sky-100'
                                                                : 'bg-amber-100 text-amber-900 dark:bg-amber-950/50 dark:text-amber-100'
                                                        }`}
                                                    >
                                                        {(() => {
                                                            const ek = providerCatalogEnvKey(catalogSelectedProviderId);
                                                            const label = ek || 'env_key (see providers.json)';
                                                            const msg = catalogEnvKeyConfigured[catalogSelectedProviderId]
                                                                ? t('catalogServerKeyOk')
                                                                : t('catalogServerKeyMissing');
                                                            return <span>{msg.replace(/\{key\}/g, label)}</span>;
                                                        })()}
                                                    </div>
                                                </div>
                                                <p className="mt-2 text-xs text-zinc-500">{t('catalogEnvKeyNote')}</p>

                                                <div className="mt-4">
                                                    <label
                                                        className="text-xs font-medium text-zinc-600 dark:text-zinc-400"
                                                        htmlFor="catalog-prov-base"
                                                    >
                                                        {t('catalogProviderBaseUrl')}
                                                    </label>
                                                    <input
                                                        id="catalog-prov-base"
                                                        className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 font-mono text-sm dark:border-zinc-600 dark:bg-zinc-950 dark:text-zinc-100"
                                                        value={catalogProviderBaseDraft}
                                                        onChange={(e) => setCatalogProviderBaseDraft(e.target.value)}
                                                        placeholder="https://…"
                                                        autoComplete="off"
                                                    />
                                                    <p className="mt-1 text-xs text-zinc-500">{t('catalogProviderBaseHint')}</p>
                                                    {catalogBaseConflict ? (
                                                        <p className="mt-1 text-xs text-amber-700 dark:text-amber-300">
                                                            {t('catalogBaseUrlConflict')}
                                                        </p>
                                                    ) : null}
                                                    <p className="mt-2 break-all font-mono text-xs text-zinc-600 dark:text-zinc-400">
                                                        {(() => {
                                                            const b = catalogProviderBaseDraft.trim().replace(/\/+$/, '');
                                                            if (catalogCap === 'llm' && catalogLlmRequestPreviewUrl) {
                                                                return `Request: ${catalogLlmRequestPreviewUrl}`;
                                                            }
                                                            const path =
                                                                catalogCap === 'image'
                                                                    ? '/images/generations'
                                                                    : catalogCap === 'tts'
                                                                      ? '/audio/speech'
                                                                      : catalogCap === 'asr'
                                                                        ? '/audio/transcriptions'
                                                                        : catalogCap === 'llm'
                                                                          ? '/chat/completions'
                                                                          : '';
                                                            return b && path ? `Request: ${b}${path}` : '—';
                                                        })()}
                                                    </p>
                                                    <button
                                                        type="button"
                                                        disabled={catalogProviderBaseBusy}
                                                        onClick={saveCatalogProviderBase}
                                                        className="mt-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                                                    >
                                                        {catalogProviderBaseBusy ? t('catalogSaving') : t('catalogSaveBaseUrl')}
                                                    </button>
                                                </div>

                                                <div className="mt-4 rounded-lg border border-red-200 bg-red-50/70 p-3 dark:border-red-900/55 dark:bg-red-950/30">
                                                    <p className="text-xs text-red-900 dark:text-red-100/90">
                                                        {t('catalogDeleteBundleHelp')}
                                                    </p>
                                                    <button
                                                        type="button"
                                                        disabled={
                                                            catalogBundleBusy || rowsForSelectedGroup.length === 0
                                                        }
                                                        onClick={deleteCatalogProviderBundle}
                                                        className="mt-2 rounded-lg border border-red-400 bg-white px-3 py-2 text-sm font-medium text-red-800 hover:bg-red-50 disabled:opacity-50 dark:border-red-700 dark:bg-red-950 dark:text-red-100 dark:hover:bg-red-900/50"
                                                    >
                                                        {catalogBundleBusy ? t('catalogSaving') : t('catalogDeleteBundle')}
                                                    </button>
                                                </div>

                                                <div className="mt-6 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                                        <h4 className="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                                            {t('catalogModelsForProvider')}
                                                        </h4>
                                                    </div>
                                                    <ul className="mt-2 space-y-2">
                                                        {rowsForSelectedGroup.map((row) => (
                                                            <li
                                                                key={row.id}
                                                                className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-900"
                                                            >
                                                                <button
                                                                    type="button"
                                                                    className="min-w-0 flex-1 text-left"
                                                                    onClick={() => setSelectedModelId(row.id)}
                                                                >
                                                                    <span className="font-medium text-zinc-900 dark:text-zinc-100">
                                                                        {row.display_name || row.id}
                                                                    </span>
                                                                    <span className="mt-0.5 block font-mono text-xs text-zinc-500">
                                                                        {row.id}
                                                                    </span>
                                                                </button>
                                                                <div className="flex shrink-0 flex-wrap gap-1">
                                                                    <button
                                                                        type="button"
                                                                        className="rounded border border-zinc-300 px-2 py-0.5 text-xs dark:border-zinc-600"
                                                                        onClick={() => testCatalogModel(row.id)}
                                                                    >
                                                                        {t('catalogTest')}
                                                                    </button>
                                                                    <button
                                                                        type="button"
                                                                        className="rounded border border-red-200 px-2 py-0.5 text-xs text-red-700 dark:border-red-900 dark:text-red-300"
                                                                        onClick={() => deleteCatalogModel(row.id)}
                                                                    >
                                                                        {t('catalogDelete')}
                                                                    </button>
                                                                </div>
                                                            </li>
                                                        ))}
                                                    </ul>

                                                    {catalogTestBusy || catalogTestResult ? (
                                                        <div className="mt-3 rounded-lg border border-zinc-200 bg-zinc-100/90 p-2 dark:border-zinc-600 dark:bg-zinc-900/70">
                                                            <p className="text-[10px] font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                                                {t('catalogTestResultTitle')}
                                                            </p>
                                                            {catalogTestBusy ? (
                                                                <p className="mt-1 text-xs text-zinc-600 dark:text-zinc-300">
                                                                    {t('catalogTesting')}
                                                                </p>
                                                            ) : (
                                                                <pre className="mt-1 max-h-40 overflow-auto rounded bg-white/80 p-2 font-mono text-[11px] text-zinc-800 dark:bg-zinc-950 dark:text-zinc-200">
                                                                    {JSON.stringify(catalogTestResult, null, 2)}
                                                                </pre>
                                                            )}
                                                        </div>
                                                    ) : null}

                                                    <p className="mt-4 text-xs font-semibold text-zinc-600 dark:text-zinc-400">
                                                        {t('catalogAddModelVariant')}
                                                    </p>
                                                    <div className="mt-2 grid gap-2 sm:grid-cols-3">
                                                        <div>
                                                            <label className="text-xs text-zinc-500" htmlFor="var-id">
                                                                {t('catalogVariantId')}
                                                            </label>
                                                            <input
                                                                id="var-id"
                                                                className="mt-1 w-full rounded border border-zinc-300 px-2 py-1 font-mono text-xs dark:border-zinc-600 dark:bg-zinc-950 dark:text-zinc-100"
                                                                value={catalogVariant.id}
                                                                onChange={(e) =>
                                                                    setCatalogVariant((v) => ({ ...v, id: e.target.value }))
                                                                }
                                                                autoComplete="off"
                                                            />
                                                        </div>
                                                        <div>
                                                            <label className="text-xs text-zinc-500" htmlFor="var-name">
                                                                {t('catalogVariantName')}
                                                            </label>
                                                            <input
                                                                id="var-name"
                                                                className="mt-1 w-full rounded border border-zinc-300 px-2 py-1 text-xs dark:border-zinc-600 dark:bg-zinc-950 dark:text-zinc-100"
                                                                value={catalogVariant.displayName}
                                                                onChange={(e) =>
                                                                    setCatalogVariant((v) => ({
                                                                        ...v,
                                                                        displayName: e.target.value,
                                                                    }))
                                                                }
                                                                autoComplete="off"
                                                            />
                                                        </div>
                                                        <div>
                                                            <label className="text-xs text-zinc-500" htmlFor="var-api">
                                                                {t('catalogVariantApiId')}
                                                            </label>
                                                            <input
                                                                id="var-api"
                                                                className="mt-1 w-full rounded border border-zinc-300 px-2 py-1 font-mono text-xs dark:border-zinc-600 dark:bg-zinc-950 dark:text-zinc-100"
                                                                value={catalogVariant.apiModelId}
                                                                onChange={(e) =>
                                                                    setCatalogVariant((v) => ({
                                                                        ...v,
                                                                        apiModelId: e.target.value,
                                                                    }))
                                                                }
                                                                autoComplete="off"
                                                            />
                                                        </div>
                                                    </div>
                                                    <button
                                                        type="button"
                                                        disabled={catalogVariantBusy}
                                                        onClick={() => createCatalogVariant()}
                                                        className="mt-2 rounded-lg border border-zinc-400 px-4 py-2 text-sm font-medium dark:border-zinc-500"
                                                    >
                                                        {catalogVariantBusy ? t('catalogCreating') : t('catalogCreateVariant')}
                                                    </button>
                                                </div>

                                                <div className="mt-6 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                                                    <h4 className="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                                        {t('catalogAdvancedJson')}
                                                    </h4>
                                                    {!selectedModelId ? (
                                                        <p className="mt-2 text-xs text-zinc-500">{t('catalogSelectHint')}</p>
                                                    ) : (
                                                        <>
                                                            <textarea
                                                                className="mt-2 h-48 w-full rounded-lg border border-zinc-300 p-2 font-mono text-xs dark:border-zinc-600 dark:bg-zinc-950 dark:text-zinc-100"
                                                                value={selectedModelJson}
                                                                onChange={(e) => setSelectedModelJson(e.target.value)}
                                                                spellCheck={false}
                                                            />
                                                            <div className="mt-2 flex flex-wrap gap-2">
                                                                <button
                                                                    type="button"
                                                                    disabled={catalogSaveJsonBusy}
                                                                    onClick={saveCatalogModelJson}
                                                                    className="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                                                                >
                                                                    {catalogSaveJsonBusy ? t('catalogSaving') : t('catalogSaveJson')}
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    disabled={catalogTestBusy}
                                                                    onClick={() => testCatalogModel()}
                                                                    className="rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:text-zinc-200"
                                                                >
                                                                    {catalogTestBusy ? t('catalogTesting') : t('catalogTest')}
                                                                </button>
                                                            </div>
                                                            <p className="mt-3 text-xs font-medium text-zinc-600 dark:text-zinc-400">
                                                                {t('catalogTestOverrides')}
                                                            </p>
                                                            <div className="mt-1 grid gap-2 sm:grid-cols-3">
                                                                <input
                                                                    className="rounded border border-zinc-300 px-2 py-1 font-mono text-xs dark:border-zinc-600 dark:bg-zinc-950 dark:text-zinc-100"
                                                                    placeholder={t('catalogTestModel')}
                                                                    value={testOverrides.model}
                                                                    onChange={(e) =>
                                                                        setTestOverrides((o) => ({
                                                                            ...o,
                                                                            model: e.target.value,
                                                                        }))
                                                                    }
                                                                    autoComplete="off"
                                                                />
                                                                <input
                                                                    className="rounded border border-zinc-300 px-2 py-1 font-mono text-xs dark:border-zinc-600 dark:bg-zinc-950 dark:text-zinc-100"
                                                                    placeholder={t('apiKey')}
                                                                    type="password"
                                                                    value={testOverrides.apiKey}
                                                                    onChange={(e) =>
                                                                        setTestOverrides((o) => ({
                                                                            ...o,
                                                                            apiKey: e.target.value,
                                                                        }))
                                                                    }
                                                                    autoComplete="off"
                                                                />
                                                                <input
                                                                    className="rounded border border-zinc-300 px-2 py-1 font-mono text-xs dark:border-zinc-600 dark:bg-zinc-950 dark:text-zinc-100"
                                                                    placeholder={t('baseUrl')}
                                                                    value={testOverrides.baseUrl}
                                                                    onChange={(e) =>
                                                                        setTestOverrides((o) => ({
                                                                            ...o,
                                                                            baseUrl: e.target.value,
                                                                        }))
                                                                    }
                                                                    autoComplete="off"
                                                                />
                                                            </div>
                                                        </>
                                                    )}
                                                </div>
                                            </>
                                        )}
                                    </div>
                                </div>

                                <div>
                                    <h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                        {t('catalogAddStub')}
                                    </h3>
                                    <div className="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                                        <div>
                                            <label className="text-xs text-zinc-600 dark:text-zinc-400" htmlFor="stub-id">
                                                {t('catalogNewId')}
                                            </label>
                                            <input
                                                id="stub-id"
                                                className="mt-1 w-full rounded-lg border border-zinc-300 px-2 py-1.5 font-mono text-sm dark:border-zinc-600 dark:bg-zinc-950 dark:text-zinc-100"
                                                value={catalogStub.id}
                                                onChange={(e) => setCatalogStub((s) => ({ ...s, id: e.target.value }))}
                                                autoComplete="off"
                                            />
                                        </div>
                                        <div>
                                            <label className="text-xs text-zinc-600 dark:text-zinc-400" htmlFor="stub-name">
                                                {t('catalogNewName')}
                                            </label>
                                            <input
                                                id="stub-name"
                                                className="mt-1 w-full rounded-lg border border-zinc-300 px-2 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-950 dark:text-zinc-100"
                                                value={catalogStub.displayName}
                                                onChange={(e) =>
                                                    setCatalogStub((s) => ({ ...s, displayName: e.target.value }))
                                                }
                                                autoComplete="off"
                                            />
                                        </div>
                                        <div>
                                            <label className="text-xs text-zinc-600 dark:text-zinc-400" htmlFor="stub-prov">
                                                {t('catalogNewProvider')}
                                            </label>
                                            <select
                                                id="stub-prov"
                                                className="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-2 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-950 dark:text-zinc-100"
                                                value={catalogStub.provider}
                                                onChange={(e) =>
                                                    setCatalogStub((s) => ({ ...s, provider: e.target.value }))
                                                }
                                            >
                                                {providerOptionsForCap.map((p) => (
                                                    <option key={p.id} value={p.id}>
                                                        {p.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        <div className="sm:col-span-2 lg:col-span-4">
                                            <label className="text-xs text-zinc-600 dark:text-zinc-400" htmlFor="stub-note">
                                                {t('catalogNewNote')}
                                            </label>
                                            <input
                                                id="stub-note"
                                                className="mt-1 w-full rounded-lg border border-zinc-300 px-2 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-950 dark:text-zinc-100"
                                                value={catalogStub.note}
                                                onChange={(e) => setCatalogStub((s) => ({ ...s, note: e.target.value }))}
                                                autoComplete="off"
                                            />
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        disabled={catalogCreateBusy}
                                        onClick={createCatalogStub}
                                        className="mt-3 rounded-lg bg-zinc-800 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 disabled:opacity-50 dark:bg-zinc-200 dark:text-zinc-900 dark:hover:bg-white"
                                    >
                                        {catalogCreateBusy ? t('catalogCreating') : t('catalogCreate')}
                                    </button>
                                </div>
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
