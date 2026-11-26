<script module lang="ts">
    import { edit } from '@/routes/profile';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Profile settings',
                href: edit(),
            },
        ],
    };
</script>

<script lang="ts">
    import { Form, page } from '@inertiajs/svelte';
    import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { t } from '@/lib/i18n';

    const user = $derived(page.props.auth.user);
</script>

<AppHead title="Profile settings" />

<h1 class="sr-only">Profile settings</h1>

<div class="flex flex-col space-y-6">
    <Heading
        variant="small"
        title="Profile"
        description={t('profile.description')}
    />

    <Form
        {...ProfileController.update.form()}
        class="space-y-6"
        options={{ preserveScroll: true }}
    >
        {#snippet children({ errors, processing })}
            <div class="grid gap-2">
                <Label for="username">{t('profile.username')}</Label>
                <Input
                    id="username"
                    name="username"
                    class="mt-1 block w-full"
                    value={user.username}
                    required
                    autocomplete="username"
                    autocapitalize="none"
                    spellcheck={false}
                />
                <p class="text-ink-2 text-sm">{t('profile.username.hint')}</p>
                <InputError class="mt-2" message={errors.username} />
            </div>

            <div class="grid gap-2">
                <Label for="display_name">{t('profile.display_name')}</Label>
                <Input
                    id="display_name"
                    name="display_name"
                    class="mt-1 block w-full"
                    value={user.display_name}
                    autocomplete="name"
                />
                <InputError class="mt-2" message={errors.display_name} />
            </div>

            <div class="flex items-center gap-4">
                <Button
                    type="submit"
                    disabled={processing}
                    data-test="update-profile-button">Save</Button
                >
            </div>
        {/snippet}
    </Form>
</div>

