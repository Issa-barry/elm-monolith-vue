<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Car,
    HandCoins,
    History,
    MapPin,
    Trash2,
    Truck,
    User,
    Users,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import { computed, reactive, ref, watch } from 'vue';

// ── Types ─────────────────────────────────────────────────────────────────────

interface VersementItem {
    id: number;
    date_versement: string | null;
    enregistre_le: string | null;
    mode_paiement: string;
    montant: number;
    note: string | null;
    created_by: string | null;
}

interface CommissionPart {
    id: number;
    type_beneficiaire: 'livreur' | 'proprietaire';
    beneficiaire_nom: string;
    role: string | null;
    taux_commission: number;
    montant_brut: number;
    frais_supplementaires: number;
    type_frais: string | null;
    commentaire_frais: string | null;
    montant_net: number;
    montant_verse: number;
    montant_restant: number;
    statut: string;
    statut_label: string;
    is_versee: boolean;
    versements: VersementItem[];
}

interface CommissionItem {
    id: number;
    commande_id: number;
    commande_reference: string | null;
    site_nom: string | null;
    vehicule_nom: string | null;
    immatriculation: string | null;
    equipe_nom: string | null;
    proprietaire_nom: string | null;
    montant_commande: number;
    montant_commission_totale: number;
    montant_verse: number;
    montant_restant: number;
    statut: string;
    statut_label: string;
    is_versee: boolean;
    is_annulee: boolean;
    created_at: string;
    parts: CommissionPart[];
}

interface ModePaiementOption {
    value: string;
    label: string;
}

// ── Props ─────────────────────────────────────────────────────────────────────

const props = defineProps<{
    commission: CommissionItem;
    modes_paiement: ModePaiementOption[];
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Commissions', href: '/commissions' },
    { title: props.commission.commande_reference ?? 'Commission', href: '' },
];

// ── Formatage ─────────────────────────────────────────────────────────────────

function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}

const statutDotColor: Record<string, string> = {
    en_attente: 'bg-amber-500',
    partielle: 'bg-blue-500',
    versee: 'bg-emerald-500',
    annulee: 'bg-zinc-400 dark:bg-zinc-500',
};

const roleLabels: Record<string, string> = {
    principal: 'Principal',
    assistant: 'Assistant',
};

// ── Tabs internes (livreurs / propriétaire) ────────────────────────────────────

const livreurParts = computed(() =>
    props.commission.parts.filter((p) => p.type_beneficiaire === 'livreur'),
);
const proprietaireParts = computed(() =>
    props.commission.parts.filter((p) => p.type_beneficiaire === 'proprietaire'),
);

const activePartTab = ref<'livreurs' | 'proprietaires'>(
    livreurParts.value.length > 0 ? 'livreurs' : 'proprietaires',
);

// ── Dialog versement (livreurs) ───────────────────────────────────────────────

const dialogVisible = ref(false);
const dialogPart = ref<CommissionPart | null>(null);

interface VersementForm {
    montant: number | null;
    mode_paiement: string;
    date_versement: string;
    note: string | null;
    processing: boolean;
}

const versementForm = reactive<VersementForm>({
    montant: null,
    mode_paiement: 'especes',
    date_versement: new Date().toISOString().slice(0, 10),
    note: null,
    processing: false,
});

function openVersementDialog(part: CommissionPart) {
    dialogPart.value = part;
    versementForm.montant = part.montant_restant > 0 ? part.montant_restant : null;
    versementForm.mode_paiement = 'especes';
    versementForm.date_versement = new Date().toISOString().slice(0, 10);
    versementForm.note = null;
    versementForm.processing = false;
    dialogVisible.value = true;
}

function closeDialog() {
    dialogVisible.value = false;
    dialogPart.value = null;
}

// ── Dialog historique (livreurs) ──────────────────────────────────────────────

const historyDialogVisible = ref(false);
const historyDialogPart = ref<CommissionPart | null>(null);

function openHistoryDialog(part: CommissionPart) {
    historyDialogPart.value = part;
    historyDialogVisible.value = true;
}

function submitVersementDialog() {
    const part = dialogPart.value;
    if (!part || !versementForm.montant || versementForm.montant <= 0) return;
    versementForm.processing = true;
    router.post(
        `/commissions/${props.commission.id}/parts/${part.id}/versements`,
        {
            montant: versementForm.montant,
            mode_paiement: versementForm.mode_paiement,
            date_versement: versementForm.date_versement,
            note: versementForm.note,
        },
        {
            preserveScroll: true,
            onSuccess: () => closeDialog(),
            onFinish: () => { versementForm.processing = false; },
        },
    );
}

// ── Formulaires de versement par part (propriétaires — inline) ────────────────

interface PartForm {
    montant: number | null;
    mode_paiement: string;
    date_versement: string;
    note: string | null;
    processing: boolean;
}

const versementForms = reactive<Record<number, PartForm>>({});

function initForms() {
    for (const part of props.commission.parts) {
        if (part.type_beneficiaire === 'proprietaire') {
            versementForms[part.id] = {
                montant: part.montant_restant > 0 ? part.montant_restant : null,
                mode_paiement: 'especes',
                date_versement: new Date().toISOString().slice(0, 10),
                note: null,
                processing: false,
            };
        }
    }
}

initForms();

watch(
    () => props.commission.parts,
    () => initForms(),
    { deep: true },
);

function submitVersement(part: CommissionPart) {
    const f = versementForms[part.id];
    if (!f || !f.montant || f.montant <= 0) return;
    f.processing = true;
    router.post(
        `/commissions/${props.commission.id}/parts/${part.id}/versements`,
        {
            montant: f.montant,
            mode_paiement: f.mode_paiement,
            date_versement: f.date_versement,
            note: f.note,
        },
        {
            preserveScroll: true,
            onFinish: () => { f.processing = false; },
        },
    );
}

// ── Frais supplémentaires (part propriétaire uniquement) ─────────────────────

const typesFraisOptions = [
    { value: 'carburant', label: 'Carburant' },
    { value: 'reparation', label: 'Réparation' },
    { value: 'autre', label: 'Autre' },
];

const typesFraisLabels: Record<string, string> = {
    carburant: 'Carburant',
    reparation: 'Réparation',
    autre: 'Autre',
};

interface FraisForm {
    frais: number;
    type_frais: string;
    commentaire_frais: string;
    processing: boolean;
}

const fraisForms = reactive<Record<number, FraisForm>>({});

function initFraisForms() {
    for (const part of props.commission.parts) {
        if (part.type_beneficiaire === 'proprietaire') {
            fraisForms[part.id] = {
                frais: part.frais_supplementaires,
                type_frais: part.type_frais ?? '',
                commentaire_frais: part.commentaire_frais ?? '',
                processing: false,
            };
        }
    }
}

initFraisForms();

watch(
    () => props.commission.parts,
    () => initFraisForms(),
    { deep: true },
);

function fraisNettePreview(part: CommissionPart): number {
    const f = fraisForms[part.id];
    return Math.max(0, part.montant_brut - (f?.frais ?? 0));
}

function saveFrais(part: CommissionPart) {
    const f = fraisForms[part.id];
    if (!f) return;
    f.processing = true;
    router.patch(
        `/commissions/${props.commission.id}/parts/${part.id}/frais`,
        {
            frais_supplementaires: f.frais,
            type_frais: f.frais > 0 ? f.type_frais || null : null,
            commentaire_frais: (f.frais > 0 && f.type_frais === 'autre') ? f.commentaire_frais : null,
        },
        {
            preserveScroll: true,
            onFinish: () => { f.processing = false; },
        },
    );
}

// ── Suppression versement ─────────────────────────────────────────────────────

function deleteVersement(versementId: number) {
    router.delete(`/versements-commissions/${versementId}`, { preserveScroll: true });
}

// ── Totaux versements (toutes parts) ─────────────────────────────────────────

const totalVersements = computed(() =>
    props.commission.parts.flatMap((p) => p.versements),
);
</script>

<template>
    <Head :title="`Commission ${commission.commande_reference ?? ''}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-5xl space-y-6 px-4 py-6 sm:px-6">

            <!-- ── En-tête ──────────────────────────────────────────────────── -->
            <div>
                <Link
                    href="/commissions"
                    class="mb-4 inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft class="h-4 w-4" />
                    Commissions
                </Link>

                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold tracking-[0.14em] text-muted-foreground uppercase">
                            Versement commission
                        </p>
                        <p class="mt-1 font-mono text-xl font-semibold leading-none">
                            {{ commission.commande_reference ?? '—' }}
                        </p>
                        <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
                            <span v-if="commission.vehicule_nom" class="inline-flex items-center gap-1.5">
                                <Car class="h-3.5 w-3.5" />
                                {{ commission.vehicule_nom }}
                                <span v-if="commission.immatriculation" class="font-mono text-xs">({{ commission.immatriculation }})</span>
                            </span>
                            <span v-if="commission.equipe_nom" class="inline-flex items-center gap-1.5">
                                <Users class="h-3.5 w-3.5" />
                                {{ commission.equipe_nom }}
                            </span>
                            <span v-if="commission.site_nom" class="inline-flex items-center gap-1.5">
                                <MapPin class="h-3.5 w-3.5" />
                                {{ commission.site_nom }}
                            </span>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <StatusDot
                            :label="commission.statut_label"
                            :dot-class="statutDotColor[commission.statut] ?? 'bg-zinc-400 dark:bg-zinc-500'"
                            class="text-sm text-muted-foreground"
                        />
                        <span class="text-xs text-muted-foreground">{{ commission.created_at }}</span>
                    </div>
                </div>
            </div>

            <!-- ── Carte de synthèse globale ─────────────────────────────────── -->
            <div class="rounded-xl border bg-card p-5 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold tracking-wider text-muted-foreground uppercase">Total commission</p>
                        <p class="mt-1 text-2xl font-bold tabular-nums">{{ formatGNF(commission.montant_commission_totale) }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-muted-foreground">Versé</p>
                        <p class="text-lg font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">{{ formatGNF(commission.montant_verse) }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-muted-foreground">Restant</p>
                        <p
                            class="text-lg font-semibold tabular-nums"
                            :class="commission.montant_restant > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-muted-foreground'"
                        >{{ formatGNF(commission.montant_restant) }}</p>
                    </div>
                </div>
            </div>

            <!-- ── Parts ──────────────────────────────────────────────────────── -->
            <div class="space-y-4">
                <!-- Tab switcher -->
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold tracking-wider text-muted-foreground uppercase">Parts de commission</h2>
                    <div class="flex rounded-lg border bg-muted/30 p-0.5 gap-0.5">
                        <button
                            class="flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold transition-colors"
                            :class="activePartTab === 'livreurs'
                                ? 'bg-background text-foreground shadow-sm'
                                : 'text-muted-foreground hover:text-foreground'"
                            :disabled="livreurParts.length === 0"
                            @click="activePartTab = 'livreurs'"
                        >
                            <Truck class="h-3.5 w-3.5" />
                            Livreurs
                            <span class="rounded-full bg-primary/10 px-1.5 py-0.5 text-[10px] tabular-nums text-primary">
                                {{ livreurParts.length }}
                            </span>
                        </button>
                        <button
                            class="flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold transition-colors"
                            :class="activePartTab === 'proprietaires'
                                ? 'bg-background text-foreground shadow-sm'
                                : 'text-muted-foreground hover:text-foreground'"
                            :disabled="proprietaireParts.length === 0"
                            @click="activePartTab = 'proprietaires'"
                        >
                            <User class="h-3.5 w-3.5" />
                            Propriétaire
                            <span class="rounded-full bg-primary/10 px-1.5 py-0.5 text-[10px] tabular-nums text-primary">
                                {{ proprietaireParts.length }}
                            </span>
                        </button>
                    </div>
                </div>

                <!-- ── Tab Livreurs : tableau compact ──────────────────────────── -->
                <div v-if="activePartTab === 'livreurs'" class="rounded-xl border bg-card shadow-sm overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b bg-muted/40">
                                    <th class="px-4 py-3 text-left font-medium text-muted-foreground">Livreur</th>
                                    <th class="px-4 py-3 text-left font-medium text-muted-foreground">Rôle</th>
                                    <th class="px-4 py-3 text-right font-medium text-muted-foreground">Taux</th>
                                    <th class="px-4 py-3 text-right font-medium text-muted-foreground">Montant</th>
                                    <th class="px-4 py-3 text-right font-medium text-muted-foreground">Versé</th>
                                    <th class="px-4 py-3 text-right font-medium text-muted-foreground">Restant</th>
                                    <th class="px-4 py-3 text-left font-medium text-muted-foreground">Statut</th>
                                    <th class="px-4 py-3 text-center font-medium text-muted-foreground">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr
                                    v-for="part in livreurParts"
                                    :key="part.id"
                                    class="transition-colors hover:bg-muted/10"
                                >
                                    <td class="px-4 py-3 font-medium">{{ part.beneficiaire_nom }}</td>
                                    <td class="px-4 py-3 text-muted-foreground">
                                        <span
                                            class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                            :class="'bg-muted text-muted-foreground'"
                                        >
                                            {{ part.role ? (roleLabels[part.role] ?? part.role) : '—' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums text-muted-foreground">{{ part.taux_commission }}%</td>
                                    <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ formatGNF(part.montant_brut) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums text-foreground">
                                        {{ formatGNF(part.montant_verse) }}
                                    </td>
                                    <td class="px-4 py-3 text-right font-semibold tabular-nums text-foreground">
                                        {{ formatGNF(part.montant_restant) }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <StatusDot
                                            :label="part.statut_label"
                                            :dot-class="statutDotColor[part.statut] ?? 'bg-zinc-400 dark:bg-zinc-500'"
                                            class="text-xs text-muted-foreground"
                                        />
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center justify-center gap-1.5">
                                            <!-- Bouton historique -->
                                            <button
                                                v-if="part.versements.length > 0"
                                                type="button"
                                                class="inline-flex h-8 w-8 items-center justify-center rounded-md border text-muted-foreground transition hover:bg-muted/40"
                                                :title="`Historique (${part.versements.length})`"
                                                @click="openHistoryDialog(part)"
                                            >
                                                <History class="h-3.5 w-3.5" />
                                            </button>
                                            <!-- Bouton versement: toujours visible, desactive si non payable -->
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                class="h-8 gap-1.5 text-xs"
                                                :disabled="part.montant_restant <= 0 || commission.is_annulee || !can('ventes.update')"
                                                @click="openVersementDialog(part)"
                                            >
                                                <HandCoins class="h-3.5 w-3.5" />
                                                Versement
                                            </Button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                </div>

                <!-- ── Tab Propriétaires : cartes inchangées ────────────────────── -->
                <template v-if="activePartTab === 'proprietaires'">
                    <div
                        v-for="part in proprietaireParts"
                        :key="part.id"
                        class="rounded-xl border bg-card shadow-sm"
                    >
                        <!-- En-tête de la part -->
                        <div class="flex flex-wrap items-start justify-between gap-3 border-b px-5 py-4">
                            <div>
                                <p class="font-semibold">{{ part.beneficiaire_nom }}</p>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    Propriétaire · {{ part.taux_commission }}%
                                </p>
                            </div>
                            <StatusDot
                                :label="part.statut_label"
                                :dot-class="statutDotColor[part.statut] ?? 'bg-zinc-400 dark:bg-zinc-500'"
                                class="text-xs text-muted-foreground"
                            />
                        </div>

                        <div class="p-5 space-y-5">
                            <!-- Montants -->
                            <div class="grid grid-cols-3 gap-4 text-sm">
                                <div>
                                    <p class="text-xs text-muted-foreground">
                                        {{ part.frais_supplementaires > 0 ? 'Brut' : 'Montant' }}
                                    </p>
                                    <p class="mt-0.5 font-semibold tabular-nums">{{ formatGNF(part.montant_brut) }}</p>
                                </div>
                                <template v-if="part.frais_supplementaires > 0">
                                    <div>
                                        <p class="text-xs text-muted-foreground">
                                            Frais
                                            <span v-if="part.type_frais" class="ml-1 font-normal">
                                                ({{ typesFraisLabels[part.type_frais] ?? part.type_frais }})
                                            </span>
                                        </p>
                                        <p class="mt-0.5 font-semibold tabular-nums text-destructive">− {{ formatGNF(part.frais_supplementaires) }}</p>
                                        <p v-if="part.commentaire_frais" class="mt-0.5 max-w-[180px] truncate text-xs text-muted-foreground/70" :title="part.commentaire_frais">
                                            {{ part.commentaire_frais }}
                                        </p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-muted-foreground">Net</p>
                                        <p class="mt-0.5 font-semibold tabular-nums">{{ formatGNF(part.montant_net) }}</p>
                                    </div>
                                </template>
                                <div>
                                    <p class="text-xs text-muted-foreground">Versé</p>
                                    <p class="mt-0.5 tabular-nums text-muted-foreground">{{ formatGNF(part.montant_verse) }}</p>
                                </div>
                                <div>
                                    <p class="text-xs text-muted-foreground">Restant</p>
                                    <p
                                        class="mt-0.5 font-semibold tabular-nums"
                                        :class="part.montant_restant > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-muted-foreground'"
                                    >{{ formatGNF(part.montant_restant) }}</p>
                                </div>
                            </div>

                            <!-- Frais supplémentaires -->
                            <div
                                v-if="!commission.is_annulee && can('ventes.update')"
                                class="rounded-lg border bg-muted/20 p-4 space-y-4"
                            >
                                <p class="text-xs font-semibold text-muted-foreground uppercase">Frais supplémentaires</p>

                                <div class="flex flex-wrap items-end gap-4">
                                    <div class="space-y-0.5">
                                        <p class="text-xs text-muted-foreground">Part brute</p>
                                        <p class="font-medium tabular-nums">{{ formatGNF(part.montant_brut) }}</p>
                                    </div>
                                    <div class="min-w-44 flex-1">
                                        <Label class="mb-1.5 block text-xs font-medium">Frais à déduire</Label>
                                        <InputNumber
                                            v-model="fraisForms[part.id].frais"
                                            :min="0"
                                            :max="part.montant_brut"
                                            :use-grouping="true"
                                            locale="fr-FR"
                                            suffix=" GNF"
                                            class="w-full"
                                            input-class="h-9 w-full text-right tabular-nums"
                                        />
                                    </div>
                                    <div class="space-y-0.5">
                                        <p class="text-xs text-muted-foreground">Part nette</p>
                                        <p class="font-semibold tabular-nums">{{ formatGNF(fraisNettePreview(part)) }}</p>
                                    </div>
                                </div>

                                <div v-if="fraisForms[part.id]?.frais > 0" class="space-y-3">
                                    <div>
                                        <Label class="mb-1.5 block text-xs font-medium">
                                            Type de frais <span class="text-destructive">*</span>
                                        </Label>
                                        <Dropdown
                                            v-model="fraisForms[part.id].type_frais"
                                            :options="typesFraisOptions"
                                            option-label="label"
                                            option-value="value"
                                            placeholder="Sélectionner…"
                                            class="w-full"
                                        />
                                    </div>

                                    <div v-if="fraisForms[part.id]?.type_frais === 'autre'">
                                        <Label class="mb-1.5 block text-xs font-medium">
                                            Commentaire <span class="text-destructive">*</span>
                                            <span class="ml-1 font-normal text-muted-foreground">
                                                ({{ (fraisForms[part.id]?.commentaire_frais ?? '').length }}/150)
                                            </span>
                                        </Label>
                                        <InputText
                                            v-model="fraisForms[part.id].commentaire_frais"
                                            :maxlength="150"
                                            placeholder="Précisez le motif des frais…"
                                            class="w-full"
                                        />
                                    </div>
                                </div>

                                <Button
                                    size="sm"
                                    variant="outline"
                                    class="h-9"
                                    :disabled="fraisForms[part.id]?.processing
                                        || (fraisForms[part.id]?.frais > 0 && !fraisForms[part.id]?.type_frais)
                                        || (fraisForms[part.id]?.type_frais === 'autre' && !(fraisForms[part.id]?.commentaire_frais?.trim()))"
                                    @click="saveFrais(part)"
                                >
                                    Appliquer
                                </Button>
                            </div>

                            <!-- Formulaire versement inline propriétaire -->
                            <div
                                v-if="!commission.is_annulee && !commission.is_versee && part.montant_restant > 0 && can('ventes.update')"
                            >
                                <p class="mb-3 text-xs font-semibold text-muted-foreground uppercase">Enregistrer un versement</p>
                                <div class="space-y-3">
                                    <div>
                                        <Label class="mb-1.5 block text-xs font-medium">Montant</Label>
                                        <InputNumber
                                            v-model="versementForms[part.id].montant"
                                            :min="0"
                                            :max="part.montant_restant"
                                            :use-grouping="true"
                                            locale="fr-FR"
                                            suffix=" GNF"
                                            class="w-full"
                                            input-class="w-full h-11 text-right text-lg font-semibold tabular-nums"
                                        />
                                    </div>
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <Label class="mb-1.5 block text-xs font-medium">Mode de paiement</Label>
                                            <Dropdown
                                                v-model="versementForms[part.id].mode_paiement"
                                                :options="modes_paiement"
                                                option-label="label"
                                                option-value="value"
                                                class="h-9 w-full"
                                            />
                                        </div>
                                        <div>
                                            <Label class="mb-1.5 block text-xs font-medium">Date</Label>
                                            <InputText
                                                v-model="versementForms[part.id].date_versement"
                                                type="date"
                                                class="h-9 w-full"
                                            />
                                        </div>
                                    </div>
                                    <div>
                                        <Label class="mb-1.5 block text-xs font-medium">Note (optionnel)</Label>
                                        <InputText
                                            v-model="versementForms[part.id].note as string"
                                            class="w-full"
                                            placeholder="Remarque…"
                                        />
                                    </div>
                                    <Button
                                        class="w-full gap-2"
                                        :disabled="versementForms[part.id]?.processing || !(versementForms[part.id]?.montant > 0)"
                                        @click="submitVersement(part)"
                                    >
                                        <HandCoins class="h-4 w-4" />
                                        Enregistrer le versement
                                    </Button>
                                </div>
                            </div>

                            <div
                                v-else-if="!commission.is_annulee && part.montant_restant <= 0"
                                class="rounded-md border border-dashed px-3 py-4 text-center text-sm text-muted-foreground"
                            >
                                Part entièrement versée.
                            </div>

                            <!-- Historique versements propriétaire -->
                            <div v-if="part.versements.length > 0" class="rounded-lg border overflow-hidden">
                                <p class="border-b bg-muted/30 px-4 py-2 text-xs font-semibold text-muted-foreground uppercase">
                                    Historique ({{ part.versements.length }})
                                </p>
                                <table class="w-full text-sm">
                                    <tbody class="divide-y">
                                        <tr
                                            v-for="v in part.versements"
                                            :key="v.id"
                                            class="transition-colors hover:bg-muted/10"
                                        >
                                            <td class="px-4 py-2.5 tabular-nums">
                                                <span class="text-xs text-muted-foreground">{{ v.date_versement ?? '—' }}</span>
                                                <span v-if="v.enregistre_le" class="ml-1.5 text-xs font-medium text-foreground">{{ v.enregistre_le.split(' ')[1] }}</span>
                                            </td>
                                            <td class="px-4 py-2.5 text-muted-foreground">{{ v.mode_paiement }}</td>
                                            <td class="px-4 py-2.5 text-right font-semibold tabular-nums">{{ formatGNF(v.montant) }}</td>
                                            <td class="px-4 py-2.5 text-muted-foreground">
                                                <span class="block max-w-[160px] truncate text-xs" :title="v.note ?? ''">{{ v.note ?? '—' }}</span>
                                            </td>
                                            <td class="px-4 py-2.5 text-xs text-muted-foreground">{{ v.created_by ?? '—' }}</td>
                                            <td v-if="can('ventes.update')" class="px-4 py-2.5 text-right">
                                                <button
                                                    type="button"
                                                    class="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground transition hover:bg-destructive/10 hover:text-destructive"
                                                    title="Supprimer ce versement"
                                                    @click="deleteVersement(v.id)"
                                                >
                                                    <Trash2 class="h-3.5 w-3.5" />
                                                </button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <!-- ── Récapitulatif de tous les versements ───────────────────────── -->
            <div v-if="totalVersements.length > 0" class="rounded-xl border bg-card shadow-sm">
                <div class="flex items-center justify-between border-b px-5 py-4">
                    <p class="text-sm font-semibold text-foreground">Tous les versements</p>
                    <span class="text-xs text-muted-foreground">{{ totalVersements.length }} versement{{ totalVersements.length > 1 ? 's' : '' }}</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b bg-muted/40">
                                <th class="px-4 py-3 text-left font-medium text-muted-foreground">Date</th>
                                <th class="px-4 py-3 text-left font-medium text-muted-foreground">Bénéficiaire</th>
                                <th class="px-4 py-3 text-left font-medium text-muted-foreground">Mode</th>
                                <th class="px-4 py-3 text-right font-medium text-muted-foreground">Montant</th>
                                <th class="px-4 py-3 text-left font-medium text-muted-foreground">Note</th>
                                <th class="px-4 py-3 text-left font-medium text-muted-foreground">Saisi par</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <template v-for="part in commission.parts" :key="part.id">
                                <tr
                                    v-for="v in part.versements"
                                    :key="v.id"
                                    class="transition-colors hover:bg-muted/10"
                                >
                                    <td class="px-4 py-3 tabular-nums">
                                        <span class="text-xs text-muted-foreground">{{ v.date_versement ?? '—' }}</span>
                                        <span v-if="v.enregistre_le" class="ml-1.5 text-xs font-medium text-foreground">{{ v.enregistre_le.split(' ')[1] }}</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span
                                            class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                            :class="part.type_beneficiaire === 'proprietaire'
                                                ? 'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300'
                                                : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'"
                                        >
                                            {{ part.beneficiaire_nom }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-muted-foreground">{{ v.mode_paiement }}</td>
                                    <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ formatGNF(v.montant) }}</td>
                                    <td class="px-4 py-3 text-muted-foreground">
                                        <span class="block max-w-[200px] truncate" :title="v.note ?? ''">{{ v.note ?? '—' }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-muted-foreground">{{ v.created_by ?? '—' }}</td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </AppLayout>

    <!-- ── Dialog historique versements livreur ──────────────────────────── -->
    <Dialog
        :visible="historyDialogVisible"
        modal
        :dismissable-mask="true"
        :style="{ width: 'min(900px, 96vw)' }"
        @update:visible="historyDialogVisible = false"
    >
        <template #header>
            <div>
                <p class="font-semibold">Historique — {{ historyDialogPart?.beneficiaire_nom }}</p>
                <p class="mt-0.5 text-xs text-muted-foreground">
                    {{ historyDialogPart?.versements.length }} versement{{ (historyDialogPart?.versements.length ?? 0) > 1 ? 's' : '' }}
                    · Versé : {{ historyDialogPart ? formatGNF(historyDialogPart.montant_verse) : '' }}
                </p>
            </div>
        </template>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b bg-muted/40">
                        <th class="px-3 py-2.5 text-left font-medium text-muted-foreground">Date versement</th>
                        <th class="px-3 py-2.5 text-left font-medium text-muted-foreground">Mode</th>
                        <th class="px-3 py-2.5 text-right font-medium text-muted-foreground">Montant</th>
                        <th class="px-3 py-2.5 text-left font-medium text-muted-foreground">Note</th>
                        <th class="px-3 py-2.5 text-left font-medium text-muted-foreground">Saisi par</th>
                        <th v-if="can('ventes.update')" class="px-3 py-2.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="v in historyDialogPart?.versements"
                        :key="v.id"
                        class="transition-colors hover:bg-muted/10"
                    >
                        <td class="px-3 py-2.5 tabular-nums">
                            <span class="text-xs text-muted-foreground">{{ v.date_versement ?? '—' }}</span>
                            <span v-if="v.enregistre_le" class="ml-1.5 text-xs font-medium text-foreground">{{ v.enregistre_le.split(' ')[1] }}</span>
                        </td>
                        <td class="px-3 py-2.5 text-muted-foreground">{{ v.mode_paiement }}</td>
                        <td class="px-3 py-2.5 text-right font-semibold tabular-nums">{{ formatGNF(v.montant) }}</td>
                        <td class="px-3 py-2.5 text-muted-foreground">
                            <span class="block max-w-[160px] truncate text-xs" :title="v.note ?? ''">{{ v.note ?? '—' }}</span>
                        </td>
                        <td class="px-3 py-2.5 text-xs text-muted-foreground">{{ v.created_by ?? '—' }}</td>
                        <td v-if="can('ventes.update')" class="px-3 py-2.5 text-right">
                            <button
                                type="button"
                                class="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground transition hover:bg-destructive/10 hover:text-destructive"
                                title="Supprimer ce versement"
                                @click="deleteVersement(v.id)"
                            >
                                <Trash2 class="h-3.5 w-3.5" />
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </Dialog>

    <!-- ── Dialog versement livreur ────────────────────────────────────────── -->
    <Dialog
        :visible="dialogVisible"
        modal
        :dismissable-mask="true"
        :style="{ width: 'min(480px, 95vw)' }"
        :pt="{ content: { style: 'overflow: visible' } }"
        @update:visible="closeDialog"
    >
        <template #header>
            <div>
                <p class="font-semibold">{{ dialogPart?.beneficiaire_nom }}</p>
                <p class="mt-0.5 text-xs text-muted-foreground">
                    {{ dialogPart?.role ? (roleLabels[dialogPart.role] ?? dialogPart.role) : 'Livreur' }}
                    · {{ dialogPart?.taux_commission }}%
                    · Restant : {{ dialogPart ? formatGNF(dialogPart.montant_restant) : '' }}
                </p>
            </div>
        </template>

        <div class="space-y-4 pb-1 pt-2">
            <div>
                <Label class="mb-1.5 block text-xs font-medium">Montant</Label>
                <InputNumber
                    v-model="versementForm.montant"
                    :min="0"
                    :max="dialogPart?.montant_restant ?? undefined"
                    :use-grouping="true"
                    locale="fr-FR"
                    suffix=" GNF"
                    class="w-full"
                    input-class="w-full h-11 text-right text-lg font-semibold tabular-nums"
                    autofocus
                />
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <Label class="mb-1.5 block text-xs font-medium">Mode de paiement</Label>
                    <Dropdown
                        v-model="versementForm.mode_paiement"
                        :options="modes_paiement"
                        option-label="label"
                        option-value="value"
                        class="h-9 w-full"
                    />
                </div>
                <div>
                    <Label class="mb-1.5 block text-xs font-medium">Date</Label>
                    <InputText
                        v-model="versementForm.date_versement"
                        type="date"
                        class="h-9 w-full"
                    />
                </div>
            </div>
            <div>
                <Label class="mb-1.5 block text-xs font-medium">Note (optionnel)</Label>
                <InputText
                    v-model="versementForm.note as string"
                    class="w-full"
                    placeholder="Remarque…"
                />
            </div>
        </div>

        <template #footer>
            <div class="flex justify-end gap-2">
                <Button type="button" variant="outline" size="sm" @click="closeDialog">
                    Annuler
                </Button>
                <Button
                    type="button"
                    size="sm"
                    class="gap-2"
                    :disabled="versementForm.processing || !(versementForm.montant && versementForm.montant > 0)"
                    @click="submitVersementDialog"
                >
                    <HandCoins class="h-4 w-4" />
                    Enregistrer
                </Button>
            </div>
        </template>
    </Dialog>
</template>
