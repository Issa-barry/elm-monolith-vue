<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Save } from 'lucide-vue-next';
import { Spinner } from '@/components/ui/spinner';
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

        <!-- ─── Header mobile ─── -->
        <div class="sticky top-0 z-20 border-b border-border/60 bg-background/95 backdrop-blur-sm sm:hidden">
            <div class="relative flex items-center justify-center px-4 py-3">
                <Link
                    href="/produits"
                    class="absolute left-4 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground transition-transform active:scale-95"
                >
                    <ArrowLeft class="h-4 w-4" />
                </Link>
                <div class="text-center">
                    <h1 class="text-[17px] font-semibold leading-tight">Modifier</h1>
                    <p class="truncate text-[11px] text-muted-foreground">{{ produit.nom }}</p>
                </div>
            </div>
        </div>

        <!-- ─── Header desktop ─── -->
        <div class="hidden sm:block mx-auto max-w-4xl px-6 pt-6 pb-0">
            <h1 class="text-2xl font-semibold tracking-tight">Modifier le produit</h1>
            <p class="mt-1 text-sm text-muted-foreground">· {{ produit.nom }}</p>
        </div>

        <!-- ─── Formulaire ─── -->
        <div class="mx-auto max-w-4xl p-4 sm:p-6">
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

        <!-- ─── Footer sticky mobile ─── -->
        <div class="fixed bottom-0 left-0 right-0 z-30 border-t border-border/60 bg-background/95 px-4 py-3 backdrop-blur-sm sm:hidden">
            <button
                type="submit"
                form="produit-form"
                :disabled="form.processing"
                class="flex w-full items-center justify-center gap-2 rounded-xl bg-primary py-3 text-sm font-semibold text-primary-foreground shadow-sm transition-transform active:scale-[0.98] disabled:opacity-60"
            >
                <Spinner v-if="form.processing" class="h-4 w-4" />
                <Save v-else class="h-4 w-4" />
                {{ form.processing ? 'Enregistrement…' : 'Enregistrer les modifications' }}
            </button>
        </div>

    </AppLayout>
</template>
