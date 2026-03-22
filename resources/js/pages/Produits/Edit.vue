<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import ProduitForm from './partials/ProduitForm.vue';

interface Option { value: string; label: string }

interface ProduitData {
    id: number;
    nom: string;
    code_interne: string | null;
    code_fournisseur: string | null;
    type: string;
    statut: string;
    prix_usine: number | null;
    prix_vente: number | null;
    prix_achat: number | null;
    cout: number | null;
    qte_stock: number;
    seuil_alerte_stock: number | null;
    description: string | null;
    is_critique: boolean;
    image_url: string | null;
}

const props = defineProps<{
    produit: ProduitData;
    types: Option[];
    statuts: Option[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Produits', href: '/produits' },
    { title: props.produit.nom, href: '#' },
];

const form = useForm({
    nom:                props.produit.nom,
    code_fournisseur:   props.produit.code_fournisseur,
    type:               props.produit.type,
    statut:             props.produit.statut,
    prix_usine:         props.produit.prix_usine,
    prix_vente:         props.produit.prix_vente,
    prix_achat:         props.produit.prix_achat,
    cout:               props.produit.cout,
    qte_stock:          props.produit.qte_stock,
    seuil_alerte_stock: props.produit.seuil_alerte_stock,
    description:        props.produit.description,
    is_critique:        props.produit.is_critique,
    image:              null as File | null,
    _method:            'PUT',
});

function submit() {
    form.post(`/produits/${props.produit.id}`, { forceFormData: true });
}
</script>

<template>
    <Head :title="`Modifier — ${produit.nom}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-4xl p-6">
            <div class="mb-6">
                <h1 class="text-2xl font-semibold tracking-tight">Modifier le produit</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    <span class="font-mono text-xs font-semibold">{{ produit.code }}</span>
                    · {{ produit.nom }}
                </p>
            </div>

            <ProduitForm
                :form="form"
                :errors="form.errors"
                :types="types"
                :statuts="statuts"
                :processing="form.processing"
                :current-image-url="produit.image_url"
                :current-code-interne="produit.code_interne"
                @update:form="Object.assign(form, $event)"
                @submit="submit"
            />
        </div>
    </AppLayout>
</template>
