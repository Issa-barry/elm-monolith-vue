<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { BadgeCheck, Car, Clock, HandCoins, Hourglass, MapPin, User, X } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import { computed, ref } from 'vue';

// -- Types ---------------------------------------------------------------------
interface VersementItem {
    id: number;
    date_versement: string | null;
    beneficiaire: string;
    beneficiaire_label: string;
    mode_paiement: string;
    montant: number;
    note: string | null;
    created_by: string | null;
}

interface CommissionItem {
    id: number;
    commande_id: number;
    commande_reference: string | null;
    site_nom: string | null;
    vehicule_nom: string | null;
    immatriculation: string | null;
    livreur_nom: string | null;
    taux_commission: number;
    taux_commission_proprietaire: number;
    montant_commande: number;
    montant_commission: number;
    montant_part_livreur: number;
    montant_part_proprietaire: number;
    montant_verse: number;
    montant_verse_livreur: number;
    montant_verse_proprietaire: number;
    montant_restant: number;
    montant_restant_livreur: number;
    montant_restant_proprietaire: number;
    statut: string;
    statut_label: string;
    is_versee: boolean;
    is_annulee: boolean;
    created_at: string;
    versements: VersementItem[];
}

interface Totaux {
    total_a_verser: number;
    nb_en_attente: number;
    montant_en_attente: number;
    nb_partielles: number;
    montant_partielles: number;
    nb_versees: number;
    montant_versees: number;
}

interface ModePaiementOption { value: string; label: string }

// -- Props ---------------------------------------------------------------------
const props = defineProps<{
    commissions: CommissionItem[];
    totaux: Totaux;
    modes_paiement: ModePaiementOption[];
    periode: string;
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Commissions', href: '/commissions' },
];

// -- Filtres -------------------------------------------------------------------
const filtres = [
    { value: 'tous',       label: 'Toutes' },
    { value: 'en_attente', label: 'En attente' },
    { value: 'partielle',  label: 'Partielles' },
    { value: 'versee',     label: 'Versees' },
    { value: 'annulee',    label: 'Annulees' },
];

const periodes = [
    { value: 'today', label: "Aujourd'hui" },
    { value: 'week',  label: 'Cette semaine' },
    { value: 'month', label: 'Ce mois' },
    { value: 'all',   label: 'Tout' },
];

const filtreStatut = ref('tous');
const search       = ref('');

function setPeriode(p: string) {
    router.get('/commissions', { periode: p }, { preserveScroll: true, replace: true });
}

const commissionsFiltrees = computed(() => {
    let list = props.commissions;

    if (filtreStatut.value !== 'tous') {
        list = list.filter(c => c.statut === filtreStatut.value);
    }

    const q = search.value.toLowerCase().trim();
    if (q) {
        list = list.filter(c =>
            (c.commande_reference && c.commande_reference.toLowerCase().includes(q)) ||
            (c.vehicule_nom       && c.vehicule_nom.toLowerCase().includes(q)) ||
            (c.immatriculation    && c.immatriculation.toLowerCase().includes(q)) ||
            (c.livreur_nom        && c.livreur_nom.toLowerCase().includes(q)) ||
            (c.site_nom           && c.site_nom.toLowerCase().includes(q))
        );
    }

    return list;
});

// -- Couleurs statut -----------------------------------------------------------
const statutColor: Record<string, string> = {
    en_attente: 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
    partielle:  'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300',
    versee:     'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
    annulee:    'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400',
};

// -- Formatage -----------------------------------------------------------------
function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}

function formatCompact(val: number): string {
    if (val >= 1_000_000) {
        const n = val / 1_000_000;
        return (Number.isInteger(n) ? n : n.toFixed(1)) + 'M GNF';
    }
    if (val >= 1_000) {
        const n = val / 1_000;
        return (Number.isInteger(n) ? n : n.toFixed(1)) + 'K GNF';
    }
    return val + ' GNF';
}

// -- Dialog versement ----------------------------------------------------------
const dialogVisible     = ref(false);
const commissionActive  = ref<CommissionItem | null>(null);
const modePaiementLivreur = ref('especes');
const dateVersementLivreur = ref(new Date().toISOString().slice(0, 10));
const modePaiementProprietaire = ref('especes');
const dateVersementProprietaire = ref(new Date().toISOString().slice(0, 10));

const versementForm = useForm({
    montant_livreur:       0 as number | null,
    montant_proprietaire:  0 as number | null,
    date_versement:        new Date().toISOString().slice(0, 10),
    mode_paiement:         'especes',
    note:                  null as string | null,
});

const montantTotalSaisi = computed(() =>
    (versementForm.montant_livreur ?? 0) + (versementForm.montant_proprietaire ?? 0),
);

const canSubmitLivreur = computed(() =>
    (commissionActive.value?.montant_restant_livreur ?? 0) > 0
    && (versementForm.montant_livreur ?? 0) > 0
    && Boolean(modePaiementLivreur.value)
    && Boolean(dateVersementLivreur.value),
);

const canSubmitProprietaire = computed(() =>
    (commissionActive.value?.montant_restant_proprietaire ?? 0) > 0
    && (versementForm.montant_proprietaire ?? 0) > 0
    && Boolean(modePaiementProprietaire.value)
    && Boolean(dateVersementProprietaire.value),
);

function openDialog(c: CommissionItem) {
    const dateDuJour = new Date().toISOString().slice(0, 10);

    commissionActive.value = c;
    versementForm.montant_livreur = c.montant_restant_livreur;
    versementForm.montant_proprietaire = c.montant_restant_proprietaire;
    versementForm.date_versement = dateDuJour;
    versementForm.mode_paiement = 'especes';
    modePaiementLivreur.value = 'especes';
    dateVersementLivreur.value = dateDuJour;
    modePaiementProprietaire.value = 'especes';
    dateVersementProprietaire.value = dateDuJour;
    versementForm.note = null;
    versementForm.clearErrors();
    dialogVisible.value = true;
}

function submitVersementLivreur() {
    if (!commissionActive.value) return;
    if (!canSubmitLivreur.value) {
        versementForm.setError('montant_livreur', 'Saisissez un montant livreur valide.');
        return;
    }
    if (!modePaiementLivreur.value) {
        versementForm.setError('mode_paiement', 'Choisissez le mode de paiement du livreur.');
        return;
    }
    if (!dateVersementLivreur.value) {
        versementForm.setError('date_versement', 'Choisissez la date de versement du livreur.');
        return;
    }

    versementForm.clearErrors('montant_livreur', 'mode_paiement', 'date_versement');
    versementForm.mode_paiement = modePaiementLivreur.value;
    versementForm.date_versement = dateVersementLivreur.value;
    const previousMontantProprietaire = versementForm.montant_proprietaire;
    versementForm.montant_proprietaire = 0;
    versementForm.post(`/commissions/${commissionActive.value.id}/versements`, {
        onSuccess: () => { dialogVisible.value = false; },
        onFinish: () => { versementForm.montant_proprietaire = previousMontantProprietaire; },
    });
}

function submitVersementProprietaire() {
    if (!commissionActive.value) return;
    if (!canSubmitProprietaire.value) {
        versementForm.setError('montant_proprietaire', 'Saisissez un montant proprietaire valide.');
        return;
    }
    if (!modePaiementProprietaire.value) {
        versementForm.setError('mode_paiement', 'Choisissez le mode de paiement du proprietaire.');
        return;
    }
    if (!dateVersementProprietaire.value) {
        versementForm.setError('date_versement', 'Choisissez la date de versement du proprietaire.');
        return;
    }

    versementForm.clearErrors('montant_proprietaire', 'mode_paiement', 'date_versement');
    versementForm.mode_paiement = modePaiementProprietaire.value;
    versementForm.date_versement = dateVersementProprietaire.value;
    const previousMontantLivreur = versementForm.montant_livreur;
    versementForm.montant_livreur = 0;
    versementForm.post(`/commissions/${commissionActive.value.id}/versements`, {
        onSuccess: () => { dialogVisible.value = false; },
        onFinish: () => { versementForm.montant_livreur = previousMontantLivreur; },
    });
}
</script>

<template>
    <Head title="Commissions" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full space-y-6 p-6">

            <!-- En-tete -->
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Commissions livreurs</h1>
                <p class="mt-1 text-sm text-muted-foreground">Suivi et versement des commissions sur ventes.</p>
            </div>

            <!-- Cartes de synthese -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">

                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <p class="text-sm text-muted-foreground">Restant a verser</p>
                        <HandCoins class="h-4 w-4 text-muted-foreground" />
                    </div>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-amber-600 dark:text-amber-400">
                        {{ formatCompact(totaux.total_a_verser) }}
                    </p>
                </div>

                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <p class="text-sm text-muted-foreground">En attente</p>
                        <Hourglass class="h-4 w-4 text-amber-500" />
                    </div>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-amber-600 dark:text-amber-400">
                        {{ formatCompact(totaux.montant_en_attente) }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">{{ totaux.nb_en_attente }} commission{{ totaux.nb_en_attente > 1 ? 's' : '' }}</p>
                </div>

                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <p class="text-sm text-muted-foreground">Partiellement versees</p>
                        <Clock class="h-4 w-4 text-blue-500" />
                    </div>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-blue-600 dark:text-blue-400">
                        {{ formatCompact(totaux.montant_partielles) }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">{{ totaux.nb_partielles }} commission{{ totaux.nb_partielles > 1 ? 's' : '' }}</p>
                </div>

                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <p class="text-sm text-muted-foreground">Versees</p>
                        <BadgeCheck class="h-4 w-4 text-emerald-500" />
                    </div>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-emerald-600 dark:text-emerald-400">
                        {{ formatCompact(totaux.montant_versees) }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">{{ totaux.nb_versees }} commission{{ totaux.nb_versees > 1 ? 's' : '' }}</p>
                </div>
            </div>

            <!-- Filtres -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2">
                    <!-- Recherche -->
                    <div class="relative">
                        <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground pointer-events-none" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0Z" />
                        </svg>
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Commande, vehicule, livreur..."
                            class="h-9 w-64 rounded-md border border-input bg-background pl-8 pr-3 text-sm shadow-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
                        />
                    </div>

                    <Dropdown
                        v-model="filtreStatut"
                        :options="filtres"
                        option-label="label"
                        option-value="value"
                        class="w-36"
                    />

                    <Dropdown
                        :model-value="periode"
                        :options="periodes"
                        option-label="label"
                        option-value="value"
                        @update:model-value="setPeriode($event)"
                        class="w-40"
                    />
                </div>
            </div>

            <!-- Tableau -->
            <div class="rounded-xl border bg-card shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b bg-muted/40">
                                <th class="px-4 py-3 text-left font-medium text-muted-foreground">Commande</th>
                                <th class="px-4 py-3 text-left font-medium text-muted-foreground">Vehicule</th>
                                <th class="px-4 py-3 text-left font-medium text-muted-foreground">Livreur</th>
                                <th class="px-4 py-3 text-left font-medium text-muted-foreground">Site</th>
                                <th class="px-4 py-3 text-right font-medium text-muted-foreground">Commande</th>
                                <th class="px-4 py-3 text-right font-medium text-muted-foreground">Commission</th>
                                <th class="px-4 py-3 text-right font-medium text-muted-foreground">Verse</th>
                                <th class="px-4 py-3 text-right font-medium text-muted-foreground">Restant</th>
                                <th class="px-4 py-3 text-center font-medium text-muted-foreground">Statut</th>
                                <th class="px-4 py-3 text-left font-medium text-muted-foreground">Date</th>
                                <th v-if="can('ventes.update')" class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="c in commissionsFiltrees"
                                :key="c.id"
                                class="hover:bg-muted/10 transition-colors"
                            >
                                <td class="px-4 py-3">
                                    <Link
                                        v-if="c.commande_id"
                                        :href="`/ventes/${c.commande_id}`"
                                        class="font-mono text-xs font-semibold text-primary hover:underline"
                                    >
                                        {{ c.commande_reference ?? '-' }}
                                    </Link>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="font-medium">{{ c.vehicule_nom ?? '-' }}</div>
                                    <div v-if="c.immatriculation" class="font-mono text-xs text-muted-foreground">{{ c.immatriculation }}</div>
                                </td>
                                <td class="px-4 py-3 font-medium">{{ c.livreur_nom ?? '-' }}</td>
                                <td class="px-4 py-3 text-muted-foreground">{{ c.site_nom ?? '-' }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-muted-foreground">{{ formatGNF(c.montant_commande) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums font-semibold">{{ formatGNF(c.montant_commission) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-emerald-600 dark:text-emerald-400">
                                    {{ c.montant_verse > 0 ? formatGNF(c.montant_verse) : '-' }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums"
                                    :class="c.montant_restant > 0 ? 'text-amber-600 dark:text-amber-400 font-semibold' : 'text-muted-foreground'">
                                    {{ c.montant_restant > 0 ? formatGNF(c.montant_restant) : '-' }}
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span
                                        class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                        :class="statutColor[c.statut] ?? 'bg-muted text-muted-foreground'"
                                    >
                                        {{ c.statut_label }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-xs text-muted-foreground tabular-nums">{{ c.created_at }}</td>
                                <td v-if="can('ventes.update')" class="px-4 py-3 text-right">
                                    <Button
                                        v-if="!c.is_annulee && !c.is_versee"
                                        size="sm"
                                        variant="outline"
                                        class="h-7 text-xs border-emerald-300 text-emerald-700 hover:bg-emerald-50 dark:border-emerald-700 dark:text-emerald-400 dark:hover:bg-emerald-950"
                                        @click="openDialog(c)"
                                    >
                                        <HandCoins class="mr-1.5 h-3.5 w-3.5" />
                                        Verser
                                    </Button>
                                    <span v-else-if="c.is_versee" class="text-xs text-emerald-600 dark:text-emerald-400 font-medium">
                                        Versee
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div v-if="commissionsFiltrees.length === 0" class="py-16 text-center text-sm text-muted-foreground">
                        Aucune commission trouvee.
                    </div>
                </div>
            </div>
        </div>
        <!-- Dialog versement -->
        <Dialog
            v-model:visible="dialogVisible"
            modal
            :showHeader="false"
            :closable="false"
            :style="{ width: '960px', maxWidth: '98vw' }"
            pt:root:class="!p-0 overflow-hidden rounded-2xl"
            pt:content:class="!p-0"
        >
            <div v-if="commissionActive" class="flex flex-col bg-background">

                <div class="border-b bg-muted/20 px-6 py-5">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-muted-foreground">Versement commission</p>
                            <p class="mt-1 font-mono text-lg font-semibold leading-none">{{ commissionActive.commande_reference }}</p>
                            <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
                                <span v-if="commissionActive.vehicule_nom" class="inline-flex items-center gap-1.5">
                                    <Car class="h-3.5 w-3.5" />
                                    {{ commissionActive.vehicule_nom }}
                                </span>
                                <span v-if="commissionActive.livreur_nom" class="inline-flex items-center gap-1.5">
                                    <User class="h-3.5 w-3.5" />
                                    {{ commissionActive.livreur_nom }}
                                </span>
                                <span v-if="commissionActive.site_nom" class="inline-flex items-center gap-1.5">
                                    <MapPin class="h-3.5 w-3.5" />
                                    {{ commissionActive.site_nom }}
                                </span>
                            </div>
                        </div>

                        <button
                            type="button"
                            class="inline-flex h-8 w-8 items-center justify-center rounded-md border bg-background text-muted-foreground transition hover:text-foreground"
                            @click="dialogVisible = false"
                        >
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                </div>

                <div class="grid gap-3 border-b bg-muted/10 p-4 md:grid-cols-3">
                    <div class="rounded-lg border bg-card p-3">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.08em] text-muted-foreground">
                            Livreur - {{ commissionActive.taux_commission }}%
                        </p>
                        <p class="mt-1.5 text-lg font-bold tabular-nums text-foreground">
                            {{ formatGNF(commissionActive.montant_part_livreur) }}
                        </p>
                        <div class="mt-2 flex items-center justify-between text-xs">
                            <span class="text-muted-foreground">Verse {{ formatGNF(commissionActive.montant_verse_livreur) }}</span>
                            <span class="font-medium text-foreground">Reste {{ formatGNF(commissionActive.montant_restant_livreur) }}</span>
                        </div>
                    </div>

                    <div class="rounded-lg border bg-card p-3">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.08em] text-muted-foreground">
                            Proprietaire - {{ commissionActive.taux_commission_proprietaire }}%
                        </p>
                        <p class="mt-1.5 text-lg font-bold tabular-nums text-foreground">
                            {{ formatGNF(commissionActive.montant_part_proprietaire) }}
                        </p>
                        <div class="mt-2 flex items-center justify-between text-xs">
                            <span class="text-muted-foreground">Verse {{ formatGNF(commissionActive.montant_verse_proprietaire) }}</span>
                            <span class="font-medium text-foreground">Reste {{ formatGNF(commissionActive.montant_restant_proprietaire) }}</span>
                        </div>
                    </div>

                    <div class="rounded-lg border bg-card p-3">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.08em] text-muted-foreground">Total commission</p>
                        <p class="mt-1.5 text-lg font-bold tabular-nums">{{ formatGNF(commissionActive.montant_commission) }}</p>
                        <div class="mt-2 flex items-center justify-between text-xs">
                            <span class="text-muted-foreground">Verse {{ formatGNF(commissionActive.montant_verse) }}</span>
                            <span class="font-semibold text-foreground">Reste {{ formatGNF(commissionActive.montant_restant) }}</span>
                        </div>
                    </div>
                </div>

                <div class="space-y-4 p-5">
                    <div class="space-y-3">
                        <div class="rounded-lg border bg-card p-4">
                            <Label class="mb-3 block text-sm font-semibold text-foreground">Versement livreur</Label>
                            <div class="grid gap-3 lg:grid-cols-[minmax(280px,1.7fr)_minmax(180px,1fr)_minmax(170px,1fr)_auto] lg:items-end">
                                <div>
                                    <Label class="mb-1.5 block text-xs font-medium">Montant</Label>
                                    <InputNumber
                                        :model-value="versementForm.montant_livreur"
                                        @update:model-value="versementForm.montant_livreur = $event"
                                        :min="0"
                                        :max="commissionActive.montant_restant_livreur"
                                        :use-grouping="true"
                                        locale="fr-FR"
                                        suffix=" GNF"
                                        class="w-full"
                                        input-class="w-full h-11 text-right text-lg font-semibold tabular-nums text-foreground"
                                        :class="{ 'p-invalid': versementForm.errors.montant_livreur }"
                                    />
                                    <p v-if="versementForm.errors.montant_livreur" class="mt-1 text-xs text-destructive">{{ versementForm.errors.montant_livreur }}</p>
                                </div>

                                <div>
                                    <Label class="mb-1.5 block text-xs font-medium">Mode de paiement</Label>
                                    <Dropdown
                                        v-model="modePaiementLivreur"
                                        :options="modes_paiement"
                                        option-label="label"
                                        option-value="value"
                                        class="w-full h-11"
                                    />
                                </div>

                                <div>
                                    <Label class="mb-1.5 block text-xs font-medium">Date</Label>
                                    <InputText
                                        v-model="dateVersementLivreur"
                                        type="date"
                                        class="w-full h-11"
                                    />
                                </div>

                                <div class="flex flex-col lg:self-end">
                                    <Label class="mb-1.5 block text-xs font-medium opacity-0 select-none">Action</Label>
                                    <Button
                                        class="h-11 min-w-44 gap-2"
                                        :disabled="versementForm.processing || !canSubmitLivreur"
                                        @click="submitVersementLivreur"
                                    >
                                        <HandCoins class="h-4 w-4" />
                                        Verser livreur
                                    </Button>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-lg border bg-card p-4">
                            <Label class="mb-3 block text-sm font-semibold text-foreground">Versement proprietaire</Label>
                            <div class="grid gap-3 lg:grid-cols-[minmax(280px,1.7fr)_minmax(180px,1fr)_minmax(170px,1fr)_auto] lg:items-end">
                                <div>
                                    <Label class="mb-1.5 block text-xs font-medium">Montant</Label>
                                    <InputNumber
                                        :model-value="versementForm.montant_proprietaire"
                                        @update:model-value="versementForm.montant_proprietaire = $event"
                                        :min="0"
                                        :max="commissionActive.montant_restant_proprietaire"
                                        :use-grouping="true"
                                        locale="fr-FR"
                                        suffix=" GNF"
                                        class="w-full"
                                        input-class="w-full h-11 text-right text-lg font-semibold tabular-nums text-foreground"
                                        :class="{ 'p-invalid': versementForm.errors.montant_proprietaire }"
                                    />
                                    <p v-if="versementForm.errors.montant_proprietaire" class="mt-1 text-xs text-destructive">{{ versementForm.errors.montant_proprietaire }}</p>
                                </div>

                                <div>
                                    <Label class="mb-1.5 block text-xs font-medium">Mode de paiement</Label>
                                    <Dropdown
                                        v-model="modePaiementProprietaire"
                                        :options="modes_paiement"
                                        option-label="label"
                                        option-value="value"
                                        class="w-full h-11"
                                    />
                                </div>

                                <div>
                                    <Label class="mb-1.5 block text-xs font-medium">Date</Label>
                                    <InputText
                                        v-model="dateVersementProprietaire"
                                        type="date"
                                        class="w-full h-11"
                                    />
                                </div>

                                <div class="flex flex-col lg:self-end">
                                    <Label class="mb-1.5 block text-xs font-medium opacity-0 select-none">Action</Label>
                                    <Button
                                        class="h-11 min-w-44 gap-2"
                                        :disabled="versementForm.processing || !canSubmitProprietaire"
                                        @click="submitVersementProprietaire"
                                    >
                                        <HandCoins class="h-4 w-4" />
                                        Verser proprietaire
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <Label class="mb-1.5 block text-sm font-medium">Note</Label>
                        <InputText v-model="(versementForm.note as string)" class="w-full" placeholder="Remarque optionnelle..." />
                    </div>

                    <div class="rounded-lg border bg-card p-4">
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <Label class="text-sm font-semibold text-foreground">Historique des versements</Label>
                            <span class="text-xs text-muted-foreground">
                                {{ commissionActive.versements.length }} versement{{ commissionActive.versements.length > 1 ? 's' : '' }}
                            </span>
                        </div>

                        <div v-if="commissionActive.versements.length === 0" class="rounded-md border border-dashed px-3 py-5 text-center text-sm text-muted-foreground">
                            Aucun versement enregistre pour cette commission.
                        </div>

                        <div v-else class="overflow-x-auto rounded-md border">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b bg-muted/40">
                                        <th class="px-3 py-2 text-left font-medium text-muted-foreground">Date</th>
                                        <th class="px-3 py-2 text-left font-medium text-muted-foreground">Beneficiaire</th>
                                        <th class="px-3 py-2 text-left font-medium text-muted-foreground">Mode</th>
                                        <th class="px-3 py-2 text-right font-medium text-muted-foreground">Montant</th>
                                        <th class="px-3 py-2 text-left font-medium text-muted-foreground">Note</th>
                                        <th class="px-3 py-2 text-left font-medium text-muted-foreground">Saisi par</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y">
                                    <tr v-for="v in commissionActive.versements" :key="v.id">
                                        <td class="px-3 py-2 text-xs tabular-nums text-muted-foreground">{{ v.date_versement ?? '-' }}</td>
                                        <td class="px-3 py-2">
                                            <span
                                                class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                                :class="v.beneficiaire === 'proprietaire'
                                                    ? 'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300'
                                                    : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'"
                                            >
                                                {{ v.beneficiaire_label }}
                                            </span>
                                        </td>
                                        <td class="px-3 py-2 text-muted-foreground">{{ v.mode_paiement }}</td>
                                        <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ formatGNF(v.montant) }}</td>
                                        <td class="px-3 py-2 text-muted-foreground">
                                            <span class="block max-w-[260px] truncate" :title="v.note ?? '-'">{{ v.note ?? '-' }}</span>
                                        </td>
                                        <td class="px-3 py-2 text-muted-foreground">{{ v.created_by ?? '-' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-3 border-t pt-4">
                        <p class="text-sm text-muted-foreground">
                            Total saisi:
                            <span class="ml-1 font-semibold text-foreground">{{ formatGNF(montantTotalSaisi) }}</span>
                        </p>
                        <div class="flex items-center gap-2">
                            <Button variant="outline" class="px-5" @click="dialogVisible = false">Fermer</Button>
                        </div>
                    </div>
                </div>
            </div>
        </Dialog>

    </AppLayout>
</template>
