<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import PrestataireForm from './partials/PrestataireForm.vue';

interface Option { value: string; label: string }

interface PrestataireData {
    id: number;
    reference: string;
    nom: string | null;
    prenom: string | null;
    raison_sociale: string | null;
    email: string | null;
    phone: string | null;
    code_phone_pays: string;
    code_pays: string;
    pays: string;
    ville: string | null;
    adresse: string | null;
    type: string;
    notes: string | null;
    is_active: boolean;
}

const props = defineProps<{ prestataire: PrestataireData; types: Option[] }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Prestataires', href: '/prestataires' },
    { title: props.prestataire.reference, href: '#' },
];

const form = useForm({
    nom: props.prestataire.nom,
    prenom: props.prestataire.prenom,
    raison_sociale: props.prestataire.raison_sociale,
    email: props.prestataire.email,
    phone: props.prestataire.phone,
    code_phone_pays: props.prestataire.code_phone_pays,
    code_pays: props.prestataire.code_pays,
    pays: props.prestataire.pays,
    ville: props.prestataire.ville,
    adresse: props.prestataire.adresse,
    type: props.prestataire.type,
    notes: props.prestataire.notes,
    is_active: props.prestataire.is_active,
});

function submit() {
    form.put(`/prestataires/${props.prestataire.id}`);
}
</script>

<template>
    <Head :title="`Modifier — ${prestataire.reference}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-2xl p-6">
            <div class="mb-8">
                <h1 class="text-2xl font-semibold tracking-tight">Modifier le prestataire</h1>
                <p class="mt-1 text-sm text-muted-foreground font-mono">{{ prestataire.reference }}</p>
            </div>

            <PrestataireForm
                :form="form"
                :errors="form.errors"
                :types="types"
                :processing="form.processing"
                :reference="prestataire.reference"
                @submit="submit"
                @update:form="Object.assign(form, $event)"
            />
        </div>
    </AppLayout>
</template>
