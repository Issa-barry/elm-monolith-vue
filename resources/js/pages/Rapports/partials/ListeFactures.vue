<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { formatGNF } from '@/lib/utils';
import type { LigneFacture } from '@/types/rapports';
import { dateFr } from './format';

// Lignes de factures (Ventes, Dettes clients) : tableau à partir de 640 px, liste empilée
// en dessous — jamais de défilement horizontal sur téléphone.
defineProps<{
    lignes: LigneFacture[];
    afficherAgent: boolean;
    afficherAnciennete?: boolean;
    vide: string;
}>();
</script>

<template>
    <div class="rounded-xl border bg-card">
        <p
            v-if="lignes.length === 0"
            class="px-3 py-8 text-center text-sm text-muted-foreground"
        >
            {{ vide }}
        </p>

        <template v-else>
            <!-- Téléphone : liste empilée -->
            <ul class="divide-y sm:hidden" data-testid="liste-mobile">
                <li
                    v-for="l in lignes"
                    :key="l.id"
                    class="space-y-1 px-3 py-2.5"
                >
                    <div class="flex items-baseline justify-between gap-3">
                        <span class="font-mono text-xs font-medium">{{
                            l.reference
                        }}</span>
                        <span class="text-sm font-semibold tabular-nums">{{
                            formatGNF(l.montant)
                        }}</span>
                    </div>
                    <div
                        class="flex items-center justify-between gap-3 text-xs text-muted-foreground"
                    >
                        <span class="truncate">
                            {{ l.client ?? 'Client non renseigné' }} ·
                            {{ dateFr(l.date) }}
                            <template v-if="afficherAnciennete">
                                · {{ l.anciennete_jours }} j</template
                            >
                            <template v-if="afficherAgent && l.agent">
                                · {{ l.agent }}</template
                            >
                        </span>
                        <StatusDot :status="l.statut" :label="l.statut_label" />
                    </div>
                    <div
                        v-if="l.reste > 0"
                        class="flex justify-between text-xs"
                    >
                        <span class="text-muted-foreground">Reste à payer</span>
                        <span class="font-medium tabular-nums">{{
                            formatGNF(l.reste)
                        }}</span>
                    </div>
                </li>
            </ul>

            <!-- À partir de 640 px : tableau -->
            <div class="hidden overflow-x-auto sm:block">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b bg-muted/40 text-left">
                            <th class="px-3 py-2 font-medium">Facture</th>
                            <th class="px-3 py-2 font-medium">Date</th>
                            <th
                                v-if="afficherAnciennete"
                                class="px-3 py-2 text-right font-medium"
                            >
                                Ancienneté
                            </th>
                            <th class="px-3 py-2 font-medium">Client</th>
                            <th
                                v-if="afficherAgent"
                                class="px-3 py-2 font-medium"
                            >
                                Agent
                            </th>
                            <th class="px-3 py-2 font-medium">Agence</th>
                            <th class="px-3 py-2 text-right font-medium">
                                Montant
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Encaissé
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Reste
                            </th>
                            <th class="px-3 py-2 font-medium">Statut</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="l in lignes"
                            :key="l.id"
                            class="hover:bg-muted/30"
                        >
                            <td class="px-3 py-2 font-mono text-xs">
                                {{ l.reference }}
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                {{ dateFr(l.date) }}
                            </td>
                            <td
                                v-if="afficherAnciennete"
                                class="px-3 py-2 text-right whitespace-nowrap tabular-nums"
                            >
                                {{ l.anciennete_jours }} j
                            </td>
                            <td class="px-3 py-2">{{ l.client ?? '—' }}</td>
                            <td v-if="afficherAgent" class="px-3 py-2">
                                {{ l.agent ?? '—' }}
                            </td>
                            <td class="px-3 py-2">{{ l.site_nom ?? '—' }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ formatGNF(l.montant) }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ formatGNF(l.encaisse) }}
                            </td>
                            <td
                                class="px-3 py-2 text-right font-medium tabular-nums"
                            >
                                {{ formatGNF(l.reste) }}
                            </td>
                            <td class="px-3 py-2">
                                <StatusDot
                                    :status="l.statut"
                                    :label="l.statut_label"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </div>
</template>
