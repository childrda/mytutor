import { applyTheme, getStoredTheme } from '../lib/uiPrefs.js';
import { useEffect } from 'react';

export default function ThemeInit() {
    useEffect(() => {
        applyTheme(getStoredTheme());
    }, []);
    return null;
}
