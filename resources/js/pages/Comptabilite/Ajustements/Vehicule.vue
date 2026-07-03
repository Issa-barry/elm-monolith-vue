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
    CheckCheck,
    Truck,
    UserMinus,
    UserPlus,
    Wrench,
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

// ── Dialog : ajuster le montant (agrégé sur toutes les commandes du bénéficiaire) ──

const showAjusterDialog = ref(false);
const ajusterTarget = ref<BeneficiaireRow | null>(null);
const ajusterMontant = ref(0);
const ajusterMotif = ref('correction');
const ajusterCommentaire = ref('');
const ajusterProcessing = ref(false);
const ajusterError = ref<string | null>(null);

function openAjuster(row: BeneficiaireRow) {
    ajusterTarget.value = row;
    ajusterMontant.value = row.ajuste;
    ajusterMotif.value = 'correction';
    ajusterCommentaire.value = '';
    ajusterError.value = null;
    showAjusterDialog.value = true;
}

function submitAjuster() {
    if (!ajusterTarget.value) return;
    const parts = ajusterTarget.value.parts.map((p) => ({
        type: p.type,
        id: p.id,
    }));

    ajusterProcessing.value = true;
    ajusterError.value = null;
    router.post(
        `${periodeUrl}/ajustements/ajuster-groupe`,
        {
            parts,
            montant: ajusterMontant.value,
            motif: ajusterMotif.value,
            commentaire: ajusterCommentaire.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                showAjusterDialog.value = false;
                flashToast('Montant ajusté.');
            },
            onError: (errors) => {
                ajusterError.value = Object.values(errors)[0] as string;
            },
            onFinish: () => {
                ajusterProcessing.value = false;
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

                    <Column header="" style="width: 130px">
                        <template #body="{ data }">
                            <div class="flex items-center justify-end gap-1.5">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    :disabled="!data.peut_etre_ajustee"
                                    @click="openAjuster(data)"
                                >
                                    <Wrench class="h-3.5 w-3.5" />
                                </Button>
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

        <!-- ── Dialog : ajuster le montant (agrégé) ──────────────────────────── -->
        <Dialog
            v-model:visible="showAjusterDialog"
            modal
            header="Ajuster le montant sur la période"
            :style="{ width: '480px' }"
            :draggable="false"
        >
            <div v-if="ajusterTarget" class="space-y-4 py-2">
                <p class="text-sm text-muted-foreground">
                    {{ ajusterTarget.beneficiaire_nom }} — Montant théorique de
                    la période :
                    <strong>{{ fmt(ajusterTarget.theorique) }}</strong>
                </p>

                <div>
                    <Label class="mb-1.5 block text-sm"
                        >Nouveau montant (GNF)</Label
                    >
                    <InputNumber
                        v-model="ajusterMontant"
                        :min="0"
                        class="w-full"
                        input-class="w-full"
                    />
                </div>

                <div>
                    <Label class="mb-1.5 block text-sm">Motif</Label>
                    <Dropdown
                        v-model="ajusterMotif"
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
                        v-model="ajusterCommentaire"
                        class="w-full"
                        rows="2"
                    />
                </div>
                <p v-if="ajusterError" class="text-xs text-destructive">
                    {{ ajusterError }}
                </p>
            </div>
            <template #footer>
                <Button
                    variant="outline"
                    :disabled="ajusterProcessing"
                    @click="showAjusterDialog = false"
                    >Annuler</Button
                >
                <Button :disabled="ajusterProcessing" @click="submitAjuster"
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
