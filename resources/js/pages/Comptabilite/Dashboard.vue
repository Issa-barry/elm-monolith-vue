<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    FileText,
    ReceiptText,
    TrendingDown,
    TrendingUp,
    Wallet,
} from 'lucide-vue-next';
import Dropdown from 'primevue/dropdown';
import InputText from 'primevue/inputtext';
import { computed, ref } from 'vue';

interface JournalLigne {
    id: string;
    date_operation: string | null;
    libelle: string;
    sens: 'entree' | 'sortie';
    categorie: string;
    categorie_label: string;
    montant: number;
    site: { id: string; nom: string } | null;
}

interface Site {
    id: string;
    nom: string;
}

interface Filtres {
    date_from: string;
    date_to: string;
    site_id: string | null;
}

const props = defineProps<{
    stats_entrees: number;
    stats_sorties: number;
    solde: number;
    fiches_a_payer: number;
    journal_recent: JournalLigne[];
    sites: Site[];
    is_admin: boolean;
    filtres: Filtres;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité', href: '/backoffice/comptabilite' },
];

const dateFrom = ref(props.filtres.date_from);
const dateTo = ref(props.filtres.date_to);
const selectedSite = ref(props.filtres.site_id ?? '');

const siteOptions = computed(() => [
    { label: 'Toutes les agences', value: '' },
    ...props.sites.map((s) => ({ label: s.nom, value: s.id })),
]);

function applyFilters() {
    router.get(
        '/backoffice/comptabilite',
        {
            date_from: dateFrom.value || undefined,
            date_to: dateTo.value || undefined,
            site_id: selectedSite.value || undefined,
        },
        { preserveState: true, replace: true },
    );
}

function fmt(n: number) {
    return new Intl.NumberFormat('fr-FR').format(Math.round(n)) + ' GNF';
}
</script>

<template>
    <Head title="Comptabilité — Tableau de bord" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Comptabilité
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Vision financière globale
                    </p>
                </div>
                <div class="flex gap-2">
                    <Link href="/backoffice/comptabilite/periodes">
                        <Button size="sm">Voir les périodes</Button>
                    </Link>
                </div>
            </div>

            <!-- Filtres -->
            <div class="flex flex-wrap items-end gap-3">
                <div class="flex flex-col gap-1">
                    <label class="text-xs text-muted-foreground">Du</label>
                    <InputText v-model="dateFrom" type="date" class="text-sm" />
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-xs text-muted-foreground">Au</label>
                    <InputText v-model="dateTo" type="date" class="text-sm" />
                </div>
                <Dropdown
                    v-if="is_admin"
                    v-model="selectedSite"
                    :options="siteOptions"
                    option-label="label"
                    option-value="value"
                    placeholder="Toutes les agences"
                    class="min-w-[180px] text-sm"
                />
                <Button variant="outline" size="sm" @click="applyFilters"
                    >Filtrer</Button
                >
            </div>

            <!-- KPI cards -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-muted-foreground"
                            >Entrées</span
                        >
                        <TrendingUp class="h-4 w-4 text-emerald-500" />
                    </div>
                    <p
                        class="mt-2 text-2xl font-bold text-emerald-600 tabular-nums dark:text-emerald-400"
                    >
                        {{ fmt(stats_entrees) }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        Encaissements clients
                    </p>
                </div>

                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-muted-foreground"
                            >Sorties</span
                        >
                        <TrendingDown class="h-4 w-4 text-red-500" />
                    </div>
                    <p
                        class="mt-2 text-2xl font-bold text-red-600 tabular-nums dark:text-red-400"
                    >
                        {{ fmt(stats_sorties) }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        Commissions · salaires · dépenses
                    </p>
                </div>

                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-muted-foreground"
                            >Solde théorique</span
                        >
                        <Wallet class="h-4 w-4 text-blue-500" />
                    </div>
                    <p
                        class="mt-2 text-2xl font-bold tabular-nums"
                        :class="
                            solde >= 0
                                ? 'text-blue-600 dark:text-blue-400'
                                : 'text-red-600 dark:text-red-400'
                        "
                    >
                        {{ fmt(solde) }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        Entrées − Sorties
                    </p>
                </div>

                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-muted-foreground"
                            >Fiches à payer</span
                        >
                        <ReceiptText class="h-4 w-4 text-amber-500" />
                    </div>
                    <p
                        class="mt-2 text-2xl font-bold text-amber-600 tabular-nums dark:text-amber-400"
                    >
                        {{ fiches_a_payer }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        En attente de paiement
                    </p>
                </div>
            </div>

            <!-- Raccourcis -->
            <div class="grid gap-3 sm:grid-cols-3">
                <Link href="/backoffice/comptabilite/periodes" class="group">
                    <div
                        class="flex items-center gap-3 rounded-xl border bg-card p-4 transition-colors group-hover:border-primary/40 group-hover:bg-primary/5"
                    >
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-100 dark:bg-blue-950/30"
                        >
                            <FileText
                                class="h-5 w-5 text-blue-600 dark:text-blue-400"
                            />
                        </div>
                        <div>
                            <p class="font-medium">Périodes de paiement</p>
                            <p class="text-xs text-muted-foreground">
                                Gérer les cycles quinzaine / mois
                            </p>
                        </div>
                    </div>
                </Link>
                <Link
                    href="/backoffice/comptabilite/fiches/livreurs"
                    class="group"
                >
                    <div
                        class="flex items-center gap-3 rounded-xl border bg-card p-4 transition-colors group-hover:border-primary/40 group-hover:bg-primary/5"
                    >
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-lg bg-teal-100 dark:bg-teal-950/30"
                        >
                            <ReceiptText
                                class="h-5 w-5 text-teal-600 dark:text-teal-400"
                            />
                        </div>
                        <div>
                            <p class="font-medium">Fiches livreurs</p>
                            <p class="text-xs text-muted-foreground">
                                Commissions tous les 15 jours
                            </p>
                        </div>
                    </div>
                </Link>
                <Link href="/backoffice/comptabilite/journal" class="group">
                    <div
                        class="flex items-center gap-3 rounded-xl border bg-card p-4 transition-colors group-hover:border-primary/40 group-hover:bg-primary/5"
                    >
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-lg bg-violet-100 dark:bg-violet-950/30"
                        >
                            <Wallet
                                class="h-5 w-5 text-violet-600 dark:text-violet-400"
                            />
                        </div>
                        <div>
                            <p class="font-medium">Journal financier</p>
                            <p class="text-xs text-muted-foreground">
                                Tous les mouvements
                            </p>
                        </div>
                    </div>
                </Link>
            </div>

            <!-- Journal récent -->
            <div class="rounded-xl border bg-card">
                <div
                    class="flex items-center justify-between border-b px-5 py-3"
                >
                    <h2 class="text-sm font-semibold">Derniers mouvements</h2>
                    <Link
                        href="/backoffice/comptabilite/journal"
                        class="text-xs text-primary hover:underline"
                        >Voir tout</Link
                    >
                </div>
                <div
                    v-if="journal_recent.length === 0"
                    class="py-10 text-center text-sm text-muted-foreground"
                >
                    Aucun mouvement sur la période sélectionnée.
                </div>
                <table v-else class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-xs text-muted-foreground">
                            <th class="px-5 py-2.5 text-left font-medium">
                                Date
                            </th>
                            <th class="px-5 py-2.5 text-left font-medium">
                                Libellé
                            </th>
                            <th class="px-5 py-2.5 text-left font-medium">
                                Type
                            </th>
                            <th class="px-5 py-2.5 text-right font-medium">
                                Entrée
                            </th>
                            <th class="px-5 py-2.5 text-right font-medium">
                                Sortie
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/50">
                        <tr
                            v-for="ligne in journal_recent"
                            :key="ligne.id"
                            class="hover:bg-muted/30"
                        >
                            <td
                                class="px-5 py-2.5 font-mono text-xs whitespace-nowrap text-muted-foreground"
                            >
                                {{ ligne.date_operation ?? '—' }}
                            </td>
                            <td class="px-5 py-2.5">{{ ligne.libelle }}</td>
                            <td class="px-5 py-2.5">
                                <span
                                    class="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground"
                                >
                                    {{ ligne.categorie_label }}
                                </span>
                            </td>
                            <td
                                class="px-5 py-2.5 text-right font-mono text-xs"
                            >
                                <span
                                    v-if="ligne.sens === 'entree'"
                                    class="flex items-center justify-end gap-1 text-emerald-600 dark:text-emerald-400"
                                >
                                    <ArrowUp class="h-3 w-3" />
                                    {{ fmt(ligne.montant) }}
                                </span>
                            </td>
                            <td
                                class="px-5 py-2.5 text-right font-mono text-xs"
                            >
                                <span
                                    v-if="ligne.sens === 'sortie'"
                                    class="flex items-center justify-end gap-1 text-red-600 dark:text-red-400"
                                >
                                    <ArrowDown class="h-3 w-3" />
                                    {{ fmt(ligne.montant) }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
