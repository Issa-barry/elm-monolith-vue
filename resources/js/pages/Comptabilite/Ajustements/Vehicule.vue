<script setup lang="ts">
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    ArrowUpDown,
    CheckCheck,
    Truck,
    UserMinus,
    UserPlus,
} from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import Textarea from 'primevue/textarea';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed, ref } from 'vue';

// ── Types ─────────────────────────────────────────────────────────────────────

interface PartRow {
    id: string;
    type: 'vente' | 'logistique';
    beneficiaire_nom: string;
    type_beneficiaire: string;
    origine: string;
    origine_label: string;
    montant_theorique: number;
    montant_actuel: number | null;
    montant_a_payer: number;
    ecart: number;
    statut: string | null;
    statut_label: string;
    est_validee: boolean;
    validateur_nom: string | null;
    validated_at: string | null;
    peut_etre_ajustee: boolean;
}

interface BeneficiaireRow {
    cle: string;
    type_beneficiaire: string;
    beneficiaire_nom: string;
    theorique: number;
    ajuste: number;
    ecart: number;
    est_validee: boolean;
    peut_etre_ajustee: boolean;
    parts: PartRow[];
}

interface Option {
    id: string;
    label?: string;
    nom?: string;
}

const props = defineProps<{
    periode: {
        id: string;
        reference: string;
        type: string;
        statut: string;
        date_debut: string | null;
        date_fin: string | null;
    };
    vehicule: {
        id: string | null;
        route_segment: string;
        nom: string;
        immat: string | null;
        theorique: number;
        ajuste: number;
        ecart: number;
    };
    beneficiaires: BeneficiaireRow[];
    filters: Record<string, string>;
    motifs: { value: string; label: string }[];
    commissions_vente: Option[];
    commissions_logistique: Option[];
    livreurs: Option[];
    proprietaires: Option[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité', href: '/backoffice/comptabilite' },
    { title: 'Périodes', href: '/backoffice/comptabilite/periodes' },
    {
        title: props.periode.reference,
        href: `/backoffice/comptabilite/periodes/${props.periode.id}`,
    },
    { title: props.vehicule.nom, href: '' },
];

const confirm = useConfirm();
const toast = useToast();
const page = usePage();

function fmt(n: number) {
    return new Intl.NumberFormat('fr-FR').format(Math.round(n)) + ' GNF';
}

function flashToast(fallback: string) {
    const flash = (page.props as any).flash;
    toast.add({
        severity: flash?.error ? 'warn' : 'success',
        summary: flash?.error ? 'Action impossible' : 'Succès',
        detail: flash?.error ?? flash?.success ?? fallback,
        life: 5000,
    });
}

const baseUrl = `/backoffice/comptabilite/periodes/${props.periode.id}/ajustements/vehicules/${props.vehicule.route_segment}`;
const periodeUrl = `/backoffice/comptabilite/periodes/${props.periode.id}`;

// ── Filtres ───────────────────────────────────────────────────────────────────

const filterFields: FilterField[] = [
    {
        key: 'beneficiaire',
        label: 'Bénéficiaire',
        type: 'text',
        inline: true,
        placeholder: 'Nom…',
    },
    {
        key: 'validation',
        label: 'Validation',
        type: 'select',
        inline: true,
        options: [
            { value: 'non_validee', label: 'Non validées' },
            { value: 'validee', label: 'Validées' },
        ],
    },
];

// ── Sélection multiple ────────────────────────────────────────────────────────

const selectedRows = ref<BeneficiaireRow[]>([]);

function validerLot() {
    if (selectedRows.value.length === 0) return;
    const parts = selectedRows.value.flatMap((row) =>
        row.parts
            .filter((p) => !p.est_validee)
            .map((p) => ({ type: p.type, id: p.id })),
    );
    if (parts.length === 0) return;

    confirm.require({
        message: `Valider ${selectedRows.value.length} bénéficiaire(s) sélectionné(s) ?`,
        header: 'Confirmer la validation',
        acceptLabel: 'Valider',
        rejectLabel: 'Annuler',
        accept: () => {
            router.post(
                `${periodeUrl}/ajustements/valider-lot`,
                { parts },
                {
                    preserveScroll: true,
                    onSuccess: () => {
                        selectedRows.value = [];
                        flashToast('Commissions validées.');
                    },
                },
            );
        },
    });
}

function validerBeneficiaire(row: BeneficiaireRow) {
    const parts = row.parts
        .filter((p) => !p.est_validee)
        .map((p) => ({ type: p.type, id: p.id }));
    if (parts.length === 0) return;

    router.post(
        `${periodeUrl}/ajustements/valider-lot`,
        { parts },
        {
            preserveScroll: true,
            onSuccess: () => flashToast('Commission validée.'),
        },
    );
}

// ── Dialog : répartition finale des commissions (tous les bénéficiaires du véhicule sur ──
// la période, en une seule action : montant ↔ % synchronisés, total toujours visible) ────

interface AjusterMultiRow {
    cle: string;
    beneficiaire_nom: string;
    theorique: number;
    original: number;
    montant: number;
    diminuer: number;
    augmenter: number;
    taux: number;
    peut_etre_ajustee: boolean;
    parts: PartRow[];
}

const showAjusterMultiDialog = ref(false);
const ajusterMultiRows = ref<AjusterMultiRow[]>([]);
const ajusterMultiMotif = ref('correction');
const ajusterMultiCommentaire = ref('');
const ajusterMultiProcessing = ref(false);
const ajusterMultiError = ref<string | null>(null);

function toTaux(montant: number, total: number): number {
    if (!total || total <= 0) return 0;
    return parseFloat(((montant / total) * 100).toFixed(2));
}

function toMontant(taux: number, total: number): number {
    return Math.round((taux / 100) * total);
}

const totalReparti = computed(() =>
    Math.round(
        ajusterMultiRows.value.reduce((sum, r) => sum + (r.montant || 0), 0) * 100,
    ) / 100,
);

const ajusterMultiEcartGlobal = computed(
    () => Math.round((props.vehicule.theorique - totalReparti.value) * 100) / 100,
);

const repartitionValide = computed(
    () => Math.abs(ajusterMultiEcartGlobal.value) < 0.01,
);

/** Recalcule montant final + % à partir de diminuer/augmenter (théorique − diminuer + augmenter). */
function syncFromDelta(row: AjusterMultiRow) {
    row.montant = row.theorique - row.diminuer + row.augmenter;
    row.taux = toTaux(row.montant, props.vehicule.theorique);
}

function onDiminuerChange(row: AjusterMultiRow, val: number | null) {
    row.diminuer = Math.max(0, val ?? 0);
    row.augmenter = 0;
    syncFromDelta(row);
}

function onAugmenterChange(row: AjusterMultiRow, val: number | null) {
    row.augmenter = Math.max(0, val ?? 0);
    row.diminuer = 0;
    syncFromDelta(row);
}

function onTauxRowChange(row: AjusterMultiRow, val: number | null) {
    row.taux = val ?? 0;
    const nouveauMontant = toMontant(row.taux, props.vehicule.theorique);
    const delta = nouveauMontant - row.theorique;
    if (delta >= 0) {
        row.augmenter = delta;
        row.diminuer = 0;
    } else {
        row.diminuer = -delta;
        row.augmenter = 0;
    }
    row.montant = nouveauMontant;
}

function openAjusterMulti() {
    ajusterMultiRows.value = props.beneficiaires.map((b) => {
        const delta = b.ajuste - b.theorique;
        return {
            cle: b.cle,
            beneficiaire_nom: b.beneficiaire_nom,
            theorique: b.theorique,
            original: b.ajuste,
            montant: b.ajuste,
            diminuer: delta < 0 ? -delta : 0,
            augmenter: delta > 0 ? delta : 0,
            taux: toTaux(b.ajuste, props.vehicule.theorique),
            peut_etre_ajustee: b.peut_etre_ajustee,
            parts: b.parts,
        };
    });
    ajusterMultiMotif.value = 'correction';
    ajusterMultiCommentaire.value = '';
    ajusterMultiError.value = null;
    showAjusterMultiDialog.value = true;
}

function submitAjusterMulti() {
    const groups = ajusterMultiRows.value
        .filter((r) => r.peut_etre_ajustee && r.montant !== r.original)
        .map((r) => ({
            label: r.beneficiaire_nom,
            parts: r.parts.map((p) => ({ type: p.type, id: p.id })),
            montant: r.montant,
        }));

    if (groups.length === 0) {
        showAjusterMultiDialog.value = false;
        return;
    }

    ajusterMultiProcessing.value = true;
    ajusterMultiError.value = null;
    router.post(
        `${periodeUrl}/ajustements/ajuster-multiple`,
        {
            groups,
            motif: ajusterMultiMotif.value,
            commentaire: ajusterMultiCommentaire.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                showAjusterMultiDialog.value = false;
                flashToast('Montants ajustés.');
            },
            onError: (errors) => {
                ajusterMultiError.value = Object.values(errors)[0] as string;
            },
            onFinish: () => {
                ajusterMultiProcessing.value = false;
            },
        },
    );
}

// ── Dialog : déclarer une absence (sur toutes les commandes du bénéficiaire) ──────

const showAbsenceDialog = ref(false);
const absenceTarget = ref<BeneficiaireRow | null>(null);
const absenceCommentaire = ref('');
const absenceProcessing = ref(false);

function openAbsence(row: BeneficiaireRow) {
    absenceTarget.value = row;
    absenceCommentaire.value = '';
    showAbsenceDialog.value = true;
}

function submitAbsence() {
    if (!absenceTarget.value) return;
    const parts = absenceTarget.value.parts
        .filter((p) => p.peut_etre_ajustee)
        .map((p) => ({ type: p.type, id: p.id }));
    if (parts.length === 0) {
        showAbsenceDialog.value = false;
        return;
    }

    absenceProcessing.value = true;
    router.post(
        `${periodeUrl}/ajustements/absence-groupe`,
        { parts, commentaire: absenceCommentaire.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                showAbsenceDialog.value = false;
                flashToast('Absence déclarée.');
            },
            onFinish: () => {
                absenceProcessing.value = false;
            },
        },
    );
}

// ── Dialog : ajouter un remplaçant ────────────────────────────────────────────

const showRemplacantDialog = ref(false);

const remplacantForm = useForm({
    commission_type: 'vente' as 'vente' | 'logistique',
    commission_id: '',
    type_beneficiaire: 'livreur' as 'livreur' | 'proprietaire',
    livreur_id: '',
    proprietaire_id: '',
    beneficiaire_nom: '',
    montant: 0,
    commentaire: '',
});

const commissionOptions = computed(() =>
    remplacantForm.commission_type === 'vente'
        ? props.commissions_vente
        : props.commissions_logistique,
);

const beneficiaireOptions = computed(() =>
    remplacantForm.type_beneficiaire === 'livreur'
        ? props.livreurs
        : props.proprietaires,
);

function openRemplacant() {
    remplacantForm.reset();
    remplacantForm.commission_type = 'vente';
    remplacantForm.type_beneficiaire = 'livreur';
    showRemplacantDialog.value = true;
}

function onBeneficiaireSelect(id: string) {
    const source =
        remplacantForm.type_beneficiaire === 'livreur'
            ? props.livreurs
            : props.proprietaires;
    const found = source.find((o) => o.id === id);
    remplacantForm.beneficiaire_nom = found?.nom ?? '';
}

function submitRemplacant() {
    remplacantForm.post(`${periodeUrl}/ajustements/remplacant`, {
        preserveScroll: true,
        onSuccess: () => {
            showRemplacantDialog.value = false;
            flashToast('Remplaçant ajouté.');
        },
    });
}
</script>

<template>
    <Head :title="`${vehicule.nom} — ${periode.reference}`" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-4 p-6">
            <!-- En-tête -->
            <div class="flex items-start justify-between">
                <div>
                    <div class="flex items-center gap-2">
                        <Link
                            :href="periodeUrl"
                            class="flex h-8 w-8 items-center justify-center rounded-full bg-muted text-muted-foreground hover:bg-muted/80"
                        >
                            <ArrowLeft class="h-4 w-4" />
                        </Link>
                        <Truck class="h-5 w-5 text-muted-foreground" />
                        <h1 class="text-xl font-semibold">
                            {{ vehicule.nom }}
                        </h1>
                        <span
                            v-if="vehicule.immat"
                            class="text-sm text-muted-foreground"
                            >({{ vehicule.immat }})</span
                        >
                    </div>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Période du {{ periode.date_debut }} au
                        {{ periode.date_fin }} — équipe globale du véhicule sur
                        la période
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <Button variant="outline" size="sm" @click="openAjusterMulti">
                        <ArrowUpDown class="mr-1.5 h-4 w-4" />
                        Répartir
                    </Button>
                    <Button variant="outline" size="sm" @click="openRemplacant">
                        <UserPlus class="mr-1.5 h-4 w-4" />
                        Ajouter un remplaçant
                    </Button>
                    <Button
                        size="sm"
                        :disabled="selectedRows.length === 0"
                        @click="validerLot"
                    >
                        <CheckCheck class="mr-1.5 h-4 w-4" />
                        Valider la sélection ({{ selectedRows.length }})
                    </Button>
                </div>
            </div>

            <!-- KPIs véhicule -->
            <div class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">
                        Commission totale
                    </p>
                    <p class="mt-1 text-lg font-bold tabular-nums">
                        {{ fmt(vehicule.theorique) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">Montant ajusté</p>
                    <p class="mt-1 text-lg font-bold tabular-nums">
                        {{ fmt(vehicule.ajuste) }}
                    </p>
                </div>
                <div
                    class="rounded-xl border bg-card p-4"
                    :class="
                        vehicule.ecart !== 0
                            ? 'border-red-200 dark:border-red-900'
                            : ''
                    "
                >
                    <p class="text-xs text-muted-foreground">
                        Reste à répartir
                    </p>
                    <p
                        class="mt-1 text-lg font-bold tabular-nums"
                        :class="
                            vehicule.ecart !== 0
                                ? 'text-red-600 dark:text-red-400'
                                : 'text-emerald-600 dark:text-emerald-400'
                        "
                    >
                        {{ vehicule.ecart > 0 ? '+' : ''
                        }}{{ fmt(vehicule.ecart) }}
                    </p>
                </div>
            </div>

            <div
                v-if="vehicule.ecart !== 0"
                class="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800/40 dark:bg-red-950/20 dark:text-red-300"
            >
                <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
                <p>
                    Redistribuez l'écart avant de pouvoir valider la période
                    (ajustez le montant d'un autre membre de l'équipe de ce
                    véhicule sur la période).
                </p>
            </div>

            <!-- Filtres -->
            <DataFilters
                :url="baseUrl"
                :values="filters"
                :fields="filterFields"
                :result-count="beneficiaires.length"
                hide-agence-selector
            />

            <!-- Équipe globale du véhicule sur la période : 1 ligne par bénéficiaire -->
            <div class="overflow-x-auto rounded-xl border bg-card">
                <DataTable
                    :value="beneficiaires"
                    data-key="cle"
                    striped-rows
                    removable-sort
                    class="text-sm"
                    :pt="{
                        root: { class: 'w-full min-w-[900px]' },
                        tbody: { class: 'divide-y' },
                    }"
                >
                    <Column style="width: 2.5rem">
                        <template #body="{ data }">
                            <input
                                type="checkbox"
                                :checked="selectedRows.includes(data)"
                                :disabled="data.est_validee"
                                class="cursor-pointer"
                                @change="
                                    () => {
                                        if (data.est_validee) return;
                                        const idx = selectedRows.indexOf(data);
                                        if (idx === -1) selectedRows.push(data);
                                        else selectedRows.splice(idx, 1);
                                    }
                                "
                            />
                        </template>
                    </Column>

                    <Column
                        field="beneficiaire_nom"
                        header="Bénéficiaire"
                        sortable
                        style="min-width: 200px"
                    >
                        <template #body="{ data }">
                            <span class="font-medium">{{
                                data.beneficiaire_nom
                            }}</span>
                        </template>
                    </Column>

                    <Column
                        field="theorique"
                        header="Théorique période"
                        sortable
                        style="width: 200px"
                    >
                        <template #body="{ data }">
                            <span class="tabular-nums">{{
                                fmt(data.theorique)
                            }}</span>
                        </template>
                    </Column>

                    <Column
                        field="ajuste"
                        header="Ajusté période"
                        sortable
                        style="width: 150px"
                    >
                        <template #body="{ data }">
                            <span
                                class="text-sm font-semibold tabular-nums"
                                :class="
                                    data.ecart !== 0
                                        ? 'text-amber-600 dark:text-amber-400'
                                        : ''
                                "
                            >
                                {{ fmt(data.ajuste) }}
                            </span>
                        </template>
                    </Column>

                    <Column
                        field="ecart"
                        header="Écart"
                        sortable
                        style="width: 130px"
                    >
                        <template #body="{ data }">
                            <span
                                v-if="data.ecart !== 0"
                                class="text-sm font-semibold tabular-nums"
                                :class="
                                    data.ecart > 0
                                        ? 'text-emerald-600 dark:text-emerald-400'
                                        : 'text-red-600 dark:text-red-400'
                                "
                            >
                                {{ data.ecart > 0 ? '+' : ''
                                }}{{ fmt(data.ecart) }}
                            </span>
                            <span
                                v-else
                                class="text-sm text-muted-foreground tabular-nums"
                                >—</span
                            >
                        </template>
                    </Column>

                    <Column
                        field="est_validee"
                        header="Validation"
                        sortable
                        style="width: 150px"
                    >
                        <template #body="{ data }">
                            <StatusDot
                                :status="
                                    data.est_validee ? 'validee' : 'en_attente'
                                "
                                :label="
                                    data.est_validee ? 'Validée' : 'À valider'
                                "
                            />
                        </template>
                    </Column>

                    <Column header="" style="width: 100px">
                        <template #body="{ data }">
                            <div class="flex items-center justify-end gap-1.5">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    :disabled="!data.peut_etre_ajustee"
                                    @click="openAbsence(data)"
                                >
                                    <UserMinus class="h-3.5 w-3.5" />
                                </Button>
                                <Button
                                    size="sm"
                                    :disabled="data.est_validee"
                                    @click="validerBeneficiaire(data)"
                                >
                                    <CheckCheck class="h-3.5 w-3.5" />
                                </Button>
                            </div>
                        </template>
                    </Column>
                </DataTable>
            </div>

            <div
                v-if="beneficiaires.length === 0"
                class="rounded-xl border bg-card py-16 text-center text-sm text-muted-foreground"
            >
                Aucun bénéficiaire ne correspond à ces filtres.
            </div>
        </div>

        <!-- ── Dialog : répartition finale des commissions ───────────────────── -->
        <Dialog
            v-model:visible="showAjusterMultiDialog"
            modal
            header="Répartition finale des commissions"
            :style="{ width: 'min(1300px, 90vw)' }"
            :draggable="false"
        >
            <div class="space-y-5 py-2">
                <div
                    class="flex items-center justify-between rounded-lg border bg-muted/30 p-4"
                >
                    <span class="text-sm text-muted-foreground"
                        >Gain total de la période</span
                    >
                    <span class="text-xl font-bold tabular-nums">{{
                        fmt(vehicule.theorique)
                    }}</span>
                </div>

                <div class="max-h-[480px] overflow-y-auto rounded-lg border">
                    <table class="w-full text-sm">
                        <thead
                            class="sticky top-0 bg-muted/50 text-xs text-muted-foreground"
                        >
                            <tr>
                                <th class="px-4 py-3 text-left">Bénéficiaire</th>
                                <th class="px-4 py-3 text-right">Théorique</th>
                                <th class="px-4 py-3 text-right">Diminuer</th>
                                <th class="px-4 py-3 text-right">Augmenter</th>
                                <th class="px-4 py-3 text-right">Final</th>
                                <th class="px-4 py-3 text-right">%</th>
                                <th class="px-4 py-3 text-right">Écart</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="row in ajusterMultiRows" :key="row.cle">
                                <td class="px-4 py-3">
                                    <span class="font-medium">{{
                                        row.beneficiaire_nom
                                    }}</span>
                                    <span
                                        v-if="!row.peut_etre_ajustee"
                                        class="ml-1.5 text-xs text-muted-foreground"
                                        >(déjà versé)</span
                                    >
                                </td>
                                <td
                                    class="px-4 py-3 text-right tabular-nums text-muted-foreground"
                                >
                                    {{ fmt(row.theorique) }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <InputNumber
                                        :model-value="row.diminuer"
                                        :min="0"
                                        :disabled="!row.peut_etre_ajustee"
                                        class="w-32"
                                        input-class="w-32 text-right"
                                        @update:model-value="
                                            onDiminuerChange(row, $event)
                                        "
                                    />
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <InputNumber
                                        :model-value="row.augmenter"
                                        :min="0"
                                        :disabled="!row.peut_etre_ajustee"
                                        class="w-32"
                                        input-class="w-32 text-right"
                                        @update:model-value="
                                            onAugmenterChange(row, $event)
                                        "
                                    />
                                </td>
                                <td
                                    class="px-4 py-3 text-right font-semibold tabular-nums"
                                >
                                    {{ fmt(row.montant) }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <InputNumber
                                        :model-value="row.taux"
                                        :min="0"
                                        :max="100"
                                        :max-fraction-digits="2"
                                        suffix=" %"
                                        :disabled="!row.peut_etre_ajustee"
                                        class="w-28"
                                        input-class="w-28 text-right"
                                        @update:model-value="
                                            onTauxRowChange(row, $event)
                                        "
                                    />
                                </td>
                                <td
                                    class="px-4 py-3 text-right text-sm font-medium tabular-nums"
                                    :class="
                                        row.montant - row.theorique > 0
                                            ? 'text-emerald-600 dark:text-emerald-400'
                                            : row.montant - row.theorique < 0
                                              ? 'text-red-600 dark:text-red-400'
                                              : 'text-muted-foreground'
                                    "
                                >
                                    {{ row.montant - row.theorique > 0 ? '+' : ''
                                    }}{{ fmt(row.montant - row.theorique) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    class="flex items-center justify-between rounded-lg border p-3 text-sm"
                    :class="
                        repartitionValide
                            ? 'border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/20'
                            : 'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/20'
                    "
                >
                    <span>
                        Total réparti :
                        <strong class="tabular-nums"
                            >{{ fmt(totalReparti) }} /
                            {{ fmt(vehicule.theorique) }}</strong
                        >
                    </span>
                    <span
                        class="font-semibold tabular-nums"
                        :class="
                            repartitionValide
                                ? 'text-emerald-600 dark:text-emerald-400'
                                : 'text-red-600 dark:text-red-400'
                        "
                    >
                        <template v-if="repartitionValide">✓ Complet</template>
                        <template v-else-if="ajusterMultiEcartGlobal > 0"
                            >Il manque
                            {{ fmt(ajusterMultiEcartGlobal) }}</template
                        >
                        <template v-else
                            >Vous dépassez de
                            {{ fmt(-ajusterMultiEcartGlobal) }}</template
                        >
                    </span>
                </div>

                <div>
                    <Label class="mb-1.5 block text-sm">Motif</Label>
                    <Dropdown
                        v-model="ajusterMultiMotif"
                        :options="motifs"
                        option-label="label"
                        option-value="value"
                        class="w-full"
                    />
                </div>
                <div>
                    <Label class="mb-1.5 block text-sm"
                        >Commentaire (optionnel)</Label
                    >
                    <Textarea
                        v-model="ajusterMultiCommentaire"
                        class="w-full"
                        rows="2"
                    />
                </div>
                <p v-if="ajusterMultiError" class="text-xs text-destructive">
                    {{ ajusterMultiError }}
                </p>
            </div>
            <template #footer>
                <Button
                    variant="outline"
                    :disabled="ajusterMultiProcessing"
                    @click="showAjusterMultiDialog = false"
                    >Annuler</Button
                >
                <Button
                    :disabled="ajusterMultiProcessing || !repartitionValide"
                    @click="submitAjusterMulti"
                    >Enregistrer</Button
                >
            </template>
        </Dialog>

        <!-- ── Dialog : déclarer une absence (agrégée) ───────────────────────── -->
        <Dialog
            v-model:visible="showAbsenceDialog"
            modal
            header="Déclarer une absence"
            :style="{ width: '440px' }"
            :draggable="false"
        >
            <div v-if="absenceTarget" class="space-y-4 py-2">
                <p class="text-sm text-muted-foreground">
                    {{ absenceTarget.beneficiaire_nom }} — le montant sera mis à
                    0 GNF sur les
                    {{
                        absenceTarget.parts.filter((p) => p.peut_etre_ajustee)
                            .length
                    }}
                    commande(s) de ce véhicule pour cette période.
                </p>
                <div>
                    <Label class="mb-1.5 block text-sm"
                        >Commentaire (optionnel)</Label
                    >
                    <Textarea
                        v-model="absenceCommentaire"
                        class="w-full"
                        rows="2"
                    />
                </div>
            </div>
            <template #footer>
                <Button
                    variant="outline"
                    :disabled="absenceProcessing"
                    @click="showAbsenceDialog = false"
                    >Annuler</Button
                >
                <Button :disabled="absenceProcessing" @click="submitAbsence"
                    >Confirmer l'absence</Button
                >
            </template>
        </Dialog>

        <!-- ── Dialog : ajouter un remplaçant ────────────────────────────────── -->
        <Dialog
            v-model:visible="showRemplacantDialog"
            modal
            header="Ajouter un remplaçant"
            :style="{ width: '480px' }"
            :draggable="false"
        >
            <div class="space-y-4 py-2">
                <div>
                    <Label class="mb-1.5 block text-sm"
                        >Type de commission</Label
                    >
                    <Dropdown
                        v-model="remplacantForm.commission_type"
                        :options="[
                            { value: 'vente', label: 'Vente' },
                            { value: 'logistique', label: 'Logistique' },
                        ]"
                        option-label="label"
                        option-value="value"
                        class="w-full"
                        @change="remplacantForm.commission_id = ''"
                    />
                </div>
                <div>
                    <Label class="mb-1.5 block text-sm"
                        >Commande / Transfert</Label
                    >
                    <Dropdown
                        v-model="remplacantForm.commission_id"
                        :options="commissionOptions"
                        option-label="label"
                        option-value="id"
                        placeholder="Sélectionner…"
                        class="w-full"
                    />
                    <p
                        v-if="remplacantForm.errors.commission_id"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ remplacantForm.errors.commission_id }}
                    </p>
                </div>
                <div>
                    <Label class="mb-1.5 block text-sm"
                        >Type de bénéficiaire</Label
                    >
                    <Dropdown
                        v-model="remplacantForm.type_beneficiaire"
                        :options="[
                            { value: 'livreur', label: 'Livreur' },
                            { value: 'proprietaire', label: 'Propriétaire' },
                        ]"
                        option-label="label"
                        option-value="value"
                        class="w-full"
                        @change="
                            () => {
                                remplacantForm.livreur_id = '';
                                remplacantForm.proprietaire_id = '';
                                remplacantForm.beneficiaire_nom = '';
                            }
                        "
                    />
                </div>
                <div>
                    <Label class="mb-1.5 block text-sm">Bénéficiaire</Label>
                    <Dropdown
                        :model-value="
                            remplacantForm.type_beneficiaire === 'livreur'
                                ? remplacantForm.livreur_id
                                : remplacantForm.proprietaire_id
                        "
                        :options="beneficiaireOptions"
                        option-label="nom"
                        option-value="id"
                        placeholder="Sélectionner…"
                        class="w-full"
                        @update:model-value="
                            (id: string) => {
                                if (
                                    remplacantForm.type_beneficiaire ===
                                    'livreur'
                                ) {
                                    remplacantForm.livreur_id = id;
                                } else {
                                    remplacantForm.proprietaire_id = id;
                                }
                                onBeneficiaireSelect(id);
                            }
                        "
                    />
                    <p
                        v-if="
                            remplacantForm.errors.livreur_id ||
                            remplacantForm.errors.proprietaire_id
                        "
                        class="mt-1 text-xs text-destructive"
                    >
                        {{
                            remplacantForm.errors.livreur_id ??
                            remplacantForm.errors.proprietaire_id
                        }}
                    </p>
                </div>
                <div>
                    <Label class="mb-1.5 block text-sm">Montant (GNF)</Label>
                    <InputNumber
                        v-model="remplacantForm.montant"
                        :min="0"
                        class="w-full"
                        input-class="w-full"
                    />
                    <p
                        v-if="remplacantForm.errors.montant"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ remplacantForm.errors.montant }}
                    </p>
                </div>
                <div>
                    <Label class="mb-1.5 block text-sm"
                        >Commentaire (optionnel)</Label
                    >
                    <Textarea
                        v-model="remplacantForm.commentaire"
                        class="w-full"
                        rows="2"
                    />
                </div>
            </div>
            <template #footer>
                <Button
                    variant="outline"
                    :disabled="remplacantForm.processing"
                    @click="showRemplacantDialog = false"
                    >Annuler</Button
                >
                <Button
                    :disabled="remplacantForm.processing"
                    @click="submitRemplacant"
                    >Ajouter</Button
                >
            </template>
        </Dialog>
    </AppLayout>
</template>
