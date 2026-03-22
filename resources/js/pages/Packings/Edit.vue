<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import PackingForm from './partials/PackingForm.vue';

interface Option { value: number; label: string }

interface PackingData {
    id: number;
    reference: string;
    prestataire_id: number;
    date: string;
    nb_rouleaux: number;
    prix_par_rouleau: number;
    montant: number;
    notes: string | null;
    statut: string;
    can_edit: boolean;
    can_cancel: boolean;
}

const props = defineProps<{
    packing: PackingData;
    prestataires: Option[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Packings', href: '/packings' },
    { title: props.packing.reference, href: `/packings/${props.packing.id}` },
    { title: 'Modifier', href: '#' },
];

const form = useForm({
    prestataire_id:   props.packing.prestataire_id,
    date:             props.packing.date,
    nb_rouleaux:      props.packing.nb_rouleaux,
    prix_par_rouleau: props.packing.prix_par_rouleau,
    notes:            props.packing.notes,
});

function submit() {
    form.put(`/packings/${props.packing.id}`);
}
</script>

<template>
    <Head :title="`Modifier — ${packing.reference}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-4xl p-6">
            <div class="mb-6">
                <h1 class="text-2xl font-semibold tracking-tight">Modifier le packing</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    <span class="font-mono text-xs font-semibold">{{ packing.reference }}</span>
                </p>
            </div>

            <PackingForm
                :form="form"
                :errors="form.errors"
                :prestataires="prestataires"
                :processing="form.processing"
                :reference="packing.reference"
                @update:form="Object.assign(form, $event)"
                @submit="submit"
            />
        </div>
    </AppLayout>
</template>
