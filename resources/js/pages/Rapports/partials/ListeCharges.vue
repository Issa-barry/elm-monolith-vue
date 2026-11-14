<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { formatGNF } from '@/lib/utils';
import type { LigneCharge } from '@/types/rapports';
import { Link } from '@inertiajs/vue3';
import { ArrowUpRight, Receipt } from 'lucide-vue-next';
import { dateFr } from './format';

// Charges saisies (dépenses validées ou en attente de validation) : tableau à partir de 640 px,
// liste empilée en dessous. Lien vers la dépense seulement si son écran est ouvert à l'utilisateur.
defineProps<{
    lignes: LigneCharge[];
    afficherAgent: boolean;
    peutVoir: boolean;
}>();
</script>

<template>
    <div class="overflow-hidden rounded-xl border bg-card">
        <div
            v-if="lignes.length === 0"
            class="flex flex-col items-center gap-2 px-4 py-8 text-center"
        >
            <Receipt
                class="mb-1 h-7 w-7 text-muted-foreground"
                aria-hidden="true"
            />
            <p class="text-sm font-medium">
                Aucune charge saisie sur la période.
            </p>
            <p class="max-w-sm text-xs leading-relaxed text-muted-foreground">
                Essayez une autre période ou ajustez les filtres.
            </p>
        </div>

        <template v-else>
            <!-- Téléphone : liste empilée -->
            <ul class="divide-y sm:hidden" data-testid="charges-liste-mobile">
                <li
                    v-for="l in lignes"
                    :key="l.id"
                    class="space-y-1 px-3 py-2.5"
                >
                    <div class="flex items-baseline justify-between gap-3">
                        <span class="truncate text-sm font-medium">{{
                            l.type ?? 'Dépense'
                        }}</span>
                        <span class="text-sm font-semibold tabular-nums">{{
                            formatGNF(l.montant)
                        }}</span>
                    </div>
                    <div
                        class="flex items-center justify-between gap-3 text-xs text-muted-foreground"
                    >
                        <span class="truncate">
                            {{ dateFr(l.date) }}
                            <template v-if="l.site_nom">
                                · {{ l.site_nom }}</template
                            >
                            <template v-if="afficherAgent && l.agent">
                                · {{ l.agent }}</template
                            >
                        </span>
                        <StatusDot :status="l.statut" :label="l.statut_label" />
                    </div>
                </li>
            </ul>

            <!-- À partir de 640 px : tableau -->
            <div class="hidden overflow-x-auto sm:block">
                <table class="w-full text-sm" data-testid="charges-tableau">
                    <thead>
                        <tr class="border-b bg-muted/40 text-left">
                            <th class="px-3 py-2 font-medium">Date</th>
                            <th class="px-3 py-2 font-medium">Type</th>
                            <th class="px-3 py-2 font-medium">Catégorie</th>
                            <th class="px-3 py-2 font-medium">Agence</th>
                            <th
                                v-if="afficherAgent"
                                class="px-3 py-2 font-medium"
                            >
                                Saisie par
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Montant
                            </th>
                            <th class="px-3 py-2 font-medium">Statut</th>
                            <th class="px-3 py-2">
                                <span class="sr-only">Détail</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="l in lignes" :key="l.id">
                            <td class="px-3 py-2 whitespace-nowrap">
                                {{ dateFr(l.date) }}
                            </td>
                            <td class="px-3 py-2">
                                {{ l.type ?? '—' }}
                                <span
                                    v-if="l.commentaire"
                                    class="block max-w-xs truncate text-xs text-muted-foreground"
                                    :title="l.commentaire"
                                    >{{ l.commentaire }}</span
                                >
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                {{ l.categorie ?? '—' }}
                                <span
                                    v-if="l.avance_tiers"
                                    class="block text-xs text-muted-foreground"
                                    title="Avance récupérable sur la commission du bénéficiaire, pas une charge de l'entreprise"
                                    >avance récupérable</span
                                >
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                {{ l.site_nom ?? '—' }}
                            </td>
                            <td v-if="afficherAgent" class="px-3 py-2">
                                {{ l.agent ?? '—' }}
                            </td>
                            <td
                                class="px-3 py-2 text-right font-medium whitespace-nowrap tabular-nums"
                            >
                                {{ formatGNF(l.montant) }}
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                <StatusDot
                                    :status="l.statut"
                                    :label="l.statut_label"
                                />
                            </td>
                            <td class="px-3 py-1.5 text-right">
                                <Link
                                    v-if="peutVoir"
                                    :href="`/backoffice/depenses/${l.id}`"
                                    :aria-label="`Voir la dépense du ${dateFr(l.date)}`"
                                    title="Voir la dépense"
                                    data-testid="charge-detail"
                                    class="inline-flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-primary focus-visible:outline-2 focus-visible:outline-ring"
                                >
                                    <ArrowUpRight
                                        class="h-4 w-4"
                                        aria-hidden="true"
                                    />
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </div>
</template>
