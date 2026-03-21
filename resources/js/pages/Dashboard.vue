<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Card from 'primevue/card';
import InputText from 'primevue/inputtext';
import { ref } from 'vue';

const name = ref('');
const clicks = ref(0);

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard().url,
    },
];
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
            <div class="grid gap-4 md:grid-cols-2">
                <Card>
                    <template #title>PrimeVue Theme Test</template>
                    <template #content>
                        <div class="flex flex-col gap-4">
                            <p class="text-sm text-neutral-500 dark:text-neutral-400">
                                Theme actif lu depuis <code>VITE_PRIMEVUE_THEME</code>.
                            </p>
                            <div class="flex flex-col gap-2">
                                <label class="text-sm font-medium" for="pv-name">Nom</label>
                                <InputText id="pv-name" v-model="name" placeholder="Tape ton nom" />
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <Button label="Primary" @click="clicks++" />
                                <Button label="Secondary" severity="secondary" />
                                <Button label="Success" severity="success" />
                                <Button label="Info" severity="info" />
                                <Button label="Warning" severity="warning" />
                                <Button label="Danger" severity="danger" />
                                <Button label="Outlined" outlined />
                            </div>
                            <p class="text-sm">
                                Salut <strong>{{ name || '...' }}</strong> - Clics: <strong>{{ clicks }}</strong>
                            </p>
                        </div>
                    </template>
                </Card>

                <Card>
                    <template #title>Light / Dark Check</template>
                    <template #content>
                        <div class="flex flex-col gap-4">
                            <p class="text-sm text-neutral-500 dark:text-neutral-400">
                                Ouvre Settings &gt; Appearance et bascule Light/Dark pour verifier la variation du preset.
                            </p>
                            <div class="flex flex-wrap gap-2">
                                <Button label="Text" text />
                                <Button label="Raised" raised />
                                <Button label="Rounded" rounded />
                                <Button label="Help" severity="help" />
                            </div>
                        </div>
                    </template>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
