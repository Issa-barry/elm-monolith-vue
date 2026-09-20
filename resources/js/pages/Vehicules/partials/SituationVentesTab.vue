<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { formatGNF } from '@/lib/utils';
import { router } from '@inertiajs/vue3';
import {
    CircleDollarSign,
    HandCoins,
    ShoppingBag,
    WalletCards,
} from 'lucide-vue-next';
import SelectButton from 'primevue/selectbutton';
import { computed } from 'vue';

interface SituationVenteProduit {
    variante_id: string;
    libelle: string | null;
    quantite: number;
    montant: number;
}

interface SituationVenteDetail {
    id: string;
    reference: string;
    date: string | null;
    client_nom: string | null;
    montant: number;
    encaisse: number;
    reste: number;
    statut: string | null;
    statut_label: string;
}

interface SituationVentesData {
    kpis: {
        ca_vendu: number;
        encaisse: number;
        reste_du: number;
        nb_ventes: number;
    };
    produits: SituationVenteProduit[];
    ventes: SituationVenteDetail[];
}

const props = defineProps<{
    vehiculeId: string;
    situation: SituationVentesData;
    periode: 'all' | 'month' | 'year';
}>();

// « Vendu » = commande ayant dépassé le stade brouillon et non annulée, cf.
// VehiculeSituationVentesService::pourVehicule() — le filtre ne se rejoue jamais côté client,
// on affiche exactement ce que le backend a déjà calculé pour la période demandée.
const PERIODE_OPTIONS = [
    { label: 'Tout', value: 'all' },
    { label: 'Mois', value: 'month' },
    { label: 'Année', value: 'year' },
];

function onPeriodeChange(value: 'all' | 'month' | 'year' | null): void {
    if (!value || value === props.periode) {
        return;
    }
    router.get(
        `/backoffice/vehicules/${props.vehiculeId}`,
        { situation_periode: value },
        { preserveScroll: true, preserveState: true, replace: true },
    );
}

const totalProduits = computed(() => ({
    quantite: props.situation.produits.reduce((s, p) => s + p.quantite, 0),
    montant: props.situation.produits.reduce((s, p) => s + p.montant, 0),
}));
</script>

<template>
    <div class="space-y-6">
        <div class="flex items-center justify-between gap-3">
            <h2
                class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
            >
                Situation des ventes
            </h2>
            <SelectButton
                :model-value="periode"
                :options="PERIODE_OPTIONS"
                option-label="label"
                option-value="value"
                class="w-max [&_.p-togglebutton]:h-9 [&_.p-togglebutton]:px-3"
                @update:model-value="onPeriodeChange"
            />
        </div>

        <!-- KPI -->
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border bg-card p-4 shadow-sm">
                <div class="flex items-start justify-between gap-3">
                    <p class="text-xs font-medium text-muted-foreground">
                        CA vendu
                    </p>
                    <span
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"
                    >
                        <CircleDollarSign class="h-4 w-4" />
                    </span>
                </div>
                <p
                    class="mt-3 text-xl font-semibold tracking-tight tabular-nums"
                >
                    {{ formatGNF(situation.kpis.ca_vendu) }}
                </p>
            </div>

            <div class="rounded-xl border bg-card p-4 shadow-sm">
                <div class="flex items-start justify-between gap-3">
                    <p class="text-xs font-medium text-muted-foreground">
                        Encaissé
                    </p>
                    <span
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"
                    >
                        <HandCoins class="h-4 w-4" />
                    </span>
                </div>
                <p
                    class="mt-3 text-xl font-semibold tracking-tight tabular-nums"
                >
                    {{ formatGNF(situation.kpis.encaisse) }}
                </p>
            </div>

            <div
                class="rounded-xl border bg-card p-4 shadow-sm"
                :class="
                    situation.kpis.reste_du > 0
                        ? 'border-amber-300/70 bg-amber-500/5 dark:border-amber-900'
                        : ''
                "
            >
                <div class="flex items-start justify-between gap-3">
                    <p class="text-xs font-medium text-muted-foreground">
                        Reste dû
                    </p>
                    <span
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400"
                    >
                        <WalletCards class="h-4 w-4" />
                    </span>
                </div>
                <p
                    class="mt-3 text-xl font-semibold tracking-tight tabular-nums"
                    :class="
                        situation.kpis.reste_du > 0
                            ? 'text-amber-600 dark:text-amber-400'
                            : ''
                    "
                >
                    {{ formatGNF(situation.kpis.reste_du) }}
                </p>
            </div>

            <div class="rounded-xl border bg-card p-4 shadow-sm">
                <div class="flex items-start justify-between gap-3">
                    <p class="text-xs font-medium text-muted-foreground">
                        Nombre de ventes
                    </p>
                    <span
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-blue-500/10 text-blue-600 dark:text-blue-400"
                    >
                        <ShoppingBag class="h-4 w-4" />
                    </span>
                </div>
                <p
                    class="mt-3 text-xl font-semibold tracking-tight tabular-nums"
                >
                    {{ situation.kpis.nb_ventes }}
                </p>
            </div>
        </div>

        <!-- Produits vendus -->
        <div class="rounded-xl border bg-card p-5 sm:p-6">
            <h3
                class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
            >
                Produits vendus
            </h3>

            <div
                v-if="!situation.produits.length"
                class="rounded-lg border border-dashed py-10 text-center"
            >
                <p class="text-sm text-muted-foreground">
                    Aucun produit vendu sur cette période.
                </p>
            </div>

            <div v-else class="overflow-x-auto rounded-lg border">
                <table class="w-full min-w-[520px] text-sm">
                    <thead class="bg-muted/30 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-3 font-medium">Produit</th>
                            <th class="px-4 py-3 text-right font-medium">
                                Quantité vendue
                            </th>
                            <th class="px-4 py-3 text-right font-medium">
                                Montant
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="p in situation.produits"
                            :key="p.variante_id"
                            class="hover:bg-muted/20"
                        >
                            <td class="px-4 py-3 font-medium">
                                {{ p.libelle ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ p.quantite }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ formatGNF(p.montant) }}
                            </td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t bg-muted/30 font-semibold">
                        <tr>
                            <td class="px-4 py-3">TOTAL</td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ totalProduits.quantite }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ formatGNF(totalProduits.montant) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Historique des ventes -->
        <div class="rounded-xl border bg-card p-5 sm:p-6">
            <h3
                class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
            >
                Historique des ventes
            </h3>

            <div
                v-if="!situation.ventes.length"
                class="rounded-lg border border-dashed py-10 text-center"
            >
                <p class="text-sm text-muted-foreground">
                    Aucune vente enregistrée pour ce véhicule sur cette période.
                </p>
            </div>

            <div v-else class="overflow-x-auto rounded-lg border">
                <table class="w-full min-w-[760px] text-sm">
                    <thead class="bg-muted/30 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-3 font-medium">Date</th>
                            <th class="px-4 py-3 font-medium">Référence</th>
                            <th class="px-4 py-3 font-medium">Client</th>
                            <th class="px-4 py-3 text-right font-medium">
                                Montant
                            </th>
                            <th class="px-4 py-3 text-right font-medium">
                                Encaissé
                            </th>
                            <th class="px-4 py-3 text-right font-medium">
                                Reste
                            </th>
                            <th class="px-4 py-3 font-medium">Statut</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="v in situation.ventes"
                            :key="v.id"
                            class="hover:bg-muted/20"
                        >
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ v.date ?? '—' }}
                            </td>
                            <td class="px-4 py-3 font-medium">
                                {{ v.reference }}
                            </td>
                            <td class="px-4 py-3">
                                {{ v.client_nom ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ formatGNF(v.montant) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ formatGNF(v.encaisse) }}
                            </td>
                            <td
                                class="px-4 py-3 text-right tabular-nums"
                                :class="
                                    v.reste > 0
                                        ? 'text-amber-600 dark:text-amber-400'
                                        : ''
                                "
                            >
                                {{ formatGNF(v.reste) }}
                            </td>
                            <td class="px-4 py-3">
                                <StatusDot
                                    :status="v.statut"
                                    :label="v.statut_label"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
