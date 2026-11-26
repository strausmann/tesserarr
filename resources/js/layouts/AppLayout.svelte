<script lang="ts">
    import type { Snippet } from 'svelte';
    import AppLayout from '@/layouts/app/AppSidebarLayout.svelte';

    let {
        title = '',
        children,
    }: {
        /** Key of the page title in lang/*.json (`export const layout = { title }` in the page). */
        title?: string;
        children?: Snippet;
    } = $props();
</script>

<AppLayout {title}>
    {@render children?.()}
</AppLayout>
