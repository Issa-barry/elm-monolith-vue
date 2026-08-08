<script setup lang="ts">
// Tableau bénéficiaire/reste-à-payer partagé par les sections "commission"
// (livreurs P1/P2, propriétaires) de Tresorerie/Show.vue — évite de répéter
// 3 fois le même balisage de tableau dans la page.
import { formatGNF } from '@/lib/utils';

interface FicheRow {
    id: string;
    nom: string;
    montant_net: number;
    montant_paye: number;
    montant_restant: number;
    statut_label: string;
}

defineProps<{
    rows: FicheRow[];
}>();
</script>

<template>
    <div
        v-if="rows.length === 0"
        class="px-5 py-8 text-center text-sm text-muted-foreground"
    >
        Aucun reste à payer.
    </div>
    <table v-else class="w-full text-sm">
        <thead>
            <tr class="border-b bg-muted/30 text-left">
                <th class="px-5 py-2.5 font-medium">Bénéficiaire</th>
                <th class="px-5 py-2.5 text-right font-medium">Net</th>
                <th class="px-5 py-2.5 text-right font-medium">Déjà payé</th>
                <th class="px-5 py-2.5 text-right font-medium">
                    Reste à payer
                </th>
                <th class="px-5 py-2.5 text-left font-medium">Statut</th>
            </tr>
        </thead>
        <tbody class="divide-y">
            <tr v-for="r in rows" :key="r.id">
                <td class="px-5 py-2.5">{{ r.nom }}</td>
                <td class="px-5 py-2.5 text-right tabular-nums">
                    {{ formatGNF(r.montant_net) }}
                </td>
                <td class="px-5 py-2.5 text-right tabular-nums">
                    {{ formatGNF(r.montant_paye) }}
                </td>
                <td class="px-5 py-2.5 text-right font-medium tabular-nums">
                    {{ formatGNF(r.montant_restant) }}
                </td>
                <td class="px-5 py-2.5">{{ r.statut_label }}</td>
            </tr>
        </tbody>
    </table>
</template>
