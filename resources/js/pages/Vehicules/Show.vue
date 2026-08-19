<script setup lang="ts">
import DetailHeader from '@/components/DetailHeader.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPhoneDisplay } from '@/lib/utils';
import EquipeStepperModal from '@/pages/Vehicules/partials/EquipeStepperModal.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Car,
    CheckCircle,
    CircleHelp,
    ExternalLink,
    Pencil,
    Plus,
    Receipt,
    Settings,
    TriangleAlert,
    Users,
} from 'lucide-vue-next';
import Toast from 'primevue/toast';
import { useToast } from 'primevue/usetoast';
import { computed, ref } from 'vue';

interface EquipeMembre {
    livreur_nom: string | null;
    telephone: string | null;
    taux_commission: number;
    montant_par_pack: number;
    role: string;
}

interface DepenseRow {
    id: string;
    libelle: string;
    montant: number;
    date_depense: string | null;
    statut: string;
    commentaire: string | null;
}

interface MembreEquipeDetail {
    livreur_id: string | null;
    // Identité civile jamais utilisée côté Eau La Maman — voir
    // EquipeStepperModal.vue et EquipeLivraisonController.
    nom_complet: string | null;
    telephone: string;
    role: string;
    montant_par_pack: number;
    taux_commission: number;
    ordre: number;
}

/** V2 uniquement — partage Livraison déjà enregistré, groupé par catégorie. */
interface PartageCategorieDetail {
    categorie_id: string;
    parts: Array<{ livreur_id: string; part_pourcentage: number }>;
}

interface EquipeData {
    id: string;
    is_active: boolean;
    commission_unitaire_par_pack: number;
    montant_par_pack_proprietaire: number | null;
    taux_commission_proprietaire: number | null;
    proprietaire_id: string | null;
    proprietaire_nom: string | null;
    membres: MembreEquipeDetail[];
    partages_categorie: PartageCategorieDetail[];
}

interface ProprietaireOption {
    value: string;
    label: string;
    telephone?: string;
}

interface CapaciteRow {
    categorie_id: string;
    categorie_nom: string;
    capacite_max: number;
}

interface VehiculeData {
    id: string;
    nom_vehicule: string;
    immatriculation: string;
    type_label: string;
    type_vehicule_id: string | null;
    capacites: CapaciteRow[];
    site_id: string | null;
    site_nom: string | null;
    categorie: 'interne' | 'partenaire';
    categorie_label: string;
    proprietaire_id: string | null;
    proprietaire_nom: string | null;
    proprietaire_nom_affichage: string | null;
    proprietaire_est_entreprise: boolean;
    proprietaire_telephone: string | null;
    equipe_id: string | null;
    equipe_membres: EquipeMembre[];
    livraison_vente: boolean;
    livraison_logistique: boolean;
    photo_url: string | null;
    is_active: boolean;
    derogation_impayes_autorisee: boolean;
    type_seuil_derogation_impayes: number | null;
}

/** V2 uniquement — barème Livraison résolu par catégorie (cf. décision AMOA
 * post-Phase 2 : le partage entre livreurs est lui aussi défini par
 * catégorie, jamais un seul pourcentage valable pour toute la commande). */
interface BaremeLivraisonCategorie {
    categorie_id: string;
    categorie_nom: string;
    montant: number;
}

const props = defineProps<{
    vehicule: VehiculeData;
    depenses: DepenseRow[];
    equipe: EquipeData | null;
    proprietaires: ProprietaireOption[];
    default_proprietaire_id: string | null;
    seuil_global_impayes: number;
    bareme_commission: {
        proprietaire: number | null;
        livraison: number | null;
    };
    baremes_livraison_categories: BaremeLivraisonCategorie[];
    moteur_commission: 'legacy' | 'v2';
}>();

const { can } = usePermissions();
const page = usePage();
const toast = useToast();

const STATUTS_EDITABLES = ['brouillon', 'rejete', 'annule'];

function editerDepense(d: DepenseRow) {
    if (!STATUTS_EDITABLES.includes(d.statut)) {
        const label = statutLabel[d.statut] ?? d.statut;
        toast.add({
            severity: 'warn',
            summary: 'Modification impossible',
            detail: `Cette dépense est "${label}" et ne peut plus être modifiée. Seules les dépenses en brouillon, rejetées ou annulées sont éditables.`,
            life: 5000,
            group: 'top',
        });
        return;
    }
    router.visit(`/backoffice/depenses/${d.id}/edit`);
}
const showStepperModal = ref(false);
const flashSuccess = computed(
    () => (page.props as { flash?: { success?: string } }).flash?.success,
);

const activeTab = ref<'informations' | 'equipe' | 'depenses'>('informations');

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Véhicules', href: '/backoffice/vehicules' },
    { title: props.vehicule.nom_vehicule, href: '#' },
];

const statutLabel: Record<string, string> = {
    brouillon: 'Brouillon',
    soumis: 'Soumis',
    approuve: 'Approuvé',
    valide: 'Validé',
    rejete: 'Rejeté',
};

const totalApprouve = computed(() =>
    props.depenses
        .filter((d) => d.statut === 'approuve')
        .reduce((s, d) => s + d.montant, 0),
);

const totalLivreurs = computed(() =>
    props.vehicule.equipe_membres.reduce((s, m) => s + m.montant_par_pack, 0),
);

const tauxLivreurs = computed(() =>
    parseFloat(
        props.vehicule.equipe_membres
            .reduce((s, m) => s + m.taux_commission, 0)
            .toFixed(2),
    ),
);

function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}

/**
 * Même règle que SolvabiliteService::seuilApplicableVehicule() (côté affichage uniquement,
 * jamais utilisée pour bloquer une opération) : dérogation active ET type configuré → seuil du
 * type, sinon seuil standard des paramètres de vente.
 */
const derogationEffective = computed(
    () =>
        props.vehicule.derogation_impayes_autorisee &&
        props.vehicule.type_seuil_derogation_impayes !== null,
);

const seuilImpayesApplicable = computed(() =>
    derogationEffective.value
        ? (props.vehicule.type_seuil_derogation_impayes as number)
        : props.seuil_global_impayes,
);

/**
 * Bascule la dérogation directement depuis la fiche (VehiculeController::toggleDerogation()) —
 * pas de mise à jour optimiste : le Switch reste piloté par `vehicule.derogation_impayes_autorisee`
 * (jamais par un état local), donc un succès l'actualise via le rechargement Inertia consécutif à
 * la redirection back(), et un échec le laisse inchangé sans rien à "annuler" manuellement.
 */
const derogationProcessing = ref(false);

function toggleDerogation() {
    if (derogationProcessing.value) return;
    derogationProcessing.value = true;

    router.patch(
        `/backoffice/vehicules/${props.vehicule.id}/toggle-derogation`,
        {},
        {
            preserveScroll: true,
            onError: (errors) => {
                toast.add({
                    severity: 'error',
                    summary: 'Dérogation impayés',
                    detail:
                        errors.derogation_impayes_autorisee ??
                        'Impossible de mettre à jour la dérogation.',
                    life: 5000,
                    group: 'top',
                });
            },
            onFinish: () => {
                derogationProcessing.value = false;
            },
        },
    );
}
</script>

<template>
    <Head :title="`${vehicule.nom_vehicule} — Détail`" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- Header mobile -->
        <div
            class="sticky top-0 z-20 border-b border-border/60 bg-background/95 backdrop-blur-sm sm:hidden"
        >
            <div class="relative flex items-center justify-center px-4 py-3">
                <Link
                    href="/backoffice/vehicules"
                    class="absolute left-4 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground transition-transform active:scale-95"
                >
                    <ArrowLeft class="h-4 w-4" />
                </Link>
                <div class="text-center">
                    <h1 class="text-[17px] leading-tight font-semibold">
                        {{ vehicule.nom_vehicule }}
                    </h1>
                    <p class="font-mono text-[11px] text-muted-foreground">
                        {{ vehicule.immatriculation }}
                    </p>
                </div>
                <Link
                    v-if="can('vehicules.update')"
                    :href="`/backoffice/vehicules/${vehicule.id}/edit`"
                    class="absolute right-4"
                >
                    <Button
                        size="sm"
                        variant="outline"
                        class="h-8 gap-1.5 px-3 text-xs"
                    >
                        <Pencil class="h-3.5 w-3.5" />
                        Modifier
                    </Button>
                </Link>
            </div>
        </div>

        <div class="w-full space-y-6 p-4 sm:p-6">
            <!-- Flash success -->
            <div
                v-if="flashSuccess"
                class="flex items-center gap-2 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"
            >
                <CheckCircle class="h-4 w-4 shrink-0" />
                {{ flashSuccess }}
            </div>

            <!-- Header desktop -->
            <DetailHeader
                eyebrow="Véhicule"
                :title="vehicule.nom_vehicule"
                :icon="Car"
                :photo-url="vehicule.photo_url"
                avatar-shape="square"
                :status-label="vehicule.is_active ? 'Actif' : 'Inactif'"
                :status-dot-class="
                    vehicule.is_active
                        ? 'bg-emerald-500'
                        : 'bg-zinc-400 dark:bg-zinc-500'
                "
            >
                <template #subtitle>
                    <p class="mt-0.5 font-mono text-sm text-muted-foreground">
                        {{ vehicule.immatriculation }}
                    </p>
                    <div class="mt-1.5 flex items-center gap-2">
                        <span
                            class="inline-flex items-center rounded-full bg-muted px-2.5 py-0.5 text-xs font-medium"
                        >
                            {{ vehicule.type_label }}
                        </span>
                        <span
                            v-for="c in vehicule.capacites"
                            :key="c.categorie_id"
                            class="text-xs text-muted-foreground"
                        >
                            {{ c.categorie_nom }} : {{ c.capacite_max }}
                        </span>
                    </div>
                </template>
                <template #actions>
                    <Link
                        v-if="vehicule.proprietaire_id"
                        :href="`/backoffice/proprietaires/${vehicule.proprietaire_id}`"
                        target="_blank"
                        data-testid="voir-fiche-proprietaire-btn"
                    >
                        <Button variant="outline" size="sm">
                            <ExternalLink class="mr-1.5 h-4 w-4" />
                            Fiche propriétaire
                        </Button>
                    </Link>
                    <Link href="/backoffice/vehicules">
                        <Button variant="outline" size="sm">
                            <ArrowLeft class="mr-1.5 h-4 w-4" />
                            Liste de véhicules
                        </Button>
                    </Link>
                </template>
            </DetailHeader>

            <!-- Tab layout -->
            <div class="grid gap-6 lg:grid-cols-[220px_minmax(0,1fr)]">
                <!-- Sidebar tabs -->
                <aside class="h-fit rounded-xl border bg-card p-2">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm font-medium transition-colors"
                        :class="
                            activeTab === 'informations'
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:bg-muted'
                        "
                        @click="activeTab = 'informations'"
                    >
                        <span class="inline-flex items-center gap-2">
                            <CircleHelp class="h-4 w-4" />
                            Informations
                        </span>
                    </button>
                    <button
                        type="button"
                        class="mt-2 flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm font-medium transition-colors"
                        :class="
                            activeTab === 'equipe'
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:bg-muted'
                        "
                        @click="activeTab = 'equipe'"
                    >
                        <span class="inline-flex items-center gap-2">
                            <Users class="h-4 w-4" />
                            Equipe
                        </span>
                        <span
                            class="inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1 text-[11px]"
                            :class="
                                activeTab === 'equipe'
                                    ? 'bg-white/20 text-primary-foreground'
                                    : 'bg-muted text-muted-foreground'
                            "
                        >
                            {{ vehicule.equipe_membres.length }}
                        </span>
                    </button>
                    <button
                        type="button"
                        class="mt-2 flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm font-medium transition-colors"
                        :class="
                            activeTab === 'depenses'
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:bg-muted'
                        "
                        @click="activeTab = 'depenses'"
                    >
                        <span class="inline-flex items-center gap-2">
                            <Receipt class="h-4 w-4" />
                            Dépenses
                        </span>
                        <span
                            class="inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1 text-[11px]"
                            :class="
                                activeTab === 'depenses'
                                    ? 'bg-white/20 text-primary-foreground'
                                    : 'bg-muted text-muted-foreground'
                            "
                        >
                            {{ depenses.length }}
                        </span>
                    </button>
                </aside>

                <!-- Informations tab -->
                <template v-if="activeTab === 'informations'">
                    <div class="rounded-xl border bg-card p-5 sm:p-6">
                        <div class="flex items-center justify-between gap-2">
                            <h2
                                class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                            >
                                Informations du véhicule
                            </h2>
                            <Link
                                v-if="can('vehicules.update')"
                                :href="`/backoffice/vehicules/${vehicule.id}/edit`"
                            >
                                <Button size="sm" variant="outline">
                                    <Pencil class="mr-1.5 h-4 w-4" />
                                    Modifier
                                </Button>
                            </Link>
                        </div>
                        <div class="mt-5 grid gap-4 sm:grid-cols-2">
                            <div class="rounded-lg border bg-background p-4">
                                <p class="text-xs text-muted-foreground">
                                    Nom du véhicule
                                </p>
                                <p class="mt-1 text-sm font-medium">
                                    {{ vehicule.nom_vehicule }}
                                </p>
                            </div>
                            <div class="rounded-lg border bg-background p-4">
                                <p class="text-xs text-muted-foreground">
                                    Immatriculation
                                </p>
                                <p class="mt-1 font-mono text-sm font-medium">
                                    {{ vehicule.immatriculation }}
                                </p>
                            </div>
                            <div class="rounded-lg border bg-background p-4">
                                <p class="text-xs text-muted-foreground">
                                    Type
                                </p>
                                <p class="mt-1 text-sm font-medium">
                                    {{ vehicule.type_label }}
                                </p>
                            </div>
                            <div class="rounded-lg border bg-background p-4">
                                <p class="text-xs text-muted-foreground">
                                    Catégorie
                                </p>
                                <p class="mt-1 text-sm font-medium">
                                    {{ vehicule.categorie_label }}
                                </p>
                            </div>
                            <div class="rounded-lg border bg-background p-4">
                                <p class="text-xs text-muted-foreground">
                                    Usages
                                </p>
                                <p class="mt-1 flex flex-wrap gap-1.5">
                                    <span
                                        v-if="vehicule.livraison_vente"
                                        class="inline-flex items-center rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-950 dark:text-blue-300"
                                        >Vente</span
                                    >
                                    <span
                                        v-if="vehicule.livraison_logistique"
                                        class="inline-flex items-center rounded-full bg-orange-50 px-2 py-0.5 text-xs font-medium text-orange-700 dark:bg-orange-950 dark:text-orange-300"
                                        >Logistique</span
                                    >
                                    <span
                                        v-if="
                                            !vehicule.livraison_vente &&
                                            !vehicule.livraison_logistique
                                        "
                                        class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-950 dark:text-amber-300"
                                    >
                                        <TriangleAlert class="h-3 w-3" />
                                        Usage non défini
                                    </span>
                                </p>
                                <p
                                    v-if="
                                        !vehicule.livraison_vente &&
                                        !vehicule.livraison_logistique
                                    "
                                    class="mt-1.5 text-xs text-muted-foreground"
                                >
                                    Ce véhicule existe mais ne peut être utilisé
                                    pour aucune opération tant qu'un usage n'est
                                    pas défini.
                                </p>
                            </div>
                            <div class="rounded-lg border bg-background p-4">
                                <p class="text-xs text-muted-foreground">
                                    Capacités maximales de chargement
                                </p>
                                <p
                                    v-if="vehicule.capacites.length === 0"
                                    class="mt-1 text-sm font-medium"
                                >
                                    — (non plafonné)
                                </p>
                                <p
                                    v-for="c in vehicule.capacites"
                                    :key="c.categorie_id"
                                    class="mt-1 text-sm font-medium"
                                >
                                    {{ c.categorie_nom }} :
                                    {{ c.capacite_max }}
                                </p>
                            </div>
                            <div class="rounded-lg border bg-background p-4">
                                <p class="text-xs text-muted-foreground">
                                    Site
                                </p>
                                <p class="mt-1 text-sm font-medium">
                                    {{ vehicule.site_nom ?? '—' }}
                                </p>
                            </div>
                            <div class="rounded-lg border bg-background p-4">
                                <p class="text-xs text-muted-foreground">
                                    Propriétaire
                                </p>
                                <template v-if="vehicule.proprietaire_id">
                                    <div class="mt-1 flex items-center gap-1.5">
                                        <p
                                            class="text-sm font-medium"
                                            data-testid="proprietaire-nom"
                                        >
                                            {{
                                                vehicule.proprietaire_nom_affichage ??
                                                vehicule.proprietaire_nom
                                            }}
                                        </p>
                                        <span
                                            v-if="
                                                vehicule.proprietaire_est_entreprise
                                            "
                                            class="inline-flex items-center rounded-full bg-indigo-50 px-1.5 py-0.5 text-[10px] font-medium text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300"
                                            >Entreprise</span
                                        >
                                    </div>
                                    <p
                                        class="mt-0.5 font-mono text-xs text-muted-foreground"
                                        data-testid="proprietaire-telephone"
                                    >
                                        {{
                                            formatPhoneDisplay(
                                                vehicule.proprietaire_telephone,
                                            )
                                        }}
                                    </p>
                                </template>
                                <template v-else>
                                    <p
                                        class="mt-1 text-sm text-muted-foreground"
                                    >
                                        Aucun propriétaire rattaché
                                    </p>
                                </template>
                            </div>
                            <div class="rounded-lg border bg-background p-4">
                                <div
                                    class="flex items-center justify-between gap-3"
                                >
                                    <p class="text-sm font-medium">
                                        Dérogation impayés
                                    </p>
                                    <Switch
                                        aria-label="Dérogation impayés"
                                        :model-value="
                                            vehicule.derogation_impayes_autorisee
                                        "
                                        :disabled="
                                            derogationProcessing ||
                                            !can('vehicules.update')
                                        "
                                        @update:model-value="toggleDerogation()"
                                    />
                                </div>
                                <p class="mt-1.5 text-xs text-muted-foreground">
                                    {{
                                        derogationEffective
                                            ? 'Plafond autorisé'
                                            : 'Seuil applicable'
                                    }}
                                    : {{ formatGNF(seuilImpayesApplicable) }}
                                </p>
                            </div>
                        </div>
                    </div>
                </template>

                <!-- Equipe tab -->
                <div
                    v-else-if="activeTab === 'equipe'"
                    class="rounded-xl border bg-card p-5 sm:p-6"
                >
                    <div
                        class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div>
                            <h2
                                class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                            >
                                Équipe de livraison
                            </h2>
                            <p class="mt-1 text-sm text-muted-foreground">
                                {{ vehicule.equipe_membres.length }} membre{{
                                    vehicule.equipe_membres.length > 1
                                        ? 's'
                                        : ''
                                }}
                            </p>
                        </div>
                        <Button
                            v-if="
                                can('equipes-livraison.update') &&
                                vehicule.equipe_id
                            "
                            size="sm"
                            @click="showStepperModal = true"
                        >
                            <Settings class="mr-1.5 h-4 w-4" />
                            Gérer l'équipe
                        </Button>
                        <Button
                            v-else-if="can('equipes-livraison.create')"
                            size="sm"
                            @click="showStepperModal = true"
                        >
                            <Plus class="mr-1.5 h-4 w-4" />
                            Ajouter une équipe
                        </Button>
                    </div>

                    <div class="space-y-3">
                        <div
                            v-if="vehicule.equipe_membres.length === 0"
                            class="rounded-lg border border-dashed py-10 text-center"
                        >
                            <p class="text-sm text-muted-foreground">
                                Aucun membre dans l'équipe.
                            </p>
                        </div>

                        <div v-else class="overflow-x-auto rounded-lg border">
                            <table class="w-full table-fixed text-sm">
                                <colgroup>
                                    <col class="w-1/5" />
                                    <col class="w-1/5" />
                                    <col class="w-1/5" />
                                    <col class="w-1/5" />
                                    <col class="w-1/5" />
                                </colgroup>
                                <thead
                                    class="bg-muted/30 text-left text-muted-foreground"
                                >
                                    <tr>
                                        <th class="px-4 py-3 font-medium">
                                            Livreur
                                        </th>
                                        <th class="px-4 py-3 font-medium">
                                            Téléphone
                                        </th>
                                        <th class="px-4 py-3 font-medium">
                                            Rôle
                                        </th>
                                        <th class="px-4 py-3 font-medium">
                                            Montant / pack
                                        </th>
                                        <th
                                            class="px-4 py-3 text-right font-medium"
                                        >
                                            Commission
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y">
                                    <tr
                                        v-for="(
                                            m, i
                                        ) in vehicule.equipe_membres"
                                        :key="i"
                                        class="hover:bg-muted/20"
                                    >
                                        <td class="px-4 py-3 font-medium">
                                            {{ m.livreur_nom ?? '—' }}
                                        </td>
                                        <td
                                            class="px-4 py-3 font-mono text-xs text-muted-foreground"
                                        >
                                            {{
                                                m.telephone
                                                    ? formatPhoneDisplay(
                                                          m.telephone,
                                                      )
                                                    : '—'
                                            }}
                                        </td>
                                        <td class="px-4 py-3">
                                            <span
                                                v-if="m.role === 'principal'"
                                                class="inline-flex items-center rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary"
                                                >Principal</span
                                            >
                                            <span
                                                v-else
                                                class="text-muted-foreground capitalize"
                                                >{{ m.role }}</span
                                            >
                                        </td>
                                        <td class="px-4 py-3 font-mono text-sm">
                                            {{
                                                m.montant_par_pack.toLocaleString(
                                                    'fr-FR',
                                                )
                                            }}
                                            GNF
                                        </td>
                                        <td
                                            class="px-4 py-3 text-right text-muted-foreground"
                                        >
                                            {{ m.taux_commission }}%
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Récap répartition -->
                        <div
                            v-if="equipe && vehicule.equipe_membres.length > 0"
                            class="mt-2 rounded-lg border bg-muted/30 p-4"
                        >
                            <p
                                class="mb-3 text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                            >
                                Répartition par pack
                            </p>
                            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                                <div>
                                    <p class="text-xs text-muted-foreground">
                                        Commission totale
                                    </p>
                                    <p
                                        class="mt-0.5 font-mono text-sm font-semibold tabular-nums"
                                    >
                                        {{
                                            formatGNF(
                                                equipe.commission_unitaire_par_pack,
                                            )
                                        }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        100%
                                    </p>
                                </div>
                                <div
                                    v-if="equipe.montant_par_pack_proprietaire"
                                >
                                    <p class="text-xs text-muted-foreground">
                                        Part propriétaire
                                    </p>
                                    <p
                                        class="mt-0.5 font-mono text-sm font-semibold tabular-nums"
                                    >
                                        {{
                                            formatGNF(
                                                equipe.montant_par_pack_proprietaire,
                                            )
                                        }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{
                                            equipe.taux_commission_proprietaire
                                        }}%
                                    </p>
                                </div>
                                <div>
                                    <p class="text-xs text-muted-foreground">
                                        Part livreurs
                                    </p>
                                    <p
                                        class="mt-0.5 font-mono text-sm font-semibold tabular-nums"
                                    >
                                        {{ formatGNF(totalLivreurs) }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ tauxLivreurs }}%
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Dépenses tab -->
                <div v-else class="rounded-xl border bg-card p-5 sm:p-6">
                    <div
                        class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                    >
                        <div>
                            <h2
                                class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                            >
                                Dépenses du véhicule
                            </h2>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Dépenses opérationnelles gérées via le module
                                Dépenses.
                            </p>
                        </div>
                        <span
                            v-if="totalApprouve > 0"
                            class="shrink-0 rounded-lg bg-amber-50 px-3 py-1 text-sm font-semibold text-amber-700 tabular-nums"
                        >
                            Approuvés : {{ formatGNF(totalApprouve) }}
                        </span>
                    </div>

                    <div
                        v-if="!depenses.length"
                        class="rounded-lg border border-dashed py-10 text-center"
                    >
                        <p class="text-sm text-muted-foreground">
                            Aucune dépense enregistrée pour ce véhicule.
                        </p>
                    </div>

                    <div v-else class="divide-y rounded-lg border">
                        <div
                            v-for="d in depenses"
                            :key="d.id"
                            class="flex items-center gap-4 px-4 py-3 hover:bg-muted/30"
                        >
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-semibold tabular-nums">
                                    {{ formatGNF(d.montant) }}
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    {{ d.libelle }}
                                    <span v-if="d.commentaire">
                                        · {{ d.commentaire }}</span
                                    >
                                </div>
                            </div>
                            <div
                                class="hidden text-xs text-muted-foreground sm:block"
                            >
                                {{ d.date_depense ?? '—' }}
                            </div>
                            <StatusDot
                                :status="d.statut"
                                :label="statutLabel[d.statut] ?? d.statut"
                                class="shrink-0"
                            />
                            <template v-if="can('depenses.update')">
                                <button
                                    v-if="STATUTS_EDITABLES.includes(d.statut)"
                                    class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground"
                                    @click="editerDepense(d)"
                                >
                                    <Pencil class="h-3.5 w-3.5" />
                                </button>
                                <span
                                    v-else
                                    class="inline-flex h-8 w-8 shrink-0 cursor-default items-center justify-center rounded-md"
                                    :class="
                                        d.statut === 'valide'
                                            ? 'text-green-500/70'
                                            : 'text-blue-400/70'
                                    "
                                    :title="`Dépense ${statutLabel[d.statut] ?? d.statut} — non modifiable`"
                                >
                                    <CheckCircle class="h-3.5 w-3.5" />
                                </span>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>

    <Toast group="top" position="top-right" />

    <EquipeStepperModal
        v-model:visible="showStepperModal"
        :vehicule="{
            id: vehicule.id,
            nom_vehicule: vehicule.nom_vehicule,
            immatriculation: vehicule.immatriculation,
            proprietaire_id: vehicule.proprietaire_id,
            proprietaire_nom:
                vehicule.proprietaire_nom_affichage ??
                vehicule.proprietaire_nom,
            proprietaire_est_entreprise: vehicule.proprietaire_est_entreprise,
        }"
        :equipe="equipe"
        :proprietaires="proprietaires"
        :bareme-commission="bareme_commission"
        :baremes-livraison-categories="baremes_livraison_categories"
        :moteur-commission="moteur_commission"
    />
</template>
