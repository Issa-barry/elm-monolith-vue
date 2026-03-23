<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import ProprietaireForm from './partials/ProprietaireForm.vue';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Propriétaires', href: '/proprietaires' },
    { title: 'Nouveau propriétaire', href: '#' },
];

const form = useForm({
    nom: '',
    prenom: '',
    email: null as string | null,
    telephone: null as string | null,
    adresse: null as string | null,
    ville: null as string | null,
    pays: 'Guinée' as string | null,
    code_pays: 'GN' as string | null,
    code_phone_pays: '+224' as string | null,
    is_active: true,
});

function submit() {
    form.post('/proprietaires');
}
</script>

<template>
    <Head title="Nouveau propriétaire" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-2xl p-6">
            <div class="mb-8">
                <h1 class="text-2xl font-semibold tracking-tight">Nouveau propriétaire</h1>
                <p class="mt-1 text-sm text-muted-foreground">Ajoutez un propriétaire à votre organisation.</p>
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
