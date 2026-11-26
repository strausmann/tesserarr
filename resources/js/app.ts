import { createInertiaApp } from '@inertiajs/svelte';
import AppLayout from '@/layouts/AppLayout.svelte';
import AuthLayout from '@/layouts/AuthLayout.svelte';
import SettingsLayout from '@/layouts/settings/Layout.svelte';
import { initializeFlashToast } from '@/lib/flash-toast';
import { initializeTheme } from '@/lib/theme.svelte';
import { trackVisibleViewport } from '@/lib/viewport';

const appName = import.meta.env.VITE_APP_NAME || 'Tesserarr';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
                return null;
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
