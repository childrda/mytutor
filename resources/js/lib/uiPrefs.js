const THEME_KEY = 'mytutor_theme';

export function getStoredTheme() {
    try {
        const v = localStorage.getItem(THEME_KEY);
        return v === 'dark' ? 'dark' : 'light';
    } catch {
        return 'light';
    }
}

export function applyTheme(mode) {
    const next = mode === 'dark' ? 'dark' : 'light';
    try {
        localStorage.setItem(THEME_KEY, next);
    } catch {
        /* ignore */
    }
    document.documentElement.classList.toggle('dark', next === 'dark');
}

