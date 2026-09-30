<script setup lang="ts">
/**
 * Fiche livreur du backoffice — même structure que la fiche client (en-tête + navigation
 * latérale + panneaux). Les onglets Commissions et Factures n'existent que si le backend les a
 * remplis, c'est-à-dire si l'utilisateur a accès aux écrans détaillés correspondants
 * (cf. App\Support\Livreurs\FicheLivreurStaffData).
 */
import DetailHeader from '@/components/DetailHeader.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { useFlashToast } from '@/composables/useFlashToast';
import { usePermissions } from '@/composables/usePermissions';
import { useUrlTab } from '@/composables/useUrlTab';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatGNF, formatPhoneDisplay } from '@/lib/utils';
import TransfertVehiculeDialog from '@/pages/Vehicules/partials/TransfertVehiculeDialog.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowLeftRight,
    ArrowRight,
    Car,
    CircleHelp,
    FileText,
    Pencil,
    ReceiptText,
    Truck,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import EditLivreurDialog from './EditLivreurDialog.vue';
import StatutLivreurDialog from './StatutLivreurDialog.vue';
import type { FicheLivreur, LivreurData } from './types';

const props = defineProps<{
    livreur: LivreurData;
    fiche: FicheLivreur;
}>();

const { can } = usePermissions();

const ONGLETS = [
    'informations',
    'vehicule',
    'commissions',
    'factures',
] as const;
const { onglet: activeTab, choisir: choisirOnglet } = useUrlTab(
    ONGLETS,
    'informations',
);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Livreurs', href: '/backoffice/livreurs' },
    { title: props.livreur.nom_complet ?? 'Livreur', href: '#' },
];

const ROLE_LABELS: Record<string, string> = {
    chauffeur: 'Chauffeur',
    convoyeur: 'Convoyeur',
};

function roleLabel(role: string): string {
    return ROLE_LABELS[role] ?? role;
}

const equipeActuelle = computed(
    () =>
        props.fiche.equipes.find((e) => e.is_active) ??
        props.fiche.equipes[0] ??
        null,
);

const telephone = computed(
    () =>
        formatPhoneDisplay(props.livreur.telephone, null) ??
        props.livreur.telephone ??
        '—',
);

useFlashToast();

const showEditDialog = ref(false);
const showStatutDialog = ref(false);
const telephoneObligatoire = computed(() =>
    props.fiche.equipes.some((e) => e.role === 'chauffeur'),
);

const showTransfertDialog = ref(false);
const peutChangerVehicule = computed(
    () => can('equipes-livraison.update') && props.livreur.equipes.length > 0,
);

function ongletClass(onglet: (typeof ONGLETS)[number]): string {
    return activeTab.value === onglet
        ? 'bg-primary text-primary-foreground'
        : 'text-muted-foreground hover:bg-muted';
}
</script>

<template>
    <Head :title="`${livreur.nom_complet ?? 'Livreur'} — Détail livreur`" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <div class="w-full space-y-6 p-4 sm:p-6">
            <DetailHeader
                eyebrow="Livreur"
                :title="livreur.nom_complet ?? '—'"
                :icon="Truck"
                :status-label="livreur.is_active ? 'Actif' : 'Inactif'"
                :status-dot-class="
                    livreur.is_active
                        ? 'bg-emerald-500'
                        : 'bg-zinc-400 dark:bg-zinc-500'
                "
                data-testid="livreur-detail-header"
            >
                <template #subtitle>
                    <div class="mt-1 flex flex-wrap items-center gap-2">
                        <span
                            v-if="equipeActuelle"
                            class="inline-flex items-center rounded-full bg-muted px-2.5 py-0.5 text-xs font-medium"
                        >
                            {{ roleLabel(equipeActuelle.role) }}
                        </span>
                        <span class="text-sm text-muted-foreground">
                            {{ telephone }}
                        </span>
                    </div>
                </template>

                <template #actions>
                    <Button
                        v-if="peutChangerVehicule"
                        variant="outline"
                        size="sm"
                        data-testid="livreur-changer-vehicule"
                        @click="showTransfertDialog = true"
                    >
                        <ArrowLeftRight class="mr-1.5 h-4 w-4" />
                        Changer de véhicule
                    </Button>
                    <Link href="/backoffice/livreurs">
                        <Button variant="outline" size="sm">
                            <ArrowLeft class="mr-1.5 h-4 w-4" />
                            Liste des livreurs
                        </Button>
                    </Link>
                </template>
            </DetailHeader>

            <div class="grid gap-6 lg:grid-cols-[220px_minmax(0,1fr)]">
                <aside
                    class="grid h-fit grid-cols-2 gap-2 rounded-xl border bg-card p-2 lg:block lg:space-y-2"
                    aria-label="Sections de la fiche livreur"
                    data-testid="livreur-detail-navigation"
                >
                    <button
                        type="button"
                        class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm font-medium transition-colors"
                        :class="ongletClass('informations')"
                        data-testid="livreur-informations-tab"
                        @click="choisirOnglet('informations')"
                    >
                        <span class="inline-flex items-center gap-2">
                            <CircleHelp class="h-4 w-4" />
                            Informations
                        </span>
                    </button>
                    <button
                        type="button"
                        class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm font-medium transition-colors"
                        :class="ongletClass('vehicule')"
                        data-testid="livreur-vehicule-tab"
                        @click="choisirOnglet('vehicule')"
                    >
                        <span class="inline-flex items-center gap-2">
                            <Car class="h-4 w-4" />
                            Véhicule &amp; équipe
                        </span>
                    </button>
                    <button
                        v-if="fiche.commissions"
                        type="button"
                        class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm font-medium transition-colors"
                        :class="ongletClass('commissions')"
                        data-testid="livreur-commissions-tab"
                        @click="choisirOnglet('commissions')"
                    >
                        <span class="inline-flex items-center gap-2">
                            <ReceiptText class="h-4 w-4" />
                            Commissions
                        </span>
                    </button>
                    <button
                        v-if="fiche.factures"
                        type="button"
                        class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm font-medium transition-colors"
                        :class="ongletClass('factures')"
                        data-testid="livreur-factures-tab"
                        @click="choisirOnglet('factures')"
                    >
                        <span class="inline-flex items-center gap-2">
                            <FileText class="h-4 w-4" />
                            Factures
                        </span>
                        <span
                            class="inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1 text-[11px]"
                            :class="
                                activeTab === 'factures'
                                    ? 'bg-white/20 text-primary-foreground'
                                    : 'bg-muted text-muted-foreground'
                            "
                        >
                            {{ fiche.factures.totaux.nb }}
                        </span>
                    </button>
                </aside>

                <!-- ── Informations ─────────────────────────────────────── -->
                <div
                    v-if="
                        activeTab === 'informations' ||
                        (activeTab === 'commissions' && !fiche.commissions) ||
                        (activeTab === 'factures' && !fiche.factures)
                    "
                    class="rounded-xl border bg-card p-5 sm:p-6"
                    data-testid="livreur-informations-panel"
                >
                    <div class="flex items-center justify-between gap-3">
                        <h2
                            class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Informations du livreur
                        </h2>
                        <Button
                            v-if="can('livreurs.update')"
                            size="sm"
                            variant="outline"
                            data-testid="livreur-edit-button"
                            @click="showEditDialog = true"
                        >
                            <Pencil class="mr-1.5 h-4 w-4" />
                            Modifier
                        </Button>
                    </div>

                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">
                                Nom complet
                            </p>
                            <p
                                class="mt-1 text-sm font-medium"
                                data-testid="livreur-name"
                            >
                                {{ livreur.nom_complet ?? '—' }}
                            </p>
                        </div>
                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">
                                Téléphone
                            </p>
                            <p class="mt-1 text-sm font-medium">
                                {{ telephone }}
                            </p>
                        </div>
                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">Statut</p>
                            <div class="mt-1 flex items-center gap-2">
                                <StatusDot
                                    class="text-sm font-medium"
                                    :status="
                                        livreur.is_active ? 'actif' : 'inactif'
                                    "
                                    :label="
                                        livreur.is_active ? 'Actif' : 'Inactif'
                                    "
                                />
                                <template v-if="can('livreurs.update')">
                                    <span class="text-muted-foreground">·</span>
                                    <button
                                        type="button"
                                        class="text-sm font-medium text-primary hover:underline"
                                        data-testid="livreur-statut-action"
                                        @click="showStatutDialog = true"
                                    >
                                        {{
                                            livreur.is_active
                                                ? 'Désactiver'
                                                : 'Activer'
                                        }}
                                    </button>
                                </template>
                            </div>
                        </div>
                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">
                                Compte application
                            </p>
                            <p class="mt-1 text-sm font-medium">
                                {{
                                    livreur.has_account
                                        ? 'Oui — peut se connecter'
                                        : 'Non'
                                }}
                            </p>
                        </div>
                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">Agence</p>
                            <p class="mt-1 text-sm font-medium">
                                {{ equipeActuelle?.vehicule?.site_nom ?? '—' }}
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Agence du véhicule actuel
                            </p>
                        </div>
                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">
                                Enregistré le
                            </p>
                            <p class="mt-1 text-sm font-medium">
                                {{ livreur.enregistre_le ?? '—' }}
                            </p>
                        </div>
                    </div>

                    <div class="mt-6 border-t pt-5">
                        <h3
                            class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Véhicule actuel
                        </h3>
                        <div
                            v-if="equipeActuelle?.vehicule"
                            class="mt-4 grid gap-4 sm:grid-cols-3"
                        >
                            <div class="rounded-lg border bg-background p-4">
                                <p class="text-xs text-muted-foreground">
                                    Véhicule
                                </p>
                                <p class="mt-1 text-sm font-medium">
                                    {{ equipeActuelle.vehicule.nom }}
                                </p>
                                <p
                                    v-if="
                                        equipeActuelle.vehicule.immatriculation
                                    "
                                    class="mt-0.5 font-mono text-xs text-muted-foreground"
                                >
                                    {{
                                        equipeActuelle.vehicule.immatriculation
                                    }}
                                </p>
                            </div>
                            <div class="rounded-lg border bg-background p-4">
                                <p class="text-xs text-muted-foreground">
                                    Rôle
                                </p>
                                <p class="mt-1 text-sm font-medium">
                                    {{ roleLabel(equipeActuelle.role) }}
                                </p>
                            </div>
                            <div class="rounded-lg border bg-background p-4">
                                <p class="text-xs text-muted-foreground">
                                    Dans l'équipe depuis
                                </p>
                                <p class="mt-1 text-sm font-medium">
                                    {{ equipeActuelle.rejoint_le ?? '—' }}
                                </p>
                            </div>
                        </div>
                        <p
                            v-else
                            class="mt-4 rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground"
                        >
                            Ce livreur n'est rattaché à aucun véhicule.
                        </p>
                    </div>
                </div>

                <!-- ── Véhicule & équipe ────────────────────────────────── -->
                <div
                    v-else-if="activeTab === 'vehicule'"
                    class="space-y-6"
                    data-testid="livreur-vehicule-panel"
                >
                    <div
                        v-if="fiche.equipes.length === 0"
                        class="rounded-xl border bg-card p-5 sm:p-6"
                    >
                        <div
                            class="flex min-h-40 flex-col items-center justify-center rounded-lg border border-dashed p-6 text-center"
                        >
                            <Car class="h-8 w-8 text-muted-foreground/60" />
                            <p class="mt-3 text-sm text-muted-foreground">
                                Ce livreur n'est rattaché à aucune équipe de
                                livraison.
                            </p>
                        </div>
                    </div>

                    <div
                        v-for="equipe in fiche.equipes"
                        :key="equipe.id"
                        class="rounded-xl border bg-card p-5 sm:p-6"
                    >
                        <div
                            class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                        >
                            <div>
                                <h2
                                    class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                                >
                                    Véhicule
                                </h2>
                                <div class="mt-1 flex items-center gap-2">
                                    <p class="text-lg font-semibold">
                                        {{ equipe.vehicule?.nom ?? '—' }}
                                    </p>
                                    <StatusDot
                                        v-if="equipe.vehicule"
                                        class="text-sm text-muted-foreground"
                                        :status="
                                            equipe.vehicule.is_active
                                                ? 'actif'
                                                : 'inactif'
                                        "
                                        :label="
                                            equipe.vehicule.is_active
                                                ? 'Actif'
                                                : 'Inactif'
                                        "
                                    />
                                </div>
                            </div>
                            <Link
                                v-if="equipe.vehicule?.url"
                                :href="equipe.vehicule.url"
                            >
                                <Button size="sm" variant="outline">
                                    Fiche véhicule
                                    <ArrowRight class="ml-1.5 h-4 w-4" />
                                </Button>
                            </Link>
                        </div>

                        <div
                            v-if="equipe.vehicule"
                            class="mt-5 grid gap-4 sm:grid-cols-2"
                        >
                            <div class="rounded-lg border bg-background p-4">
                                <p class="text-xs text-muted-foreground">
                                    Immatriculation
                                </p>
                                <p class="mt-1 font-mono text-sm font-medium">
                                    {{ equipe.vehicule.immatriculation ?? '—' }}
                                </p>
                            </div>
                            <div class="rounded-lg border bg-background p-4">
                                <p class="text-xs text-muted-foreground">
                                    Type
                                </p>
                                <p class="mt-1 text-sm font-medium">
                                    {{ equipe.vehicule.type_label ?? '—' }}
                                </p>
                            </div>
                            <div class="rounded-lg border bg-background p-4">
                                <p class="text-xs text-muted-foreground">
                                    Agence
                                </p>
                                <p class="mt-1 text-sm font-medium">
                                    {{ equipe.vehicule.site_nom ?? '—' }}
                                </p>
                            </div>
                            <div class="rounded-lg border bg-background p-4">
                                <p class="text-xs text-muted-foreground">
                                    Capacités
                                </p>
                                <p
                                    v-if="
                                        equipe.vehicule.capacites.length === 0
                                    "
                                    class="mt-1 text-sm font-medium"
                                >
                                    —
                                </p>
                                <p
                                    v-for="c in equipe.vehicule.capacites"
                                    :key="c.categorie_id"
                                    class="mt-1 text-sm font-medium"
                                >
                                    {{ c.categorie_nom }} :
                                    <span class="tabular-nums">{{
                                        c.capacite_max
                                    }}</span>
                                    packs
                                </p>
                            </div>
                        </div>

                        <div class="mt-6 border-t pt-5">
                            <h3
                                class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                            >
                                Équipe de livraison
                            </h3>
                            <div class="mt-4 divide-y rounded-lg border">
                                <div
                                    class="flex items-center justify-between gap-3 bg-muted/30 px-4 py-3 text-sm"
                                >
                                    <span class="font-medium">
                                        {{ livreur.nom_complet ?? '—' }}
                                        <span
                                            class="ml-1 text-xs text-muted-foreground"
                                            >(ce livreur)</span
                                        >
                                    </span>
                                    <span class="text-muted-foreground">
                                        {{ roleLabel(equipe.role) }}
                                        <template v-if="equipe.rejoint_le">
                                            · depuis le
                                            {{ equipe.rejoint_le }}
                                        </template>
                                    </span>
                                </div>
                                <div
                                    v-for="membre in equipe.coequipiers"
                                    :key="membre.id"
                                    class="flex items-center justify-between gap-3 px-4 py-3 text-sm"
                                >
                                    <Link
                                        v-if="membre.url"
                                        :href="membre.url"
                                        class="font-medium hover:underline"
                                    >
                                        {{ membre.nom }}
                                    </Link>
                                    <span v-else class="font-medium">{{
                                        membre.nom
                                    }}</span>
                                    <span class="text-muted-foreground">
                                        {{ roleLabel(membre.role) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Commissions ──────────────────────────────────────── -->
                <div
                    v-else-if="activeTab === 'commissions' && fiche.commissions"
                    class="rounded-xl border bg-card p-5 sm:p-6"
                    data-testid="livreur-commissions-panel"
                >
                    <div
                        class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                    >
                        <div>
                            <h2
                                class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                            >
                                Synthèse des commissions
                            </h2>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Tous processus confondus (vente, distribution,
                                transfert logistique).
                            </p>
                        </div>
                        <Link :href="fiche.commissions.detail_url">
                            <Button size="sm" variant="outline">
                                Voir le détail
                                <ArrowRight class="ml-1.5 h-4 w-4" />
                            </Button>
                        </Link>
                    </div>

                    <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">
                                Total généré
                            </p>
                            <p
                                class="mt-1 text-lg font-semibold tabular-nums"
                                data-testid="commissions-total"
                            >
                                {{
                                    formatGNF(
                                        fiche.commissions.kpis.total_genere,
                                    )
                                }}
                            </p>
                        </div>
                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">
                                À valider
                            </p>
                            <p class="mt-1 text-lg font-semibold tabular-nums">
                                {{
                                    formatGNF(
                                        fiche.commissions.kpis
                                            .en_attente_periode,
                                    )
                                }}
                            </p>
                        </div>
                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">
                                Reste à payer
                            </p>
                            <p
                                class="mt-1 text-lg font-semibold tabular-nums"
                                :class="
                                    fiche.commissions.kpis.payable > 0
                                        ? 'text-amber-600 dark:text-amber-400'
                                        : ''
                                "
                            >
                                {{ formatGNF(fiche.commissions.kpis.payable) }}
                            </p>
                        </div>
                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">
                                Déjà payé
                            </p>
                            <p
                                class="mt-1 text-lg font-semibold text-emerald-600 tabular-nums dark:text-emerald-400"
                            >
                                {{
                                    formatGNF(fiche.commissions.kpis.deja_paye)
                                }}
                            </p>
                        </div>
                    </div>

                    <h3
                        class="mt-6 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Dernières commissions
                    </h3>
                    <div
                        v-if="fiche.commissions.recentes.length === 0"
                        class="mt-4 rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
                    >
                        Aucune commission pour ce livreur.
                    </div>
                    <template v-else>
                        <div
                            class="mt-4 hidden overflow-x-auto rounded-lg border sm:block"
                        >
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b bg-muted/40">
                                        <th
                                            class="px-4 py-2.5 text-left font-medium text-muted-foreground"
                                        >
                                            Référence
                                        </th>
                                        <th
                                            class="px-4 py-2.5 text-left font-medium text-muted-foreground"
                                        >
                                            Date
                                        </th>
                                        <th
                                            class="px-4 py-2.5 text-left font-medium text-muted-foreground"
                                        >
                                            Processus
                                        </th>
                                        <th
                                            class="px-4 py-2.5 text-right font-medium text-muted-foreground"
                                        >
                                            Montant
                                        </th>
                                        <th
                                            class="px-4 py-2.5 text-right font-medium text-muted-foreground"
                                        >
                                            Reste
                                        </th>
                                        <th
                                            class="px-4 py-2.5 text-left font-medium text-muted-foreground"
                                        >
                                            Statut
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y">
                                    <tr
                                        v-for="(c, index) in fiche.commissions
                                            .recentes"
                                        :key="c.id ?? index"
                                    >
                                        <td class="px-4 py-3 font-medium">
                                            {{ c.reference ?? '—' }}
                                        </td>
                                        <td class="px-4 py-3">
                                            {{ c.date ?? '—' }}
                                        </td>
                                        <td class="px-4 py-3">
                                            {{ c.processus_label }}
                                        </td>
                                        <td
                                            class="px-4 py-3 text-right tabular-nums"
                                        >
                                            {{ formatGNF(c.montant) }}
                                        </td>
                                        <td
                                            class="px-4 py-3 text-right tabular-nums"
                                        >
                                            {{ formatGNF(c.reste) }}
                                        </td>
                                        <td class="px-4 py-3">
                                            <StatusDot
                                                :status="c.statut ?? ''"
                                                :label="c.statut_label ?? '—'"
                                            />
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-4 space-y-2 sm:hidden">
                            <div
                                v-for="(c, index) in fiche.commissions.recentes"
                                :key="c.id ?? index"
                                class="rounded-lg border p-3"
                            >
                                <div
                                    class="flex items-center justify-between gap-2"
                                >
                                    <p class="text-sm font-medium">
                                        {{ c.reference ?? '—' }}
                                    </p>
                                    <StatusDot
                                        class="text-xs"
                                        :status="c.statut ?? ''"
                                        :label="c.statut_label ?? '—'"
                                    />
                                </div>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{ c.date ?? '—' }} ·
                                    {{ c.processus_label }}
                                </p>
                                <p class="mt-1 text-sm tabular-nums">
                                    {{ formatGNF(c.montant) }}
                                    <span class="text-muted-foreground">
                                        · reste {{ formatGNF(c.reste) }}
                                    </span>
                                </p>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- ── Factures ─────────────────────────────────────────── -->
                <div
                    v-else-if="activeTab === 'factures' && fiche.factures"
                    class="rounded-xl border bg-card p-5 sm:p-6"
                    data-testid="livreur-factures-panel"
                >
                    <div
                        class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                    >
                        <div>
                            <h2
                                class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                            >
                                Factures de vente
                            </h2>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Factures des ventes du véhicule de ce livreur.
                            </p>
                        </div>
                        <Link :href="fiche.factures.liste_url">
                            <Button size="sm" variant="outline">
                                Voir toutes les factures
                                <ArrowRight class="ml-1.5 h-4 w-4" />
                            </Button>
                        </Link>
                    </div>

                    <div class="mt-5 grid gap-4 sm:grid-cols-3">
                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">
                                Factures
                            </p>
                            <p
                                class="mt-1 text-lg font-semibold tabular-nums"
                                data-testid="factures-count"
                            >
                                {{ fiche.factures.totaux.nb }}
                            </p>
                        </div>
                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">
                                Montant facturé
                            </p>
                            <p class="mt-1 text-lg font-semibold tabular-nums">
                                {{ formatGNF(fiche.factures.totaux.montant) }}
                            </p>
                        </div>
                        <div class="rounded-lg border bg-background p-4">
                            <p class="text-xs text-muted-foreground">
                                Reste à encaisser
                            </p>
                            <p
                                class="mt-1 text-lg font-semibold tabular-nums"
                                :class="
                                    fiche.factures.totaux.a_encaisser > 0
                                        ? 'text-amber-600 dark:text-amber-400'
                                        : ''
                                "
                            >
                                {{
                                    formatGNF(fiche.factures.totaux.a_encaisser)
                                }}
                            </p>
                        </div>
                    </div>

                    <h3
                        class="mt-6 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Dernières factures
                    </h3>
                    <div
                        v-if="fiche.factures.recentes.length === 0"
                        class="mt-4 rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
                    >
                        Aucune facture pour ce livreur.
                    </div>
                    <template v-else>
                        <div
                            class="mt-4 hidden overflow-x-auto rounded-lg border sm:block"
                        >
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b bg-muted/40">
                                        <th
                                            class="px-4 py-2.5 text-left font-medium text-muted-foreground"
                                        >
                                            N° facture
                                        </th>
                                        <th
                                            class="px-4 py-2.5 text-left font-medium text-muted-foreground"
                                        >
                                            Client
                                        </th>
                                        <th
                                            class="px-4 py-2.5 text-left font-medium text-muted-foreground"
                                        >
                                            Date
                                        </th>
                                        <th
                                            class="px-4 py-2.5 text-right font-medium text-muted-foreground"
                                        >
                                            Montant
                                        </th>
                                        <th
                                            class="px-4 py-2.5 text-right font-medium text-muted-foreground"
                                        >
                                            Reste
                                        </th>
                                        <th
                                            class="px-4 py-2.5 text-left font-medium text-muted-foreground"
                                        >
                                            Statut
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y">
                                    <tr
                                        v-for="f in fiche.factures.recentes"
                                        :key="f.id"
                                    >
                                        <td class="px-4 py-3 font-medium">
                                            <Link
                                                v-if="f.url"
                                                :href="f.url"
                                                class="hover:underline"
                                            >
                                                {{ f.reference }}
                                            </Link>
                                            <span v-else>{{
                                                f.reference
                                            }}</span>
                                        </td>
                                        <td class="px-4 py-3">
                                            {{ f.client_nom ?? '—' }}
                                        </td>
                                        <td class="px-4 py-3">
                                            {{ f.date ?? '—' }}
                                        </td>
                                        <td
                                            class="px-4 py-3 text-right tabular-nums"
                                        >
                                            {{ formatGNF(f.montant_net) }}
                                        </td>
                                        <td
                                            class="px-4 py-3 text-right tabular-nums"
                                        >
                                            {{ formatGNF(f.montant_restant) }}
                                        </td>
                                        <td class="px-4 py-3">
                                            <StatusDot
                                                :status="f.statut ?? ''"
                                                :label="f.statut_label ?? '—'"
                                            />
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-4 space-y-2 sm:hidden">
                            <component
                                :is="f.url ? Link : 'div'"
                                v-for="f in fiche.factures.recentes"
                                :key="f.id"
                                :href="f.url ?? undefined"
                                class="block rounded-lg border p-3"
                            >
                                <div
                                    class="flex items-center justify-between gap-2"
                                >
                                    <p class="text-sm font-medium">
                                        {{ f.reference }}
                                    </p>
                                    <StatusDot
                                        class="text-xs"
                                        :status="f.statut ?? ''"
                                        :label="f.statut_label ?? '—'"
                                    />
                                </div>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{ f.date ?? '—' }} ·
                                    {{ f.client_nom ?? '—' }}
                                </p>
                                <p class="mt-1 text-sm tabular-nums">
                                    {{ formatGNF(f.montant_net) }}
                                    <span class="text-muted-foreground">
                                        · reste
                                        {{ formatGNF(f.montant_restant) }}
                                    </span>
                                </p>
                            </component>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <EditLivreurDialog
            v-if="can('livreurs.update')"
            v-model:visible="showEditDialog"
            :livreur-id="livreur.id"
            :nom-complet="livreur.nom_complet"
            :telephone="livreur.telephone"
            :has-account="livreur.has_account"
            :telephone-obligatoire="telephoneObligatoire"
        />
        <StatutLivreurDialog
            v-if="can('livreurs.update')"
            v-model:visible="showStatutDialog"
            :livreur-id="livreur.id"
            :is-active="livreur.is_active"
            :has-account="livreur.has_account"
            :a-une-equipe="fiche.equipes.length > 0"
        />
        <TransfertVehiculeDialog
            v-if="peutChangerVehicule"
            v-model:visible="showTransfertDialog"
            :livreur-id="livreur.id"
        />
    </AppLayout>
</template>
