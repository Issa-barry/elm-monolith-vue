<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import type { FinCddRh } from '@/types/dashboard-rh';
import { Link } from '@inertiajs/vue3';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import { CARTE_APOLLO, TITRE_GRAPHIQUE_APOLLO } from '../commissions/apollo';

// CDD en cours dont la fin prévue est dépassée ou atteinte dans les 30 jours, les plus urgents
// d'abord (serveur). La fin prévue ne fait jamais sortir le salarié : c'est un rappel à traiter
// (renouvellement, nouveau contrat ou départ), en orange, pas une erreur. Lien vers la fiche
// seulement avec l'accès aux fiches salariés (employe_id null sinon).
defineProps<{
    lignes: FinCddRh[];
}>();

const dateCourte = (iso: string) =>
    new Date(`${iso}T00:00:00`).toLocaleDateString('fr-FR');
</script>

<template>
    <div :class="CARTE_APOLLO">
        <div :class="TITRE_GRAPHIQUE_APOLLO" class="mb-1">Fins de CDD</div>
        <p class="mb-5 text-[13px] text-[var(--p-text-muted-color)]">
            CDD en cours dont la fin prévue est dépassée ou dans les 30
            prochains jours.
        </p>
        <DataTable
            :value="lignes"
            data-key="contrat_id"
            size="small"
            scrollable
            paginator
            :rows="10"
            :always-show-paginator="false"
            data-testid="rh-fins-cdd"
        >
            <Column header="Échéance">
                <template #body="{ data }">
                    <StatusDot
                        :status="data.statut"
                        :label="data.statut_label"
                    />
                </template>
            </Column>
            <Column header="Salarié">
                <template #body="{ data }">
                    <Link
                        v-if="data.employe_id"
                        :href="`/backoffice/employes/${data.employe_id}`"
                        class="text-[var(--p-primary-color)] hover:underline"
                        data-testid="fin-cdd-fiche"
                        >{{ data.salarie }}</Link
                    >
                    <span v-else>{{ data.salarie }}</span>
                    <span
                        v-if="data.matricule"
                        class="ml-1 text-[var(--p-text-muted-color)]"
                        >· {{ data.matricule }}</span
                    >
                </template>
            </Column>
            <Column field="site" header="Agence" />
            <Column header="Fin prévue" body-class="whitespace-nowrap">
                <template #body="{ data }">{{
                    dateCourte(data.date_fin)
                }}</template>
            </Column>
            <template #empty>
                <div class="py-6 text-center text-[var(--p-text-muted-color)]">
                    Aucune fin de CDD à traiter
                </div>
            </template>
        </DataTable>
    </div>
</template>
