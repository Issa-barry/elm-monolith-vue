<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import StatusDot from '@/components/StatusDot.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { BadgeCheck, Clock, CreditCard, Hourglass } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import { computed, ref } from 'vue';

// ── Types ─────────────────────────────────────────────────────────────────────
interface FactureItem {
    id: number;
    reference: string;
    commande_id: number;
    commande_reference: string | null;
    vehicule_nom: string | null;
    client_nom: string | null;
    site_nom: string | null;
    montant_net: number;
    montant_encaisse: number;
    montant_restant: number;
    statut_facture: string;
    statut_label: string;
    is_annulee: boolean;
    is_payee: boolean;
    created_at: string;
}

interface Totaux {
    total_a_encaisser: number;
    nb_impayees: number;
    montant_impayees: number;
    nb_partielles: number;
    montant_partielles: number;
    nb_payees: number;
    montant_payees: number;
}

interface ModePaiementOption {
    value: string;
    label: string;
}

// ── Props ─────────────────────────────────────────────────────────────────────
const props = defineProps<{
    factures: FactureItem[];
    totaux: Totaux;
    modes_paiement: ModePaiementOption[];
    periode: string;
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Factures', href: '/factures' },
];

// ── Filtre par période ───────────────────────────────────────────────────────
const periodes = [
    { value: 'today', label: "Aujourd'hui" },
    { value: 'week',  label: 'Cette semaine' },
    { value: 'month', label: 'Ce mois' },
    { value: 'all',   label: 'Tout' },
];

function setPeriode(p: string) {
    router.get('/factures', { periode: p }, { preserveScroll: true, replace: true });
}

// ── Filtre par statut ────────────────────────────────────────────────────────
const filtreStatut = ref<string>('tous');
const filtres = [
    { value: 'tous',     label: 'Toutes' },
    { value: 'impayee',  label: 'Impayées' },
    { value: 'partiel',  label: 'Partielles' },
    { value: 'payee',    label: 'Payées' },
    { value: 'annulee',  label: 'Annulées' },
];

const search = ref('');

const facturesFiltrees = computed(() => {
    let list = props.factures;

    if (filtreStatut.value !== 'tous') {
        list = list.filter(f => f.statut_facture === filtreStatut.value);
    }

    const q = search.value.toLowerCase().trim();
    if (q) {
        list = list.filter(f =>
            f.reference.toLowerCase().includes(q) ||
            (f.vehicule_nom && f.vehicule_nom.toLowerCase().includes(q)) ||
            (f.client_nom   && f.client_nom.toLowerCase().includes(q)) ||
            (f.site_nom     && f.site_nom.toLowerCase().includes(q))
        );
    }

    return list;
});

// ── Couleurs statut ───────────────────────────────────────────────────────────
const statutColor: Record<string, string> = {
    impayee: 'bg-amber-500',
    partiel: 'bg-blue-500',
    payee:   'bg-emerald-500',
    annulee: 'bg-zinc-400 dark:bg-zinc-500',
};

// ── Formatage ─────────────────────────────────────────────────────────────────
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

// ── Dialog encaissement ───────────────────────────────────────────────────────
const dialogVisible  = ref(false);
const factureActive  = ref<FactureItem | null>(null);

const encaissementForm = useForm({
    montant:           0 as number | null,
    date_encaissement: new Date().toISOString().slice(0, 10),
    mode_paiement:     'especes',
    note:              null as string | null,
});

function openDialog(facture: FactureItem) {
    factureActive.value          = facture;
    encaissementForm.montant     = facture.montant_restant;
    encaissementForm.date_encaissement = new Date().toISOString().slice(0, 10);
    encaissementForm.mode_paiement = 'especes';
    encaissementForm.note        = null;
    encaissementForm.clearErrors();
    dialogVisible.value          = true;
}

function submitEncaissement() {
    if (!factureActive.value) return;
    encaissementForm.post(`/factures/${factureActive.value.id}/encaissements`, {
        onSuccess: () => {
            dialogVisible.value = false;
        },
    });
}

// ── Progression ───────────────────────────────────────────────────────────────
function progressPercent(f: FactureItem): number {
    if (f.montant_net <= 0) return 0;
    return Math.min(100, Math.round((f.montant_encaisse / f.montant_net) * 100));
}
</script>

<template>
    <Head title="Factures de vente" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full space-y-6 p-6">

            <!-- En-tête -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Factures de vente</h1>
                    <p class="mt-1 text-sm text-muted-foreground">Suivi et encaissement des factures.</p>
                </div>
            </div>

            <!-- Cartes de synthèse -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">

                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <p class="text-sm text-muted-foreground">Restant à encaisser</p>
                        <CreditCard class="h-4 w-4 text-muted-foreground" />
                    </div>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-amber-600 dark:text-amber-400">
                        {{ formatCompact(totaux.total_a_encaisser) }}
                    </p>
                </div>

                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <p class="text-sm text-muted-foreground">Impayées</p>
                        <Hourglass class="h-4 w-4 text-amber-500" />
                    </div>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-amber-600 dark:text-amber-400">{{ formatCompact(totaux.montant_impayees) }}</p>
                    <p class="mt-0.5 text-xs text-muted-foreground">{{ totaux.nb_impayees }} facture{{ totaux.nb_impayees > 1 ? 's' : '' }}</p>
                </div>

                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <p class="text-sm text-muted-foreground">Partiellement payées</p>
                        <Clock class="h-4 w-4 text-blue-500" />
                    </div>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-blue-600 dark:text-blue-400">{{ formatCompact(totaux.montant_partielles) }}</p>
                    <p class="mt-0.5 text-xs text-muted-foreground">{{ totaux.nb_partielles }} facture{{ totaux.nb_partielles > 1 ? 's' : '' }}</p>
                </div>

                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <p class="text-sm text-muted-foreground">Soldées</p>
                        <BadgeCheck class="h-4 w-4 text-emerald-500" />
                    </div>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{{ formatCompact(totaux.montant_payees) }}</p>
                    <p class="mt-0.5 text-xs text-muted-foreground">{{ totaux.nb_payees }} facture{{ totaux.nb_payees > 1 ? 's' : '' }}</p>
                </div>
            </div>

            <!-- Filtres statut + période -->
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
                            placeholder="Référence, véhicule, client…"
                            class="h-9 w-64 rounded-md border border-input bg-background pl-8 pr-3 text-sm shadow-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
                        />
                    </div>

                    <!-- Statut dropdown -->
                    <Dropdown
                        v-model="filtreStatut"
                        :options="filtres"
                        option-label="label"
                        option-value="value"
                        class="w-36"
                    />

                    <!-- Période dropdown -->
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
                                <th class="px-4 py-3 text-left font-medium text-muted-foreground">Référence</th>
                                <th class="px-4 py-3 text-left font-medium text-muted-foreground">Véhicule / Client</th>
                                <th class="px-4 py-3 text-left font-medium text-muted-foreground">Site</th>
                                <th class="px-4 py-3 text-right font-medium text-muted-foreground">Montant</th>
                                <th class="px-4 py-3 text-right font-medium text-muted-foreground">Encaissé</th>
                                <th class="px-4 py-3 text-right font-medium text-muted-foreground">Restant</th>
                                <th class="px-4 py-3 text-center font-medium text-muted-foreground">Statut</th>
                                <th class="px-4 py-3 text-left font-medium text-muted-foreground">Date</th>
                                <th class="px-4 py-3" v-if="can('ventes.update')"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="f in facturesFiltrees"
                                :key="f.id"
                                class="hover:bg-muted/10 transition-colors"
                            >
                                <!-- Référence -->
                                <td class="px-4 py-3">
                                    <span class="font-mono text-xs font-semibold">{{ f.reference }}</span>
                                </td>

                                <!-- Véhicule / Client -->
                                <td class="px-4 py-3">
                                    <div v-if="f.vehicule_nom" class="font-medium">{{ f.vehicule_nom }}</div>
                                    <div v-if="f.client_nom" class="text-muted-foreground" :class="{ 'text-xs': f.vehicule_nom }">
                                        {{ f.client_nom }}
                                    </div>
                                    <span v-if="!f.vehicule_nom && !f.client_nom" class="text-muted-foreground">—</span>
                                </td>

                                <!-- Site -->
                                <td class="px-4 py-3 text-muted-foreground">{{ f.site_nom ?? '—' }}</td>

                                <!-- Montant -->
                                <td class="px-4 py-3 text-right tabular-nums font-medium">
                                    {{ formatGNF(f.montant_net) }}
                                </td>

                                <!-- Encaissé -->
                                <td class="px-4 py-3 text-right tabular-nums text-emerald-600 dark:text-emerald-400">
                                    {{ formatGNF(f.montant_encaisse) }}
                                </td>

                                <!-- Restant -->
                                <td class="px-4 py-3 text-right tabular-nums"
                                    :class="f.montant_restant > 0 ? 'text-amber-600 dark:text-amber-400 font-semibold' : 'text-muted-foreground'">
                                    {{ f.montant_restant > 0 ? formatGNF(f.montant_restant) : '—' }}
                                </td>


                                <!-- Statut -->
                                <td class="px-4 py-3 text-center">
                                    <StatusDot
                                        :label="f.statut_label"
                                        :dot-class="statutColor[f.statut_facture] ?? 'bg-zinc-400 dark:bg-zinc-500'"
                                        class="text-muted-foreground"
                                    />
                                </td>

                                <!-- Date -->
                                <td class="px-4 py-3 text-muted-foreground tabular-nums text-xs">
                                    {{ f.created_at }}
                                </td>

                                <!-- Action -->
                                <td v-if="can('ventes.update')" class="px-4 py-3 text-right">
                                    <Button
                                        v-if="!f.is_annulee && !f.is_payee"
                                        size="sm"
                                        variant="outline"
                                        class="h-7 text-xs border-emerald-300 text-emerald-700 hover:bg-emerald-50 dark:border-emerald-700 dark:text-emerald-400 dark:hover:bg-emerald-950"
                                        @click="openDialog(f)"
                                    >
                                        <CreditCard class="mr-1.5 h-3.5 w-3.5" />
                                        Encaisser
                                    </Button>
                                    <span v-else-if="f.is_payee" class="text-xs text-emerald-600 dark:text-emerald-400 font-medium">
                                        Soldée ✓
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div v-if="facturesFiltrees.length === 0" class="py-16 text-center text-sm text-muted-foreground">
                        Aucune facture trouvée.
                    </div>
                </div>
            </div>
        </div>

        <!-- Dialog encaissement ─────────────────────────────────────────────── -->
        <Dialog
            v-model:visible="dialogVisible"
            modal
            header="Enregistrer un encaissement"
            :style="{ width: '520px' }"
        >
            <div v-if="factureActive" class="space-y-5">

                <!-- Résumé facture -->
                <div class="rounded-lg bg-muted/40 p-4 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="text-xs text-muted-foreground">Facture</span>
                        <span class="font-mono text-sm font-semibold">{{ factureActive.reference }}</span>
                    </div>
                    <div v-if="factureActive.vehicule_nom || factureActive.client_nom" class="flex items-center justify-between">
                        <span class="text-xs text-muted-foreground">{{ factureActive.vehicule_nom ? 'Véhicule' : 'Client' }}</span>
                        <span class="text-sm font-medium">{{ factureActive.vehicule_nom ?? factureActive.client_nom }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-xs text-muted-foreground">Montant total</span>
                        <span class="text-sm font-medium tabular-nums">{{ formatGNF(factureActive.montant_net) }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-xs text-muted-foreground">Déjà encaissé</span>
                        <span class="text-sm font-medium tabular-nums text-emerald-600 dark:text-emerald-400">
                            {{ formatGNF(factureActive.montant_encaisse) }}
                        </span>
                    </div>
                    <div class="flex items-center justify-between border-t pt-2">
                        <span class="text-xs font-semibold text-muted-foreground">Restant dû</span>
                        <span class="text-base font-bold tabular-nums text-amber-600 dark:text-amber-400">
                            {{ formatGNF(factureActive.montant_restant) }}
                        </span>
                    </div>
                </div>

                <!-- Formulaire -->
                <div class="grid gap-4 sm:grid-cols-2">

                    <!-- Montant -->
                    <div class="sm:col-span-2">
                        <Label class="mb-1.5 block text-sm">Montant <span class="text-destructive">*</span></Label>
                        <InputNumber
                            :model-value="encaissementForm.montant"
                            @update:model-value="encaissementForm.montant = $event"
                            :min="0.01"
                            :max="factureActive.montant_restant"
                            :use-grouping="true"
                            locale="fr-FR"
                            suffix=" GNF"
                            class="w-full"
                            input-class="w-full text-right text-lg font-bold"
                            :class="{ 'p-invalid': encaissementForm.errors.montant }"
                        />
                        <p v-if="encaissementForm.errors.montant" class="mt-1 text-xs text-destructive">
                            {{ encaissementForm.errors.montant }}
                        </p>
                    </div>

                    <!-- Mode paiement -->
                    <div>
                        <Label class="mb-1.5 block text-sm">Mode de paiement <span class="text-destructive">*</span></Label>
                        <Dropdown
                            v-model="encaissementForm.mode_paiement"
                            :options="modes_paiement"
                            option-label="label"
                            option-value="value"
                            class="w-full"
                            :class="{ 'p-invalid': encaissementForm.errors.mode_paiement }"
                        />
                        <p v-if="encaissementForm.errors.mode_paiement" class="mt-1 text-xs text-destructive">
                            {{ encaissementForm.errors.mode_paiement }}
                        </p>
                    </div>

                    <!-- Date -->
                    <div>
                        <Label class="mb-1.5 block text-sm">Date <span class="text-destructive">*</span></Label>
                        <InputText
                            v-model="encaissementForm.date_encaissement"
                            type="date"
                            class="w-full"
                            :class="{ 'p-invalid': encaissementForm.errors.date_encaissement }"
                        />
                        <p v-if="encaissementForm.errors.date_encaissement" class="mt-1 text-xs text-destructive">
                            {{ encaissementForm.errors.date_encaissement }}
                        </p>
                    </div>

                    <!-- Note -->
                    <div class="sm:col-span-2">
                        <Label class="mb-1.5 block text-sm">Note</Label>
                        <InputText
                            v-model="(encaissementForm.note as string)"
                            class="w-full"
                            placeholder="Optionnel…"
                        />
                    </div>
                </div>
            </div>

            <template #footer>
                <div class="flex justify-end gap-2">
                    <Button variant="outline" @click="dialogVisible = false">Annuler</Button>
                    <Button
                        :disabled="encaissementForm.processing || !encaissementForm.montant"
                        @click="submitEncaissement"
                    >
                        <CreditCard class="mr-2 h-4 w-4" />
                        {{ encaissementForm.processing ? 'Enregistrement…' : 'Valider l\'encaissement' }}
                    </Button>
                </div>
            </template>
        </Dialog>

    </AppLayout>
</template>
