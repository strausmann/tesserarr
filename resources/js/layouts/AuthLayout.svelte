<script lang="ts">
    import { page } from '@inertiajs/svelte';
    import type { Snippet } from 'svelte';
    import AuthLayout from '@/layouts/auth/AuthSimpleLayout.svelte';

    let {
        title = '',
        description = '',
        children,
    }: {
        title?: string;
        description?: string;
        children?: Snippet;
    } = $props();

    // A title or description that is a key of lang/*.json is shown in the
    // page's language (pages of Tesserarr); any other text as it is (the
    // starter kit's pages).
    const lines = $derived(((page.props as { translations?: Record<string, string> }).translations ?? {}) as Record<string, string>);
</script>

<AuthLayout title={lines[title] ?? title} description={lines[description] ?? description}>
    {@render children?.()}
</AuthLayout>
