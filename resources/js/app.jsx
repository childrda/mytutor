import './bootstrap';
import ThemeInit from './components/ThemeInit.jsx';
import { createInertiaApp } from '@inertiajs/react';
import { Fragment } from 'react';
import { createRoot } from 'react-dom/client';

createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.jsx', { eager: true });
        const path = `./Pages/${name}.jsx`;
        const page = pages[path];
        if (!page) {
            throw new Error(`Unknown page: ${name}`);
        }
        return page.default;
    },
    setup({ el, App, props }) {
        createRoot(el).render(
            <Fragment>
                <ThemeInit />
                <App {...props} />
            </Fragment>,
        );
    },
    progress: {
        color: '#4f46e5',
    },
});
