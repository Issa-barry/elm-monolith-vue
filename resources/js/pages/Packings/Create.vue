<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import PackingForm from './partials/PackingForm.vue';

interface Option { value: number; label: string }

const props = defineProps<{
    prestataires: Option[];
    prix_defaut?: number;
    statuts: { value: string; label: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Packings', href: '/packings' },
    { title: 'Nouveau packing', href: '/packings/create' },
];

const form = useForm({
    prestataire_id:   null as number | null,
    date:             new Date().toISOString().slice(0, 10),
    nb_rouleaux:      null as number | null,
    prix_par_rouleau: props.prix_defaut ?? 0,
    notes:            null as string | null,
});

function submit() {
    form.post('/packings');
}
</script>

<template>
    <Head title="Nouveau packing" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-7xl p-6">
            <div class="mb-6">
                <h1 class="text-2xl font-semibold tracking-tight">Nouveau packing</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Enregistrez un nouveau packing pour votre organisation.
                </p>
            </div>

            <PackingForm
                :form="form"
                :errors="form.errors"
                :prestataires="prestataires"
                :processing="form.processing"
                @update:form="Object.assign(form, $event)"
                @submit="submit"
            />
        </div>
    </AppLayout>
</template>
