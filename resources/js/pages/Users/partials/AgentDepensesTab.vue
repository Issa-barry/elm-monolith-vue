<script setup lang="ts">
import SituationKpiCard from '@/components/situation/SituationKpiCard.vue';
import SituationSection from '@/components/situation/SituationSection.vue';
import StatusDot from '@/components/StatusDot.vue';
import { formatGNF } from '@/lib/utils';
import type { AgentDepensesData } from '@/types/agent-fiche';
import { Link } from '@inertiajs/vue3';
import { CheckCircle, Hourglass, Receipt } from 'lucide-vue-next';

defineProps<{
    data: AgentDepensesData;
}>();

const pluriel = (n: number) => `${n} dépense${n > 1 ? 's' : ''}`;

function formatDate(date: string | null): string {
    if (!date) {
        return '—';
    }
    const [annee, mois, jour] = date.split('-');

    return `${jour}/${mois}/${annee}`;
}
</script>

<template>
    <div class="space-y-6" data-testid="agent-depenses-panel">
        <SituationSection
            title="Dépenses saisies par l'agent"
            description="Dépenses enregistrées par cet agent dans le module Dépenses, tous statuts"
        >
            <div
                class="grid grid-cols-1 gap-4 @lg:grid-cols-2 @4xl:grid-cols-3"
            >
                <SituationKpiCard
                    label="Validées"
                    :value="data.resume.validees.montant"
                    unit="GNF"
                    :icon="CheckCircle"
                    tone="success"
                    :detail="pluriel(data.resume.validees.nombre)"
                />
                <SituationKpiCard
                    label="En attente de validation"
                    :value="data.resume.en_attente.montant"
                    unit="GNF"
                    :icon="Hourglass"
                    tone="warning"
                    :detail="pluriel(data.resume.en_attente.nombre)"
                />
                <SituationKpiCard
                    label="Nombre de dépenses"
                    :value="data.resume.nombre"
                    :icon="Receipt"
                    tone="info"
                />
            </div>

            <div class="rounded-xl border bg-card p-5 sm:p-6">
                <div
                    v-if="!data.lignes.length"
                    class="rounded-lg border border-dashed py-10 text-center"
                >
                    <p class="text-sm text-muted-foreground">
                        Aucune dépense saisie par cet agent.
                    </p>
                </div>

                <template v-else>
                    <!-- Tableau ≥ 640 px -->
                    <div class="hidden overflow-x-auto sm:block">
                        <table class="w-full text-sm">
                            <thead class="text-xs text-muted-foreground">
                                <tr class="border-b">
                                    <th class="pr-2 pb-2 text-left font-medium">
                                        Date
                                    </th>
                                    <th class="px-2 pb-2 text-left font-medium">
                                        Type
                                    </th>
                                    <th class="px-2 pb-2 text-left font-medium">
                                        Catégorie
                                    </th>
                                    <th
                                        class="px-2 pb-2 text-right font-medium"
                                    >
                                        Montant
                                    </th>
                                    <th class="pb-2 pl-2 text-left font-medium">
                                        Statut
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="d in data.lignes"
                                    :key="d.id"
                                    class="border-t hover:bg-muted/30"
                                >
                                    <td
                                        class="py-2.5 pr-2 whitespace-nowrap tabular-nums"
                                    >
                                        <Link
                                            :href="`/backoffice/depenses/${d.id}`"
                                            class="hover:underline"
                                        >
                                            {{ formatDate(d.date_depense) }}
                                        </Link>
                                    </td>
                                    <td class="px-2 py-2.5 font-medium">
                                        {{ d.type ?? '—' }}
                                    </td>
                                    <td
                                        class="px-2 py-2.5 text-muted-foreground"
                                    >
                                        {{ d.categorie ?? '—' }}
                                    </td>
                                    <td
                                        class="px-2 py-2.5 text-right font-semibold whitespace-nowrap tabular-nums"
                                    >
                                        {{ formatGNF(d.montant) }}
                                    </td>
                                    <td class="py-2.5 pl-2">
                                        <StatusDot
                                            :status="d.statut"
                                            :label="d.statut_label"
                                        />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Liste empilée < 640 px -->
                    <div class="divide-y sm:hidden">
                        <Link
                            v-for="d in data.lignes"
                            :key="d.id"
                            :href="`/backoffice/depenses/${d.id}`"
                            class="flex items-start justify-between gap-3 py-3"
                        >
                            <div class="min-w-0">
                                <p class="text-sm font-medium">
                                    {{ d.type ?? '—' }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ formatDate(d.date_depense) }}
                                    <span v-if="d.categorie">
                                        · {{ d.categorie }}</span
                                    >
                                </p>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="text-sm font-semibold tabular-nums">
                                    {{ formatGNF(d.montant) }}
                                </p>
                                <StatusDot
                                    :status="d.statut"
                                    :label="d.statut_label"
                                />
                            </div>
                        </Link>
                    </div>

                    <p
                        v-if="data.total_lignes > data.lignes.length"
                        class="mt-3 text-xs text-muted-foreground"
                    >
                        {{ data.lignes.length }} dépenses les plus récentes
                        affichées sur {{ data.total_lignes }} ; les totaux
                        portent sur toutes les dépenses.
                    </p>
                </template>
            </div>
        </SituationSection>
    </div>
</template>
