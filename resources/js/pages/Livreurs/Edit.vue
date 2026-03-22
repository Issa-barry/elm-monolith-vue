<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import LivreurForm from './partials/LivreurForm.vue';

interface LivreurData {
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

const props = defineProps<{ livreur: LivreurData }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Livreurs', href: '/livreurs' },
    { title: `${props.livreur.prenom} ${props.livreur.nom}`, href: '#' },
];

const form = useForm({
    nom: props.livreur.nom,
    prenom: props.livreur.prenom,
    email: props.livreur.email,
    telephone: props.livreur.telephone,
    adresse: props.livreur.adresse,
    ville: props.livreur.ville,
    pays: props.livreur.pays,
    code_pays: props.livreur.code_pays,
    code_phone_pays: props.livreur.code_phone_pays,
    is_active: props.livreur.is_active,
});

function submit() {
    form.put(`/livreurs/${props.livreur.id}`);
}
</script>

<template>
    <Head :title="`Modifier — ${livreur.prenom} ${livreur.nom}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-2xl p-6">
            <div class="mb-8">
                <h1 class="text-2xl font-semibold tracking-tight">Modifier le livreur</h1>
                <p class="mt-1 text-sm text-muted-foreground font-medium">{{ livreur.prenom }} {{ livreur.nom }}</p>
            </div>

            <LivreurForm
                :form="form"
                :errors="form.errors"
                :processing="form.processing"
                @submit="submit"
                @update:form="Object.assign(form, $event)"
            />
        </div>
    </AppLayout>
</template>
