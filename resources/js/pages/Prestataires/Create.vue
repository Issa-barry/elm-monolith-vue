<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import PrestataireForm from './partials/PrestataireForm.vue';

interface Option { value: string; label: string }

defineProps<{ types: Option[] }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Prestataires', href: '/prestataires' },
    { title: 'Nouveau prestataire', href: '#' },
];

const form = useForm({
    nom: null as string | null,
    prenom: null as string | null,
    raison_sociale: null as string | null,
    email: null as string | null,
    phone: null as string | null,
    code_phone_pays: '+224',
    code_pays: 'GN',
    pays: 'Guinée',
    ville: null as string | null,
    adresse: null as string | null,
    type: 'fournisseur',
    notes: null as string | null,
    is_active: true,
});

function submit() {
    form.post('/prestataires');
}
</script>

<template>
    <Head title="Nouveau prestataire" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-2xl p-6">
            <div class="mb-8">
                <h1 class="text-2xl font-semibold tracking-tight">Nouveau prestataire</h1>
                <p class="mt-1 text-sm text-muted-foreground">Ajoutez un prestataire à votre organisation.</p>
            </div>

            <PrestataireForm
                :form="form"
                :errors="form.errors"
                :types="types"
                :processing="form.processing"
                @submit="submit"
                @update:form="Object.assign(form, $event)"
            />
        </div>
    </AppLayout>
</template>
