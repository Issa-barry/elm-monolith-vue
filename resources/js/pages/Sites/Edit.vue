<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import SiteForm from './partials/SiteForm.vue';

interface Option { value: number | string; label: string }

interface SiteData {
    id: number;
    nom: string;
    code: string;
    type: string | null;
    statut: string | null;
    localisation: string | null;
    pays: string | null;
    ville: string | null;
    quartier: string | null;
    description: string | null;
    parent_id: number | null;
}

const props = defineProps<{
    site: SiteData;
    types: Option[];
    statuts: Option[];
    parentOptions: Option[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Sites', href: '/sites' },
    { title: props.site.nom, href: '#' },
];

const form = useForm({
    _method:      'PUT',
    nom:          props.site.nom,
    code:         props.site.code,
    type:         props.site.type,
    statut:       props.site.statut,
    localisation: props.site.localisation,
    pays:         props.site.pays,
    ville:        props.site.ville,
    quartier:     props.site.quartier,
    description:  props.site.description,
    parent_id:    props.site.parent_id,
});

function submit() {
    form.post(`/sites/${props.site.id}`);
}
</script>

<template>
    <Head :title="`Modifier — ${site.nom}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-2xl p-6">
            <div class="mb-8">
                <h1 class="text-2xl font-semibold tracking-tight">Modifier le site</h1>
                <p class="mt-1 font-mono text-sm font-medium text-muted-foreground">{{ site.code }}</p>
            </div>

            <SiteForm
                :form="form"
                :errors="form.errors"
                :processing="form.processing"
                :types="types"
                :statuts="statuts"
                :parent-options="parentOptions"
                @submit="submit"
                @update:form="Object.assign(form, $event)"
            />
        </div>
    </AppLayout>
</template>
