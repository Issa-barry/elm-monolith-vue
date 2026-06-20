<script setup lang="ts">
import AuditDrawer from '@/components/AuditDrawer.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Building2,
    Download,
    FileText,
    HandCoins,
    History,
    MoreHorizontal,
    Search,
    Truck,
    User,
    X,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import { computed, reactive, ref, watch } from 'vue';

interface BeneficiaireRow {
    beneficiaire_id: string;
    beneficiaire_nom: string;
    telephone: string | null;
    agence: string | null;
    vehicules: string | null;
    total_brut_cumule: number;
    total_frais: number;
    total_net_cumule: number;
    total_verse: number;
    solde_restant: number;
    nb_commandes: number;
    statut_global: string;
}

interface PeriodeOption {
    code: string;
    label: string;
}

const props = defineProps<{
    beneficiaires: BeneficiaireRow[];
    kpis: {
        nb_livreurs: number;
        total_brut: number;
        total_frais?: number;
        total_net: number;
        total_verse: number;
        solde_total: number;
    };
    search: string;
    filtre_statut: string;
    filtre_site: string;
    selected_periode: string;
    periodes_disponibles: PeriodeOption[];
    periode_courante: string;
    is_admin: boolean;
    sites: { value: string; label: string }[];
    can_payer: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Comptabilité', href: '/comptabilite' },
    {
        title: 'Commission livreur vente',
        href: '/comptabilite/commissions/vente',
    },
];

const searchVal = ref(props.search ?? '');
const statutFiltre = ref(props.filtre_statut || '');
const periodeFiltre = ref(props.selected_periode || '');
const siteFiltre = ref(props.filtre_site || '');

const activeFilterCount = computed(
    () =>
        [
            !!statutFiltre.value,
            !!periodeFiltre.value,
            !!siteFiltre.value,
        ].filter(Boolean).length,
);

const hasActiveFilters = computed(
    () => !!searchVal.value || activeFilterCount.value > 0,
);

function appliquerFiltres() {
    router.get(
        '/comptabilite/commissions/vente',
        {
            search: searchVal.value || undefined,
            statut: statutFiltre.value || undefined,
            periode: periodeFiltre.value || undefined,
            site: siteFiltre.value || undefined,
        },
        { preserveState: true, replace: true },
    );
}

function resetFilters() {
    searchVal.value = '';
    statutFiltre.value = '';
    periodeFiltre.value = '';
    siteFiltre.value = '';
    router.get(
        '/comptabilite/commissions/vente',
        {},
        { preserveState: true, replace: true },
    );
}

let searchDebounce: ReturnType<typeof setTimeout> | null = null;
watch(searchVal, () => {
    if (searchDebounce) clearTimeout(searchDebounce);
    searchDebounce = setTimeout(appliquerFiltres, 400);
});

function statutClass(s: string) {
    return (
        {
            impaye: 'bg-red-100 text-red-700 dark:bg-red-950/30 dark:text-red-400',
            partiel:
                'bg-amber-100 text-amber-700 dark:bg-amber-950/30 dark:text-amber-400',
            paye: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-400',
        }[s] ?? 'bg-muted text-muted-foreground'
    );
}

function statutLabel(s: string) {
    return { impaye: 'Impayé', partiel: 'Partiel', paye: 'Payé' }[s] ?? s;
}

// Dialog paiement
const showPaiementDialog = ref(false);
const selectedBenef = ref<BeneficiaireRow | null>(null);
const paiementForm = reactive({
    montant: null as number | null,
    mode_paiement: 'especes',
    note: '',
    processing: false,
    errors: {} as Record<string, string>,
});

const MODES = [
    { value: 'especes', label: 'Espèces' },
    { value: 'virement', label: 'Virement' },
    { value: 'cheque', label: 'Chèque' },
    { value: 'mobile_money', label: 'Mobile Money' },
];

const showAudit = ref(false);
const auditBenefId = ref('');
const auditBenefNom = ref('');

function openAudit(b: BeneficiaireRow) {
    auditBenefId.value = b.beneficiaire_id;
    auditBenefNom.value = b.beneficiaire_nom;
    showAudit.value = true;
}

function openPaiement(b: BeneficiaireRow) {
    selectedBenef.value = b;
    paiementForm.montant = b.solde_restant > 0 ? b.solde_restant : null;
    paiementForm.mode_paiement = 'especes';
    paiementForm.note = '';
    paiementForm.processing = false;
    paiementForm.errors = {};
    showPaiementDialog.value = true;
}

function submitPaiement() {
    if (!selectedBenef.value || !paiementForm.montant) return;
    paiementForm.processing = true;
    paiementForm.errors = {};
    router.post(
        `/comptabilite/commissions/vente/livreurs/${selectedBenef.value.beneficiaire_id}/paiements`,
        {
            montant: paiementForm.montant,
            mode_paiement: paiementForm.mode_paiement,
            note: paiementForm.note || null,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                showPaiementDialog.value = false;
            },
            onError: (e) => {
                paiementForm.errors = e as Record<string, string>;
            },
            onFinish: () => {
                paiementForm.processing = false;
            },
        },
    );
}

function buildParams(): URLSearchParams {
    const params = new URLSearchParams();
    if (periodeFiltre.value) params.set('periode', periodeFiltre.value);
    if (statutFiltre.value) params.set('statut', statutFiltre.value);
    if (siteFiltre.value) params.set('site', siteFiltre.value);
    if (searchVal.value) params.set('search', searchVal.value);
    return params;
}

function exportExcel() {
    window.open(
        '/comptabilite/commissions/vente/export/excel?' +
            buildParams().toString(),
        '_blank',
    );
}

function exportPdf() {
    window.open(
        '/comptabilite/commissions/vente/export/pdf?' +
            buildParams().toString(),
        '_blank',
    );
}

const kpiTotalFrais = computed(
    () =>
        props.kpis.total_frais ??
        props.beneficiaires.reduce((s, b) => s + b.total_frais, 0),
);

function fmt(val: number | null | undefined) {
    return (
        new Intl.NumberFormat('fr-FR').format(
            Math.round(Math.abs(Number(val ?? 0))),
        ) + ' GNF'
    );
}

function fmtTel(tel: string | null | undefined): string {
    if (!tel) return '—';
    const digits = tel.replace(/\s/g, '');
    if (digits.startsWith('+')) {
        const cc = digits.slice(0, 4);
        const rest = digits.slice(4).replace(/(\d{3})(?=\d)/g, '$1 ');
        return cc + ' ' + rest;
    }
    return digits.replace(/(\d{3})(?=\d)/g, '$1 ');
}
</script>

<template>
    <Head title="Commission livreur vente — Comptabilité" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Commission livreur vente
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ kpis.nb_livreurs }} livreur{{
                            kpis.nb_livreurs !== 1 ? 's' : ''
                        }}
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg border bg-card px-3 py-2 text-sm hover:bg-muted/50"
                        @click="exportExcel"
                    >
                        <Download class="h-4 w-4" />
                        Excel
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg border bg-card px-3 py-2 text-sm hover:bg-muted/50"
                        @click="exportPdf"
                    >
                        <FileText class="h-4 w-4" />
                        PDF
                    </button>
                </div>
            </div>

            <!-- KPIs -->
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Brut cumulé</p>
                    <p
                        class="mt-2 text-2xl font-bold text-foreground tabular-nums"
                    >
                        {{ fmt(kpis.total_brut) }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ beneficiaires.length }} livreur{{
                            beneficiaires.length !== 1 ? 's' : ''
                        }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Frais</p>
                    <p
                        class="mt-2 text-2xl font-bold text-red-600 tabular-nums dark:text-red-400"
                    >
                        {{
                            kpiTotalFrais > 0
                                ? '-' + fmt(kpiTotalFrais)
                                : fmt(0)
                        }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Net à payer</p>
                    <p
                        class="mt-2 text-2xl font-bold text-foreground tabular-nums"
                    >
                        {{ fmt(kpis.total_net) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Déjà payé</p>
                    <p
                        class="mt-2 text-2xl font-bold text-foreground tabular-nums"
                    >
                        {{ fmt(kpis.total_verse) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Reste à payer</p>
                    <p
                        class="mt-2 text-2xl font-bold text-foreground tabular-nums"
                    >
                        {{ fmt(kpis.solde_total) }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{
                            beneficiaires.filter((b) => b.solde_restant > 0)
                                .length
                        }}
                        impayé{{
                            beneficiaires.filter((b) => b.solde_restant > 0)
                                .length !== 1
                                ? 's'
                                : ''
                        }}
                    </p>
                </div>
            </div>

            <!-- Filtres -->
            <div class="flex flex-wrap items-center gap-3">
                <div class="relative w-[280px] shrink-0">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <input
                        v-model="searchVal"
                        type="search"
                        placeholder="Rechercher un livreur, téléphone, véhicule..."
                        class="h-9 w-full rounded-md border border-input bg-background py-2 pr-7 pl-8 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    />
                    <button
                        v-if="searchVal"
                        type="button"
                        class="absolute top-1/2 right-2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                        @click="searchVal = ''"
                    >
                        <X class="h-3.5 w-3.5" />
                    </button>
                </div>

                <select
                    v-if="is_admin && sites.length > 1"
                    v-model="siteFiltre"
                    class="h-9 rounded-md border border-input bg-background px-2 text-sm"
                >
                    <option value="">Toutes les agences</option>
                    <option v-for="s in sites" :key="s.value" :value="s.value">
                        {{ s.label }}
                    </option>
                </select>
                <span
                    v-else-if="!is_admin && sites.length >= 1"
                    class="inline-flex h-9 items-center gap-1.5 rounded-md border bg-muted/40 px-3 text-sm text-muted-foreground"
                >
                    <Building2 class="h-3.5 w-3.5" />
                    {{ sites[0]?.label }}
                </span>

                <select
                    v-model="statutFiltre"
                    class="h-9 rounded-md border border-input bg-background px-2 text-sm"
                >
                    <option value="">Tous les statuts</option>
                    <option value="impaye">Impayé</option>
                    <option value="partiel">Partiel</option>
                    <option value="paye">Payé</option>
                </select>

                <select
                    v-model="periodeFiltre"
                    class="h-9 rounded-md border border-input bg-background px-2 text-sm"
                >
                    <option value="">Toutes les périodes</option>
                    <option
                        v-for="p in periodes_disponibles"
                        :key="p.code"
                        :value="p.code"
                    >
                        {{ p.label }}
                    </option>
                </select>

                <button
                    type="button"
                    class="h-9 rounded-md bg-primary px-3 text-sm text-primary-foreground hover:bg-primary/90"
                    @click="appliquerFiltres"
                >
                    Appliquer
                </button>

                <span
                    class="shrink-0 text-xs whitespace-nowrap text-muted-foreground"
                >
                    {{ beneficiaires.length }} résultat{{
                        beneficiaires.length !== 1 ? 's' : ''
                    }}
                </span>
                <button
                    v-if="hasActiveFilters"
                    type="button"
                    class="shrink-0 text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                    @click="resetFilters"
                >
                    Réinitialiser
                </button>
            </div>

            <!-- Tableau -->
            <div class="overflow-hidden rounded-xl border bg-card shadow-sm">
                <div v-if="beneficiaires.length > 0" class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b bg-muted/40">
                                <th
                                    class="px-5 py-3.5 text-left font-medium text-muted-foreground"
                                >
                                    Livreur
                                </th>
                                <th
                                    class="px-5 py-3.5 text-left font-medium text-muted-foreground"
                                >
                                    Véhicule
                                </th>
                                <th
                                    class="px-5 py-3.5 text-left font-medium text-muted-foreground"
                                >
                                    Agence
                                </th>
                                <th
                                    class="px-5 py-3.5 text-right font-medium text-muted-foreground"
                                >
                                    Total cumulé
                                </th>
                                <th
                                    class="px-5 py-3.5 text-right font-medium text-muted-foreground"
                                >
                                    Frais
                                </th>
                                <th
                                    class="px-5 py-3.5 text-right font-medium text-muted-foreground"
                                >
                                    Net à payer
                                </th>
                                <th
                                    class="px-5 py-3.5 text-right font-medium text-muted-foreground"
                                >
                                    Déjà payé
                                </th>
                                <th
                                    class="px-5 py-3.5 text-right font-medium text-muted-foreground"
                                >
                                    Reste à payer
                                </th>
                                <th
                                    class="px-5 py-3.5 text-left font-medium text-muted-foreground"
                                >
                                    Statut
                                </th>
                                <th class="w-10 px-4 py-3.5" />
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="b in beneficiaires"
                                :key="b.beneficiaire_id"
                                class="cursor-pointer transition-colors even:bg-muted/20 hover:bg-muted/10"
                                @click="
                                    router.visit(
                                        '/comptabilite/commissions/vente/livreurs/' +
                                            b.beneficiaire_id,
                                    )
                                "
                            >
                                <td class="px-5 py-3">
                                    <div class="flex items-center gap-2.5">
                                        <User
                                            class="h-4 w-4 shrink-0 text-muted-foreground"
                                        />
                                        <div>
                                            <p class="font-semibold">
                                                {{ b.beneficiaire_nom }}
                                            </p>
                                            <p
                                                v-if="b.telephone"
                                                class="mt-0.5 text-xs text-muted-foreground"
                                            >
                                                {{ fmtTel(b.telephone) }}
                                            </p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-3">
                                    <div
                                        v-if="b.vehicules"
                                        class="flex items-center gap-1.5 text-sm text-muted-foreground"
                                    >
                                        <Truck class="h-3.5 w-3.5 shrink-0" />
                                        <span>{{ b.vehicules }}</span>
                                    </div>
                                    <span
                                        v-else
                                        class="text-xs text-muted-foreground"
                                        >—</span
                                    >
                                </td>
                                <td class="px-5 py-3 text-sm">
                                    <div
                                        v-if="b.agence"
                                        class="flex items-center gap-1.5 text-muted-foreground"
                                    >
                                        <Building2
                                            class="h-3.5 w-3.5 shrink-0"
                                        />
                                        <span>{{ b.agence }}</span>
                                    </div>
                                    <span
                                        v-else
                                        class="text-xs text-muted-foreground"
                                        >—</span
                                    >
                                </td>
                                <td
                                    class="px-5 py-3 text-right text-muted-foreground tabular-nums"
                                >
                                    {{ fmt(b.total_brut_cumule) }}
                                </td>
                                <td
                                    class="px-5 py-3 text-right text-red-600 tabular-nums dark:text-red-400"
                                >
                                    {{
                                        b.total_frais > 0
                                            ? '-' + fmt(b.total_frais)
                                            : '—'
                                    }}
                                </td>
                                <td
                                    class="px-5 py-3 text-right text-muted-foreground tabular-nums"
                                >
                                    {{ fmt(b.total_net_cumule) }}
                                </td>
                                <td
                                    class="px-5 py-3 text-right text-muted-foreground tabular-nums"
                                >
                                    {{ fmt(b.total_verse) }}
                                </td>
                                <td
                                    class="px-5 py-3 text-right font-bold tabular-nums"
                                >
                                    {{ fmt(b.solde_restant) }}
                                </td>
                                <td class="px-5 py-3">
                                    <span
                                        class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium"
                                        :class="statutClass(b.statut_global)"
                                    >
                                        {{ statutLabel(b.statut_global) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right" @click.stop>
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                class="h-7 w-7"
                                            >
                                                <MoreHorizontal
                                                    class="h-4 w-4"
                                                />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            <DropdownMenuItem as-child>
                                                <Link
                                                    :href="`/comptabilite/commissions/vente/livreurs/${b.beneficiaire_id}`"
                                                    class="flex w-full cursor-pointer items-center"
                                                >
                                                    Détail
                                                </Link>
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                class="cursor-pointer"
                                                @click="openAudit(b)"
                                            >
                                                <History class="mr-2 h-4 w-4" />
                                                Historique
                                            </DropdownMenuItem>
                                            <template
                                                v-if="
                                                    can_payer &&
                                                    b.solde_restant > 0
                                                "
                                            >
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    class="cursor-pointer"
                                                    @click="openPaiement(b)"
                                                >
                                                    <HandCoins
                                                        class="mr-2 h-4 w-4"
                                                    />
                                                    Payer
                                                </DropdownMenuItem>
                                            </template>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div
                    v-else
                    class="flex flex-col items-center gap-3 py-16 text-muted-foreground"
                >
                    <HandCoins class="h-12 w-12 opacity-30" />
                    <p class="text-sm">Aucune commission trouvée.</p>
                </div>
            </div>
        </div>
    </AppLayout>

    <!-- Dialog paiement -->
    <Dialog
        v-model:visible="showPaiementDialog"
        modal
        :style="{ width: '420px' }"
        header="Enregistrer un paiement"
    >
        <div class="flex flex-col gap-4 py-2">
            <div class="flex flex-col gap-1.5">
                <Label>Montant (GNF)</Label>
                <InputNumber
                    v-model="paiementForm.montant"
                    :min="1"
                    :max="selectedBenef?.solde_restant ?? 0"
                    :use-grouping="true"
                    class="w-full"
                    input-class="w-full"
                    suffix=" GNF"
                    locale="fr-FR"
                    autofocus
                />
                <p
                    v-if="paiementForm.errors.montant"
                    class="text-xs text-destructive"
                >
                    {{ paiementForm.errors.montant }}
                </p>
                <p class="text-xs text-muted-foreground">
                    Disponible : {{ fmt(selectedBenef?.solde_restant ?? 0) }}
                </p>
            </div>
            <div class="flex flex-col gap-1.5">
                <Label>Mode de paiement</Label>
                <Dropdown
                    v-model="paiementForm.mode_paiement"
                    :options="MODES"
                    option-label="label"
                    option-value="value"
                    class="w-full text-sm"
                />
            </div>
            <div class="flex flex-col gap-1.5">
                <Label>Note (optionnel)</Label>
                <textarea
                    v-model="paiementForm.note"
                    rows="2"
                    class="w-full resize-none rounded-lg border border-input bg-background px-3 py-2 text-sm focus:ring-2 focus:ring-ring focus:outline-none"
                />
            </div>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    @click="showPaiementDialog = false"
                    >Annuler</Button
                >
                <Button
                    size="sm"
                    :disabled="paiementForm.processing || !paiementForm.montant"
                    @click="submitPaiement"
                >
                    {{
                        paiementForm.processing
                            ? 'Enregistrement…'
                            : 'Confirmer'
                    }}
                </Button>
            </div>
        </template>
    </Dialog>

    <AuditDrawer
        v-model:visible="showAudit"
        :title="`Historique — ${auditBenefNom}`"
        auditable-type="App\Models\Livreur"
        :auditable-id="auditBenefId"
        module="commissions_vente"
    />
</template>
