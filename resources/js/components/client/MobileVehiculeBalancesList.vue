<script setup lang="ts">
import type { EarningsVehiculePayload } from '@/types/client-space';
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from 'lucide-vue-next';

const props = defineProps<{
    rows: EarningsVehiculePayload[];
    dateDebut?: string | null;
    dateFin?: string | null;
}>();

function formatMoney(value: number): string {
    return `${new Intl.NumberFormat('fr-FR').format(value ?? 0)} GNF`;
}

function detailHref(vehiculeId: string | number): string {
    const params = new URLSearchParams();
    if (props.dateDebut) {
        params.set('date_debut', props.dateDebut);
    }
    if (props.dateFin) {
        params.set('date_fin', props.dateFin);
    }

    const query = params.toString();

    return `/client/vehicules/${encodeURIComponent(String(vehiculeId))}/solde${query ? `?${query}` : ''}`;
}
</script>

<template>
    <div>
        <Link
            v-for="row in rows"
            :key="row.vehicule_id"
            :href="detailHref(row.vehicule_id)"
            class="block border-b border-border px-4 py-3 transition-colors last:border-b-0 hover:bg-muted/40"
        >
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium text-foreground">
                        {{ row.nom_vehicule }}
                    </p>
                    <p class="mt-0.5 truncate text-xs text-muted-foreground">
                        {{ row.immatriculation ?? '-' }}
                    </p>
                </div>
                <ChevronRight
                    class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground"
                />
            </div>

            <div class="mt-2 flex items-baseline justify-between gap-3">
                <span class="text-xs text-muted-foreground">Gains</span>
                <span class="text-sm font-semibold text-foreground">
                    {{ formatMoney(row.total_earned) }}
                </span>
            </div>
        </Link>

        <div
            v-if="rows.length === 0"
            class="px-4 py-3 text-sm text-muted-foreground"
        >
            Aucun vehicule partenaire detecte pour ce compte.
        </div>
    </div>
</template>
