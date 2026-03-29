<script setup lang="ts">
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Save } from 'lucide-vue-next';
import ProduitForm from './partials/ProduitForm.vue';

interface Option {
    value: string;
    label: string;
}

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
    nom: '',
    code_fournisseur: null as string | null,
    type: 'materiel',
    statut: 'actif',
    prix_usine: null as number | null,
    prix_vente: null as number | null,
    prix_achat: null as number | null,
    cout: null as number | null,
    qte_stock: 0,
    seuil_alerte_stock: null as number | null,
    description: null as string | null,
    is_critique: false,
    image: null as File | null,
});

function submit() {
    form.post('/produits', { forceFormData: true });
}
</script>

<template>
    <Head title="Nouveau produit" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- ─── Header mobile ─── -->
        <div
            class="sticky top-0 z-20 border-b border-border/60 bg-background/95 backdrop-blur-sm sm:hidden"
        >
            <div class="relative flex items-center justify-center px-4 py-3">
                <Link
                    href="/produits"
                    class="absolute left-4 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground transition-transform active:scale-95"
                >
                    <ArrowLeft class="h-4 w-4" />
                </Link>
                <div class="text-center">
                    <h1 class="text-[17px] leading-tight font-semibold">
                        Nouveau produit
                    </h1>
                </div>
            </div>
        </div>

        <!-- ─── Header desktop ─── -->
        <div class="mx-auto hidden max-w-4xl px-6 pt-6 pb-0 sm:block">
            <h1 class="text-2xl font-semibold tracking-tight">
                Nouveau produit
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Ajoutez un produit au catalogue de votre organisation.
            </p>
        </div>

        <!-- ─── Formulaire ─── -->
        <div class="mx-auto max-w-4xl p-4 sm:p-6">
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

        <!-- ─── Footer sticky mobile ─── -->
        <div
            class="fixed right-0 bottom-0 left-0 z-30 border-t border-border/60 bg-background/95 px-4 py-3 backdrop-blur-sm sm:hidden"
        >
            <button
                type="submit"
                form="produit-form"
                :disabled="form.processing"
                class="flex w-full items-center justify-center gap-2 rounded-xl bg-primary py-3 text-sm font-semibold text-primary-foreground shadow-sm transition-transform active:scale-[0.98] disabled:opacity-60"
            >
                <Spinner v-if="form.processing" class="h-4 w-4" />
                <Save v-else class="h-4 w-4" />
                {{ form.processing ? 'Enregistrement…' : 'Créer le produit' }}
            </button>
        </div>
    </AppLayout>
</template>
