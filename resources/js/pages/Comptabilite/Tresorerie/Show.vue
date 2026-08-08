<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { formatGNF } from '@/lib/utils';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';
import { computed } from 'vue';
import BeneficiaireTable from './partials/BeneficiaireTable.vue';

interface FicheDetail {
    id: string;
    nom: string;
    montant_net: number;
    montant_paye: number;
    montant_restant: number;
    statut: string | null;
    statut_label: string;
}

interface SalaireDetail {
    id: string;
    nom: string | null;
    net: number;
    deja_paye: number;
    reste_a_payer: number;
    statut: string | null;
}

const props = defineProps<{
    site: { id: string | null; nom: string };
    detail: {
        livreurs_p1: FicheDetail[];
        livreurs_p2: FicheDetail[];
        proprietaires: FicheDetail[];
        salaires: SalaireDetail[];
    };
    filters: { annee: string; mois: string; echeance: 'p1' | 'p2' | 'mensuel' };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité', href: '/backoffice/comptabilite' },
    {
        title: 'Besoin de trésorerie',
        href: '/backoffice/comptabilite/tresorerie',
    },
    { title: props.site.nom, href: '#' },
];

const moisOptions = [
    'Janvier',
    'Février',
    'Mars',
    'Avril',
    'Mai',
    'Juin',
    'Juillet',
    'Août',
    'Septembre',
    'Octobre',
    'Novembre',
    'Décembre',
];

const moisLabel = computed(
    () => moisOptions[Number(props.filters.mois) - 1] ?? props.filters.mois,
);

const totalLivreursP1 = computed(() =>
    props.detail.livreurs_p1.reduce((s, l) => s + l.montant_restant, 0),
);
const totalLivreursP2 = computed(() =>
    props.detail.livreurs_p2.reduce((s, l) => s + l.montant_restant, 0),
);
const totalProprietaires = computed(() =>
    props.detail.proprietaires.reduce((s, l) => s + l.montant_restant, 0),
);
const totalSalaires = computed(() =>
    props.detail.salaires.reduce((s, l) => s + l.reste_a_payer, 0),
);

// ── Sections affichées selon l'échéance d'où vient le clic (index) ───────────
// Le détail complet est toujours chargé (voir controller), seule la vue est
// filtrée : un décaissement P1 ne concerne que les livreurs de la 1ère
// quinzaine, un P2 les livreurs de la 2e + propriétaires + salaires.
const showLivreursP1 = computed(() => props.filters.echeance !== 'p2');
const showLivreursP2 = computed(() => props.filters.echeance !== 'p1');
const showProprietaires = computed(() => props.filters.echeance !== 'p1');
const showSalaires = computed(() => props.filters.echeance !== 'p1');

const totalAgence = computed(() => {
    let total = 0;
    if (showLivreursP1.value) total += totalLivreursP1.value;
    if (showLivreursP2.value) total += totalLivreursP2.value;
    if (showProprietaires.value) total += totalProprietaires.value;
    if (showSalaires.value) total += totalSalaires.value;
    return total;
});

const echeanceLabel = computed(() => {
    if (props.filters.echeance === 'p1') return 'Décaissement P1 (1 → 15)';
    if (props.filters.echeance === 'p2')
        return 'Décaissement P2 (16 → fin du mois)';
    return 'Vue mensuelle';
});

const backHref = computed(
    () =>
        `/backoffice/comptabilite/tresorerie?annee=${props.filters.annee}&mois=${props.filters.mois}`,
);
</script>

<template>
    <Head :title="`Besoin de trésorerie — ${site.nom}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full space-y-6 p-4 sm:p-6">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold">{{ site.nom }}</h1>
                    <p class="text-sm text-muted-foreground">
                        {{ moisLabel }} {{ filters.annee }} —
                        {{ echeanceLabel }}
                    </p>
                </div>
                <Link :href="backHref">
                    <Button variant="outline" size="sm">
                        <ArrowLeft class="mr-1.5 h-4 w-4" />
                        Retour
                    </Button>
                </Link>
            </div>

            <div class="rounded-xl border bg-card p-5">
                <p class="text-xs text-muted-foreground">
                    Total à envoyer à l'agence
                </p>
                <p class="mt-1 text-2xl font-bold tabular-nums">
                    {{ formatGNF(totalAgence) }}
                </p>
            </div>

            <!-- Livreurs P1 -->
            <section v-if="showLivreursP1" class="rounded-xl border bg-card">
                <header
                    class="flex items-center justify-between border-b px-5 py-3"
                >
                    <h2 class="text-sm font-semibold">
                        Commissions livreurs — P1 (1 → 15)
                    </h2>
                    <span class="text-sm font-semibold tabular-nums">{{
                        formatGNF(totalLivreursP1)
                    }}</span>
                </header>
                <BeneficiaireTable :rows="detail.livreurs_p1" />
            </section>

            <!-- Livreurs P2 -->
            <section v-if="showLivreursP2" class="rounded-xl border bg-card">
                <header
                    class="flex items-center justify-between border-b px-5 py-3"
                >
                    <h2 class="text-sm font-semibold">
                        Commissions livreurs — P2 (16 → fin du mois)
                    </h2>
                    <span class="text-sm font-semibold tabular-nums">{{
                        formatGNF(totalLivreursP2)
                    }}</span>
                </header>
                <BeneficiaireTable :rows="detail.livreurs_p2" />
            </section>

            <!-- Propriétaires -->
            <section
                v-if="showProprietaires"
                class="rounded-xl border bg-card"
            >
                <header
                    class="flex items-center justify-between border-b px-5 py-3"
                >
                    <h2 class="text-sm font-semibold">
                        Commissions propriétaires — mois complet
                    </h2>
                    <span class="text-sm font-semibold tabular-nums">{{
                        formatGNF(totalProprietaires)
                    }}</span>
                </header>
                <BeneficiaireTable :rows="detail.proprietaires" />
            </section>

            <!-- Salaires -->
            <section v-if="showSalaires" class="rounded-xl border bg-card">
                <header
                    class="flex items-center justify-between border-b px-5 py-3"
                >
                    <h2 class="text-sm font-semibold">Salaires</h2>
                    <span class="text-sm font-semibold tabular-nums">{{
                        formatGNF(totalSalaires)
                    }}</span>
                </header>
                <div
                    v-if="detail.salaires.length === 0"
                    class="px-5 py-8 text-center text-sm text-muted-foreground"
                >
                    Aucun reste à payer.
                </div>
                <table v-else class="w-full text-sm">
                    <thead>
                        <tr class="border-b bg-muted/30 text-left">
                            <th class="px-5 py-2.5 font-medium">Salarié</th>
                            <th class="px-5 py-2.5 text-right font-medium">
                                Net
                            </th>
                            <th class="px-5 py-2.5 text-right font-medium">
                                Déjà payé
                            </th>
                            <th class="px-5 py-2.5 text-right font-medium">
                                Reste à payer
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="l in detail.salaires" :key="l.id">
                            <td class="px-5 py-2.5">{{ l.nom ?? '—' }}</td>
                            <td class="px-5 py-2.5 text-right tabular-nums">
                                {{ formatGNF(l.net) }}
                            </td>
                            <td class="px-5 py-2.5 text-right tabular-nums">
                                {{ formatGNF(l.deja_paye) }}
                            </td>
                            <td
                                class="px-5 py-2.5 text-right font-medium tabular-nums"
                            >
                                {{ formatGNF(l.reste_a_payer) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </AppLayout>
</template>
