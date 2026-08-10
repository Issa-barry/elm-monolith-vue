<script setup lang="ts">
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Save } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import ProduitForm from './partials/ProduitForm.vue';
import VarianteEditModal from './partials/VarianteEditModal.vue';

interface Option {
    value: string;
    label: string;
}

interface Categorie {
    id: string;
    nom: string;
    parent_id: string | null;
}

interface Limites {
    max_photos_produit: number;
    max_options_produit: number;
    max_valeurs_option: number;
    max_variantes_produit: number;
}

interface VarianteOption {
    option: string;
    valeur: string;
}

interface Variante {
    id: string;
    libelle: string;
    sku: string | null;
    code_barres: string | null;
    prix_usine: number | null;
    prix_vente: number | null;
    prix_achat: number | null;
    cout: number | null;
    seuil_alerte_stock: number | null;
    is_default: boolean;
    is_active: boolean;
    options: VarianteOption[];
}

interface ProduitData {
    id: number;
    nom: string;
    categorie_id: string | null;
    sku: string | null;
    code_barres: string | null;
    type: string;
    statut: string;
    prix_usine: number | null;
    prix_vente: number | null;
    prix_achat: number | null;
    cout: number | null;
    seuil_alerte_stock: number | null;
    description: string | null;
    is_alerte: boolean;
    image_url: string | null;
    variantes_count: number;
    variantes: Variante[];
}

const props = defineProps<{
    produit: ProduitData;
    types: Option[];
    statuts: Option[];
    categories: Categorie[];
    limites: Limites;
}>();

const showVarianteModal = ref(false);
const varianteEnEdition = ref<Variante | null>(null);
const isFabricable = computed(() => props.produit.type === 'fabricable');

function editerVariante(variante: Variante) {
    varianteEnEdition.value = variante;
    showVarianteModal.value = true;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Produits', href: '/backoffice/produits' },
    { title: props.produit.nom, href: '#' },
];

const form = useForm({
    nom: props.produit.nom,
    categorie_id: props.produit.categorie_id,
    code_barres: props.produit.code_barres,
    type: props.produit.type,
    statut: props.produit.statut,
    prix_usine: props.produit.prix_usine,
    prix_vente: props.produit.prix_vente,
    prix_achat: props.produit.prix_achat,
    cout: props.produit.cout,
    seuil_alerte_stock: props.produit.seuil_alerte_stock,
    description: props.produit.description,
    is_alerte: props.produit.is_alerte,
    images: [] as File[],
    options: [] as {
        nom: string;
        valeurs: string[];
        option_catalogue_id: string | null;
    }[],
    _method: 'PUT',
});

function submit() {
    form.post(`/backoffice/produits/${props.produit.id}`, {
        forceFormData: true,
    });
}
</script>

<template>
    <Head :title="`Modifier — ${produit.nom}`" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- ─── Header mobile ─── -->
        <div
            class="sticky top-0 z-20 border-b border-border/60 bg-background/95 backdrop-blur-sm sm:hidden"
        >
            <div class="relative flex items-center justify-center px-4 py-3">
                <Link
                    href="/backoffice/produits"
                    class="absolute left-4 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground transition-transform active:scale-95"
                >
                    <ArrowLeft class="h-4 w-4" />
                </Link>
                <div class="text-center">
                    <h1 class="text-[17px] leading-tight font-semibold">
                        Modifier
                    </h1>
                    <p class="truncate text-[11px] text-muted-foreground">
                        {{ produit.nom }}
                    </p>
                </div>
            </div>
        </div>

        <!-- ─── Header desktop ─── -->
        <div class="mx-auto hidden max-w-4xl px-6 pt-6 pb-0 sm:block">
            <h1 class="text-2xl font-semibold tracking-tight">
                Modifier le produit
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                · {{ produit.nom }}
            </p>
        </div>

        <!-- ─── Formulaire ─── -->
        <div class="mx-auto max-w-4xl p-4 sm:p-6">
            <ProduitForm
                :form="form"
                :errors="form.errors"
                :types="types"
                :statuts="statuts"
                :categories="categories"
                :limites="limites"
                :processing="form.processing"
                :current-image-url="produit.image_url"
                :current-sku="produit.sku"
                :allow-declinaisons="false"
                :existing-variantes="produit.variantes"
                @update:form="Object.assign(form, $event)"
                @submit="submit"
                @edit-variante="editerVariante"
            />
        </div>

        <VarianteEditModal
            v-model:visible="showVarianteModal"
            :produit-id="String(produit.id)"
            :variante="varianteEnEdition"
            :is-fabricable="isFabricable"
        />

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
                {{
                    form.processing
                        ? 'Enregistrement…'
                        : 'Enregistrer les modifications'
                }}
            </button>
        </div>
    </AppLayout>
</template>
