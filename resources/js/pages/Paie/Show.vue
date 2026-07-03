<script setup lang="ts">
import PaymentDialogCompact from '@/components/PaymentDialogCompact.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { FilterMatchMode } from '@primevue/core/api';
import { BadgeCheck, Calculator, Lock, Plus, Trash2, X } from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Dialog from 'primevue/dialog';
import Select from 'primevue/select';
import Tag from 'primevue/tag';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { ref, watch } from 'vue';

interface Variable {
    id: string;
    type: string;
    libelle: string;
    montant: number;
    note: string | null;
}
interface Paiement {
    id: string;
    montant: number;
    date_paiement: string;
    mode_paiement: string;
    note: string | null;
}
interface Ligne {
    id: string;
    employe_id: string;
    employe_nom: string;
    employe_matricule: string;
    salaire_base: number;
    jours_travailles: number;
    jours_periode: number;
    total_primes: number;
    total_autres_gains: number;
    total_avances: number;
    total_retenues: number;
    total_absences: number;
    total_autres_deductions: number;
    brut: number;
    deductions: number;
    net: number;
    deja_paye: number;
    reste_a_payer: number;
    statut: string;
    statut_label: string;
    variables: Variable[];
    paiements: Paiement[];
}
interface Periode {
    id: string;
    mois: number;
    annee: number;
    label: string;
    statut: string;
    statut_label: string;
    est_verrouille: boolean;
    notes: string | null;
}
interface Can {
    update: boolean;
    validate: boolean;
    pay: boolean;
    close: boolean;
    delete: boolean;
}

const props = defineProps<{
    periode: Periode;
    lignes: Ligne[];
    transitions: string[];
    can: Can;
}>();

const confirm = useConfirm();
const toast = useToast();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Paie', href: '/backoffice/paie' },
    {
        title: props.periode.label,
        href: `/backoffice/paie/${props.periode.id}`,
    },
];

const globalFilter = ref('');
const filtersMeta = ref({
    global: { value: '', matchMode: FilterMatchMode.CONTAINS },
});
watch(globalFilter, (v) => {
    filtersMeta.value.global.value = v;
});

const expandedRows = ref<Record<string, boolean>>({});

// ── Actions workflow ──────────────────────────────────────────────────────────
function calculer() {
    router.post(
        `/backoffice/paie/${props.periode.id}/calculer`,
        {},
        {
            onSuccess: () =>
                toast.add({
                    severity: 'success',
                    summary: 'Calculé',
                    life: 3000,
                }),
        },
    );
}
function valider() {
    router.post(
        `/backoffice/paie/${props.periode.id}/valider`,
        {},
        {
            onSuccess: () =>
                toast.add({
                    severity: 'success',
                    summary: 'Validé RH',
                    life: 3000,
                }),
        },
    );
}
function marquerPaye() {
    router.post(
        `/backoffice/paie/${props.periode.id}/paye`,
        {},
        {
            onSuccess: () =>
                toast.add({
                    severity: 'success',
                    summary: 'Marqué payé',
                    life: 3000,
                }),
        },
    );
}
function cloturer() {
    confirm.require({
        message: 'Cette action est irréversible. Confirmer la clôture ?',
        header: 'Clôturer la période',
        accept: () =>
            router.post(
                `/backoffice/paie/${props.periode.id}/cloturer`,
                {},
                {
                    onSuccess: () =>
                        toast.add({
                            severity: 'success',
                            summary: 'Clôturé',
                            life: 3000,
                        }),
                },
            ),
    });
}
function supprimerPeriode() {
    confirm.require({
        message: 'Supprimer cette période de paie ?',
        header: 'Confirmation',
        accept: () => router.delete(`/backoffice/paie/${props.periode.id}`),
    });
}

// ── Modal variable ────────────────────────────────────────────────────────────
const showVariableModal = ref(false);
const selectedLigne = ref<Ligne | null>(null);
const editingVariable = ref<Variable | null>(null);

const typeOptions = [
    { value: 'prime', label: 'Prime' },
    { value: 'autre_gain', label: 'Autre gain' },
    { value: 'avance', label: 'Avance sur salaire' },
    { value: 'retenue', label: 'Retenue' },
    { value: 'absence', label: 'Absence' },
    { value: 'autre_deduction', label: 'Autre déduction' },
];

const varForm = useForm({ type: 'prime', libelle: '', montant: 0, note: '' });

function openVariableModal(ligne: Ligne, variable?: Variable) {
    selectedLigne.value = ligne;
    editingVariable.value = variable ?? null;
    if (variable) {
        varForm.type = variable.type;
        varForm.libelle = variable.libelle;
        varForm.montant = variable.montant;
        varForm.note = variable.note ?? '';
    } else {
        varForm.reset();
        varForm.type = 'prime';
    }
    showVariableModal.value = true;
}

function submitVariable() {
    if (editingVariable.value) {
        varForm.put(`/backoffice/paie-variables/${editingVariable.value.id}`, {
            onSuccess: () => {
                showVariableModal.value = false;
                varForm.reset();
            },
        });
    } else {
        varForm.post(
            `/backoffice/paie-lignes/${selectedLigne.value!.id}/variables`,
            {
                onSuccess: () => {
                    showVariableModal.value = false;
                    varForm.reset();
                },
            },
        );
    }
}

function supprimerVariable(variable: Variable) {
    confirm.require({
        message: `Supprimer la variable "${variable.libelle}" ?`,
        header: 'Confirmation',
        accept: () =>
            router.delete(`/backoffice/paie-variables/${variable.id}`, {
                onSuccess: () =>
                    toast.add({
                        severity: 'success',
                        summary: 'Variable supprimée',
                        life: 3000,
                    }),
            }),
    });
}

// ── Modal paiement (PaymentDialogCompact) ─────────────────────────────────────
const showPaiementModal = ref(false);
const paiementProcessing = ref(false);
const paiementErrors = ref<Record<string, string>>({});

function openPaiementModal(ligne: Ligne) {
    selectedLigne.value = ligne;
    paiementErrors.value = {};
    showPaiementModal.value = true;
}

function submitPaiement(payload: { montant: number; mode_paiement: string }) {
    if (!selectedLigne.value) return;
    paiementProcessing.value = true;
    paiementErrors.value = {};

    router.post(
        `/backoffice/paie-lignes/${selectedLigne.value.id}/paiements`,
        {
            montant: payload.montant,
            mode_paiement: payload.mode_paiement,
            date_paiement: new Date().toISOString().slice(0, 10),
        },
        {
            onSuccess: () => {
                showPaiementModal.value = false;
                paiementProcessing.value = false;
                toast.add({
                    severity: 'success',
                    summary: 'Paiement enregistré',
                    life: 3000,
                });
            },
            onError: (errors) => {
                paiementErrors.value = errors as Record<string, string>;
                paiementProcessing.value = false;
            },
        },
    );
}

function supprimerPaiement(paiement: Paiement) {
    confirm.require({
        message: 'Supprimer ce paiement ?',
        header: 'Confirmation',
        accept: () =>
            router.delete(`/backoffice/paie-paiements/${paiement.id}`, {
                onSuccess: () =>
                    toast.add({
                        severity: 'success',
                        summary: 'Paiement supprimé',
                        life: 3000,
                    }),
            }),
    });
}

// ── Helpers ───────────────────────────────────────────────────────────────────
const modePaiementLabels: Record<string, string> = {
    especes: 'Espèces',
    virement: 'Virement',
    cheque: 'Chèque',
    mobile_money: 'Mobile Money',
};

function statutSeverity(statut: string) {
    const map: Record<string, string> = {
        en_attente: 'secondary',
        calcule: 'info',
        partiellement_paye: 'warning',
        paye: 'success',
        brouillon: 'secondary',
        valide_rh: 'warning',
        cloture: 'contrast',
    };
    return map[statut] ?? 'secondary';
}

function fmt(n: number) {
    return new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 0 }).format(
        n,
    );
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`Paie — ${periode.label}`" />

        <div class="space-y-6 p-6">
            <!-- En-tête -->
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold">{{ periode.label }}</h1>
                    <Tag
                        :value="periode.statut_label"
                        :severity="statutSeverity(periode.statut)"
                        class="mt-1"
                    />
                    <p
                        v-if="periode.notes"
                        class="mt-1 text-sm text-muted-foreground"
                    >
                        {{ periode.notes }}
                    </p>
                </div>

                <!-- Actions workflow -->
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="can.update && transitions.includes('calcule')"
                        size="sm"
                        variant="outline"
                        @click="calculer"
                    >
                        <Calculator class="mr-1 h-4 w-4" /> Calculer
                    </Button>
                    <Button
                        v-if="can.validate && transitions.includes('valide_rh')"
                        size="sm"
                        variant="outline"
                        @click="valider"
                    >
                        <BadgeCheck class="mr-1 h-4 w-4" /> Valider RH
                    </Button>
                    <Button
                        v-if="can.pay && transitions.includes('paye')"
                        size="sm"
                        variant="outline"
                        @click="marquerPaye"
                    >
                        Marquer tout payé
                    </Button>
                    <Button
                        v-if="can.close && transitions.includes('cloture')"
                        size="sm"
                        variant="destructive"
                        @click="cloturer"
                    >
                        <Lock class="mr-1 h-4 w-4" /> Clôturer
                    </Button>
                    <Button
                        v-if="can.delete"
                        size="sm"
                        variant="ghost"
                        class="text-destructive"
                        @click="supprimerPeriode"
                    >
                        <Trash2 class="mr-1 h-4 w-4" /> Supprimer
                    </Button>
                </div>
            </div>

            <!-- Recherche employé -->
            <div class="flex items-center gap-3">
                <input
                    v-model="globalFilter"
                    type="text"
                    placeholder="Rechercher un employé (nom, matricule)…"
                    class="w-80 rounded-md border border-input bg-background px-3 py-1.5 text-sm shadow-sm placeholder:text-muted-foreground focus:ring-1 focus:ring-ring focus:outline-none"
                />
                <span class="text-sm text-muted-foreground"
                    >{{ lignes.length }} employé(s)</span
                >
            </div>

            <!-- Table lignes -->
            <DataTable
                :value="lignes"
                dataKey="id"
                :filters="filtersMeta"
                :global-filter-fields="[
                    'employe_nom',
                    'employe_matricule',
                    'statut_label',
                ]"
                v-model:expanded-rows="expandedRows"
                striped-rows
                class="text-sm"
            >
                <Column expander style="width: 3rem" />
                <Column
                    field="employe_matricule"
                    header="Matricule"
                    sortable
                    style="width: 8rem"
                />
                <Column field="employe_nom" header="Employé" sortable />
                <Column field="net" header="Net à payer" sortable>
                    <template #body="{ data }">
                        <span class="font-semibold">{{ fmt(data.net) }}</span>
                    </template>
                </Column>
                <Column field="deja_paye" header="Déjà payé" sortable>
                    <template #body="{ data }">{{
                        fmt(data.deja_paye)
                    }}</template>
                </Column>
                <Column field="reste_a_payer" header="Reste" sortable>
                    <template #body="{ data }">
                        <span
                            :class="
                                data.reste_a_payer > 0
                                    ? 'font-medium text-amber-600'
                                    : 'text-green-600'
                            "
                        >
                            {{ fmt(data.reste_a_payer) }}
                        </span>
                    </template>
                </Column>
                <Column field="statut_label" header="Statut">
                    <template #body="{ data }">
                        <Tag
                            :value="data.statut_label"
                            :severity="statutSeverity(data.statut)"
                        />
                    </template>
                </Column>
                <Column header="Payer" style="width: 7rem">
                    <template #body="{ data }">
                        <Button
                            v-if="can.pay && data.reste_a_payer > 0"
                            size="sm"
                            @click="openPaiementModal(data)"
                        >
                            Payer
                        </Button>
                        <span
                            v-else-if="data.reste_a_payer === 0"
                            class="text-xs text-green-600"
                            >✓ Soldé</span
                        >
                    </template>
                </Column>

                <!-- Ligne étendue : variables + historique paiements -->
                <template #expansion="{ data }">
                    <div class="grid grid-cols-1 gap-6 p-4 md:grid-cols-2">
                        <!-- Variables -->
                        <div>
                            <div class="mb-2 flex items-center justify-between">
                                <h3 class="font-semibold">Variables de paie</h3>
                                <Button
                                    v-if="!periode.est_verrouille && can.update"
                                    size="sm"
                                    variant="outline"
                                    @click="openVariableModal(data)"
                                >
                                    <Plus class="mr-1 h-3 w-3" /> Ajouter
                                </Button>
                            </div>
                            <div
                                v-if="data.variables.length === 0"
                                class="text-sm text-muted-foreground"
                            >
                                Aucune variable
                            </div>
                            <table v-else class="w-full text-sm">
                                <thead>
                                    <tr class="text-muted-foreground">
                                        <th class="pb-1 text-left font-normal">
                                            Type
                                        </th>
                                        <th class="pb-1 text-left font-normal">
                                            Libellé
                                        </th>
                                        <th class="pb-1 text-right font-normal">
                                            Montant
                                        </th>
                                        <th />
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="v in data.variables"
                                        :key="v.id"
                                        class="border-t"
                                    >
                                        <td class="py-1 pr-2 capitalize">
                                            {{ v.type }}
                                        </td>
                                        <td class="py-1 pr-2">
                                            {{ v.libelle }}
                                        </td>
                                        <td class="py-1 pr-2 text-right">
                                            {{ fmt(v.montant) }}
                                        </td>
                                        <td class="py-1">
                                            <div class="flex gap-1">
                                                <Button
                                                    v-if="
                                                        !periode.est_verrouille &&
                                                        can.update
                                                    "
                                                    size="icon"
                                                    variant="ghost"
                                                    class="h-6 w-6"
                                                    @click="
                                                        openVariableModal(
                                                            data,
                                                            v,
                                                        )
                                                    "
                                                >
                                                    <span class="text-xs"
                                                        >✎</span
                                                    >
                                                </Button>
                                                <Button
                                                    v-if="
                                                        !periode.est_verrouille &&
                                                        can.update
                                                    "
                                                    size="icon"
                                                    variant="ghost"
                                                    class="h-6 w-6 text-destructive"
                                                    @click="
                                                        supprimerVariable(v)
                                                    "
                                                >
                                                    <X class="h-3 w-3" />
                                                </Button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>

                            <!-- Récap rapide -->
                            <div
                                class="mt-3 grid grid-cols-3 gap-2 rounded-md bg-muted/40 p-2 text-xs"
                            >
                                <div>
                                    <div class="text-muted-foreground">
                                        Base
                                    </div>
                                    <div class="font-medium">
                                        {{ fmt(data.salaire_base) }}
                                    </div>
                                </div>
                                <div>
                                    <div class="text-muted-foreground">
                                        Brut
                                    </div>
                                    <div class="font-medium">
                                        {{ fmt(data.brut) }}
                                    </div>
                                </div>
                                <div>
                                    <div class="text-muted-foreground">Net</div>
                                    <div class="font-semibold text-primary">
                                        {{ fmt(data.net) }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Historique paiements -->
                        <div>
                            <div class="mb-2 flex items-center justify-between">
                                <h3 class="font-semibold">
                                    Historique paiements
                                </h3>
                                <Button
                                    v-if="can.pay && data.reste_a_payer > 0"
                                    size="sm"
                                    variant="outline"
                                    @click="openPaiementModal(data)"
                                >
                                    Payer
                                </Button>
                            </div>
                            <div
                                v-if="data.paiements.length === 0"
                                class="text-sm text-muted-foreground"
                            >
                                Aucun paiement
                            </div>
                            <table v-else class="w-full text-sm">
                                <thead>
                                    <tr class="text-muted-foreground">
                                        <th class="pb-1 text-left font-normal">
                                            Date
                                        </th>
                                        <th class="pb-1 text-left font-normal">
                                            Mode
                                        </th>
                                        <th class="pb-1 text-right font-normal">
                                            Montant
                                        </th>
                                        <th />
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="p in data.paiements"
                                        :key="p.id"
                                        class="border-t"
                                    >
                                        <td class="py-1 pr-2">
                                            {{ p.date_paiement }}
                                        </td>
                                        <td class="py-1 pr-2">
                                            {{
                                                modePaiementLabels[
                                                    p.mode_paiement
                                                ] ?? p.mode_paiement
                                            }}
                                        </td>
                                        <td
                                            class="py-1 pr-2 text-right font-medium"
                                        >
                                            {{ fmt(p.montant) }}
                                        </td>
                                        <td class="py-1">
                                            <Button
                                                v-if="can.pay"
                                                size="icon"
                                                variant="ghost"
                                                class="h-6 w-6 text-destructive"
                                                @click="supprimerPaiement(p)"
                                            >
                                                <X class="h-3 w-3" />
                                            </Button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </template>
            </DataTable>
        </div>

        <!-- Modal variable -->
        <Dialog
            v-model:visible="showVariableModal"
            modal
            :header="
                editingVariable
                    ? 'Modifier la variable'
                    : 'Ajouter une variable'
            "
            style="width: 30rem"
        >
            <form class="space-y-4" @submit.prevent="submitVariable">
                <div class="space-y-1">
                    <label class="text-sm font-medium">Type</label>
                    <Select
                        v-model="varForm.type"
                        :options="typeOptions"
                        option-label="label"
                        option-value="value"
                        class="w-full"
                    />
                    <p
                        v-if="varForm.errors.type"
                        class="text-xs text-destructive"
                    >
                        {{ varForm.errors.type }}
                    </p>
                </div>
                <div class="space-y-1">
                    <label class="text-sm font-medium">Libellé</label>
                    <input
                        v-model="varForm.libelle"
                        type="text"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:ring-1 focus:ring-ring focus:outline-none"
                    />
                    <p
                        v-if="varForm.errors.libelle"
                        class="text-xs text-destructive"
                    >
                        {{ varForm.errors.libelle }}
                    </p>
                </div>
                <div class="space-y-1">
                    <label class="text-sm font-medium">Montant</label>
                    <input
                        v-model.number="varForm.montant"
                        type="number"
                        step="0.01"
                        min="0.01"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:ring-1 focus:ring-ring focus:outline-none"
                    />
                    <p
                        v-if="varForm.errors.montant"
                        class="text-xs text-destructive"
                    >
                        {{ varForm.errors.montant }}
                    </p>
                </div>
                <div class="space-y-1">
                    <label class="text-sm font-medium"
                        >Note
                        <span class="text-muted-foreground"
                            >(optionnel)</span
                        ></label
                    >
                    <textarea
                        v-model="varForm.note"
                        rows="2"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:ring-1 focus:ring-ring focus:outline-none"
                    />
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <Button
                        type="button"
                        variant="outline"
                        @click="showVariableModal = false"
                        >Annuler</Button
                    >
                    <Button type="submit" :disabled="varForm.processing"
                        >Enregistrer</Button
                    >
                </div>
            </form>
        </Dialog>

        <!-- Modal paiement — réutilise PaymentDialogCompact comme les commissions -->
        <PaymentDialogCompact
            v-if="selectedLigne"
            v-model:visible="showPaiementModal"
            :title="`Payer — ${selectedLigne.employe_nom}`"
            :solde="selectedLigne.reste_a_payer"
            :processing="paiementProcessing"
            :errors="paiementErrors"
            @submit="submitPaiement"
        />
    </AppLayout>
</template>
