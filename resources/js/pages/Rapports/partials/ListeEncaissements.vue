<script setup lang="ts">
import { formatGNF } from '@/lib/utils';
import type {
    AnomalieMobileMoney,
    LigneEncaissement,
    LigneMobileMoney,
} from '@/types/rapports';
import { AlertTriangle } from 'lucide-vue-next';
import { dateFr, heureFr } from './format';

// Lignes d'encaissements (onglets Encaissements et Mobile Money) : tableau à partir de 640 px,
// liste empilée en dessous. En mode Mobile Money, chaque ligne affiche le contrôle de référence.
defineProps<{
    lignes: (LigneEncaissement | Partial<LigneMobileMoney>)[];
    afficherAgent: boolean;
    controle?: boolean;
    vide: string;
    total?: number;
}>();

const LIBELLES_ANOMALIE: Record<AnomalieMobileMoney, string> = {
    reference_absente: 'Référence absente',
    reference_dupliquee: 'Référence déjà utilisée',
    anterieure_obligation: "Sans référence (avant l'obligation)",
};

function anomalie(l: Partial<LigneMobileMoney>) {
    return l.anomalie ?? null;
}

function autres(l: Partial<LigneMobileMoney>): string | null {
    const visibles = (l.autres_utilisations ?? []).map(
        (a) => `${a.facture_reference} (${dateFr(a.date_encaissement)})`,
    );
    if ((l.autres_hors_perimetre ?? 0) > 0) {
        visibles.push(`${l.autres_hors_perimetre} hors de votre périmètre`);
    }

    return visibles.length > 0 ? `Aussi sur ${visibles.join(', ')}` : null;
}
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
                        <span class="text-sm font-medium">{{
                            l.moyen_libelle
                        }}</span>
                        <span class="text-sm font-semibold tabular-nums">{{
                            formatGNF(l.montant)
                        }}</span>
                    </div>
                    <div class="text-xs text-muted-foreground">
                        <span class="font-mono">{{ l.facture_reference }}</span>
                        · {{ l.client ?? 'Client non renseigné' }}
                        <template v-if="afficherAgent && l.agent">
                            · {{ l.agent }}</template
                        >
                    </div>
                    <div
                        class="flex flex-wrap justify-between gap-x-3 text-xs text-muted-foreground"
                    >
                        <span>
                            {{ dateFr(l.date_encaissement) }}
                            <span
                                v-if="l.saisie_differee"
                                class="text-amber-700 dark:text-amber-400"
                                >· saisi le {{ heureFr(l.saisi_le) }}</span
                            >
                        </span>
                        <span v-if="l.reference_paiement" class="font-mono">{{
                            l.reference_paiement
                        }}</span>
                    </div>
                    <div v-if="controle && anomalie(l)" class="text-xs">
                        <span
                            v-if="anomalie(l) === 'anterieure_obligation'"
                            class="text-muted-foreground"
                            >{{ LIBELLES_ANOMALIE[anomalie(l)!] }}</span
                        >
                        <span
                            v-else
                            class="inline-flex items-center gap-1 font-medium text-amber-700 dark:text-amber-400"
                        >
                            <AlertTriangle class="h-3.5 w-3.5" />
                            {{ LIBELLES_ANOMALIE[anomalie(l)!] }}
                        </span>
                        <span
                            v-if="autres(l)"
                            class="block text-muted-foreground"
                            >{{ autres(l) }}</span
                        >
                    </div>
                </li>
            </ul>

            <!-- À partir de 640 px : tableau -->
            <div class="hidden overflow-x-auto sm:block">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b bg-muted/40 text-left">
                            <th class="px-3 py-2 font-medium">Date</th>
                            <th class="px-3 py-2 font-medium">Saisi le</th>
                            <th class="px-3 py-2 font-medium">Facture</th>
                            <th class="px-3 py-2 font-medium">Client</th>
                            <th
                                v-if="afficherAgent"
                                class="px-3 py-2 font-medium"
                            >
                                Agent
                            </th>
                            <th class="px-3 py-2 font-medium">Moyen</th>
                            <th class="px-3 py-2 font-medium">Référence</th>
                            <th class="px-3 py-2 text-right font-medium">
                                Montant
                            </th>
                            <th v-if="controle" class="px-3 py-2 font-medium">
                                Contrôle
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="l in lignes"
                            :key="l.id"
                            class="hover:bg-muted/30"
                            :data-testid="
                                controle
                                    ? `mobile-money-ligne-${l.id}`
                                    : undefined
                            "
                        >
                            <td class="px-3 py-2 whitespace-nowrap">
                                {{ dateFr(l.date_encaissement) }}
                            </td>
                            <td
                                class="px-3 py-2 whitespace-nowrap"
                                :class="
                                    l.saisie_differee
                                        ? 'text-amber-700 dark:text-amber-400'
                                        : 'text-muted-foreground'
                                "
                                :title="
                                    l.saisie_differee
                                        ? 'Saisi un autre jour que la date d\'encaissement'
                                        : undefined
                                "
                            >
                                {{ heureFr(l.saisi_le) }}
                            </td>
                            <td class="px-3 py-2 font-mono text-xs">
                                {{ l.facture_reference }}
                            </td>
                            <td class="px-3 py-2">{{ l.client ?? '—' }}</td>
                            <td v-if="afficherAgent" class="px-3 py-2">
                                {{ l.agent ?? '—' }}
                            </td>
                            <td class="px-3 py-2">{{ l.moyen_libelle }}</td>
                            <td class="px-3 py-2 font-mono text-xs">
                                {{ l.reference_paiement ?? '—' }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ formatGNF(l.montant) }}
                            </td>
                            <td v-if="controle" class="px-3 py-2 text-xs">
                                <span
                                    v-if="anomalie(l) === null"
                                    class="text-muted-foreground"
                                    >OK</span
                                >
                                <span
                                    v-else-if="
                                        anomalie(l) === 'anterieure_obligation'
                                    "
                                    class="text-muted-foreground"
                                    >{{ LIBELLES_ANOMALIE[anomalie(l)!] }}</span
                                >
                                <span
                                    v-else
                                    class="inline-flex items-center gap-1 font-medium text-amber-700 dark:text-amber-400"
                                >
                                    <AlertTriangle class="h-3.5 w-3.5" />
                                    {{ LIBELLES_ANOMALIE[anomalie(l)!] }}
                                </span>
                                <div
                                    v-if="autres(l)"
                                    class="mt-0.5 text-muted-foreground"
                                >
                                    {{ autres(l) }}
                                </div>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot v-if="total !== undefined">
                        <tr class="border-t bg-muted/20 font-semibold">
                            <td
                                :colspan="afficherAgent ? 7 : 6"
                                class="px-3 py-2"
                            >
                                Total de la période
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ formatGNF(total) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </template>
    </div>
</template>
