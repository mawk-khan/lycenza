import { createApp, h, type DefineComponent } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import AccountLayout from './Layouts/AccountLayout.vue';

const appName = import.meta.env.VITE_APP_NAME || 'School OS';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => {
        const pages = import.meta.glob<DefineComponent>('./Pages/**/*.vue', { eager: true });
        return pages[`./Pages/${name}.vue`];
    },
    // Every page gets the account bar (Log out); it renders only for an
    // authenticated request. No page declares its own layout.
    layout: () => AccountLayout,
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
});
