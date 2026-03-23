<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import ProprietaireForm from './partials/ProprietaireForm.vue';

interface ProprietaireData {
    id: number;
    nom: string;
    prenom: string;
    email: string | null;
    telephone: string | null;
    adresse: string | null;
    ville: string | null;
    pays: string | null;
    code_pays: string | null;
    code_phone_pays: string | null;
    is_active: boolean;
}

const props = defineProps<{ proprietaire: ProprietaireData }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Propriétaires', href: '/proprietaires' },
    { title: `${props.proprietaire.prenom} ${props.proprietaire.nom}`, href: '#' },
];

const form = useForm({
    nom: props.proprietaire.nom,
    prenom: props.proprietaire.prenom,
    email: props.proprietaire.email,
    telephone: props.proprietaire.telephone,
    adresse: props.proprietaire.adresse,
    ville: props.proprietaire.ville,
    pays: props.proprietaire.pays,
    code_pays: props.proprietaire.code_pays,
    code_phone_pays: props.proprietaire.code_phone_pays,
    is_active: props.proprietaire.is_active,
});

function submit() {
    form.put(`/proprietaires/${props.proprietaire.id}`);
}
</script>

<template>
    <Head :title="`Modifier — ${proprietaire.prenom} ${proprietaire.nom}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-2xl p-6">
            <div class="mb-8">
                <h1 class="text-2xl font-semibold tracking-tight">Modifier le propriétaire</h1>
                <p class="mt-1 text-sm text-muted-foreground font-medium">{{ proprietaire.prenom }} {{ proprietaire.nom }}</p>
            </div>

            <ProprietaireForm
                :form="form"
                :errors="form.errors"
                :processing="form.processing"
                @submit="submit"
                @update:form="Object.assign(form, $event)"
            />
        </div>
    </AppLayout>
</template>
