<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatGNF } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';

interface DetailFiche {
    id: string;
    nom: string | null;
    quinzaine?: string;
    montant_net?: number;
    montant_paye?: number;
    montant_restant?: number;
    net?: number;
    deja_paye?: number;
    reste_a_payer?: number;
    statut: string | null;
    statut_label?: string;
}

const props = defineProps<{
    site: { id: string | null; nom: string };
    detail: Record<string, DetailFiche[]>;
    filters: { annee: string; mois: string };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité' },
    {
        title: 'Financement des agences',
        href: '/backoffice/comptabilite/tresorerie/financement',
    },
    { title: props.site.nom, href: '#' },
];

const sections: { key: string; label: string }[] = [
    { key: 'livreurs_p1', label: 'Commissions livreurs — 1re quinzaine' },
    { key: 'livreurs_p2', label: 'Commissions livreurs — 2e quinzaine' },
    { key: 'proprietaires', label: 'Commissions propriétaires' },
    { key: 'salaires', label: 'Salaires' },
];

function restant(item: DetailFiche): number {
    return item.montant_restant ?? item.reste_a_payer ?? 0;
}

function montantTotal(item: DetailFiche): number {
    return item.montant_net ?? item.net ?? 0;
}

function retourHref(): string {
    return `/backoffice/comptabilite/tresorerie/financement?annee=${props.filters.annee}&mois=${props.filters.mois}`;
}
</script>

<template>
    <Head :title="`Financement — ${site.nom}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full space-y-6 p-4 sm:p-6">
            <div class="flex items-center gap-3">
                <Link
                    :href="retourHref()"
                    class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft class="h-4 w-4" />
                    Retour
                </Link>
            </div>

            <h1 class="text-xl font-semibold">{{ site.nom }}</h1>

            <div
                v-for="section in sections"
                :key="section.key"
                class="space-y-2"
            >
                <h2 class="text-sm font-medium text-muted-foreground">
                    {{ section.label }}
                </h2>
                <div class="overflow-x-auto rounded-xl border bg-card">
                    <table class="w-full min-w-[560px] text-sm">
                        <thead>
                            <tr class="border-b bg-muted/40 text-left">
                                <th class="px-4 py-3 font-medium">
                                    Bénéficiaire
                                </th>
                                <th class="px-4 py-3 text-right font-medium">
                                    Montant
                                </th>
                                <th class="px-4 py-3 text-right font-medium">
                                    Restant
                                </th>
                                <th class="px-4 py-3 text-left font-medium">
                                    Statut
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="item in detail[section.key] ?? []"
                                :key="item.id"
                            >
                                <td class="px-4 py-3">{{ item.nom ?? '—' }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ formatGNF(montantTotal(item)) }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right font-medium tabular-nums"
                                >
                                    {{ formatGNF(restant(item)) }}
                                </td>
                                <td class="px-4 py-3">
                                    <StatusDot
                                        v-if="item.statut"
                                        :status="item.statut"
                                        :label="
                                            item.statut_label ?? item.statut
                                        "
                                    />
                                    <span v-else class="text-muted-foreground"
                                        >—</span
                                    >
                                </td>
                            </tr>
                            <tr v-if="(detail[section.key] ?? []).length === 0">
                                <td
                                    colspan="4"
                                    class="px-4 py-6 text-center text-muted-foreground"
                                >
                                    Rien à payer sur ce poste.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
