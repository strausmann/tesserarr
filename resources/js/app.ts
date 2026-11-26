import { createInertiaApp, http, router } from '@inertiajs/svelte';
import AppLayout from '@/layouts/AppLayout.svelte';
import AuthLayout from '@/layouts/AuthLayout.svelte';
import SettingsLayout from '@/layouts/settings/Layout.svelte';
import { initializeFlashToast } from '@/lib/flash-toast';
import { applyDocumentLanguage } from '@/lib/i18n';
import { initializeTheme, setDefaultAppearance } from '@/lib/theme.svelte';
import { watchInertia } from '@/lib/updates.svelte';
import { trackVisibleViewport } from '@/lib/viewport';

const appName = import.meta.env.VITE_APP_NAME || 'Tesserarr';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    progress: {
        // --tr-accent (tokens.css); the bar is drawn by Inertia outside the app.
        color: '#d4912a',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();

// The visible area as --vvh and --vv-top, for everything fixed or sized to the
// screen (lib/viewport.ts), on every page, including the sign-in.
trackVisibleViewport();

// <html lang> follows the language after Inertia visits too (lib/i18n.ts):
// `navigate` for visits that change the page, `success` for a form that
// redirects back to it (the language setting).
router.on('navigate', (event) =>
    applyDocumentLanguage(event.detail.page.props.locale),
);
router.on('success', (event) =>
    applyDocumentLanguage(event.detail.page.props.locale),
);

// The version the server names on every Inertia answer, the 409 of an
// outdated background reload included (lib/updates.svelte.ts).
watchInertia(http);

// The default theme the operator set follows an Inertia visit too: it changes
// for everybody without an own choice when the setting is saved (lib/theme.svelte.ts).
const followDefaultTheme = (props: { ui?: { themeDefault?: string } }) =>
    setDefaultAppearance(props.ui?.themeDefault);
router.on('navigate', (event) => followDefaultTheme(event.detail.page.props));
router.on('success', (event) => followDefaultTheme(event.detail.page.props));
