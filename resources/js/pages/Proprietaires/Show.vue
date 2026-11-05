<script setup lang="ts">
import DetailHeader from '@/components/DetailHeader.vue';
import ImageLightbox from '@/components/ImageLightbox.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPhoneDisplay } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Car,
    CircleHelp,
    ExternalLink,
    IdCard,
    Pencil,
    Plus,
    Receipt,
    ShieldCheck,
    UserRound,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import PieceIdentiteSection from './partials/PieceIdentiteSection.vue';

interface TypeOption {
    value: string;
    label: string;
}

interface PieceIdentiteRow {
    id: string;
    type_piece: string;
    type_piece_label: string;
    numero_masque: string | null;
    pays_delivrance: string | null;
    date_delivrance: string | null;
    date_expiration: string | null;
    statut_verification: string;
    statut_verification_label: string;
    statut_affichage: string;
    est_active: boolean;
    motif_rejet: string | null;
    verifiee_le: string | null;
    has_recto: boolean;
    has_verso: boolean;
    created_at: string | null;
}

interface PieceIdentitePermissions {
    can_create: boolean;
    can_update: boolean;
    can_delete: boolean;
    can_download: boolean;
    can_valider: boolean;
    can_rejeter: boolean;
}

interface ProprietaireData {
    id: string;
    nom: string;
    prenom: string;
    surnom: string | null;
    nom_complet: string;
    type: string;
    raison_sociale: string | null;
    nom_affichage: string;
    est_entreprise: boolean;
    email: string | null;
    telephone: string | null;
    code_phone_pays: string | null;
    ville: string | null;
    pays: string | null;
    code_pays: string | null;
    adresse: string | null;
    is_active: boolean;
    vehicules_count: number;
    has_valid_identity_document: boolean;
    is_proprietaire_interne: boolean;
}

interface EquipeMembre {
    nom: string;
    telephone: string | null;
}

interface EquipeDetail {
    nom: string;
    taux_commission_proprietaire: number | null;
    chauffeur: EquipeMembre | null;
    convoyeurs: EquipeMembre[];
}

interface VehiculeRow {
    id: number;
    nom_vehicule: string;
    immatriculation: string | null;
    photo_url: string | null;
    type_label: string;
    capacites: { categorie_nom: string; capacite_max: number }[];
    categorie: string | null;
    is_active: boolean;
    equipe_detail: EquipeDetail | null;
}

interface DepenseRow {
    id: string;
    libelle: string;
    montant: number;
    date_depense: string | null;
    statut: string;
    commentaire: string | null;
}

const props = defineProps<{
    proprietaire: ProprietaireData;
    vehicules: VehiculeRow[];
    depenses: DepenseRow[];
    pieces_identite: PieceIdentiteRow[];
    piece_identite_permissions: PieceIdentitePermissions;
    type_piece_options: TypeOption[];
    can_create_vehicule: boolean;
}>();

const { can } = usePermissions();
const confirm = useConfirm();
const toast = useToast();

function confirmDefinirInterne() {
    confirm.require({
        message: `Définir « ${props.proprietaire.nom_affichage} » comme propriétaire interne par défaut de l'organisation ? Les véhicules "interne" sans propriétaire tiers choisi, ainsi que leurs commissions propriétaire, lui seront désormais rattachés.`,
        header: 'Confirmer le propriétaire interne',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Annuler',
        acceptLabel: 'Confirmer',
        accept: () => {
            router.post(
                `/backoffice/proprietaires/${props.proprietaire.id}/definir-interne`,
                {},
                {
                    onSuccess: () =>
                        toast.add({
                            severity: 'success',
                            summary: 'Propriétaire interne défini',
                            detail: `${props.proprietaire.nom_affichage} est maintenant le propriétaire interne par défaut.`,
                            life: 3000,
                        }),
                },
            );
        },
    });
}

type OngletProprietaire =
    | 'informations'
    | 'vehicules'
    | 'depenses'
    | 'piece_identite';

const activeTab = ref<OngletProprietaire>('informations');

const detailTabs = computed(() => [
    {
        key: 'informations' as const,
        label: 'Informations',
        icon: CircleHelp,
        count: null,
        testid: 'owner-informations-tab',
    },
    {
        key: 'vehicules' as const,
        label: 'Vehicules',
        icon: Car,
        count: props.vehicules.length,
        testid: 'owner-vehicles-tab',
    },
    {
        key: 'depenses' as const,
        label: 'Dépenses',
        icon: Receipt,
        count: props.depenses.length,
        testid: 'owner-depenses-tab',
    },
    {
        key: 'piece_identite' as const,
        label: "Pièce d'identité",
        icon: IdCard,
        count: props.pieces_identite.length,
        testid: 'owner-piece-identite-tab',
    },
]);

function classeCompteur(key: OngletProprietaire): string {
    if (activeTab.value === key) {
        return 'bg-primary/10 text-primary lg:bg-white/20 lg:text-primary-foreground';
    }
    if (
        key === 'piece_identite' &&
        props.proprietaire.has_valid_identity_document
    ) {
        return 'bg-emerald-500/20 text-emerald-700 dark:text-emerald-400';
    }

    return 'bg-muted text-muted-foreground';
}

const tabNav = ref<HTMLElement | null>(null);

// Sous 1024 px les onglets défilent horizontalement : l'onglet actif est recentré.
async function centrerOnglet() {
    await nextTick();
    const nav = tabNav.value;
    if (!nav || !window.matchMedia('(max-width: 1023px)').matches) return;
    const tab = nav.querySelector<HTMLElement>('[aria-selected="true"]');
    if (tab)
        nav.scrollLeft =
            tab.offsetLeft - (nav.clientWidth - tab.offsetWidth) / 2;
}
watch(activeTab, centrerOnglet);
onMounted(centrerOnglet);

function naviguerOnglets(event: KeyboardEvent, key: OngletProprietaire) {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
    event.preventDefault();
    const keys = detailTabs.value.map((t) => t.key);
    const index = keys.indexOf(key);
    let next = (index + 1) % keys.length;
    if (event.key === 'Home') next = 0;
    else if (event.key === 'End') next = keys.length - 1;
    else if (event.key === 'ArrowLeft')
        next = (index - 1 + keys.length) % keys.length;
    activeTab.value = keys[next];
    nextTick(() =>
        document.getElementById(`proprietaire-tab-${keys[next]}`)?.focus(),
    );
}

const statutLabel: Record<string, string> = {
    brouillon: 'Brouillon',
    soumis: 'Soumis',
    approuve: 'Approuvé',
    rejete: 'Rejeté',
};

const totalApprouve = computed(() =>
    props.depenses
        .filter((d) => d.statut === 'approuve')
        .reduce((s, d) => s + d.montant, 0),
);

function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Proprietaires', href: '/backoffice/proprietaires' },
    { title: props.proprietaire.nom_affichage, href: '#' },
];

const locationLabel = computed(() => {
    const address = (props.proprietaire.adresse ?? '').trim();
    const city = (props.proprietaire.ville ?? '').trim();
    if (!address && !city) {
        return '-';
    }
    if (!address) {
        return city;
    }
    if (!city) {
        return address;
    }

    return `${address}, ${city}`;
});

function flagUrl(code: string) {
    return `https://flagcdn.com/20x15/${code.toLowerCase()}.png`;
}

function chauffeurCountLabel(equipe: EquipeDetail | null): string {
    const count = equipe?.chauffeur ? 1 : 0;
    return `${count} chauffeur${count > 1 ? 's' : ''}`;
}

function convoyeurCountLabel(equipe: EquipeDetail | null): string {
    const count = equipe?.convoyeurs.length ?? 0;
    return `${count} convoyeur${count > 1 ? 's' : ''}`;
}

const equipeDialogVisible = ref(false);
const selectedVehiculeForEquipe = ref<VehiculeRow | null>(null);

function openEquipeDialog(vehicule: VehiculeRow) {
    selectedVehiculeForEquipe.value = vehicule;
    equipeDialogVisible.value = true;
}

const lightboxUrl = ref<string | null>(null);
const lightboxAlt = ref('');

function openLightbox(url: string, alt: string) {
    lightboxUrl.value = url;
    lightboxAlt.value = alt;
}

function closeLightbox() {
    lightboxUrl.value = null;
}
</script>

<template>
    <Head :title="`${proprietaire.nom_affichage} - Detail proprietaire`" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- Header mobile -->
        <header
            data-testid="proprietaire-mobile-header"
            class="fixed inset-x-0 top-0 z-20 grid grid-cols-[2.75rem_minmax(0,1fr)_2.75rem] items-center gap-2 border-b bg-background px-4 pt-[max(0.5rem,env(safe-area-inset-top))] pb-2 sm:hidden"
        >
            <Link
                href="/backoffice/proprietaires"
                aria-label="Retour aux propriétaires"
                class="inline-flex size-11 items-center justify-center rounded-full text-muted-foreground hover:bg-muted"
            >
                <ArrowLeft class="h-4 w-4" />
            </Link>
            <div class="min-w-0 text-center">
                <h1 class="truncate text-base leading-tight font-semibold">
                    Détail du propriétaire
                </h1>
            </div>
            <div aria-hidden="true" />
        </header>

        <div
            class="proprietaire-detail w-full min-w-0 space-y-4 px-4 pt-[calc(77px+env(safe-area-inset-top))] pb-[max(1rem,env(safe-area-inset-bottom))] sm:space-y-6 sm:p-6"
        >
            <div
                data-testid="proprietaire-identity"
                class="min-w-0 [overflow-wrap:anywhere]"
            >
                <DetailHeader
                    eyebrow="Propriétaire"
                    :title="proprietaire.nom_affichage"
                    :icon="UserRound"
                    :status-label="proprietaire.is_active ? 'Actif' : 'Inactif'"
                    :status-dot-class="
                        proprietaire.is_active
                            ? 'bg-emerald-500'
                            : 'bg-zinc-400 dark:bg-zinc-500'
                    "
                >
                    <template #subtitle>
                        <div class="mt-1 flex flex-wrap items-center gap-2">
                            <span
                                v-if="proprietaire.est_entreprise"
                                class="inline-flex items-center rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300"
                                data-testid="proprietaire-type-badge"
                                >Entreprise</span
                            >
                            <p class="text-sm text-muted-foreground">
                                {{ proprietaire.vehicules_count }} vehicule{{
                                    proprietaire.vehicules_count > 1 ? 's' : ''
                                }}
                                rattache{{
                                    proprietaire.vehicules_count > 1 ? 's' : ''
                                }}
                            </p>
                        </div>
                    </template>
                    <template #actions>
                        <Link
                            href="/backoffice/proprietaires"
                            class="hidden sm:block"
                        >
                            <Button variant="outline" size="sm">
                                <ArrowLeft class="mr-1.5 h-4 w-4" />
                                Retour
                            </Button>
                        </Link>
                    </template>
                </DetailHeader>
            </div>

            <div
                class="grid min-w-0 gap-4 sm:gap-6 lg:grid-cols-[220px_minmax(0,1fr)]"
            >
                <nav
                    ref="tabNav"
                    role="tablist"
                    aria-label="Rubriques du propriétaire"
                    data-testid="proprietaire-tabs"
                    class="proprietaire-tabs relative flex min-w-0 gap-1 overflow-x-auto border-b lg:h-fit lg:flex-col lg:rounded-xl lg:border lg:bg-card lg:p-2"
                >
                    <button
                        v-for="tab in detailTabs"
                        :id="'proprietaire-tab-' + tab.key"
                        :key="tab.key"
                        type="button"
                        role="tab"
                        :data-testid="tab.testid"
                        :aria-selected="activeTab === tab.key"
                        aria-controls="proprietaire-tabpanel"
                        :tabindex="activeTab === tab.key ? 0 : -1"
                        class="flex min-h-11 shrink-0 items-center justify-between gap-2 border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors lg:w-full lg:rounded-lg lg:border-b-0"
                        :class="
                            activeTab === tab.key
                                ? 'border-primary text-primary lg:bg-primary lg:text-primary-foreground'
                                : 'border-transparent text-muted-foreground hover:bg-muted'
                        "
                        @click="activeTab = tab.key"
                        @keydown="naviguerOnglets($event, tab.key)"
                    >
                        <span class="inline-flex items-center gap-2"
                            ><component
                                :is="tab.icon"
                                class="hidden size-4 lg:block"
                            />{{ tab.label }}</span
                        >
                        <span
                            v-if="tab.count !== null"
                            class="inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1 text-[11px]"
                            :class="classeCompteur(tab.key)"
                            >{{ tab.count }}</span
                        >
                    </button>
                </nav>

                <div
                    id="proprietaire-tabpanel"
                    role="tabpanel"
                    :aria-labelledby="'proprietaire-tab-' + activeTab"
                    class="min-w-0 [overflow-wrap:anywhere]"
                >
                    <div
                        v-if="activeTab === 'informations'"
                        class="rounded-xl border bg-card p-4 sm:p-6"
                    >
                        <div
                            class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <h2
                                class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                            >
                                Informations du proprietaire
                            </h2>
                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    v-if="proprietaire.is_proprietaire_interne"
                                    class="inline-flex items-center gap-1.5 rounded-lg bg-primary/10 px-2.5 py-1.5 text-xs font-medium text-primary"
                                >
                                    <ShieldCheck class="h-3.5 w-3.5" />
                                    Propriétaire interne de l'organisation
                                </span>
                                <Button
                                    v-else-if="can('proprietaires.update')"
                                    size="sm"
                                    variant="outline"
                                    @click="confirmDefinirInterne"
                                >
                                    <ShieldCheck class="mr-1.5 h-4 w-4" />
                                    Définir comme propriétaire interne
                                </Button>
                                <Link
                                    v-if="can('proprietaires.update')"
                                    :href="`/backoffice/proprietaires/${proprietaire.id}/edit`"
                                >
                                    <Button size="sm" variant="outline">
                                        <Pencil class="mr-1.5 h-4 w-4" />
                                        Modifier
                                    </Button>
                                </Link>
                            </div>
                        </div>
                        <div
                            class="mt-5 grid gap-4 sm:grid-cols-2"
                            data-info-grid
                        >
                            <div class="rounded-lg border bg-background p-4">
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        proprietaire.est_entreprise
                                            ? 'Raison sociale'
                                            : 'Nom complet'
                                    }}
                                </p>
                                <p
                                    class="mt-1 text-sm font-medium"
                                    data-testid="proprietaire-nom-affichage"
                                >
                                    {{ proprietaire.nom_affichage }}
                                </p>
                            </div>
                            <div
                                v-if="
                                    proprietaire.est_entreprise &&
                                    proprietaire.nom_complet.trim()
                                "
                                class="rounded-lg border bg-background p-4"
                            >
                                <p class="text-xs text-muted-foreground">
                                    Contact
                                </p>
                                <p class="mt-1 text-sm font-medium">
                                    {{ proprietaire.nom_complet }}
                                </p>
                            </div>
                            <div
                                v-if="proprietaire.surnom"
                                class="rounded-lg border bg-background p-4"
                            >
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        proprietaire.est_entreprise
                                            ? 'Nom commercial / enseigne'
                                            : 'Surnom'
                                    }}
                                </p>
                                <p class="mt-1 text-sm font-medium">
                                    {{ proprietaire.surnom }}
                                </p>
                            </div>
                            <div class="rounded-lg border bg-background p-4">
                                <p class="text-xs text-muted-foreground">
                                    Telephone
                                </p>
                                <p class="mt-1 text-sm font-medium">
                                    {{
                                        formatPhoneDisplay(
                                            proprietaire.telephone,
                                            proprietaire.code_phone_pays,
                                        ) ?? '-'
                                    }}
                                </p>
                            </div>
                            <div class="rounded-lg border bg-background p-4">
                                <p class="text-xs text-muted-foreground">
                                    Email
                                </p>
                                <p class="mt-1 text-sm font-medium">
                                    {{ proprietaire.email ?? '-' }}
                                </p>
                            </div>
                            <div class="rounded-lg border bg-background p-4">
                                <p class="text-xs text-muted-foreground">
                                    Localisation
                                </p>
                                <div class="mt-1 flex items-center gap-2">
                                    <img
                                        v-if="proprietaire.code_pays"
                                        :src="flagUrl(proprietaire.code_pays)"
                                        class="h-4 w-auto rounded-sm shadow-sm"
                                    />
                                    <p class="text-sm font-medium">
                                        {{ locationLabel }}
                                    </p>
                                </div>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{ proprietaire.pays ?? '-' }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div
                        v-else-if="activeTab === 'vehicules'"
                        class="rounded-xl border bg-card p-4 sm:p-6"
                    >
                        <div
                            class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div>
                                <h2
                                    class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                                >
                                    Liste des vehicules
                                </h2>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    {{ vehicules.length }} vehicule{{
                                        vehicules.length > 1 ? 's' : ''
                                    }}
                                </p>
                            </div>
                            <Link
                                v-if="can_create_vehicule"
                                :href="`/backoffice/vehicules/create?proprietaire_id=${proprietaire.id}`"
                                data-testid="add-owner-vehicle-btn"
                            >
                                <Button size="sm">
                                    <Plus class="mr-1.5 h-4 w-4" />
                                    Ajouter un vehicule
                                </Button>
                            </Link>
                        </div>

                        <div
                            v-if="vehicules.length === 0"
                            class="rounded-lg border border-dashed py-10 text-center"
                        >
                            <Car
                                class="mx-auto h-10 w-10 text-muted-foreground/30"
                            />
                            <p class="mt-3 text-sm text-muted-foreground">
                                Aucun vehicule rattache a ce proprietaire.
                            </p>
                        </div>

                        <div v-else class="overflow-x-auto rounded-lg border">
                            <table
                                class="w-full text-sm"
                                data-mobile-cards="vehicules"
                            >
                                <thead
                                    class="bg-muted/30 text-left text-muted-foreground"
                                >
                                    <tr>
                                        <th class="px-4 py-3 font-medium">
                                            Photo
                                        </th>
                                        <th class="px-4 py-3 font-medium">
                                            Vehicule
                                        </th>
                                        <th class="px-4 py-3 font-medium">
                                            Immatriculation
                                        </th>
                                        <th class="px-4 py-3 font-medium">
                                            Type
                                        </th>
                                        <th class="px-4 py-3 font-medium">
                                            Capacite
                                        </th>
                                        <th class="px-4 py-3 font-medium">
                                            Equipe
                                        </th>
                                        <th class="px-4 py-3 font-medium">
                                            Statut
                                        </th>
                                        <th class="px-4 py-3 font-medium">
                                            Action
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y">
                                    <tr
                                        v-for="vehicule in vehicules"
                                        :key="vehicule.id"
                                        class="hover:bg-muted/20"
                                    >
                                        <td data-photo class="px-4 py-3">
                                            <div
                                                class="h-10 w-10 overflow-hidden rounded-lg border bg-muted"
                                                :class="
                                                    vehicule.photo_url
                                                        ? 'cursor-zoom-in'
                                                        : ''
                                                "
                                                @click="
                                                    vehicule.photo_url &&
                                                    openLightbox(
                                                        vehicule.photo_url,
                                                        vehicule.nom_vehicule,
                                                    )
                                                "
                                            >
                                                <img
                                                    v-if="vehicule.photo_url"
                                                    :src="vehicule.photo_url"
                                                    :alt="vehicule.nom_vehicule"
                                                    class="h-full w-full object-cover"
                                                />
                                                <div
                                                    v-else
                                                    class="flex h-full w-full items-center justify-center"
                                                >
                                                    <Car
                                                        class="h-5 w-5 text-muted-foreground/40"
                                                    />
                                                </div>
                                            </div>
                                        </td>
                                        <td data-nom class="px-4 py-3">
                                            <Link
                                                v-if="can('vehicules.read')"
                                                :href="`/backoffice/vehicules/${vehicule.id}`"
                                                class="font-medium hover:underline"
                                            >
                                                {{ vehicule.nom_vehicule }}
                                            </Link>
                                            <span v-else class="font-medium">{{
                                                vehicule.nom_vehicule
                                            }}</span>
                                            <p
                                                class="text-xs text-muted-foreground capitalize"
                                            >
                                                {{ vehicule.categorie ?? '-' }}
                                            </p>
                                        </td>
                                        <td
                                            data-label="Immatriculation"
                                            class="px-4 py-3 font-mono text-muted-foreground"
                                        >
                                            {{
                                                vehicule.immatriculation ?? '-'
                                            }}
                                        </td>
                                        <td data-label="Type" class="px-4 py-3">
                                            {{ vehicule.type_label }}
                                        </td>
                                        <td
                                            data-label="Capacite"
                                            data-wide
                                            class="px-4 py-3"
                                        >
                                            <template
                                                v-if="
                                                    vehicule.capacites
                                                        .length === 0
                                                "
                                            >
                                                -
                                            </template>
                                            <template v-else>
                                                <span
                                                    v-for="(
                                                        c, i
                                                    ) in vehicule.capacites"
                                                    :key="c.categorie_nom"
                                                >
                                                    {{ i > 0 ? ' · ' : ''
                                                    }}{{ c.categorie_nom }} :
                                                    {{ c.capacite_max }}
                                                </span>
                                            </template>
                                        </td>
                                        <td
                                            data-label="Equipe"
                                            class="px-4 py-3"
                                        >
                                            <div
                                                class="flex items-center gap-1.5"
                                            >
                                                <p>
                                                    {{
                                                        chauffeurCountLabel(
                                                            vehicule.equipe_detail,
                                                        )
                                                    }}
                                                </p>
                                                <button
                                                    v-if="
                                                        vehicule.equipe_detail
                                                    "
                                                    type="button"
                                                    class="inline-flex shrink-0 items-center text-primary focus:outline-none"
                                                    @click="
                                                        openEquipeDialog(
                                                            vehicule,
                                                        )
                                                    "
                                                >
                                                    <ExternalLink
                                                        class="h-3.5 w-3.5"
                                                    />
                                                </button>
                                            </div>
                                            <p
                                                class="text-xs text-muted-foreground"
                                            >
                                                {{
                                                    convoyeurCountLabel(
                                                        vehicule.equipe_detail,
                                                    )
                                                }}
                                            </p>
                                        </td>
                                        <td
                                            data-label="Statut"
                                            class="px-4 py-3"
                                        >
                                            <StatusDot
                                                :label="
                                                    vehicule.is_active
                                                        ? 'Actif'
                                                        : 'Inactif'
                                                "
                                                :dot-class="
                                                    vehicule.is_active
                                                        ? 'bg-emerald-500'
                                                        : 'bg-zinc-400 dark:bg-zinc-500'
                                                "
                                                class="text-muted-foreground"
                                            />
                                        </td>
                                        <td data-wide class="px-4 py-3">
                                            <Link
                                                v-if="can('vehicules.update')"
                                                :href="`/backoffice/vehicules/${vehicule.id}/edit`"
                                            >
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                >
                                                    <Pencil
                                                        class="mr-1.5 h-4 w-4"
                                                    />
                                                    Modifier
                                                </Button>
                                            </Link>
                                            <span
                                                v-else
                                                class="text-muted-foreground"
                                                >-</span
                                            >
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Dépenses tab -->
                    <div
                        v-else-if="activeTab === 'depenses'"
                        class="rounded-xl border bg-card p-4 sm:p-6"
                    >
                        <div
                            class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                        >
                            <div>
                                <h2
                                    class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                                >
                                    Dépenses du propriétaire
                                </h2>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    Dépenses opérationnelles gérées via le
                                    module Dépenses.
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
                                Aucune dépense enregistrée pour ce propriétaire.
                            </p>
                        </div>

                        <div v-else class="divide-y rounded-lg border">
                            <div
                                v-for="d in depenses"
                                :key="d.id"
                                class="flex items-center gap-4 px-4 py-3 hover:bg-muted/30"
                            >
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="text-sm font-semibold tabular-nums"
                                    >
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
                                <Link
                                    v-if="can('depenses.update')"
                                    :href="`/backoffice/depenses/${d.id}/edit`"
                                    class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground"
                                >
                                    <Pencil class="h-3.5 w-3.5" />
                                </Link>
                            </div>
                        </div>
                    </div>

                    <!-- Pièce d'identité tab -->
                    <PieceIdentiteSection
                        v-else
                        :proprietaire-id="proprietaire.id"
                        :pieces="pieces_identite"
                        :permissions="piece_identite_permissions"
                        :type-piece-options="type_piece_options"
                    />
                </div>
            </div>
        </div>
        <ImageLightbox
            :url="lightboxUrl"
            :alt="lightboxAlt"
            @close="closeLightbox"
        />

        <Dialog
            v-model:visible="equipeDialogVisible"
            modal
            header="Équipe de livraison"
            :style="{ width: '30rem' }"
            :breakpoints="{ '640px': 'calc(100vw - 1rem)' }"
        >
            <div class="space-y-4 px-1 py-2">
                <div class="flex justify-between">
                    <span class="text-sm text-muted-foreground">Équipe</span>
                    <span class="text-sm font-medium">{{
                        selectedVehiculeForEquipe?.equipe_detail?.nom ?? '—'
                    }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-sm text-muted-foreground">Véhicule</span>
                    <span class="text-sm font-medium">{{
                        selectedVehiculeForEquipe?.nom_vehicule ?? '—'
                    }}</span>
                </div>
                <div
                    v-if="
                        selectedVehiculeForEquipe?.equipe_detail
                            ?.taux_commission_proprietaire != null
                    "
                    class="flex justify-between"
                >
                    <span class="text-sm text-muted-foreground"
                        >Taux propriétaire</span
                    >
                    <span class="text-sm font-medium"
                        >{{
                            selectedVehiculeForEquipe.equipe_detail
                                .taux_commission_proprietaire
                        }}
                        %</span
                    >
                </div>
                <div
                    v-if="selectedVehiculeForEquipe?.equipe_detail?.chauffeur"
                    class="border-t pt-3"
                >
                    <p
                        class="mb-2 text-xs font-medium tracking-wider text-muted-foreground uppercase"
                    >
                        Chauffeur principal
                    </p>
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium">{{
                            selectedVehiculeForEquipe.equipe_detail.chauffeur
                                .nom
                        }}</span>
                        <span class="text-sm text-muted-foreground">
                            {{
                                formatPhoneDisplay(
                                    selectedVehiculeForEquipe.equipe_detail
                                        .chauffeur.telephone,
                                )
                            }}
                        </span>
                    </div>
                </div>
                <div
                    v-if="
                        selectedVehiculeForEquipe?.equipe_detail?.convoyeurs
                            ?.length
                    "
                    class="border-t pt-3"
                >
                    <p
                        class="mb-2 text-xs font-medium tracking-wider text-muted-foreground uppercase"
                    >
                        Convoyeurs
                    </p>
                    <div
                        v-for="conv in selectedVehiculeForEquipe.equipe_detail
                            .convoyeurs"
                        :key="conv.nom"
                        class="flex items-center justify-between py-1"
                    >
                        <span class="text-sm font-medium">{{ conv.nom }}</span>
                        <span class="text-sm text-muted-foreground">{{
                            formatPhoneDisplay(conv.telephone)
                        }}</span>
                    </div>
                </div>
            </div>
            <template #footer>
                <Button
                    variant="outline"
                    size="sm"
                    @click="equipeDialogVisible = false"
                    >Fermer</Button
                >
            </template>
        </Dialog>
    </AppLayout>
</template>

<style scoped>
@media (max-width: 639px) {
    .proprietaire-detail [data-info-grid] {
        gap: 0;
    }
    .proprietaire-detail [data-info-grid] > div {
        display: grid;
        grid-template-columns: 7rem minmax(0, 1fr);
        align-items: start;
        gap: 0.25rem 0.75rem;
        border: 0;
        border-top: 1px solid var(--border);
        border-radius: 0;
        padding: 0.875rem 0;
        background: transparent;
    }
    .proprietaire-detail [data-info-grid] > div > :first-child {
        grid-column: 1;
    }
    .proprietaire-detail [data-info-grid] > div > :not(:first-child) {
        grid-column: 2;
        margin-top: 0;
    }
    .proprietaire-detail [data-mobile-cards],
    .proprietaire-detail [data-mobile-cards] tbody {
        display: block;
        min-width: 0;
    }
    .proprietaire-detail [data-mobile-cards] thead {
        display: none;
    }
    .proprietaire-detail [data-mobile-cards] tbody tr {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.75rem;
        padding: 1rem;
    }
    .proprietaire-detail [data-mobile-cards] td {
        display: block;
        min-width: 0;
        padding: 0;
        overflow-wrap: anywhere;
    }
    .proprietaire-detail [data-mobile-cards] td[data-label]::before {
        content: attr(data-label);
        display: block;
        margin-bottom: 0.25rem;
        color: var(--muted-foreground);
        font: 400 0.75rem var(--font-sans);
    }
    .proprietaire-detail [data-mobile-cards] td[data-wide] {
        grid-column: 1 / -1;
    }
    /* Photo et nom partagent la première ligne de la carte. */
    .proprietaire-detail [data-mobile-cards] td[data-photo],
    .proprietaire-detail [data-mobile-cards] td[data-nom] {
        grid-column: 1 / -1;
        grid-row: 1;
    }
    .proprietaire-detail [data-mobile-cards] td[data-nom] {
        align-self: center;
        padding-left: 3.25rem;
    }
    .proprietaire-detail [data-mobile-cards] td[data-photo] {
        justify-self: start;
        z-index: 1;
    }
}
</style>
