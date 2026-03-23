<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { BadgeCheck, Clock, HandCoins, Hourglass } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import { computed, ref } from 'vue';

// ── Types ─────────────────────────────────────────────────────────────────────
interface CommissionItem {
    id: number;
    commande_id: number;
    commande_reference: string | null;
    site_nom: string | null;
    vehicule_nom: string | null;
    immatriculation: string | null;
    livreur_nom: string | null;
    taux_commission: number;
    montant_commande: number;
    montant_commission: number;
    montant_verse: number;
    montant_restant: number;
    statut: string;
    statut_label: string;
    is_versee: boolean;
    is_annulee: boolean;
    created_at: string;
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

// ── Props ─────────────────────────────────────────────────────────────────────
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

// ── Filtres ───────────────────────────────────────────────────────────────────
const filtres = [
    { value: 'tous',       label: 'Toutes' },
    { value: 'en_attente', label: 'En attente' },
    { value: 'partielle',  label: 'Partielles' },
    { value: 'versee',     label: 'Versées' },
    { value: 'annulee',    label: 'Annulées' },
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

// ── Couleurs statut ───────────────────────────────────────────────────────────
const statutColor: Record<string, string> = {
    en_attente: 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
    partielle:  'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300',
    versee:     'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
    annulee:    'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400',
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

// ── Dialog versement ──────────────────────────────────────────────────────────
const dialogVisible     = ref(false);
const commissionActive  = ref<CommissionItem | null>(null);

const versementForm = useForm({
    montant:        0 as number | null,
    date_versement: new Date().toISOString().slice(0, 10),
    mode_paiement:  'especes',
    note:           null as string | null,
});

function openDialog(c: CommissionItem) {
    commissionActive.value      = c;
    versementForm.montant       = c.montant_restant;
    versementForm.date_versement = new Date().toISOString().slice(0, 10);
    versementForm.mode_paiement = 'especes';
    versementForm.note          = null;
    versementForm.clearErrors();
    dialogVisible.value         = true;
}

function submitVersement() {
    if (!commissionActive.value) return;
    versementForm.post(`/commissions/${commissionActive.value.id}/versements`, {
        onSuccess: () => { dialogVisible.value = false; },
    });
}
</script>

<template>
    <Head title="Commissions" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full space-y-6 p-6">

            <!-- En-tête -->
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Commissions livreurs</h1>
                <p class="mt-1 text-sm text-muted-foreground">Suivi et versement des commissions sur ventes.</p>
            </div>

            <!-- Cartes de synthèse -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">

                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <p class="text-sm text-muted-foreground">Restant à verser</p>
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
                        <p class="text-sm text-muted-foreground">Partiellement versées</p>
                        <Clock class="h-4 w-4 text-blue-500" />
                    </div>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-blue-600 dark:text-blue-400">
                        {{ formatCompact(totaux.montant_partielles) }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">{{ totaux.nb_partielles }} commission{{ totaux.nb_partielles > 1 ? 's' : '' }}</p>
                </div>

                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <p class="text-sm text-muted-foreground">Versées</p>
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
                            placeholder="Commande, véhicule, livreur…"
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
                                <th class="px-4 py-3 text-left font-medium text-muted-foreground">Véhicule</th>
                                <th class="px-4 py-3 text-left font-medium text-muted-foreground">Livreur</th>
                                <th class="px-4 py-3 text-left font-medium text-muted-foreground">Site</th>
                                <th class="px-4 py-3 text-right font-medium text-muted-foreground">Commande</th>
                                <th class="px-4 py-3 text-center font-medium text-muted-foreground">Taux</th>
                                <th class="px-4 py-3 text-right font-medium text-muted-foreground">Commission</th>
                                <th class="px-4 py-3 text-right font-medium text-muted-foreground">Versé</th>
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
                                        {{ c.commande_reference ?? '—' }}
                                    </Link>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="font-medium">{{ c.vehicule_nom ?? '—' }}</div>
                                    <div v-if="c.immatriculation" class="font-mono text-xs text-muted-foreground">{{ c.immatriculation }}</div>
                                </td>
                                <td class="px-4 py-3 font-medium">{{ c.livreur_nom ?? '—' }}</td>
                                <td class="px-4 py-3 text-muted-foreground">{{ c.site_nom ?? '—' }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-muted-foreground">{{ formatGNF(c.montant_commande) }}</td>
                                <td class="px-4 py-3 text-center tabular-nums font-medium">{{ c.taux_commission }}%</td>
                                <td class="px-4 py-3 text-right tabular-nums font-semibold">{{ formatGNF(c.montant_commission) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-emerald-600 dark:text-emerald-400">
                                    {{ c.montant_verse > 0 ? formatGNF(c.montant_verse) : '—' }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums"
                                    :class="c.montant_restant > 0 ? 'text-amber-600 dark:text-amber-400 font-semibold' : 'text-muted-foreground'">
                                    {{ c.montant_restant > 0 ? formatGNF(c.montant_restant) : '—' }}
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
                                        Versée ✓
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div v-if="commissionsFiltrees.length === 0" class="py-16 text-center text-sm text-muted-foreground">
                        Aucune commission trouvée.
                    </div>
                </div>
            </div>
        </div>

        <!-- Dialog versement -->
        <Dialog
            v-model:visible="dialogVisible"
            modal
            header="Enregistrer un versement"
            :style="{ width: '500px' }"
        >
            <div v-if="commissionActive" class="space-y-5">

                <!-- Résumé -->
                <div class="rounded-lg bg-muted/40 p-4 space-y-2">
                    <div class="flex justify-between">
                        <span class="text-xs text-muted-foreground">Commande</span>
                        <span class="font-mono text-sm font-semibold">{{ commissionActive.commande_reference }}</span>
                    </div>
                    <div v-if="commissionActive.vehicule_nom" class="flex justify-between">
                        <span class="text-xs text-muted-foreground">Véhicule</span>
                        <span class="text-sm font-medium">{{ commissionActive.vehicule_nom }}</span>
                    </div>
                    <div v-if="commissionActive.livreur_nom" class="flex justify-between">
                        <span class="text-xs text-muted-foreground">Livreur</span>
                        <span class="text-sm font-medium">{{ commissionActive.livreur_nom }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-xs text-muted-foreground">Taux</span>
                        <span class="text-sm font-medium">{{ commissionActive.taux_commission }}%</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-xs text-muted-foreground">Commission totale</span>
                        <span class="text-sm font-medium tabular-nums">{{ formatGNF(commissionActive.montant_commission) }}</span>
                    </div>
                    <div v-if="commissionActive.montant_verse > 0" class="flex justify-between">
                        <span class="text-xs text-muted-foreground">Déjà versé</span>
                        <span class="text-sm font-medium tabular-nums text-emerald-600 dark:text-emerald-400">{{ formatGNF(commissionActive.montant_verse) }}</span>
                    </div>
                    <div class="flex justify-between border-t pt-2">
                        <span class="text-xs font-semibold text-muted-foreground">Restant à verser</span>
                        <span class="text-base font-bold tabular-nums text-amber-600 dark:text-amber-400">{{ formatGNF(commissionActive.montant_restant) }}</span>
                    </div>
                </div>

                <!-- Formulaire -->
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <Label class="mb-1.5 block text-sm">Montant <span class="text-destructive">*</span></Label>
                        <InputNumber
                            :model-value="versementForm.montant"
                            @update:model-value="versementForm.montant = $event"
                            :min="0.01"
                            :max="commissionActive.montant_restant"
                            :use-grouping="true"
                            locale="fr-FR"
                            suffix=" GNF"
                            class="w-full"
                            input-class="w-full text-right text-lg font-bold"
                            :class="{ 'p-invalid': versementForm.errors.montant }"
                        />
                        <p v-if="versementForm.errors.montant" class="mt-1 text-xs text-destructive">{{ versementForm.errors.montant }}</p>
                    </div>

                    <div>
                        <Label class="mb-1.5 block text-sm">Mode <span class="text-destructive">*</span></Label>
                        <Dropdown
                            v-model="versementForm.mode_paiement"
                            :options="modes_paiement"
                            option-label="label"
                            option-value="value"
                            class="w-full"
                        />
                    </div>

                    <div>
                        <Label class="mb-1.5 block text-sm">Date <span class="text-destructive">*</span></Label>
                        <InputText
                            v-model="versementForm.date_versement"
                            type="date"
                            class="w-full"
                        />
                    </div>

                    <div class="sm:col-span-2">
                        <Label class="mb-1.5 block text-sm">Note</Label>
                        <InputText v-model="(versementForm.note as string)" class="w-full" placeholder="Optionnel…" />
                    </div>
                </div>
            </div>

            <template #footer>
                <div class="flex justify-end gap-2">
                    <Button variant="outline" @click="dialogVisible = false">Annuler</Button>
                    <Button
                        :disabled="versementForm.processing || !versementForm.montant"
                        @click="submitVersement"
                    >
                        <HandCoins class="mr-2 h-4 w-4" />
                        {{ versementForm.processing ? 'Enregistrement…' : 'Valider le versement' }}
                    </Button>
                </div>
            </template>
        </Dialog>

    </AppLayout>
</template>
