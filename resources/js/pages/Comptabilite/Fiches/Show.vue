<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { FileDown, Minus, Plus } from 'lucide-vue-next';
import Dropdown from 'primevue/dropdown';
import { useToast } from 'primevue/usetoast';
import { computed, ref } from 'vue';

interface Ligne {
    id: string;
    type_ligne: string;
    type_label: string;
    libelle: string;
    montant: number;
    is_gain: boolean;
    is_deduction: boolean;
}

interface HistoriquePaiement {
    id: string;
    montant: number;
    mode_paiement: string;
    date_paiement: string | null;
    note: string | null;
    createur: string | null;
}

interface Fiche {
    id: string;
    reference: string;
    beneficiaire_type: string;
    beneficiaire_nom: string;
    site: { id: string; nom: string } | null;
    periode: {
        id: string;
        reference: string;
        date_debut: string | null;
        date_fin: string | null;
    } | null;
    montant_brut: number;
    total_deductions: number;
    montant_net: number;
    montant_paye: number;
    montant_restant: number;
    statut: string;
    statut_label: string;
    mode_paiement: string | null;
    date_paiement: string | null;
    commentaires: string | null;
    signature_path: string | null;
    lignes: Ligne[];
    historique: HistoriquePaiement[];
}

interface Option {
    value: string;
    label: string;
}

const props = defineProps<{
    fiche: Fiche;
    modes_paiement: Option[];
    can_payer: boolean;
}>();

const typeLabel = {
    livreur: 'Livreur',
    proprietaire: 'Propriétaire',
    salarie: 'Salarié',
};

const typeRoute = {
    livreur: '/comptabilite/fiches/livreurs',
    proprietaire: '/comptabilite/fiches/proprietaires',
    salarie: '/comptabilite/fiches/salaries',
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Comptabilité', href: '/comptabilite' },
    {
        title:
            typeLabel[
                props.fiche.beneficiaire_type as keyof typeof typeLabel
            ] ?? 'Fiches',
        href:
            typeRoute[
                props.fiche.beneficiaire_type as keyof typeof typeRoute
            ] ?? '/comptabilite',
    },
    {
        title: props.fiche.reference,
        href: `/comptabilite/fiches/${props.fiche.id}`,
    },
];

const toast = useToast();

const paiementForm = ref({
    montant: props.fiche.montant_restant,
    mode_paiement: '',
    date_paiement: new Date().toISOString().split('T')[0],
    note: '',
});
const submittingPaiement = ref(false);
const paiementErrors = ref<Record<string, string>>({});

function fmt(n: number) {
    return new Intl.NumberFormat('fr-FR').format(Math.round(n)) + ' GNF';
}

const ficheBadge = (s: string) =>
    ({
        a_payer: 'bg-red-100 text-red-700 dark:bg-red-950/30 dark:text-red-400',
        partiellement_paye:
            'bg-amber-100 text-amber-700 dark:bg-amber-950/30 dark:text-amber-400',
        paye: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-400',
    })[s] ?? 'bg-muted text-muted-foreground';

const progressPct = computed(() => {
    if (!props.fiche.montant_net) return 0;
    return Math.min(
        100,
        Math.round((props.fiche.montant_paye / props.fiche.montant_net) * 100),
    );
});

const gains = computed(() => props.fiche.lignes.filter((l) => l.is_gain));
const deductions = computed(() =>
    props.fiche.lignes.filter((l) => l.is_deduction),
);

function submitPaiement() {
    submittingPaiement.value = true;
    paiementErrors.value = {};
    router.post(
        `/comptabilite/fiches/${props.fiche.id}/paiements`,
        paiementForm.value,
        {
            onError: (e) => {
                paiementErrors.value = e;
                submittingPaiement.value = false;
            },
            onSuccess: () => {
                toast.add({
                    severity: 'success',
                    summary: 'Paiement enregistré',
                    life: 3000,
                });
                submittingPaiement.value = false;
                paiementForm.value.note = '';
            },
            onFinish: () => {
                submittingPaiement.value = false;
            },
        },
    );
}

function exportPdf() {
    window.open(`/comptabilite/fiches/${props.fiche.id}/pdf`, '_blank');
}
</script>

<template>
    <Head :title="`Fiche ${fiche.reference}`" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex max-w-3xl flex-col gap-6 p-6">
            <!-- Header fiche -->
            <div class="flex items-start justify-between">
                <div>
                    <div class="flex items-center gap-3">
                        <h1 class="font-mono text-xl font-semibold">
                            {{ fiche.reference }}
                        </h1>
                        <span
                            class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                            :class="ficheBadge(fiche.statut)"
                        >
                            {{ fiche.statut_label }}
                        </span>
                    </div>
                    <p class="mt-1 text-sm font-medium">
                        {{ fiche.beneficiaire_nom }}
                    </p>
                    <div
                        class="mt-1 flex flex-wrap gap-3 text-xs text-muted-foreground"
                    >
                        <span v-if="fiche.site"
                            >Agence : {{ fiche.site.nom }}</span
                        >
                        <span v-if="fiche.periode">
                            Période : {{ fiche.periode.reference }} ({{
                                fiche.periode.date_debut
                            }}
                            → {{ fiche.periode.date_fin }})
                        </span>
                    </div>
                </div>
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-lg border bg-card px-3 py-2 text-sm hover:bg-muted/50"
                    @click="exportPdf"
                >
                    <FileDown class="h-4 w-4" />
                    Imprimer PDF
                </button>
            </div>

            <!-- Montants résumé -->
            <div class="grid grid-cols-3 gap-3">
                <div class="rounded-xl border bg-card p-4 text-center">
                    <p class="text-xs text-muted-foreground">Gains</p>
                    <p
                        class="mt-1 text-lg font-bold text-emerald-600 dark:text-emerald-400"
                    >
                        {{ fmt(fiche.montant_brut) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4 text-center">
                    <p class="text-xs text-muted-foreground">Déductions</p>
                    <p
                        class="mt-1 text-lg font-bold text-red-600 dark:text-red-400"
                    >
                        -{{ fmt(fiche.total_deductions) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4 text-center">
                    <p class="text-xs text-muted-foreground">Net à payer</p>
                    <p class="mt-1 text-lg font-bold">
                        {{ fmt(fiche.montant_net) }}
                    </p>
                </div>
            </div>

            <!-- Détail lignes -->
            <div class="overflow-hidden rounded-xl border bg-card">
                <div class="border-b px-5 py-3">
                    <h2 class="text-sm font-semibold">Détail du calcul</h2>
                </div>

                <!-- Gains -->
                <div v-if="gains.length" class="divide-y divide-border/50">
                    <div
                        v-for="ligne in gains"
                        :key="ligne.id"
                        class="flex items-center justify-between px-5 py-3"
                    >
                        <div class="flex items-center gap-2">
                            <span
                                class="flex h-5 w-5 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-950/30"
                            >
                                <Plus
                                    class="h-3 w-3 text-emerald-600 dark:text-emerald-400"
                                />
                            </span>
                            <div>
                                <p class="text-sm">{{ ligne.libelle }}</p>
                                <p class="text-xs text-muted-foreground">
                                    {{ ligne.type_label }}
                                </p>
                            </div>
                        </div>
                        <span
                            class="text-sm font-medium text-emerald-600 tabular-nums dark:text-emerald-400"
                        >
                            +{{ fmt(ligne.montant) }}
                        </span>
                    </div>
                </div>

                <!-- Séparateur -->
                <div v-if="deductions.length" class="border-t border-dashed" />

                <!-- Déductions -->
                <div v-if="deductions.length" class="divide-y divide-border/50">
                    <div
                        v-for="ligne in deductions"
                        :key="ligne.id"
                        class="flex items-center justify-between px-5 py-3"
                    >
                        <div class="flex items-center gap-2">
                            <span
                                class="flex h-5 w-5 items-center justify-center rounded-full bg-red-100 dark:bg-red-950/30"
                            >
                                <Minus
                                    class="h-3 w-3 text-red-600 dark:text-red-400"
                                />
                            </span>
                            <div>
                                <p class="text-sm">{{ ligne.libelle }}</p>
                                <p class="text-xs text-muted-foreground">
                                    {{ ligne.type_label }}
                                </p>
                            </div>
                        </div>
                        <span
                            class="text-sm font-medium text-red-600 tabular-nums dark:text-red-400"
                        >
                            {{ fmt(ligne.montant) }}
                        </span>
                    </div>
                </div>

                <!-- Total -->
                <div
                    class="flex items-center justify-between border-t bg-muted/30 px-5 py-3"
                >
                    <span class="text-sm font-semibold">Net à payer</span>
                    <span class="text-base font-bold">{{
                        fmt(fiche.montant_net)
                    }}</span>
                </div>
            </div>

            <!-- Barre de progression paiement -->
            <div
                v-if="fiche.montant_net > 0"
                class="rounded-xl border bg-card p-5"
            >
                <div class="flex items-center justify-between text-sm">
                    <span class="text-muted-foreground"
                        >Avancement du paiement</span
                    >
                    <span class="font-semibold">{{ progressPct }}%</span>
                </div>
                <div class="mt-2 h-2 overflow-hidden rounded-full bg-muted">
                    <div
                        class="h-full rounded-full bg-emerald-500 transition-all duration-300"
                        :style="{ width: progressPct + '%' }"
                    />
                </div>
                <div
                    class="mt-2 flex justify-between text-xs text-muted-foreground"
                >
                    <span>Payé : {{ fmt(fiche.montant_paye) }}</span>
                    <span>Reste : {{ fmt(fiche.montant_restant) }}</span>
                </div>
            </div>

            <!-- Formulaire paiement -->
            <div
                v-if="can_payer && fiche.statut !== 'paye'"
                class="rounded-xl border bg-card p-5"
            >
                <h2 class="mb-4 text-sm font-semibold">
                    Enregistrer un paiement
                </h2>
                <form
                    class="flex flex-col gap-4"
                    @submit.prevent="submitPaiement"
                >
                    <div class="grid grid-cols-2 gap-4">
                        <div class="flex flex-col gap-1.5">
                            <label
                                class="text-xs font-medium text-muted-foreground"
                                >Montant (GNF)</label
                            >
                            <input
                                v-model.number="paiementForm.montant"
                                type="number"
                                :max="fiche.montant_restant"
                                min="1"
                                class="h-10 rounded-lg border border-input bg-background px-3 text-sm font-semibold focus:ring-2 focus:ring-ring focus:outline-none"
                            />
                            <p
                                v-if="paiementErrors.montant"
                                class="text-xs text-destructive"
                            >
                                {{ paiementErrors.montant }}
                            </p>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label
                                class="text-xs font-medium text-muted-foreground"
                                >Mode de paiement</label
                            >
                            <Dropdown
                                v-model="paiementForm.mode_paiement"
                                :options="modes_paiement"
                                option-label="label"
                                option-value="value"
                                placeholder="Sélectionner…"
                                class="w-full"
                            />
                            <p
                                v-if="paiementErrors.mode_paiement"
                                class="text-xs text-destructive"
                            >
                                {{ paiementErrors.mode_paiement }}
                            </p>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="flex flex-col gap-1.5">
                            <label
                                class="text-xs font-medium text-muted-foreground"
                                >Date du paiement</label
                            >
                            <input
                                v-model="paiementForm.date_paiement"
                                type="date"
                                class="h-10 rounded-lg border border-input bg-background px-3 text-sm focus:ring-2 focus:ring-ring focus:outline-none"
                            />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label
                                class="text-xs font-medium text-muted-foreground"
                                >Note (optionnel)</label
                            >
                            <input
                                v-model="paiementForm.note"
                                type="text"
                                placeholder="Observation…"
                                class="h-10 rounded-lg border border-input bg-background px-3 text-sm focus:ring-2 focus:ring-ring focus:outline-none"
                            />
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <button
                            type="submit"
                            :disabled="submittingPaiement"
                            class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground transition-opacity disabled:opacity-60"
                        >
                            {{
                                submittingPaiement
                                    ? 'Enregistrement…'
                                    : 'Enregistrer le paiement'
                            }}
                        </button>
                    </div>
                </form>
            </div>

            <!-- Historique paiements -->
            <div
                v-if="fiche.historique.length"
                class="overflow-hidden rounded-xl border bg-card"
            >
                <div class="border-b px-5 py-3">
                    <h2 class="text-sm font-semibold">
                        Historique des paiements
                    </h2>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-xs text-muted-foreground">
                            <th class="px-5 py-2.5 text-left font-medium">
                                Date
                            </th>
                            <th class="px-5 py-2.5 text-left font-medium">
                                Montant
                            </th>
                            <th class="px-5 py-2.5 text-left font-medium">
                                Mode
                            </th>
                            <th class="px-5 py-2.5 text-left font-medium">
                                Par
                            </th>
                            <th class="px-5 py-2.5 text-left font-medium">
                                Note
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/50">
                        <tr v-for="p in fiche.historique" :key="p.id">
                            <td class="px-5 py-2.5 font-mono text-xs">
                                {{ p.date_paiement ?? '—' }}
                            </td>
                            <td
                                class="px-5 py-2.5 font-semibold text-emerald-600 tabular-nums dark:text-emerald-400"
                            >
                                {{ fmt(p.montant) }}
                            </td>
                            <td class="px-5 py-2.5 text-muted-foreground">
                                {{ p.mode_paiement ?? '—' }}
                            </td>
                            <td class="px-5 py-2.5 text-muted-foreground">
                                {{ p.createur ?? '—' }}
                            </td>
                            <td
                                class="px-5 py-2.5 text-xs text-muted-foreground"
                            >
                                {{ p.note ?? '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Zone signature -->
            <div class="rounded-xl border bg-card p-5">
                <h2 class="mb-4 text-sm font-semibold">Signatures</h2>
                <div class="grid grid-cols-2 gap-8">
                    <div class="flex flex-col items-center gap-2">
                        <div
                            v-if="fiche.signature_path"
                            class="h-24 w-full overflow-hidden rounded border"
                        >
                            <img
                                :src="fiche.signature_path"
                                alt="Signature"
                                class="h-full w-full object-contain"
                            />
                        </div>
                        <div
                            v-else
                            class="flex h-16 w-full items-center justify-center rounded border border-dashed text-xs text-muted-foreground"
                        >
                            À signer
                        </div>
                        <div class="h-px w-full bg-border" />
                        <p class="text-xs text-muted-foreground">
                            Signature du bénéficiaire
                        </p>
                        <p class="text-xs font-medium">
                            {{ fiche.beneficiaire_nom }}
                        </p>
                    </div>
                    <div class="flex flex-col items-center gap-2">
                        <div
                            class="flex h-16 w-full items-center justify-center rounded border border-dashed text-xs text-muted-foreground"
                        >
                            À signer
                        </div>
                        <div class="h-px w-full bg-border" />
                        <p class="text-xs text-muted-foreground">
                            Signature du comptable
                        </p>
                        <p class="text-xs font-medium">
                            {{ fiche.site?.nom ?? 'Agence' }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
