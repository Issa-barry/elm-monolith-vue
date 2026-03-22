<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import LivreurForm from './partials/LivreurForm.vue';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Livreurs', href: '/livreurs' },
    { title: 'Nouveau livreur', href: '#' },
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
    form.post('/livreurs');
}
</script>

<template>
    <Head title="Nouveau livreur" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-2xl p-6">
            <div class="mb-8">
                <h1 class="text-2xl font-semibold tracking-tight">Nouveau livreur</h1>
                <p class="mt-1 text-sm text-muted-foreground">Ajoutez un livreur à votre organisation.</p>
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
