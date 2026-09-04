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

// `required_prices`/`gere_stock` (cf. ProduitController::typesOptions()) pilotent l'affichage
// du "*" sur les prix obligatoires et de la section Stock, dans ProduitForm.vue.
// `achetable`/`vendable` pilotent la visibilité (applicabilité) de prix_achat/prix_vente.
interface ProduitTypeOption extends Option {
    gere_stock: boolean;
    required_prices: string[];
    achetable: boolean;
    vendable: boolean;
    code: string;
}

interface Categorie {
    id: string;
    nom: string;
    parent_id: string | null;
}

interface FournisseurOption {
    id: string;
    nom_complet: string;
    phone: string | null;
}

interface Limites {
    max_photos_produit: number;
    max_options_produit: number;
    max_valeurs_option: number;
    max_variantes_produit: number;
}

interface SiteOption {
    id: string;
    code: string;
    label: string;
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
    prix_usine_tricycle: number | null;
    prix_vente: number | null;
    prix_achat: number | null;
    cout: number | null;
    is_default: boolean;
    is_active: boolean;
    options: VarianteOption[];
}

interface ProduitData {
    id: number;
    nom: string;
    categorie_id: string | null;
    fournisseur_id: string | null;
    sku: string | null;
    code_barres: string | null;
    produit_type_id: string;
    statut: string;
    prix_usine: number | null;
    prix_usine_tricycle: number | null;
    prix_externe: number | null;
    prix_revendeur: number | null;
    prix_distributeur: number | null;
    prix_vente: number | null;
    prix_achat: number | null;
    cout: number | null;
    description: string | null;
    image_url: string | null;
    variantes_count: number;
    variantes: Variante[];
}

const props = defineProps<{
    produit: ProduitData;
    types: ProduitTypeOption[];
    statuts: Option[];
    categories: Categorie[];
    fournisseurs: FournisseurOption[];
    limites: Limites;
    seuilOrganisationDefaut: number;
    sites: SiteOption[];
    seuilsAlerteSite: Record<
        string,
        { disponible: boolean; actif: boolean; seuil: number | null }
    >;
}>();

const showVarianteModal = ref(false);
const varianteEnEdition = ref<Variante | null>(null);
// Expose canSubmit (champs obligatoires + marge usine bloquante) pour désactiver le bouton
// sticky mobile, physiquement hors du <form> (relié via l'attribut HTML form="produit-form").
const produitFormRef = ref<InstanceType<typeof ProduitForm> | null>(null);
const typeCourant = computed(() =>
    props.types.find((t) => t.value === props.produit.produit_type_id),
);
const prixUsineRequis = computed(
    () => typeCourant.value?.required_prices.includes('prix_usine') ?? false,
);
// cf. ProduitForm.vue : achetable/vendable pilotent la visibilité de prix_achat/prix_vente,
// distincte de leur obligation — même règle reproduite ici pour VarianteEditModal.vue afin de
// ne jamais diverger entre création/édition du produit simple et édition d'une variante.
const prixAchatApplicable = computed(
    () => typeCourant.value?.achetable ?? true,
);
const prixVenteApplicable = computed(() => typeCourant.value?.vendable ?? true);

function editerVariante(variante: Variante) {
    varianteEnEdition.value = variante;
    showVarianteModal.value = true;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Produits', href: '/backoffice/produits' },
    { title: props.produit.nom, href: '#' },
];

// Disponibilité — indépendante de l'alerte (cf. ProduitForm.vue) : mode "selection" dès qu'au
// moins un site actif est explicitement marqué non disponible, "tous" sinon (comportement par
// défaut, aucune restriction).
const sitesNonDisponibles = props.sites.filter(
    (s) => props.seuilsAlerteSite[s.id]?.disponible === false,
);
const disponibiliteModeInitial: 'tous' | 'selection' =
    sitesNonDisponibles.length > 0 ? 'selection' : 'tous';
const sitesDisponiblesInitial = props.sites
    .filter((s) => props.seuilsAlerteSite[s.id]?.disponible !== false)
    .map((s) => s.id);

const form = useForm({
    nom: props.produit.nom,
    categorie_id: props.produit.categorie_id,
    fournisseur_id: props.produit.fournisseur_id,
    code_barres: props.produit.code_barres,
    produit_type_id: props.produit.produit_type_id,
    statut: props.produit.statut,
    prix_usine: props.produit.prix_usine,
    prix_usine_tricycle: props.produit.prix_usine_tricycle,
    prix_externe: props.produit.prix_externe,
    prix_revendeur: props.produit.prix_revendeur,
    prix_distributeur: props.produit.prix_distributeur,
    prix_vente: props.produit.prix_vente,
    prix_achat: props.produit.prix_achat,
    cout: props.produit.cout,
    disponibilite_mode: disponibiliteModeInitial,
    sites_disponibles: sitesDisponiblesInitial,
    seuils_site: props.sites.map((s) => ({
        site_id: s.id,
        actif: props.seuilsAlerteSite[s.id]?.actif ?? false,
        seuil: props.seuilsAlerteSite[s.id]?.seuil ?? null,
    })),
    description: props.produit.description,
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
                ref="produitFormRef"
                :form="form"
                :errors="form.errors"
                :types="types"
                :statuts="statuts"
                :categories="categories"
                :fournisseurs="fournisseurs"
                :limites="limites"
                :seuil-organisation-defaut="seuilOrganisationDefaut"
                :sites="sites"
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
            :prix-usine-requis="prixUsineRequis"
            :prix-achat-applicable="prixAchatApplicable"
            :prix-vente-applicable="prixVenteApplicable"
        />

        <!-- ─── Footer sticky mobile ─── -->
        <div
            class="fixed right-0 bottom-0 left-0 z-30 border-t border-border/60 bg-background/95 px-4 py-3 backdrop-blur-sm sm:hidden"
        >
            <button
                type="submit"
                form="produit-form"
                :disabled="
                    form.processing || !(produitFormRef?.canSubmit ?? true)
                "
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
