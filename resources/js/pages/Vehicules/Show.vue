<script setup lang="ts">
import DetailHeader from '@/components/DetailHeader.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPhoneDisplay } from '@/lib/utils';
import EquipeStepperModal from '@/pages/Vehicules/partials/EquipeStepperModal.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
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
    Users,
} from 'lucide-vue-next';
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
    nom: string;
    prenom: string;
    telephone: string;
    role: string;
    montant_par_pack: number;
    taux_commission: number;
    ordre: number;
    numero: number;
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
}

interface ProprietaireOption {
    value: string;
    label: string;
    telephone?: string;
}

interface VehiculeData {
    id: string;
    nom_vehicule: string;
    immatriculation: string;
    type_label: string;
    type_vehicule_id: string | null;
    categorie: string | null;
    capacite_packs: number | null;
    site_id: string | null;
    site_nom: string | null;
    proprietaire_id: string | null;
    proprietaire_nom: string | null;
    proprietaire_telephone: string | null;
    equipe_id: string | null;
    equipe_membres: EquipeMembre[];
    pris_en_charge_par_usine: boolean;
    photo_url: string | null;
    is_active: boolean;
}

const props = defineProps<{
    vehicule: VehiculeData;
    depenses: DepenseRow[];
    equipe: EquipeData | null;
    proprietaires: ProprietaireOption[];
}>();

const { can } = usePermissions();
const page = usePage();
const showStepperModal = ref(false);
const flashSuccess = computed(
    () => (page.props as { flash?: { success?: string } }).flash?.success,
);

const activeTab = ref<'informations' | 'equipe' | 'depenses'>('informations');

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Véhicules', href: '/vehicules' },
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
                    href="/vehicules"
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
                    :href="`/vehicules/${vehicule.id}/edit`"
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
                            v-if="vehicule.capacite_packs"
                            class="text-xs text-muted-foreground"
                        >
                            {{ vehicule.capacite_packs }} packs
                        </span>
                    </div>
                </template>
                <template #actions>
                    <Link
                        v-if="vehicule.proprietaire_id"
                        :href="`/proprietaires/${vehicule.proprietaire_id}`"
                        target="_blank"
                        data-testid="voir-fiche-proprietaire-btn"
                    >
                        <Button variant="outline" size="sm">
                            <ExternalLink class="mr-1.5 h-4 w-4" />
                            Fiche propriétaire
                        </Button>
                    </Link>
                    <Link href="/vehicules">
                        <Button variant="outline" size="sm">
                            <ArrowLeft class="mr-1.5 h-4 w-4" />
                            Liste de véhicules
                        </Button>
                    </Link>
                    <Link
                        v-if="can('vehicules.update')"
                        :href="`/vehicules/${vehicule.id}/edit`"
                    >
                        <Button size="sm">
                            <Pencil class="mr-1.5 h-4 w-4" />
                            Modifier
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
                <div
                    v-if="activeTab === 'informations'"
                    class="rounded-xl border bg-card p-5 sm:p-6"
                >
                    <div class="flex items-center justify-between gap-2">
                        <h2
                            class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Informations du véhicule
                        </h2>
                        <Link
                            v-if="can('vehicules.update')"
                            :href="`/vehicules/${vehicule.id}/edit`"
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
                            <p class="text-xs text-muted-foreground">Type</p>
                            <p class="mt-1 text-sm font-medium">
                                {{ vehicule.type_label }}
                            </p>
                        </div>
                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">
                                Capacité
                            </p>
                            <p class="mt-1 text-sm font-medium">
                                {{
                                    vehicule.capacite_packs !== null
                                        ? `${vehicule.capacite_packs} packs`
                                        : '—'
                                }}
                            </p>
                        </div>
                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">
                                Propriétaire
                            </p>
                            <template v-if="vehicule.categorie === 'interne'">
                                <p class="mt-1 text-sm font-medium">
                                    {{ vehicule.site_nom ?? '—' }}
                                </p>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    Site (véhicule interne)
                                </p>
                            </template>
                            <template v-else-if="vehicule.proprietaire_id">
                                <p
                                    class="mt-1 text-sm font-medium"
                                    data-testid="proprietaire-nom"
                                >
                                    {{ vehicule.proprietaire_nom }}
                                </p>
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
                                <p class="mt-1 text-sm text-muted-foreground">
                                    Aucun propriétaire rattaché
                                </p>
                            </template>
                        </div>
                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">
                                Commission prise en charge par l'usine
                            </p>
                            <p class="mt-1">
                                <span
                                    v-if="vehicule.pris_en_charge_par_usine"
                                    class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700"
                                    >Oui</span
                                >
                                <span
                                    v-else
                                    class="inline-flex items-center rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground"
                                    >Non</span
                                >
                            </p>
                        </div>
                    </div>
                </div>

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
                                    v-if="
                                        vehicule.categorie === 'externe' &&
                                        equipe.montant_par_pack_proprietaire
                                    "
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
                            <Link
                                v-if="can('depenses.update')"
                                :href="`/depenses/${d.id}/edit`"
                                class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground"
                            >
                                <Pencil class="h-3.5 w-3.5" />
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>

    <EquipeStepperModal
        v-model:visible="showStepperModal"
        :vehicule="{
            id: vehicule.id,
            nom_vehicule: vehicule.nom_vehicule,
            immatriculation: vehicule.immatriculation,
            categorie: vehicule.categorie,
            capacite_packs: vehicule.capacite_packs,
            proprietaire_id: vehicule.proprietaire_id,
            proprietaire_nom: vehicule.proprietaire_nom,
        }"
        :equipe="equipe"
        :proprietaires="proprietaires"
    />
</template>
