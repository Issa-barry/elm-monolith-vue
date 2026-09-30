<script setup lang="ts">
import ListPageActions from '@/components/ListPageActions.vue';
import StatusDot from '@/components/StatusDot.vue';
import DataFilters from '@/components/filters/DataFilters.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatGNF } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, ArrowRightLeft } from 'lucide-vue-next';
import { computed } from 'vue';

interface Reversement {
    debiteur: { id: string; nom: string };
    creancier: { id: string; nom: string };
    a_verser: number;
    en_cours_versement: number;
    verse: number;
    statut: string;
    statut_label: string;
    detail_url: string;
}

const props = defineProps<{
    reversements: Reversement[];
    filters: { site_ids: string[] };
    sites: { value: string; label: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité' },
    { title: 'Inter-agences', href: '#' },
];

const sitesPourFiltre = computed(() =>
    props.sites.map((s) => ({ id: s.value, nom: s.label })),
);
const avecVersementsEnCours = computed(() =>
    props.reversements.some((ligne) => ligne.en_cours_versement > 0),
);
</script>

<template>
    <Head title="Reversements entre agences" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full space-y-5 p-4 sm:p-6">
            <div
                class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
            >
                <div class="space-y-1">
                    <h1 class="flex items-center gap-2 text-xl font-semibold">
                        <ArrowRightLeft
                            class="h-5 w-5 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        Reversements entre agences
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        L'agence qui a encaissé doit envoyer l'argent à l'agence
                        de la commande.
                    </p>
                </div>
                <ListPageActions>
                    <template #filters>
                        <DataFilters
                            trigger-only
                            url="/backoffice/comptabilite/tresorerie/inter-agences"
                            :values="filters"
                            :fields="[]"
                            :sites="sitesPourFiltre"
                            :result-count="reversements.length"
                            hide-result-count
                        />
                    </template>
                </ListPageActions>
            </div>

            <div
                v-if="reversements.length === 0"
                class="rounded-xl border bg-card px-4 py-12 text-center"
                data-testid="reversements-vide"
            >
                <p class="font-medium">Aucun reversement entre agences</p>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{
                        filters.site_ids.length > 0
                            ? 'Aucun encaissement à reverser ou déjà versé pour les agences sélectionnées.'
                            : 'Aucun encaissement à reverser ou déjà versé entre vos agences.'
                    }}
                </p>
            </div>

            <div v-else class="overflow-hidden rounded-xl border bg-card">
                <table class="block w-full text-sm xl:table">
                    <caption class="sr-only">
                        Agences qui doivent envoyer l'argent, agences
                        destinataires, montants à envoyer, déjà versés et
                        statut.
                    </caption>
                    <thead
                        class="hidden border-b bg-muted/40 xl:table-header-group"
                    >
                        <tr class="text-left text-muted-foreground">
                            <th scope="col" class="px-4 py-3 font-medium">
                                Agence qui doit envoyer
                            </th>
                            <th scope="col" class="px-4 py-3 font-medium">
                                Agence qui reçoit
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-3 text-right font-medium"
                            >
                                Montant à envoyer
                            </th>
                            <th
                                v-if="avecVersementsEnCours"
                                scope="col"
                                class="px-4 py-3 text-right font-medium"
                            >
                                En cours de versement
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-3 text-right font-medium"
                            >
                                Déjà versé
                            </th>
                            <th scope="col" class="px-4 py-3 font-medium">
                                Statut
                            </th>
                            <th scope="col" class="px-4 py-3">
                                <span class="sr-only">Détail</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="block divide-y xl:table-row-group">
                        <tr
                            v-for="ligne in reversements"
                            :key="`${ligne.debiteur.id}:${ligne.creancier.id}`"
                            class="grid grid-cols-2 gap-x-4 gap-y-3 p-4 xl:table-row xl:p-0 xl:hover:bg-muted/20"
                            data-testid="reversement"
                        >
                            <td class="min-w-0 xl:px-4 xl:py-4">
                                <span
                                    class="mb-1 block text-xs text-muted-foreground xl:hidden"
                                    >Agence qui doit envoyer</span
                                >
                                <span
                                    class="font-medium [overflow-wrap:anywhere]"
                                    data-testid="agence-verse"
                                    >{{ ligne.debiteur.nom }}</span
                                >
                            </td>
                            <td class="min-w-0 xl:px-4 xl:py-4">
                                <span
                                    class="mb-1 block text-xs text-muted-foreground xl:hidden"
                                    >Agence qui reçoit</span
                                >
                                <span
                                    class="font-medium [overflow-wrap:anywhere]"
                                    data-testid="agence-recoit"
                                    >{{ ligne.creancier.nom }}</span
                                >
                            </td>
                            <td
                                class="col-span-2 border-t pt-3 xl:border-0 xl:px-4 xl:py-4 xl:text-right"
                            >
                                <span
                                    class="mb-1 block text-xs text-muted-foreground xl:hidden"
                                    >Montant à envoyer</span
                                >
                                <span
                                    class="text-base font-semibold whitespace-nowrap tabular-nums"
                                    data-testid="reste-a-verser"
                                    >{{ formatGNF(ligne.a_verser) }}</span
                                >
                            </td>
                            <td
                                v-if="avecVersementsEnCours"
                                class="col-span-2 xl:px-4 xl:py-4 xl:text-right"
                            >
                                <span
                                    class="mb-1 block text-xs text-muted-foreground xl:hidden"
                                    >En cours de versement</span
                                >
                                <span
                                    class="whitespace-nowrap tabular-nums"
                                    data-testid="en-cours-versement"
                                    >{{
                                        ligne.en_cours_versement > 0
                                            ? formatGNF(
                                                  ligne.en_cours_versement,
                                              )
                                            : '—'
                                    }}</span
                                >
                            </td>
                            <td
                                class="col-span-2 xl:px-4 xl:py-4 xl:text-right"
                            >
                                <span
                                    class="mb-1 block text-xs text-muted-foreground xl:hidden"
                                    >Déjà versé</span
                                >
                                <span
                                    class="whitespace-nowrap tabular-nums"
                                    data-testid="deja-verse"
                                    >{{ formatGNF(ligne.verse) }}</span
                                >
                            </td>
                            <td
                                class="col-span-2 xl:px-4 xl:py-4"
                                data-testid="statut-reversement"
                            >
                                <span
                                    class="mb-1 block text-xs text-muted-foreground xl:hidden"
                                    >Statut</span
                                >
                                <StatusDot
                                    :status="ligne.statut"
                                    :label="ligne.statut_label"
                                />
                            </td>
                            <td
                                class="col-span-2 xl:px-4 xl:py-4 xl:text-right"
                            >
                                <Link
                                    :href="ligne.detail_url"
                                    :aria-label="`Voir le détail du reversement de ${ligne.debiteur.nom} vers ${ligne.creancier.nom}`"
                                    class="inline-flex min-h-10 items-center gap-1.5 rounded-md text-sm font-medium whitespace-nowrap text-primary underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
                                >
                                    Voir le détail
                                    <ArrowRight
                                        class="h-4 w-4"
                                        aria-hidden="true"
                                    />
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p
                    v-if="avecVersementsEnCours"
                    class="border-t bg-muted/20 px-4 py-3 text-xs text-muted-foreground"
                >
                    En cours de versement : l'argent a déjà été envoyé. L'agence
                    destinataire doit encore confirmer sa réception.
                </p>
            </div>
        </div>
    </AppLayout>
</template>
