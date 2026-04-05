<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    BadgeCheck,
    ChevronRight,
    HandCoins,
    Hourglass,
    Search,
    Sigma,
} from 'lucide-vue-next';
import Dropdown from 'primevue/dropdown';
import { computed, ref } from 'vue';

// -- Types ---------------------------------------------------------------------
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
    nb_parts: number;
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

// -- Props ---------------------------------------------------------------------
const props = defineProps<{
    commissions: CommissionItem[];
    totaux: Totaux;
    periode: string;
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Commissions', href: '/commissions' },
];

// -- Filtres -------------------------------------------------------------------
const filtres = [
    { value: 'tous', label: 'Toutes' },
    { value: 'en_attente', label: 'En attente' },
    { value: 'partielle', label: 'Partielles' },
    { value: 'versee', label: 'Versées' },
    { value: 'annulee', label: 'Annulées' },
];

const periodes = [
    { value: 'today', label: "Aujourd'hui" },
    { value: 'week', label: 'Cette semaine' },
    { value: 'month', label: 'Ce mois' },
    { value: 'all', label: 'Tout' },
];

const filtreStatut = ref('tous');
const search = ref('');
const mobileSearch = ref('');

function setPeriode(p: string) {
    router.get('/commissions', { periode: p }, { preserveScroll: true, replace: true });
}

const totalCommissions = computed(() =>
    props.commissions.reduce((sum, c) => sum + c.montant_commission_totale, 0),
);
const nbTotalCommissions = computed(() => props.commissions.length);

function filterList(list: CommissionItem[], q: string) {
    if (filtreStatut.value !== 'tous') {
        list = list.filter((c) => c.statut === filtreStatut.value);
    }
    const query = q.toLowerCase().trim();
    if (query) {
        list = list.filter(
            (c) =>
                (c.commande_reference && c.commande_reference.toLowerCase().includes(query)) ||
                (c.vehicule_nom && c.vehicule_nom.toLowerCase().includes(query)) ||
                (c.immatriculation && c.immatriculation.toLowerCase().includes(query)) ||
                (c.equipe_nom && c.equipe_nom.toLowerCase().includes(query)) ||
                (c.proprietaire_nom && c.proprietaire_nom.toLowerCase().includes(query)) ||
                (c.site_nom && c.site_nom.toLowerCase().includes(query)),
        );
    }
    return list;
}

const commissionsFiltrees = computed(() => filterList([...props.commissions], search.value));
const mobileFiltered = computed(() => filterList([...props.commissions], mobileSearch.value));

// -- Couleurs statut -----------------------------------------------------------
const statutDotColor: Record<string, string> = {
    en_attente: 'bg-amber-500',
    partielle: 'bg-blue-500',
    versee: 'bg-emerald-500',
    annulee: 'bg-zinc-400 dark:bg-zinc-500',
};

function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}
</script>

<template>
    <Head title="Commissions" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- ── MOBILE VIEW ─────────────────────────────────────────────────── -->
        <div class="flex flex-col sm:hidden">
            <!-- Sticky header -->
            <div class="sticky top-0 z-10 flex items-center justify-between border-b bg-background px-4 py-3">
                <Link href="/dashboard" class="flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground hover:text-foreground">
                    <ArrowLeft class="h-5 w-5" />
                </Link>
                <span class="text-base font-semibold">Commissions</span>
                <div class="w-8" />
            </div>

            <!-- KPI cards -->
            <div class="grid grid-cols-2 gap-3 p-4">
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground">Restant à verser</p>
                    <p class="mt-1 text-lg font-bold text-amber-600 tabular-nums dark:text-amber-400">
                        {{ formatGNF(totaux.total_a_verser) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground">En attente</p>
                    <p class="mt-1 text-lg font-bold text-amber-600 tabular-nums dark:text-amber-400">
                        {{ formatGNF(totaux.montant_en_attente) }}
                    </p>
                    <p class="text-xs text-muted-foreground">{{ totaux.nb_en_attente }} commission{{ totaux.nb_en_attente > 1 ? 's' : '' }}</p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground">Partielles</p>
                    <p class="mt-1 text-lg font-bold text-blue-600 tabular-nums dark:text-blue-400">
                        {{ formatGNF(totaux.montant_partielles) }}
                    </p>
                    <p class="text-xs text-muted-foreground">{{ totaux.nb_partielles }} commission{{ totaux.nb_partielles > 1 ? 's' : '' }}</p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground">Versées</p>
                    <p class="mt-1 text-lg font-bold text-emerald-600 tabular-nums dark:text-emerald-400">
                        {{ formatGNF(totaux.montant_versees) }}
                    </p>
                    <p class="text-xs text-muted-foreground">{{ totaux.nb_versees }} commission{{ totaux.nb_versees > 1 ? 's' : '' }}</p>
                </div>
            </div>

            <!-- Search -->
            <div class="border-t border-b px-4 py-2">
                <div class="relative">
                    <Search class="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <input
                        v-model="mobileSearch"
                        type="text"
                        placeholder="Commande, véhicule, équipe…"
                        class="h-9 w-full rounded-md border border-input bg-background pr-3 pl-8 text-sm placeholder:text-muted-foreground focus:ring-1 focus:ring-ring focus:outline-none"
                    />
                </div>
            </div>

            <!-- Card list -->
            <div class="divide-y">
                <Link
                    v-for="c in mobileFiltered"
                    :key="c.id"
                    :href="`/commissions/${c.id}`"
                    class="flex items-start justify-between gap-3 px-4 py-3 transition-colors active:bg-muted/40"
                >
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium">{{ c.vehicule_nom ?? '—' }}</p>
                        <p v-if="c.equipe_nom" class="text-xs text-muted-foreground">{{ c.equipe_nom }}</p>
                        <p class="mt-0.5 font-mono text-xs font-semibold text-primary">{{ c.commande_reference ?? '—' }}</p>
                        <p class="mt-1 text-sm font-semibold tabular-nums">{{ formatGNF(c.montant_commission_totale) }}</p>
                        <p v-if="c.montant_restant > 0" class="text-xs font-semibold text-amber-600 tabular-nums dark:text-amber-400">
                            Restant : {{ formatGNF(c.montant_restant) }}
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-2">
                        <StatusDot
                            :label="c.statut_label"
                            :dot-class="statutDotColor[c.statut] ?? 'bg-zinc-400 dark:bg-zinc-500'"
                            class="text-xs text-muted-foreground"
                        />
                        <span class="text-xs text-muted-foreground tabular-nums">{{ c.created_at }}</span>
                        <ChevronRight class="h-4 w-4 text-muted-foreground" />
                    </div>
                </Link>
            </div>

            <div v-if="mobileFiltered.length === 0" class="py-16 text-center text-sm text-muted-foreground">
                Aucune commission trouvée.
            </div>
        </div>

        <!-- ── DESKTOP VIEW ────────────────────────────────────────────────── -->
        <div class="hidden w-full space-y-6 p-4 sm:block sm:p-6">
            <!-- En-tête -->
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Commissions livreurs</h1>
                <p class="mt-1 text-sm text-muted-foreground">Suivi et versement des commissions sur ventes.</p>
            </div>

            <!-- Cartes de synthèse -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <p class="text-sm text-muted-foreground">Total commissions</p>
                        <Sigma class="h-4 w-4 text-primary" />
                    </div>
                    <p class="mt-2 text-2xl font-bold tabular-nums">{{ formatGNF(totalCommissions) }}</p>
                    <p class="mt-0.5 text-xs text-muted-foreground">{{ nbTotalCommissions }} commission{{ nbTotalCommissions > 1 ? 's' : '' }}</p>
                </div>

                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <p class="text-sm text-muted-foreground">Restant à verser</p>
                        <HandCoins class="h-4 w-4 text-muted-foreground" />
                    </div>
                    <p class="mt-2 text-2xl font-bold tabular-nums">{{ formatGNF(totaux.total_a_verser) }}</p>
                </div>

                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <p class="text-sm text-muted-foreground">En attente</p>
                        <Hourglass class="h-4 w-4 text-amber-500" />
                    </div>
                    <p class="mt-2 text-2xl font-bold tabular-nums">{{ formatGNF(totaux.montant_en_attente) }}</p>
                    <p class="mt-0.5 text-xs text-muted-foreground">{{ totaux.nb_en_attente }} commission{{ totaux.nb_en_attente > 1 ? 's' : '' }}</p>
                </div>

                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <p class="text-sm text-muted-foreground">Versées</p>
                        <BadgeCheck class="h-4 w-4 text-emerald-500" />
                    </div>
                    <p class="mt-2 text-2xl font-bold text-emerald-600 tabular-nums dark:text-emerald-400">{{ formatGNF(totaux.montant_versees) }}</p>
                    <p class="mt-0.5 text-xs text-muted-foreground">{{ totaux.nb_versees }} commission{{ totaux.nb_versees > 1 ? 's' : '' }}</p>
                </div>
            </div>

            <!-- Filtres -->
            <div class="flex flex-wrap items-center gap-3">
                <div class="relative flex-1 max-w-xs">
                    <Search class="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Commande, véhicule, équipe…"
                        class="h-9 w-full rounded-md border border-input bg-background pr-3 pl-8 text-sm shadow-sm placeholder:text-muted-foreground focus:ring-1 focus:ring-ring focus:outline-none"
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

            <!-- Tableau -->
            <div class="overflow-hidden rounded-xl border bg-card">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b bg-muted/40">
                                <th class="px-4 py-3 text-left font-medium text-muted-foreground">Commande</th>
                                <th class="px-4 py-3 text-left font-medium text-muted-foreground">Véhicule</th>
                                <th class="px-4 py-3 text-left font-medium text-muted-foreground">Équipe / Propriétaire</th>
                                <th class="px-4 py-3 text-left font-medium text-muted-foreground">Site</th>
                                <th class="px-4 py-3 text-right font-medium text-muted-foreground">Commission</th>
                                <th class="px-4 py-3 text-right font-medium text-muted-foreground">Versé</th>
                                <th class="px-4 py-3 text-right font-medium text-muted-foreground">Restant</th>
                                <th class="px-4 py-3 text-left font-medium text-muted-foreground">Statut</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="c in commissionsFiltrees"
                                :key="c.id"
                                class="transition-colors hover:bg-muted/10"
                            >
                                <td class="px-4 py-3">
                                    <Link
                                        v-if="c.commande_id"
                                        :href="`/ventes/${c.commande_id}`"
                                        class="font-mono text-xs font-semibold text-primary hover:underline"
                                    >
                                        {{ c.commande_reference ?? '—' }}
                                    </Link>
                                    <span v-else class="font-mono text-xs">{{ c.commande_reference ?? '—' }}</span>
                                    <p class="text-xs text-muted-foreground">{{ c.created_at }}</p>
                                </td>
                                <td class="px-4 py-3">
                                    <p class="font-medium">{{ c.vehicule_nom ?? '—' }}</p>
                                    <p v-if="c.immatriculation" class="font-mono text-xs text-muted-foreground">{{ c.immatriculation }}</p>
                                </td>
                                <td class="px-4 py-3">
                                    <p class="text-muted-foreground">{{ c.equipe_nom ?? '—' }}</p>
                                    <p v-if="c.proprietaire_nom" class="text-xs text-muted-foreground/70">{{ c.proprietaire_nom }}</p>
                                    <span class="mt-0.5 inline-block text-xs text-muted-foreground/60">{{ c.nb_parts }} part{{ c.nb_parts > 1 ? 's' : '' }}</span>
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">{{ c.site_nom ?? '—' }}</td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ formatGNF(c.montant_commission_totale) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-muted-foreground">{{ formatGNF(c.montant_verse) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    <span :class="c.montant_restant > 0 ? 'font-semibold text-amber-600 dark:text-amber-400' : 'text-muted-foreground'">
                                        {{ formatGNF(c.montant_restant) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <StatusDot
                                        :label="c.statut_label"
                                        :dot-class="statutDotColor[c.statut] ?? 'bg-zinc-400 dark:bg-zinc-500'"
                                        class="text-muted-foreground"
                                    />
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <Link :href="`/commissions/${c.id}`">
                                        <Button size="sm" variant="ghost" class="h-8 gap-1.5 text-xs">
                                            Détails
                                            <ChevronRight class="h-3.5 w-3.5" />
                                        </Button>
                                    </Link>
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
    </AppLayout>
</template>
