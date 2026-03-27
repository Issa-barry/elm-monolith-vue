<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import SiteForm from './partials/SiteForm.vue';

interface Option { value: number | string; label: string }

const props = defineProps<{
    types: Option[];
    statuts: Option[];
    parentOptions: Option[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Sites', href: '/sites' },
    { title: 'Nouveau site', href: '#' },
];

const form = useForm({
    nom:          '',
    type:         null as string | null,
    statut:       'active' as string | null,
    localisation: null as string | null,
    pays:         'Guinée' as string | null,
    ville:        null as string | null,
    description:  null as string | null,
    parent_id:    null as number | null,
    latitude:     null as number | null,
    longitude:    null as number | null,
    telephone:    null as string | null,
    email:        null as string | null,
});

function submit() {
    form.post('/sites');
}
</script>

<template>
    <Head title="Nouveau site" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-2xl p-6">
            <div class="mb-8">
                <h1 class="text-2xl font-semibold tracking-tight">Nouveau site</h1>
                <p class="mt-1 text-sm text-muted-foreground">Ajoutez un site à votre organisation.</p>
            </div>

            <SiteForm
                :form="form"
                :errors="form.errors"
                :processing="form.processing"
                :types="types"
                :statuts="statuts"
                :parent-options="parentOptions"
                :is-create="true"
                @submit="submit"
                @update:form="Object.assign(form, $event)"
            />
        </div>
    </AppLayout>
</template>
