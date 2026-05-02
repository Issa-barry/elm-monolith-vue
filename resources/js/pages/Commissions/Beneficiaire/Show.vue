<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPhoneDisplay } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CreditCard,
    Filter,
    History,
    MoreVertical,
    Pencil,
    Plus,
    Search,
    Truck,
    User,
    Wallet,
} from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import { computed, reactive, ref, watch } from 'vue';

// ── Types ─────────────────────────────────────────────────────────────────────

interface ResumeGlobal {
    id: string;
    type: 'livreur' | 'proprietaire';
    nom: string;
    telephone: string | null;
    nb_commandes: number;
    total_brut_cumule: number;
    total_frais: number;
    total_net_cumule: number;
    total_verse: number;
    solde_global: number;
    statut_global: 'a_verser' | 'partielle' | 'solde';
}

interface CommandeRow {
    commission_id: number;
    commande_reference: string | null;
    commande_id: number | null;
    date_commande: string | null;
    site: string | null;
    vehicule: string | null;
    immatriculation: string | null;
    taux: number;
    montant_brut: number;
    frais: number;
    montant_net: number;
    montant_verse: number;
    periode: string | null;
    periode_label: string | null;
    part_id: number;
    type_frais: string | null;
    commentaire_frais: string | null;
}

interface PeriodeOption {
    code: string;
    label: string;
}

interface PaiementRow {
    id: number;
    paid_at: string | null;
    montant: number;
    mode_paiement: string;
    note: string | null;
    created_by: string | null;
}

interface ModePaiementOption {
    value: string;
    label: string;
}

interface Filtres {
    date_from: string | null;
    date_to: string | null;
    commande: string | null;
    periode: string | null;
}

interface FraisDepense {
    id: string;
    date: string;
    type: string;
    vehicule: string | null;
    montant: number;
    commentaire: string | null;
}

// ── Props ─────────────────────────────────────────────────────────────────────

const props = defineProps<{
    resume_global: ResumeGlobal;
    historique_commandes: CommandeRow[];
    historique_paiements_globaux: PaiementRow[];
    frais_depenses: FraisDepense[];
    modes_paiement: ModePaiementOption[];
    filtres: Filtres;
    periode_courante: string;
    periode_courante_label: string;
    selected_periode: string;
    periodes_disponibles: PeriodeOption[];
}>();

const { can } = usePermissions();
const page = usePage();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Commissions', href: '/commissions' },
    { title: props.resume_global.nom, href: '' },
];

const isLivreur = computed(() => props.resume_global.type === 'livreur');

// ── Formatage ──────────────────────────────────────────────────────────────────

function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}

const typeIcon = computed(() => (isLivreur.value ? Truck : User));
const typeLabel = computed(() =>
    isLivreur.value ? 'Livreur' : 'Propriétaire',
);

const statutGlobalConfig: Record<string, { label: string; dotClass: string }> =
    {
        a_verser: { label: 'À verser', dotClass: 'bg-amber-500' },
        partielle: { label: 'Partiel', dotClass: 'bg-blue-500' },
        solde: { label: 'Soldé', dotClass: 'bg-emerald-500' },
    };

const statutCfg = computed(
    () =>
        statutGlobalConfig[props.resume_global.statut_global] ??
        statutGlobalConfig.a_verser,
);

// ── Flash ─────────────────────────────────────────────────────────────────────

const flashSuccess = computed(
    () => (page.props.flash as Record<string, string>)?.success ?? null,
);

// ── Filtres server-side (modal) ───────────────────────────────────────────────

const filtresDialogVisible = ref(false);

const filtresServeur = reactive({
    date_from: props.filtres.date_from ?? '',
    date_to: props.filtres.date_to ?? '',
    periode: props.filtres.periode ?? '',
});

const nbFiltresActifs = computed(
    () =>
        [
            filtresServeur.date_from,
            filtresServeur.date_to,
            filtresServeur.periode,
        ].filter((v) => !!v).length,
);

function applyFiltresServeur() {
    router.get(
        `/commissions/beneficiaires/${props.resume_global.type}/${props.resume_global.id}`,
        {
            ...(filtresServeur.date_from
                ? { date_from: filtresServeur.date_from }
                : {}),
            ...(filtresServeur.date_to
                ? { date_to: filtresServeur.date_to }
                : {}),
            ...(filtresServeur.periode
                ? { periode: filtresServeur.periode }
                : {}),
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                filtresDialogVisible.value = false;
            },
        },
    );
}

function resetFiltresServeur() {
    filtresServeur.date_from = '';
    filtresServeur.date_to = '';
    filtresServeur.periode = '';
}

const periodeSelectionnee = ref<string>(props.selected_periode);

watch(
    () => props.selected_periode,
    (val) => {
        periodeSelectionnee.value = val;
        filtresServeur.periode = val;
    },
);

const periodeOptions = [
    { code: '', label: 'Toutes les périodes' },
    ...props.periodes_disponibles,
];

function onPeriodeChange(value: string) {
    const params: Record<string, string> = {};
    if (value) params.periode = value;
    if (filtresServeur.date_from) params.date_from = filtresServeur.date_from;
    if (filtresServeur.date_to) params.date_to = filtresServeur.date_to;
    router.get(
        `/commissions/beneficiaires/${props.resume_global.type}/${props.resume_global.id}`,
        params,
        { preserveScroll: true, replace: true },
    );
}

// ── Recherche locale (DataTable) ───────────────────────────────────────────────

const localSearch = ref('');

const commandesFiltrees = computed(() => {
    const q = localSearch.value.toLowerCase().trim();
    if (!q) return props.historique_commandes;
    return props.historique_commandes.filter(
        (c) =>
            (c.commande_reference ?? '').toLowerCase().includes(q) ||
            (c.date_commande ?? '').toLowerCase().includes(q) ||
            String(c.montant_brut).includes(q) ||
            String(c.montant_net).includes(q) ||
            String(c.frais).includes(q),
    );
});

// ── KPI filtrés (adaptés à la recherche locale + filtre période) ──────────────

const totalFraisDepenses = computed(() =>
    (props.frais_depenses ?? []).reduce((s, d) => s + d.montant, 0),
);

const kpis = computed(() => {
    const rows = commandesFiltrees.value;
    const brut = rows.reduce((s, c) => s + c.montant_brut, 0);
    // Pour les propriétaires, les frais viennent des dépenses, pas des parts
    const frais = isLivreur.value
        ? rows.reduce((s, c) => s + c.frais, 0)
        : totalFraisDepenses.value;
    const net = Math.max(0, brut - frais);
    const verse = rows.reduce((s, c) => s + c.montant_verse, 0);
    return {
        nb_commandes: rows.length,
        total_brut: brut,
        total_frais: frais,
        total_net: net,
        total_verse: verse,
        total_restant: Math.max(0, net - verse),
    };
});

// ── Dialog paiement groupé ─────────────────────────────────────────────────────

const paiementVisible = ref(false);
function currentDateYmd(): string {
    return new Date().toISOString().slice(0, 10);
}

interface PaiementForm {
    montant: number | null;
    mode_paiement: string;
    paid_at: string;
    note: string | null;
    processing: boolean;
}

const paiementForm = reactive<PaiementForm>({
    montant: null,
    mode_paiement: 'especes',
    paid_at: currentDateYmd(),
    note: null,
    processing: false,
});

const paiementErrors = ref<Record<string, string>>({});

function openPaiementDialog() {
    paiementForm.montant =
        props.resume_global.solde_global > 0
            ? props.resume_global.solde_global
            : null;
    paiementForm.mode_paiement = 'especes';
    // Date de paiement forcée au jour courant (champ non affiché pour l'instant).
    paiementForm.paid_at = currentDateYmd();
    // Note conservée en back, non affichée dans l'UI pour l'instant.
    paiementForm.note = null;
    paiementForm.processing = false;
    paiementErrors.value = {};
    paiementVisible.value = true;
}

function closePaiementDialog() {
    paiementVisible.value = false;
}

function submitPaiement() {
    if (!paiementForm.montant || paiementForm.montant <= 0) return;
    // Sécurité: on force la date du jour au moment de l'envoi.
    paiementForm.paid_at = currentDateYmd();
    paiementForm.processing = true;
    paiementErrors.value = {};
    router.post(
        `/commissions/beneficiaires/${props.resume_global.type}/${props.resume_global.id}/paiements`,
        {
            montant: paiementForm.montant,
            mode_paiement: paiementForm.mode_paiement,
            paid_at: paiementForm.paid_at,
            note: paiementForm.note,
        },
        {
            preserveScroll: true,
            onSuccess: () => closePaiementDialog(),
            onError: (err) => {
                paiementErrors.value = err;
            },
            onFinish: () => {
                paiementForm.processing = false;
            },
        },
    );
}

const montantDepasse = computed(
    () =>
        paiementForm.montant !== null &&
        paiementForm.montant > props.resume_global.solde_global + 0.009,
);

// ── Dialog historique paiements ────────────────────────────────────────────────

const historyVisible = ref(false);

function openHistory() {
    historyVisible.value = true;
}

// ── Dialog frais livreur ───────────────────────────────────────────────────────

const fraisVisible = ref(false);
const fraisCommande = ref<CommandeRow | null>(null);

const typeFraisOptions = [
    { value: 'carburant', label: 'Carburant' },
    { value: 'reparation', label: 'Réparation' },
    { value: 'autre', label: 'Autre' },
];

interface FraisForm {
    frais: number;
    type_frais: string | null;
    commentaire_frais: string | null;
    processing: boolean;
}

const fraisForm = reactive<FraisForm>({
    frais: 0,
    type_frais: null,
    commentaire_frais: null,
    processing: false,
});

function openFraisDialog(c: CommandeRow) {
    fraisCommande.value = c;
    fraisForm.frais = c.frais;
    fraisForm.type_frais = c.type_frais ?? null;
    fraisForm.commentaire_frais = c.commentaire_frais ?? null;
    fraisForm.processing = false;
    fraisVisible.value = true;
}

function closeFraisDialog() {
    fraisVisible.value = false;
    fraisCommande.value = null;
}

function submitFrais() {
    if (!fraisCommande.value || fraisForm.frais < 0 || !fraisForm.type_frais)
        return;
    fraisForm.processing = true;
    router.patch(
        `/commissions/parts/${fraisCommande.value.part_id}/frais`,
        {
            frais: fraisForm.frais,
            type_frais: fraisForm.type_frais,
            commentaire_frais: fraisForm.commentaire_frais,
        },
        {
            preserveScroll: true,
            onSuccess: () => closeFraisDialog(),
            onFinish: () => {
                fraisForm.processing = false;
            },
        },
    );
}

// Réinitialise commentaire quand le type change
watch(
    () => fraisForm.type_frais,
    (val) => {
        if (val !== 'autre') fraisForm.commentaire_frais = null;
    },
);

// ── Dialog détail commission ───────────────────────────────────────────────────

const detailVisible = ref(false);
const detailCommande = ref<CommandeRow | null>(null);

function openDetailDialog(c: CommandeRow) {
    detailCommande.value = c;
    detailVisible.value = true;
}

function closeDetailDialog() {
    detailVisible.value = false;
    detailCommande.value = null;
}
</script>

<template>
    <Head :title="`Commission — ${resume_global.nom}`" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- ══════════════════════ MOBILE ══════════════════════════════════════ -->
        <div class="flex flex-col sm:hidden">
            <div class="sticky top-0 z-10 border-b bg-background">
                <div class="flex items-center justify-between px-4 py-3">
                    <Link
                        href="/commissions"
                        class="flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground"
                    >
                        <ArrowLeft class="h-5 w-5" />
                    </Link>
                    <span class="text-base font-semibold">{{
                        resume_global.nom
                    }}</span>
                    <div class="w-8" />
                </div>
            </div>

            <div
                v-if="flashSuccess"
                class="mx-4 mt-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"
            >
                {{ flashSuccess }}
            </div>

            <!-- KPI mobile -->
            <div class="grid grid-cols-2 gap-3 p-4">
                <div class="col-span-2 rounded-xl border bg-card p-3 shadow-sm">
                    <p class="text-xs text-muted-foreground">
                        Total brut cumulé
                    </p>
                    <p class="mt-1 text-base font-bold tabular-nums">
                        {{ formatGNF(resume_global.total_brut_cumule) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-3 shadow-sm">
                    <p class="text-xs text-muted-foreground">
                        Total net cumulé
                    </p>
                    <p class="mt-1 text-base font-bold tabular-nums">
                        {{ formatGNF(resume_global.total_net_cumule) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-3 shadow-sm">
                    <p class="text-xs text-muted-foreground">Total versé</p>
                    <p class="mt-1 text-base font-bold tabular-nums">
                        {{ formatGNF(resume_global.total_verse) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-3 shadow-sm">
                    <p class="text-xs text-muted-foreground">Total frais</p>
                    <p
                        class="mt-1 text-base font-bold tabular-nums"
                        :class="
                            resume_global.total_frais > 0
                                ? 'text-destructive'
                                : 'text-muted-foreground'
                        "
                    >
                        {{
                            resume_global.total_frais > 0
                                ? '− ' + formatGNF(resume_global.total_frais)
                                : '—'
                        }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-3 shadow-sm">
                    <p class="text-xs text-muted-foreground">
                        Total restant à payer
                    </p>
                    <p
                        class="mt-1 text-base font-bold tabular-nums"
                        :class="
                            resume_global.solde_global > 0
                                ? 'text-amber-600 dark:text-amber-400'
                                : 'text-muted-foreground'
                        "
                    >
                        {{ formatGNF(resume_global.solde_global) }}
                    </p>
                </div>
            </div>

            <!-- Actions mobile -->
            <div class="flex gap-2 px-4 pb-3">
                <Button
                    v-if="can('ventes.update')"
                    class="flex-1 gap-2"
                    :disabled="resume_global.solde_global <= 0"
                    @click="openPaiementDialog"
                >
                    <Plus class="h-4 w-4" />
                    Nouveau paiement
                </Button>
                <Button
                    v-if="historique_paiements_globaux.length > 0"
                    variant="outline"
                    class="gap-2"
                    @click="openHistory"
                >
                    <History class="h-4 w-4" />
                    {{ historique_paiements_globaux.length }}
                </Button>
            </div>

            <!-- Recherche + filtre mobile -->
            <div class="space-y-2 border-t px-4 py-3">
                <div class="flex items-center gap-2">
                    <div class="relative min-w-0 flex-1">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <input
                            v-model="localSearch"
                            type="text"
                            placeholder="Référence commande…"
                            class="h-9 w-full rounded-md border bg-background pr-3 pl-8 text-sm"
                        />
                    </div>
                    <Button
                        variant="outline"
                        size="icon"
                        class="relative h-9 w-9 shrink-0"
                        @click="filtresDialogVisible = true"
                    >
                        <Filter class="h-4 w-4" />
                        <span
                            v-if="nbFiltresActifs > 0"
                            class="absolute -top-1 -right-1 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] font-semibold text-primary-foreground"
                        >
                            {{ nbFiltresActifs }}
                        </span>
                    </Button>
                </div>
            </div>

            <!-- Liste mobile -->
            <div class="divide-y">
                <div
                    v-for="c in commandesFiltrees"
                    :key="c.commission_id"
                    class="px-4 py-3.5"
                >
                    <div class="flex items-start justify-between">
                        <div class="min-w-0 flex-1">
                            <p
                                class="font-mono text-xs font-semibold text-primary"
                            >
                                {{ c.commande_reference ?? '—' }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ c.date_commande }}
                            </p>
                        </div>
                        <div class="text-right">
                            <p class="font-semibold tabular-nums">
                                {{ formatGNF(c.montant_net) }}
                            </p>
                            <p
                                v-if="c.frais > 0"
                                class="text-xs text-destructive tabular-nums"
                            >
                                − {{ formatGNF(c.frais) }}
                            </p>
                        </div>
                    </div>
                    <div class="mt-1 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span
                                v-if="isLivreur && c.periode"
                                class="inline-flex items-center rounded-full bg-blue-100 px-1.5 py-0.5 text-[10px] font-medium text-blue-700 dark:bg-blue-900 dark:text-blue-300"
                            >
                                {{ c.periode.slice(-2) }}
                            </span>
                        </div>
                        <div class="flex gap-1">
                            <Button
                                v-if="isLivreur && can('ventes.update')"
                                size="sm"
                                variant="ghost"
                                class="h-7 gap-1 text-xs"
                                @click="openFraisDialog(c)"
                            >
                                <Pencil class="h-3 w-3" /> Frais
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                class="h-7 text-xs"
                                @click="openDetailDialog(c)"
                            >
                                Détails
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
            <div
                v-if="commandesFiltrees.length === 0"
                class="py-12 text-center text-sm text-muted-foreground"
            >
                Aucune commande.
            </div>
        </div>

        <!-- ══════════════════════ DESKTOP ═════════════════════════════════════ -->
        <div class="hidden flex-col gap-6 p-6 sm:flex">
            <!-- Flash -->
            <div
                v-if="flashSuccess"
                class="rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"
            >
                {{ flashSuccess }}
            </div>

            <!-- En-tête (style Ventes) -->
            <div class="flex items-center justify-between gap-4">
                <div>
                    <p class="text-sm text-muted-foreground">
                        <Link href="/commissions" class="hover:text-foreground"
                            >Commissions</Link
                        >
                        <span class="mx-1">›</span>
                        <span class="inline-flex items-center gap-1.5">
                            <component :is="typeIcon" class="h-3.5 w-3.5" />
                            {{ typeLabel }}
                        </span>
                    </p>
                    <div class="mt-1 flex items-center gap-2">
                        <h1 class="text-2xl font-semibold tracking-tight">
                            {{ resume_global.nom }}
                        </h1>
                        <StatusDot
                            :label="statutCfg.label"
                            :dot-class="statutCfg.dotClass"
                            class="text-sm text-muted-foreground"
                        />
                    </div>
                    <p
                        v-if="resume_global.telephone"
                        class="mt-0.5 text-sm text-muted-foreground"
                    >
                        {{ formatPhoneDisplay(resume_global.telephone) }}
                    </p>
                </div>

                <!-- Actions droite -->
                <div class="flex shrink-0 items-center gap-2">
                    <Button
                        v-if="historique_paiements_globaux.length > 0"
                        variant="outline"
                        class="gap-2"
                        @click="openHistory"
                    >
                        <History class="h-4 w-4" />
                        {{ historique_paiements_globaux.length }} versement{{
                            historique_paiements_globaux.length > 1 ? 's' : ''
                        }}
                    </Button>
                    <Button
                        v-if="can('ventes.update')"
                        class="gap-2"
                        :disabled="resume_global.solde_global <= 0"
                        :title="resume_global.solde_global <= 0 ? 'Aucun montant restant à payer' : undefined"
                        @click="openPaiementDialog"
                    >
                        <Plus class="h-4 w-4" />
                        Nouveau paiement
                    </Button>
                </div>
            </div>

            <!-- Cards KPI (5 cards — réactifs au filtre courant) -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">
                        Total brut cumulé
                    </p>
                    <p class="mt-2 text-2xl font-bold tabular-nums">
                        {{ formatGNF(kpis.total_brut) }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ kpis.nb_commandes }} commande{{
                            kpis.nb_commandes !== 1 ? 's' : ''
                        }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">
                        Total net cumulé
                    </p>
                    <p class="mt-2 text-2xl font-bold tabular-nums">
                        {{ formatGNF(kpis.total_net) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Total frais</p>
                    <p
                        class="mt-2 text-2xl font-bold tabular-nums"
                        :class="
                            kpis.total_frais > 0
                                ? 'text-destructive'
                                : 'text-muted-foreground'
                        "
                    >
                        {{
                            kpis.total_frais > 0
                                ? '− ' + formatGNF(kpis.total_frais)
                                : '—'
                        }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Total versé</p>
                    <p class="mt-2 text-2xl font-bold tabular-nums">
                        {{ formatGNF(resume_global.total_verse) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">
                        Total restant à payer
                    </p>
                    <p
                        class="mt-2 text-2xl font-bold tabular-nums"
                        :class="
                            resume_global.solde_global > 0
                                ? 'text-amber-600 dark:text-amber-400'
                                : 'text-muted-foreground'
                        "
                    >
                        {{ formatGNF(resume_global.solde_global) }}
                    </p>
                </div>
            </div>

            <!-- Tableau DataTable (style Factures) -->
            <div class="overflow-x-auto rounded-xl border bg-card">
                <DataTable
                    :value="commandesFiltrees"
                    :paginator="commandesFiltrees.length > 25"
                    :rows="25"
                    data-key="commission_id"
                    striped-rows
                    removable-sort
                    class="text-sm"
                    :pt="{
                        root: { class: 'w-full' },
                        header: { class: 'border-b bg-muted/30 px-4 py-3' },
                        tbody: { class: 'divide-y' },
                        table: {
                            style: 'table-layout: fixed; min-width: 980px',
                        },
                    }"
                >
                    <!-- ─ Header slot : filtres ─────────────────────────────── -->
                    <template #header>
                        <div class="flex flex-wrap items-center gap-3">
                            <!-- Recherche locale -->
                            <IconField class="max-w-xs flex-1">
                                <InputIcon class="pointer-events-none">
                                    <Search
                                        class="h-4 w-4 text-muted-foreground"
                                    />
                                </InputIcon>
                                <InputText
                                    v-model="localSearch"
                                    placeholder="Référence commande…"
                                    class="w-full text-sm"
                                />
                            </IconField>

                            <!-- Filtre période (livreur uniquement) -->
                            <Dropdown
                                v-if="isLivreur && periodeOptions.length > 1"
                                v-model="periodeSelectionnee"
                                :options="periodeOptions"
                                option-label="label"
                                option-value="code"
                                placeholder="Toutes les périodes"
                                class="w-72 text-sm"
                                @change="onPeriodeChange(periodeSelectionnee)"
                            />

                            <div class="ml-auto flex items-center gap-2">
                                <Button
                                    variant="outline"
                                    size="icon"
                                    class="relative h-9 w-9"
                                    @click="filtresDialogVisible = true"
                                >
                                    <Filter class="h-4 w-4" />
                                    <span
                                        v-if="nbFiltresActifs > 0"
                                        class="absolute -top-1 -right-1 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] font-semibold text-primary-foreground"
                                    >
                                        {{ nbFiltresActifs }}
                                    </span>
                                </Button>
                                <span class="text-xs text-muted-foreground">
                                    {{ commandesFiltrees.length }} résultat{{
                                        commandesFiltrees.length !== 1
                                            ? 's'
                                            : ''
                                    }}
                                </span>
                            </div>
                        </div>
                    </template>

                    <!-- ─ Colonne : Commande (20%) ────────────────────────── -->
                    <Column
                        field="commande_reference"
                        header="Commande"
                        sortable
                        style="width: 220px"
                        header-class="py-3 align-middle text-left"
                        body-class="align-middle"
                    >
                        <template #body="{ data }">
                            <div class="min-w-0 space-y-1 leading-tight">
                                <Link
                                    v-if="data.commande_id"
                                    :href="`/ventes/${data.commande_id}`"
                                    class="inline-block max-w-full truncate rounded bg-muted px-1.5 py-0.5 font-mono text-[11px] text-muted-foreground hover:text-foreground"
                                >
                                    {{ data.commande_reference ?? '—' }}
                                </Link>
                                <span
                                    v-else
                                    class="inline-block max-w-full truncate rounded bg-muted px-1.5 py-0.5 font-mono text-[11px] text-muted-foreground"
                                >
                                    {{ data.commande_reference ?? '—' }}
                                </span>
                            </div>
                        </template>
                    </Column>

                    <!-- Colonnes vehicule/site retirees: visibles dans le detail commande -->

                    <Column
                        field="montant_brut"
                        header="Brut"
                        sortable
                        style="width: 140px"
                        header-class="py-3 align-middle text-left"
                        body-class="align-middle"
                    >
                        <template #body="{ data }">
                            <div
                                class="flex flex-col items-start justify-center gap-1 leading-tight"
                            >
                                <p
                                    class="whitespace-nowrap text-muted-foreground tabular-nums"
                                >
                                    {{ formatGNF(data.montant_brut) }}
                                </p>
                                <p
                                    class="text-xs text-muted-foreground tabular-nums"
                                >
                                    {{ data.taux }}%
                                </p>
                            </div>
                        </template>
                    </Column>

                    <!-- ─ Colonne : Frais (11%) ───────────────────────────── -->
                    <Column
                        field="frais"
                        header="Frais"
                        sortable
                        style="width: 130px"
                        header-class="py-3 align-middle text-left"
                        body-class="align-middle"
                    >
                        <template #body="{ data }">
                            <div
                                class="flex items-center justify-start leading-tight"
                            >
                                <span
                                    class="inline-block whitespace-nowrap tabular-nums"
                                    :class="
                                        data.frais > 0
                                            ? 'text-destructive'
                                            : 'text-muted-foreground'
                                    "
                                >
                                    {{
                                        data.frais > 0
                                            ? '−\u202F' + formatGNF(data.frais)
                                            : '—'
                                    }}
                                </span>
                            </div>
                        </template>
                    </Column>

                    <!-- ─ Colonne : Net (12%) ─────────────────────────────── -->
                    <Column
                        field="montant_net"
                        header="Net"
                        sortable
                        style="width: 140px"
                        header-class="py-3 align-middle text-left"
                        body-class="align-middle"
                    >
                        <template #body="{ data }">
                            <p
                                class="text-left font-semibold whitespace-nowrap tabular-nums"
                            >
                                {{ formatGNF(data.montant_net) }}
                            </p>
                        </template>
                    </Column>

                    <!-- ─ Colonne : Date (9%) ─────────────────────────────── -->
                    <Column
                        field="date_commande"
                        header="Date"
                        sortable
                        style="width: 120px"
                        header-class="py-3 align-middle text-left"
                        body-class="align-middle"
                    >
                        <template #body="{ data }">
                            <span
                                class="block text-left text-xs whitespace-nowrap text-muted-foreground tabular-nums"
                            >
                                {{ data.date_commande ?? '—' }}
                            </span>
                        </template>
                    </Column>

                    <!-- ─ Colonne : Période (livreur) ────────────────────── -->
                    <Column
                        v-if="isLivreur"
                        field="periode"
                        header="Période"
                        sortable
                        style="width: 130px"
                        header-class="py-3 align-middle text-left"
                        body-class="align-middle"
                    >
                        <template #body="{ data }">
                            <span
                                v-if="data.periode"
                                class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-[11px] font-medium text-blue-700 dark:bg-blue-900 dark:text-blue-300"
                            >
                                {{ data.periode.slice(-2) }}
                            </span>
                            <span v-else class="text-xs text-muted-foreground"
                                >—</span
                            >
                        </template>
                    </Column>

                    <!-- ─ Colonne : Actions (4%) ──────────────────────────── -->
                    <Column
                        header=""
                        style="width: 72px"
                        header-class="py-3 align-middle text-center"
                        body-class="align-middle"
                    >
                        <template #body="{ data }">
                            <div class="flex justify-center">
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="h-8 w-8"
                                        >
                                            <MoreVertical class="h-4 w-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent
                                        align="end"
                                        class="w-44"
                                    >
                                        <DropdownMenuItem
                                            v-if="
                                                isLivreur &&
                                                can('ventes.update')
                                            "
                                            class="cursor-pointer"
                                            @click="openFraisDialog(data)"
                                        >
                                            <Pencil class="h-4 w-4" />
                                            Modifier frais
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            class="cursor-pointer"
                                            @click="openDetailDialog(data)"
                                        >
                                            <CreditCard class="h-4 w-4" />
                                            Détail commission
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </template>
                    </Column>

                    <template #empty>
                        <div
                            class="py-16 text-center text-sm text-muted-foreground"
                        >
                            Aucune commande trouvée.
                        </div>
                    </template>
                </DataTable>
            </div>
            <!-- Bloc dépenses (propriétaires uniquement) -->
            <div v-if="!isLivreur" class="overflow-x-auto rounded-xl border bg-card">
                <div class="flex items-center justify-between border-b bg-muted/30 px-4 py-3">
                    <h2 class="text-sm font-semibold">
                        Frais déduits (dépenses approuvées liées au véhicule)
                    </h2>
                    <span class="text-sm font-bold text-destructive tabular-nums">
                        {{ totalFraisDepenses > 0 ? '− ' + formatGNF(totalFraisDepenses) : '—' }}
                    </span>
                </div>
                <table v-if="frais_depenses.length > 0" class="w-full text-sm">
                    <thead>
                        <tr class="border-b bg-muted/10">
                            <th class="px-4 py-2.5 text-left font-medium text-muted-foreground">Date</th>
                            <th class="px-4 py-2.5 text-left font-medium text-muted-foreground">Type</th>
                            <th class="px-4 py-2.5 text-left font-medium text-muted-foreground">Véhicule</th>
                            <th class="px-4 py-2.5 text-left font-medium text-muted-foreground">Commentaire</th>
                            <th class="px-4 py-2.5 text-right font-medium text-muted-foreground">Montant</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="d in frais_depenses" :key="d.id" class="hover:bg-muted/20">
                            <td class="px-4 py-2.5 text-xs text-muted-foreground tabular-nums">{{ d.date }}</td>
                            <td class="px-4 py-2.5 font-medium">{{ d.type }}</td>
                            <td class="px-4 py-2.5 text-xs text-muted-foreground">{{ d.vehicule ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-xs text-muted-foreground italic">{{ d.commentaire ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-right font-mono font-semibold text-destructive tabular-nums">
                                − {{ formatGNF(d.montant) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="px-4 py-8 text-center text-sm text-muted-foreground">
                    Aucune dépense approuvée pour ce véhicule.
                </p>
            </div>
        </div>

        <!-- ══════════════════════ DIALOG FILTRES ═════════════════════════════ -->
        <Dialog
            v-model:visible="filtresDialogVisible"
            modal
            :dismissable-mask="true"
            :style="{ width: 'min(420px, 95vw)' }"
        >
            <template #header>
                <span class="text-base font-semibold">Filtres</span>
            </template>

            <div class="space-y-4 pt-1">
                <div
                    v-if="isLivreur && periodes_disponibles.length > 0"
                    class="space-y-1.5"
                >
                    <label class="text-sm font-medium">Période comptable</label>
                    <Dropdown
                        v-model="filtresServeur.periode"
                        :options="[
                            { code: '', label: 'Toutes les périodes' },
                            ...periodes_disponibles,
                        ]"
                        option-label="label"
                        option-value="code"
                        class="w-full"
                    />
                </div>

                <div class="space-y-1.5">
                    <label class="text-sm font-medium">Date du</label>
                    <input
                        v-model="filtresServeur.date_from"
                        type="date"
                        class="h-10 w-full rounded-md border bg-background px-3 text-sm"
                    />
                </div>

                <div class="space-y-1.5">
                    <label class="text-sm font-medium">Date au</label>
                    <input
                        v-model="filtresServeur.date_to"
                        type="date"
                        class="h-10 w-full rounded-md border bg-background px-3 text-sm"
                    />
                </div>

                <div class="flex items-center justify-between gap-2 pt-1">
                    <Button
                        variant="ghost"
                        size="sm"
                        class="text-destructive hover:text-destructive"
                        @click="resetFiltresServeur"
                    >
                        Réinitialiser
                    </Button>
                    <Button @click="applyFiltresServeur">
                        Appliquer filtres
                    </Button>
                </div>
            </div>
        </Dialog>

        <!-- ══════════════════════ DIALOG PAIEMENT GROUPÉ ══════════════════════ -->
        <Dialog
            v-model:visible="paiementVisible"
            header="Paiement groupé"
            modal
            :style="{ width: '440px' }"
            @hide="closePaiementDialog"
        >
            <div class="space-y-4 pt-2">
                <div class="space-y-1 rounded-lg bg-muted/40 p-3 text-sm">
                    <div class="flex justify-between">
                        <span class="text-muted-foreground"
                            >Solde restant :</span
                        >
                        <span
                            class="font-semibold text-emerald-700 tabular-nums dark:text-emerald-400"
                        >
                            {{ formatGNF(resume_global.solde_global) }}
                        </span>
                    </div>
                </div>

                <div class="space-y-1">
                    <label class="text-sm font-medium">Montant (GNF)</label>
                    <InputNumber
                        v-model="paiementForm.montant"
                        :max="resume_global.solde_global"
                        :min="1"
                        class="w-full"
                        :use-grouping="true"
                        locale="fr-FR"
                        :class="{
                            'p-invalid':
                                montantDepasse || paiementErrors.montant,
                        }"
                    />
                    <p v-if="montantDepasse" class="text-xs text-destructive">
                        Le montant dépasse le solde restant ({{
                            formatGNF(resume_global.solde_global)
                        }}).
                    </p>
                    <p
                        v-if="paiementErrors.montant"
                        class="text-xs text-destructive"
                    >
                        {{ paiementErrors.montant }}
                    </p>
                </div>

                <div class="space-y-1">
                    <label class="text-sm font-medium">Mode de paiement</label>
                    <Dropdown
                        v-model="paiementForm.mode_paiement"
                        :options="modes_paiement"
                        option-label="label"
                        option-value="value"
                        class="w-full"
                    />
                </div>

                <!--
                <div class="space-y-1">
                    <label class="text-sm font-medium">Date du paiement</label>
                    <input
                        v-model="paiementForm.paid_at"
                        type="date"
                        class="h-9 w-full rounded-md border bg-background px-3 text-sm"
                    />
                </div>

                <div class="space-y-1">
                    <label class="text-sm font-medium">Note (optionnel)</label>
                    <InputText
                        v-model="paiementForm.note"
                        class="w-full"
                        placeholder="Commentaire…"
                    />
                </div>
                -->

                <div class="flex justify-end gap-2 pt-2">
                    <Button variant="ghost" @click="closePaiementDialog"
                        >Annuler</Button
                    >
                    <Button
                        :disabled="
                            paiementForm.processing ||
                            !paiementForm.montant ||
                            paiementForm.montant <= 0 ||
                            montantDepasse
                        "
                        @click="submitPaiement"
                    >
                        <Wallet class="mr-1.5 h-4 w-4" />
                        Enregistrer
                    </Button>
                </div>
            </div>
        </Dialog>

        <!-- ══════════════════════ DIALOG HISTORIQUE PAIEMENTS ═════════════════ -->
        <Dialog
            v-model:visible="historyVisible"
            header="Historique des paiements versés"
            modal
            :style="{ width: 'min(860px, 96vw)' }"
        >
            <div class="pt-2">
                <table
                    v-if="historique_paiements_globaux.length > 0"
                    class="w-full text-sm"
                >
                    <thead>
                        <tr class="border-b bg-muted/30">
                            <th
                                class="px-3 py-2.5 text-left font-medium text-muted-foreground"
                            >
                                Date
                            </th>
                            <th
                                class="px-3 py-2.5 text-right font-medium text-muted-foreground"
                            >
                                Montant
                            </th>
                            <th
                                class="px-3 py-2.5 text-left font-medium text-muted-foreground"
                            >
                                Mode
                            </th>
                            <th
                                class="px-3 py-2.5 text-left font-medium text-muted-foreground"
                            >
                                Note
                            </th>
                            <th
                                class="px-3 py-2.5 text-left font-medium text-muted-foreground"
                            >
                                Par
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="p in historique_paiements_globaux"
                            :key="p.id"
                            class="hover:bg-muted/20"
                        >
                            <td
                                class="px-3 py-2.5 text-muted-foreground tabular-nums"
                            >
                                {{ p.paid_at ?? '—' }}
                            </td>
                            <td
                                class="px-3 py-2.5 text-right font-semibold text-emerald-700 tabular-nums dark:text-emerald-400"
                            >
                                {{ formatGNF(p.montant) }}
                            </td>
                            <td class="px-3 py-2.5">{{ p.mode_paiement }}</td>
                            <td
                                class="px-3 py-2.5 text-xs text-muted-foreground italic"
                            >
                                {{ p.note ?? '—' }}
                            </td>
                            <td
                                class="px-3 py-2.5 text-xs text-muted-foreground"
                            >
                                {{ p.created_by ?? '—' }}
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t">
                            <td class="px-3 py-2.5 font-medium">Total</td>
                            <td
                                class="px-3 py-2.5 text-right font-bold text-emerald-700 tabular-nums dark:text-emerald-400"
                            >
                                {{
                                    formatGNF(
                                        historique_paiements_globaux.reduce(
                                            (s, p) => s + p.montant,
                                            0,
                                        ),
                                    )
                                }}
                            </td>
                            <td colspan="3" />
                        </tr>
                    </tfoot>
                </table>
                <p
                    v-else
                    class="py-8 text-center text-sm text-muted-foreground"
                >
                    Aucun paiement enregistré.
                </p>
            </div>
        </Dialog>

        <!-- ══════════════════════ DIALOG FRAIS LIVREUR ════════════════════════ -->
        <Dialog
            v-model:visible="fraisVisible"
            :header="`Frais — ${fraisCommande?.commande_reference ?? '—'}`"
            modal
            :style="{ width: '400px' }"
            @hide="closeFraisDialog"
        >
            <div v-if="fraisCommande" class="space-y-4 pt-2">
                <!-- Rappel brut -->
                <div class="rounded-lg bg-muted/40 p-3 text-sm">
                    <div class="flex justify-between">
                        <span class="text-muted-foreground"
                            >Montant brut :</span
                        >
                        <span class="font-medium tabular-nums">{{
                            formatGNF(fraisCommande.montant_brut)
                        }}</span>
                    </div>
                    <div class="mt-1 flex justify-between text-xs">
                        <span class="text-muted-foreground"
                            >Net après frais :</span
                        >
                        <span class="font-semibold tabular-nums">
                            {{
                                formatGNF(
                                    Math.max(
                                        0,
                                        fraisCommande.montant_brut -
                                            (fraisForm.frais ?? 0),
                                    ),
                                )
                            }}
                        </span>
                    </div>
                </div>

                <div class="space-y-1">
                    <label class="text-sm font-medium"
                        >Montant des frais (GNF)</label
                    >
                    <InputNumber
                        v-model="fraisForm.frais"
                        :max="fraisCommande.montant_brut"
                        :min="0"
                        class="w-full"
                        :use-grouping="true"
                        locale="fr-FR"
                    />
                </div>

                <div class="space-y-1">
                    <label class="text-sm font-medium"
                        >Type de frais
                        <span class="text-destructive">*</span></label
                    >
                    <Dropdown
                        v-model="fraisForm.type_frais"
                        :options="typeFraisOptions"
                        option-label="label"
                        option-value="value"
                        placeholder="Sélectionner…"
                        class="w-full"
                        :class="{ 'p-invalid': !fraisForm.type_frais }"
                    />
                </div>

                <div v-if="fraisForm.type_frais === 'autre'" class="space-y-1">
                    <label class="text-sm font-medium">Commentaire</label>
                    <InputText
                        v-model="fraisForm.commentaire_frais"
                        class="w-full"
                        placeholder="Préciser…"
                    />
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <Button variant="ghost" @click="closeFraisDialog"
                        >Annuler</Button
                    >
                    <Button
                        :disabled="
                            fraisForm.processing ||
                            fraisForm.frais < 0 ||
                            !fraisForm.type_frais
                        "
                        @click="submitFrais"
                    >
                        <Pencil class="mr-1.5 h-4 w-4" />
                        Enregistrer
                    </Button>
                </div>
            </div>
        </Dialog>

        <!-- ══════════════════════ DIALOG DÉTAIL COMMISSION ══════════════════ -->
        <Dialog
            v-model:visible="detailVisible"
            :header="
                detailCommande
                    ? `Commission — ${detailCommande.commande_reference ?? '—'}`
                    : 'Détail commission'
            "
            modal
            :style="{ width: '480px' }"
            @hide="closeDetailDialog"
        >
            <div v-if="detailCommande" class="space-y-4 pt-2">
                <!-- Infos commande -->
                <div
                    class="space-y-2 rounded-lg border bg-muted/20 p-3 text-sm"
                >
                    <div class="flex items-center justify-between">
                        <span class="text-muted-foreground">Commande</span>
                        <Link
                            v-if="detailCommande.commande_id"
                            :href="`/ventes/${detailCommande.commande_id}`"
                            class="font-mono text-xs font-semibold text-primary hover:underline"
                        >
                            {{ detailCommande.commande_reference ?? '—' }}
                        </Link>
                        <span v-else class="font-mono text-xs font-semibold">{{
                            detailCommande.commande_reference ?? '—'
                        }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-muted-foreground">Date</span>
                        <span class="tabular-nums">{{
                            detailCommande.date_commande ?? '—'
                        }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-muted-foreground">Site</span>
                        <span>{{ detailCommande.site ?? '—' }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-muted-foreground">Véhicule</span>
                        <span class="text-right">
                            {{ detailCommande.vehicule ?? '—' }}
                            <span
                                v-if="detailCommande.immatriculation"
                                class="ml-1 font-mono text-xs text-muted-foreground"
                            >
                                {{ detailCommande.immatriculation }}
                            </span>
                        </span>
                    </div>
                </div>

                <!-- Montants -->
                <div class="grid grid-cols-2 gap-2">
                    <div class="rounded-lg bg-muted/30 p-3 text-center">
                        <p class="text-xs text-muted-foreground">Taux</p>
                        <p class="mt-1 text-lg font-bold tabular-nums">
                            {{ detailCommande.taux }}%
                        </p>
                    </div>
                    <div class="rounded-lg bg-muted/30 p-3 text-center">
                        <p class="text-xs text-muted-foreground">Brut</p>
                        <p class="mt-1 text-lg font-bold tabular-nums">
                            {{ formatGNF(detailCommande.montant_brut) }}
                        </p>
                    </div>
                    <div class="rounded-lg bg-muted/30 p-3 text-center">
                        <p class="text-xs text-muted-foreground">Frais</p>
                        <p
                            class="mt-1 text-lg font-bold tabular-nums"
                            :class="
                                detailCommande.frais > 0
                                    ? 'text-destructive'
                                    : 'text-muted-foreground'
                            "
                        >
                            {{
                                detailCommande.frais > 0
                                    ? '− ' + formatGNF(detailCommande.frais)
                                    : '—'
                            }}
                        </p>
                        <p
                            v-if="detailCommande.type_frais"
                            class="mt-0.5 text-xs text-muted-foreground capitalize"
                        >
                            {{ detailCommande.type_frais }}
                            <span v-if="detailCommande.commentaire_frais">
                                · {{ detailCommande.commentaire_frais }}</span
                            >
                        </p>
                    </div>
                    <div class="rounded-lg bg-muted/30 p-3 text-center">
                        <p class="text-xs text-muted-foreground">Net</p>
                        <p class="mt-1 text-lg font-bold tabular-nums">
                            {{ formatGNF(detailCommande.montant_net) }}
                        </p>
                    </div>
                </div>

                <!-- Statut paiement -->
                <div
                    class="space-y-2 rounded-lg border bg-muted/20 p-3 text-sm"
                >
                    <div class="flex items-center justify-between">
                        <span class="text-muted-foreground">Versé</span>
                        <span
                            class="font-semibold tabular-nums"
                            :class="
                                detailCommande.montant_verse > 0
                                    ? 'text-emerald-700 dark:text-emerald-400'
                                    : 'text-muted-foreground'
                            "
                        >
                            {{ formatGNF(detailCommande.montant_verse) }}
                        </span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-muted-foreground">Restant</span>
                        <span
                            class="font-semibold tabular-nums"
                            :class="
                                detailCommande.montant_net -
                                    detailCommande.montant_verse >
                                0
                                    ? 'text-amber-600 dark:text-amber-400'
                                    : 'text-muted-foreground'
                            "
                        >
                            {{
                                formatGNF(
                                    Math.max(
                                        0,
                                        detailCommande.montant_net -
                                            detailCommande.montant_verse,
                                    ),
                                )
                            }}
                        </span>
                    </div>
                </div>

                <div class="flex justify-end pt-1">
                    <Button variant="ghost" @click="closeDetailDialog"
                        >Fermer</Button
                    >
                </div>
            </div>
        </Dialog>
    </AppLayout>
</template>
