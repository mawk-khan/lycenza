import { createApp, h, type DefineComponent } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import AccountLayout from './Layouts/AccountLayout.vue';
import { captureRecoveryFragment } from './support/recoveryFragment';

const appName = import.meta.env.VITE_APP_NAME || 'School OS';

// Before Inertia reads the URL (ADR 0056 section 8.1).
captureRecoveryFragment();

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => {
        const pages = import.meta.glob<DefineComponent>('./Pages/**/*.vue', { eager: true });
        return pages[`./Pages/${name}.vue`];
    },
    // Phase 0O.3 (ADR 0049 section 11): the enforced CSP allows no inline
    // styles, so Inertia's progress bar must not inject its <style>
    // element; the same rules ship in the built stylesheet
    // (resources/css/app.css, "Inertia progress bar").
    progress: { includeCSS: false },
    // Every page gets the account bar (Log out); it renders only for an
    // authenticated request. No page declares its own layout.
    layout: () => AccountLayout,
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
});
