<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import ProduitForm from './partials/ProduitForm.vue';

interface Option { value: string; label: string }

defineProps<{
    types: Option[];
    statuts: Option[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Produits', href: '/produits' },
    { title: 'Nouveau produit', href: '/produits/create' },
];

const form = useForm({
    nom:                '',
    code_fournisseur:   null as string | null,
    type:               'materiel',
    statut:             'actif',
    prix_usine:         null as number | null,
    prix_vente:         null as number | null,
    prix_achat:         null as number | null,
    cout:               null as number | null,
    qte_stock:          0,
    seuil_alerte_stock: null as number | null,
    description:        null as string | null,
    is_critique:        false,
    image:              null as File | null,
});

function submit() {
    form.post('/produits', { forceFormData: true });
}
</script>

<template>
    <Head title="Nouveau produit" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-4xl p-6">
            <div class="mb-6">
                <h1 class="text-2xl font-semibold tracking-tight">Nouveau produit</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Ajoutez un produit au catalogue de votre organisation.
                </p>
            </div>

            <ProduitForm
                :form="form"
                :errors="form.errors"
                :types="types"
                :statuts="statuts"
                :processing="form.processing"
                @update:form="Object.assign(form, $event)"
                @submit="submit"
            />
        </div>
    </AppLayout>
</template>
