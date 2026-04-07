const THEME_KEY = 'mytutor_theme';
const LOCALE_KEY = 'mytutor_locale';

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

export function getStoredLocale() {
    try {
        const v = localStorage.getItem(LOCALE_KEY);
        return v === 'zh-CN' ? 'zh-CN' : 'en';
    } catch {
        return 'en';
    }
}

export function setStoredLocale(locale) {
    const next = locale === 'zh-CN' ? 'zh-CN' : 'en';
    try {
        localStorage.setItem(LOCALE_KEY, next);
    } catch {
        /* ignore */
    }
    return next;
}
