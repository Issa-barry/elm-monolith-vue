<script setup lang="ts">
import DataFilters from '@/components/filters/DataFilters.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatGNF } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowDownLeft, ArrowRightLeft, ArrowUpRight } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Trésorerie → Inter-agences (ADR 0012) : pour chaque agence, ce qu'elle détient pour le compte
 * d'autres agences (« À verser ») et ce que d'autres agences détiennent pour elle (« À recevoir »).
 * Montants calculés par le serveur à partir des encaissements (DetteInterAgencesService).
 */
interface Contrepartie {
    contrepartie_id: string;
    contrepartie_nom: string;
    montant: number;
    en_cours_versement: number;
    nombre?: number;
    detail_url: string;
}

interface Agence {
    site_id: string;
    site_nom: string;
    a_verser: Contrepartie[];
    total_a_verser: number;
    a_recevoir: Contrepartie[];
    total_a_recevoir: number;
}

const props = defineProps<{
    agences: Agence[];
    totaux: { a_verser: number; a_recevoir: number };
    filters: { site_ids: string[] };
    sites: { value: string; label: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité' },
    { title: 'Inter-agences', href: '#' },
];

// DataFilters attend { id, nom } (convention Site), pas { value, label }.
const sitesPourFiltre = computed(() =>
    props.sites.map((s) => ({ id: s.value, nom: s.label })),
);
</script>

<template>
    <Head title="Trésorerie inter-agences" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full space-y-6 p-4 sm:p-6">
            <div class="flex flex-col gap-1">
                <h1 class="flex items-center gap-2 text-xl font-semibold">
                    <ArrowRightLeft class="h-5 w-5 text-muted-foreground" />
                    Trésorerie inter-agences
                </h1>
                <p class="text-sm text-muted-foreground">
                    Encaissements reçus par une agence pour une commande d'une
                    autre agence : ce que chaque agence doit reverser, et ce
                    qu'elle doit recevoir.
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-sm text-muted-foreground">Total à verser</p>
                    <p
                        class="mt-1 text-2xl font-bold tabular-nums"
                        data-testid="total-a-verser"
                    >
                        {{ formatGNF(totaux.a_verser) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-sm text-muted-foreground">
                        Total à recevoir
                    </p>
                    <p
                        class="mt-1 text-2xl font-bold tabular-nums"
                        data-testid="total-a-recevoir"
                    >
                        {{ formatGNF(totaux.a_recevoir) }}
                    </p>
                </div>
            </div>

            <DataFilters
                url="/backoffice/comptabilite/tresorerie/inter-agences"
                :values="filters"
                :fields="[]"
                :sites="sitesPourFiltre"
                :result-count="agences.length"
                hide-result-count
            />

            <div
                v-if="agences.length === 0"
                class="rounded-xl border bg-card p-10 text-center text-sm text-muted-foreground"
            >
                Aucun montant à verser ni à recevoir entre agences.
            </div>

            <div
                v-for="agence in agences"
                :key="agence.site_id"
                class="rounded-xl border bg-card"
                data-testid="agence-inter-agences"
            >
                <div class="border-b px-4 py-3">
                    <h2 class="font-semibold">{{ agence.site_nom }}</h2>
                </div>
                <div class="grid gap-0 md:grid-cols-2 md:divide-x">
                    <section class="p-4">
                        <div class="mb-3 flex items-center justify-between">
                            <h3
                                class="flex items-center gap-1.5 text-sm font-medium"
                            >
                                <ArrowUpRight
                                    class="h-4 w-4 text-amber-600 dark:text-amber-400"
                                />
                                À verser à d'autres agences
                            </h3>
                            <span
                                class="text-sm font-semibold tabular-nums"
                                data-testid="agence-total-a-verser"
                                >{{ formatGNF(agence.total_a_verser) }}</span
                            >
                        </div>
                        <p
                            v-if="agence.a_verser.length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            Rien à verser.
                        </p>
                        <ul v-else class="divide-y">
                            <li
                                v-for="ligne in agence.a_verser"
                                :key="ligne.contrepartie_id"
                            >
                                <Link
                                    :href="ligne.detail_url"
                                    class="flex items-center justify-between gap-3 py-2 text-sm hover:text-primary"
                                >
                                    <span>
                                        {{ ligne.contrepartie_nom }}
                                        <span
                                            v-if="ligne.en_cours_versement > 0"
                                            class="block text-xs text-muted-foreground"
                                        >
                                            +
                                            {{
                                                formatGNF(
                                                    ligne.en_cours_versement,
                                                )
                                            }}
                                            en cours de versement
                                        </span>
                                    </span>
                                    <span class="font-medium tabular-nums">{{
                                        formatGNF(ligne.montant)
                                    }}</span>
                                </Link>
                            </li>
                        </ul>
                    </section>
                    <section class="border-t p-4 md:border-t-0">
                        <div class="mb-3 flex items-center justify-between">
                            <h3
                                class="flex items-center gap-1.5 text-sm font-medium"
                            >
                                <ArrowDownLeft
                                    class="h-4 w-4 text-emerald-600 dark:text-emerald-400"
                                />
                                À recevoir d'autres agences
                            </h3>
                            <span
                                class="text-sm font-semibold tabular-nums"
                                data-testid="agence-total-a-recevoir"
                                >{{ formatGNF(agence.total_a_recevoir) }}</span
                            >
                        </div>
                        <p
                            v-if="agence.a_recevoir.length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            Rien à recevoir.
                        </p>
                        <ul v-else class="divide-y">
                            <li
                                v-for="ligne in agence.a_recevoir"
                                :key="ligne.contrepartie_id"
                            >
                                <Link
                                    :href="ligne.detail_url"
                                    class="flex items-center justify-between gap-3 py-2 text-sm hover:text-primary"
                                >
                                    <span>
                                        {{ ligne.contrepartie_nom }}
                                        <span
                                            v-if="ligne.en_cours_versement > 0"
                                            class="block text-xs text-muted-foreground"
                                        >
                                            dont
                                            {{
                                                formatGNF(
                                                    ligne.en_cours_versement,
                                                )
                                            }}
                                            en cours de versement
                                        </span>
                                    </span>
                                    <span class="font-medium tabular-nums">{{
                                        formatGNF(ligne.montant)
                                    }}</span>
                                </Link>
                            </li>
                        </ul>
                    </section>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
