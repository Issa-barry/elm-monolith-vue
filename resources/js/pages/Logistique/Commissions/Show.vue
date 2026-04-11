<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ChevronRight,
    HandCoins,
    History,
    MapPin,
    Truck,
    User,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import { computed, reactive, ref } from 'vue';

// ── Types ─────────────────────────────────────────────────────────────────────

interface Versement {
    id: number;
    montant: number;
    date_versement: string | null;
    mode_paiement: string;
    note: string | null;
    created_by: string | null;
    created_at: string | null;
}

interface CommissionPart {
    id: number;
    type_beneficiaire: 'livreur' | 'proprietaire';
    beneficiaire_nom: string;
    taux_commission: number;
    montant_brut: number;
    frais_supplementaires: number;
    montant_net: number;
    montant_verse: number;
    montant_restant: number;
    statut: string;
    statut_label: string;
    statut_dot_class: string;
    is_versee: boolean;
    versements: Versement[];
}

interface Commission {
    id: number;
    transfert_id: number;
    transfert_reference: string | null;
    transfert_statut: string | null;
    transfert_statut_label: string | null;
    site_source_nom: string | null;
    site_destination_nom: string | null;
    vehicule_nom: string | null;
    immatriculation: string | null;
    base_calcul_label: string;
    valeur_base: number;
    quantite_reference: number | null;
    montant_total: number;
    montant_verse: number;
    montant_restant: number;
    statut: string;
    statut_label: string;
    statut_dot_class: string;
    is_versee: boolean;
    parts: CommissionPart[];
    created_at: string | null;
}

interface ModePaiementOption {
    value: string;
    label: string;
}

// ── Props ─────────────────────────────────────────────────────────────────────

const props = defineProps<{
    commission: Commission;
    modes_paiement: ModePaiementOption[];
    can_verser: boolean;
}>();

// ── Breadcrumbs ───────────────────────────────────────────────────────────────

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Commissions logistiques', href: '/logistique/commissions' },
    { title: props.commission.transfert_reference ?? 'Commission', href: '' },
];

// ── Onglets ───────────────────────────────────────────────────────────────────

const livreurParts = computed(() =>
    props.commission.parts.filter((p) => p.type_beneficiaire === 'livreur'),
);
const proprietaireParts = computed(() =>
    props.commission.parts.filter((p) => p.type_beneficiaire === 'proprietaire'),
);

const activeTab = ref<'livreurs' | 'proprietaires'>(
    livreurParts.value.length > 0 ? 'livreurs' : 'proprietaires',
);

// ── Agrégats ──────────────────────────────────────────────────────────────────

function aggregate(parts: CommissionPart[]) {
    return parts.reduce(
        (acc, p) => ({
            brut:    acc.brut + p.montant_brut,
            frais:   acc.frais + p.frais_supplementaires,
            net:     acc.net + p.montant_net,
            verse:   acc.verse + p.montant_verse,
            restant: acc.restant + p.montant_restant,
        }),
        { brut: 0, frais: 0, net: 0, verse: 0, restant: 0 },
    );
}

const livreurTotals     = computed(() => aggregate(livreurParts.value));
const proprietaireTotals = computed(() => aggregate(proprietaireParts.value));

// ── Dialog versement ──────────────────────────────────────────────────────────

const showVersementDialog = ref(false);
const selectedPart = ref<CommissionPart | null>(null);

interface VersementForm {
    montant: number | null;
    mode_paiement: string;
    note: string;
    processing: boolean;
    errors: Record<string, string>;
}

const versementForm = reactive<VersementForm>({
    montant: null,
    mode_paiement: 'especes',
    note: '',
    processing: false,
    errors: {},
});

function openVersementDialog(part: CommissionPart) {
    selectedPart.value = part;
    versementForm.montant = part.montant_restant > 0 ? part.montant_restant : null;
    versementForm.mode_paiement = 'especes';
    versementForm.note = '';
    versementForm.processing = false;
    versementForm.errors = {};
    showVersementDialog.value = true;
}

function submitVersement() {
    const part = selectedPart.value;
    if (!part || !versementForm.montant || versementForm.montant <= 0) return;
    versementForm.processing = true;
    versementForm.errors = {};

    router.post(
        `/commissions-logistique/parts/${part.id}/versements`,
        {
            montant:        versementForm.montant,
            mode_paiement:  versementForm.mode_paiement,
            note:           versementForm.note || null,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                showVersementDialog.value = false;
            },
            onError: (errors) => {
                versementForm.errors = errors as Record<string, string>;
            },
            onFinish: () => {
                versementForm.processing = false;
            },
        },
    );
}

// ── Dialog historique ─────────────────────────────────────────────────────────

const showHistoriqueDialog = ref(false);
const historiquePart = ref<CommissionPart | null>(null);

function openHistoriqueDialog(part: CommissionPart) {
    historiquePart.value = part;
    showHistoriqueDialog.value = true;
}

// ── Formatage ─────────────────────────────────────────────────────────────────

function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}

function formatModePaiement(mode: string): string {
    return props.modes_paiement.find((m) => m.value === mode)?.label ?? mode;
}
</script>

<template>
    <Head :title="`Commission ${commission.transfert_reference ?? ''}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-7xl space-y-6 px-4 py-6 sm:px-6">

            <!-- ── En-tête ──────────────────────────────────────────────────── -->
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-center gap-3">
                    <Link
                        href="/logistique/commissions"
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground hover:bg-muted/80"
                    >
                        <ArrowLeft class="h-4 w-4" />
                    </Link>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-muted-foreground">
                            Commission logistique
                        </p>
                        <p class="mt-1 font-mono text-xl font-semibold leading-none">
                            {{ commission.transfert_reference ?? '—' }}
                        </p>
                        <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
                            <span class="inline-flex items-center gap-1.5">
                                <MapPin class="h-3.5 w-3.5 shrink-0" />
                                <span>{{ commission.site_source_nom ?? '—' }}</span>
                                <ChevronRight class="h-3 w-3 shrink-0" />
                                <span>{{ commission.site_destination_nom ?? '—' }}</span>
                            </span>
                            <span v-if="commission.vehicule_nom" class="inline-flex items-center gap-1.5">
                                <Truck class="h-3.5 w-3.5 shrink-0" />
                                {{ commission.vehicule_nom }}
                                <span v-if="commission.immatriculation" class="font-mono text-xs">({{ commission.immatriculation }})</span>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <StatusDot
                        :label="commission.statut_label"
                        :dot-class="commission.statut_dot_class"
                        class="text-sm text-muted-foreground"
                    />
                    <Link
                        :href="`/logistique/${commission.transfert_id}`"
                        class="text-xs text-primary hover:underline"
                    >
                        Voir le transfert →
                    </Link>
                </div>
            </div>

            <!-- ── KPI cards ────────────────────────────────────────────────── -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Total commission</p>
                    <p class="mt-1 text-xl font-bold tabular-nums">{{ formatGNF(commission.montant_total) }}</p>
                    <p class="mt-1 text-xs text-muted-foreground">{{ commission.base_calcul_label }}</p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Versé</p>
                    <p class="mt-1 text-xl font-bold tabular-nums text-emerald-600 dark:text-emerald-400">
                        {{ formatGNF(commission.montant_verse) }}
                    </p>
                </div>
                <div
                    class="rounded-xl border bg-card p-4 shadow-sm"
                    :class="commission.montant_restant > 0 ? 'border-amber-200 dark:border-amber-900' : ''"
                >
                    <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Restant à verser</p>
                    <p
                        class="mt-1 text-xl font-bold tabular-nums"
                        :class="commission.montant_restant > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-foreground'"
                    >
                        {{ formatGNF(commission.montant_restant) }}
                    </p>
                </div>
            </div>

            <!-- ── Onglets parts ─────────────────────────────────────────────── -->
            <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
                <!-- Tabs header -->
                <div class="flex items-center gap-1 border-b px-4 py-2">
                    <button
                        class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors"
                        :class="activeTab === 'livreurs'
                            ? 'bg-primary/10 text-primary'
                            : 'text-muted-foreground hover:bg-muted hover:text-foreground'"
                        :disabled="livreurParts.length === 0"
                        @click="activeTab = 'livreurs'"
                    >
                        <Truck class="h-3.5 w-3.5" />
                        Livreurs
                        <span class="rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-medium tabular-nums">
                            {{ livreurParts.length }}
                        </span>
                    </button>
                    <button
                        class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors"
                        :class="activeTab === 'proprietaires'
                            ? 'bg-primary/10 text-primary'
                            : 'text-muted-foreground hover:bg-muted hover:text-foreground'"
                        :disabled="proprietaireParts.length === 0"
                        @click="activeTab = 'proprietaires'"
                    >
                        <User class="h-3.5 w-3.5" />
                        Propriétaires
                        <span class="rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-medium tabular-nums">
                            {{ proprietaireParts.length }}
                        </span>
                    </button>
                </div>

                <!-- Onglet Livreurs -->
                <div v-if="activeTab === 'livreurs'" class="overflow-x-auto">
                    <table v-if="livreurParts.length > 0" class="w-full text-sm">
                        <thead>
                            <tr class="border-b bg-muted/40">
                                <th class="px-4 py-3 text-left font-medium text-muted-foreground">Livreur</th>
                                <th class="px-4 py-3 text-right font-medium text-muted-foreground">Taux</th>
                                <th class="px-4 py-3 text-right font-medium text-muted-foreground">Montant</th>
                                <th class="px-4 py-3 text-right font-medium text-muted-foreground">Versé</th>
                                <th class="px-4 py-3 text-right font-medium text-muted-foreground">Restant</th>
                                <th class="px-4 py-3 text-left font-medium text-muted-foreground">Statut</th>
                                <th class="px-4 py-3 text-center font-medium text-muted-foreground">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="part in livreurParts"
                                :key="part.id"
                                class="transition-colors hover:bg-muted/10"
                            >
                                <td class="px-4 py-3 font-medium">{{ part.beneficiaire_nom }}</td>
                                <td class="px-4 py-3 text-right text-muted-foreground tabular-nums">{{ part.taux_commission }}%</td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ formatGNF(part.montant_net) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-emerald-600 dark:text-emerald-400">{{ formatGNF(part.montant_verse) }}</td>
                                <td
                                    class="px-4 py-3 text-right font-semibold tabular-nums"
                                    :class="part.montant_restant > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-muted-foreground'"
                                >
                                    {{ formatGNF(part.montant_restant) }}
                                </td>
                                <td class="px-4 py-3">
                                    <StatusDot :label="part.statut_label" :dot-class="part.statut_dot_class" class="text-xs text-muted-foreground" />
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <Button
                                            v-if="part.versements.length > 0"
                                            variant="ghost"
                                            size="sm"
                                            class="h-8 px-2.5"
                                            @click="openHistoriqueDialog(part)"
                                        >
                                            <History class="mr-1.5 h-3.5 w-3.5" />
                                            Hist. ({{ part.versements.length }})
                                        </Button>
                                        <Button
                                            v-if="can_verser && !part.is_versee"
                                            size="sm"
                                            @click="openVersementDialog(part)"
                                        >
                                            Verser
                                        </Button>
                                        <span v-else-if="part.is_versee" class="text-xs font-medium text-emerald-600 dark:text-emerald-400">Versé ✓</span>
                                        <span v-else-if="!can_verser && !part.is_versee" class="text-xs text-muted-foreground">—</span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 bg-muted/20 text-sm font-semibold">
                                <td colspan="2" class="px-4 py-2.5 text-xs font-bold uppercase text-muted-foreground">Total</td>
                                <td class="px-4 py-2.5 text-right tabular-nums">{{ formatGNF(livreurTotals.net) }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-emerald-600 dark:text-emerald-400">{{ formatGNF(livreurTotals.verse) }}</td>
                                <td
                                    class="px-4 py-2.5 text-right tabular-nums"
                                    :class="livreurTotals.restant > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-muted-foreground'"
                                >
                                    {{ formatGNF(livreurTotals.restant) }}
                                </td>
                                <td colspan="2" />
                            </tr>
                        </tfoot>
                    </table>
                    <div v-else class="flex flex-col items-center gap-3 py-12 text-muted-foreground">
                        <Truck class="h-10 w-10 opacity-30" />
                        <p class="text-sm">Aucune part livreur.</p>
                    </div>
                </div>

                <!-- Onglet Propriétaires -->
                <div v-if="activeTab === 'proprietaires'" class="overflow-x-auto">
                    <table v-if="proprietaireParts.length > 0" class="w-full text-sm">
                        <thead>
                            <tr class="border-b bg-muted/40">
                                <th class="px-4 py-3 text-left font-medium text-muted-foreground">Propriétaire</th>
                                <th class="px-4 py-3 text-right font-medium text-muted-foreground">Taux</th>
                                <th class="px-4 py-3 text-right font-medium text-muted-foreground">Brut</th>
                                <th class="px-4 py-3 text-right font-medium text-muted-foreground">Frais</th>
                                <th class="px-4 py-3 text-right font-medium text-muted-foreground">Net</th>
                                <th class="px-4 py-3 text-right font-medium text-muted-foreground">Versé</th>
                                <th class="px-4 py-3 text-right font-medium text-muted-foreground">Restant</th>
                                <th class="px-4 py-3 text-left font-medium text-muted-foreground">Statut</th>
                                <th class="px-4 py-3 text-center font-medium text-muted-foreground">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="part in proprietaireParts"
                                :key="part.id"
                                class="transition-colors hover:bg-muted/10"
                            >
                                <td class="px-4 py-3 font-medium">{{ part.beneficiaire_nom }}</td>
                                <td class="px-4 py-3 text-right text-muted-foreground tabular-nums">{{ part.taux_commission }}%</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ formatGNF(part.montant_brut) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    <span v-if="part.frais_supplementaires > 0" class="font-semibold text-destructive">
                                        - {{ formatGNF(part.frais_supplementaires) }}
                                    </span>
                                    <span v-else class="text-muted-foreground">{{ formatGNF(0) }}</span>
                                </td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ formatGNF(part.montant_net) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-emerald-600 dark:text-emerald-400">{{ formatGNF(part.montant_verse) }}</td>
                                <td
                                    class="px-4 py-3 text-right font-semibold tabular-nums"
                                    :class="part.montant_restant > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-muted-foreground'"
                                >
                                    {{ formatGNF(part.montant_restant) }}
                                </td>
                                <td class="px-4 py-3">
                                    <StatusDot :label="part.statut_label" :dot-class="part.statut_dot_class" class="text-xs text-muted-foreground" />
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <Button
                                            v-if="part.versements.length > 0"
                                            variant="ghost"
                                            size="sm"
                                            class="h-8 px-2.5"
                                            @click="openHistoriqueDialog(part)"
                                        >
                                            <History class="mr-1.5 h-3.5 w-3.5" />
                                            Hist. ({{ part.versements.length }})
                                        </Button>
                                        <Button
                                            v-if="can_verser && !part.is_versee"
                                            size="sm"
                                            @click="openVersementDialog(part)"
                                        >
                                            Verser
                                        </Button>
                                        <span v-else-if="part.is_versee" class="text-xs font-medium text-emerald-600 dark:text-emerald-400">Versé ✓</span>
                                        <span v-else-if="!can_verser && !part.is_versee" class="text-xs text-muted-foreground">—</span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 bg-muted/20 text-sm font-semibold">
                                <td colspan="2" class="px-4 py-2.5 text-xs font-bold uppercase text-muted-foreground">Total</td>
                                <td class="px-4 py-2.5 text-right tabular-nums">{{ formatGNF(proprietaireTotals.brut) }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-destructive">- {{ formatGNF(proprietaireTotals.frais) }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums">{{ formatGNF(proprietaireTotals.net) }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-emerald-600 dark:text-emerald-400">{{ formatGNF(proprietaireTotals.verse) }}</td>
                                <td
                                    class="px-4 py-2.5 text-right tabular-nums"
                                    :class="proprietaireTotals.restant > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-muted-foreground'"
                                >
                                    {{ formatGNF(proprietaireTotals.restant) }}
                                </td>
                                <td colspan="2" />
                            </tr>
                        </tfoot>
                    </table>
                    <div v-else class="flex flex-col items-center gap-3 py-12 text-muted-foreground">
                        <User class="h-10 w-10 opacity-30" />
                        <p class="text-sm">Aucune part propriétaire.</p>
                    </div>
                </div>
            </div>

        </div>

        <!-- ── Dialog : Enregistrer un versement ─────────────────────────────── -->
        <Dialog
            v-model:visible="showVersementDialog"
            modal
            :header="`Versement — ${selectedPart?.beneficiaire_nom ?? ''}`"
            :style="{ width: '420px' }"
            :draggable="false"
        >
            <div class="space-y-4 py-2">
                <div>
                    <Label class="mb-1.5 block text-sm">Montant (GNF)</Label>
                    <InputNumber
                        v-model="versementForm.montant"
                        :min="1"
                        :max="selectedPart?.montant_restant ?? undefined"
                        class="w-full"
                        input-class="w-full"
                    />
                    <p v-if="versementForm.errors.montant" class="mt-1 text-xs text-destructive">
                        {{ versementForm.errors.montant }}
                    </p>
                </div>
                <div>
                    <Label class="mb-1.5 block text-sm">Mode de paiement</Label>
                    <Dropdown
                        v-model="versementForm.mode_paiement"
                        :options="modes_paiement"
                        option-label="label"
                        option-value="value"
                        class="w-full"
                    />
                    <p v-if="versementForm.errors.mode_paiement" class="mt-1 text-xs text-destructive">
                        {{ versementForm.errors.mode_paiement }}
                    </p>
                </div>
                <div>
                    <Label class="mb-1.5 block text-sm">Note (optionnel)</Label>
                    <InputText
                        v-model="versementForm.note"
                        class="w-full"
                        placeholder="Remarque…"
                    />
                </div>
            </div>
            <template #footer>
                <Button variant="outline" :disabled="versementForm.processing" @click="showVersementDialog = false">
                    Annuler
                </Button>
                <Button :disabled="versementForm.processing || !versementForm.montant" @click="submitVersement">
                    <HandCoins v-if="!versementForm.processing" class="mr-1.5 h-4 w-4" />
                    <span v-if="versementForm.processing" class="mr-2 inline-block h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent" />
                    {{ versementForm.processing ? 'Enregistrement…' : 'Enregistrer' }}
                </Button>
            </template>
        </Dialog>

        <!-- ── Dialog : Historique des versements ────────────────────────────── -->
        <Dialog
            v-model:visible="showHistoriqueDialog"
            modal
            :dismissable-mask="true"
            :header="`Historique — ${historiquePart?.beneficiaire_nom ?? ''}`"
            :style="{ width: 'min(720px, 96vw)' }"
            :draggable="false"
        >
            <div v-if="historiquePart?.versements.length" class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b bg-muted/40">
                            <th class="px-3 py-2.5 text-left font-medium text-muted-foreground">Date</th>
                            <th class="px-3 py-2.5 text-left font-medium text-muted-foreground">Mode</th>
                            <th class="px-3 py-2.5 text-right font-medium text-muted-foreground">Montant</th>
                            <th class="px-3 py-2.5 text-left font-medium text-muted-foreground">Note</th>
                            <th class="px-3 py-2.5 text-left font-medium text-muted-foreground">Enregistré par</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="v in historiquePart.versements"
                            :key="v.id"
                            class="hover:bg-muted/10"
                        >
                            <td class="px-3 py-2.5 tabular-nums">{{ v.date_versement ?? '—' }}</td>
                            <td class="px-3 py-2.5 text-muted-foreground">{{ formatModePaiement(v.mode_paiement) }}</td>
                            <td class="px-3 py-2.5 text-right font-semibold tabular-nums">{{ formatGNF(v.montant) }}</td>
                            <td class="px-3 py-2.5 text-muted-foreground">{{ v.note || '—' }}</td>
                            <td class="px-3 py-2.5 text-muted-foreground">{{ v.created_by ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="py-4 text-center text-sm text-muted-foreground">
                Aucun versement enregistré pour ce bénéficiaire.
            </p>
        </Dialog>

    </AppLayout>
</template>
