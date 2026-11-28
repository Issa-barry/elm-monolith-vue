<script setup lang="ts">
import type {
    BlocsVehicules,
    OperationVehicules,
} from '@/types/dashboard-vehicules';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import { computed } from 'vue';
import { CARTE_APOLLO, TITRE_GRAPHIQUE_APOLLO } from '../commissions/apollo';
import { nombreTexte, ouTiret } from './nombres';

// Une ligne par type d'opération, jamais additionnée aux autres : véhicules actifs du parc qui ont
// l'usage, ceux que le serveur laisse réaliser l'opération aujourd'hui, puis l'activité de la
// période quand sa source est consultable (tiret sinon). Distribution et transfert reposent tous
// deux sur l'usage Logistique : un même camion figure sur les deux lignes.
const props = defineProps<{
    operations: OperationVehicules[];
    blocs: BlocsVehicules;
}>();

const grossiste = computed(() =>
    props.operations.find((o) => o.code === 'grossiste'),
);

function sourceIndisponible(o: OperationVehicules): string {
    return o.code === 'transfert'
        ? 'Accès à la logistique requis'
        : 'Accès aux ventes requis';
}
</script>

<template>
    <div :class="CARTE_APOLLO">
        <div :class="TITRE_GRAPHIQUE_APOLLO" class="mb-1">
            Disponibilité et activité par opération
        </div>
        <p class="mb-5 text-[13px] text-[var(--p-text-muted-color)]">
            Parc à ce jour (agence actuelle) ; rotations et jours-véhicule sur
            la période (agence de l’opération).
        </p>
        <DataTable
            :value="operations"
            data-key="code"
            size="small"
            scrollable
            data-testid="vehicules-operations"
        >
            <Column header="Opération" frozen>
                <template #body="{ data }">
                    <span class="font-medium">{{ data.libelle }}</span>
                    <span class="ml-1 text-[var(--p-text-muted-color)]"
                        >· usage {{ data.usage }}</span
                    >
                </template>
            </Column>
            <Column
                header="Véhicules"
                header-class="justify-end text-right"
                body-class="text-right tabular-nums"
            >
                <template #body="{ data }">{{
                    nombreTexte(data.vehicules)
                }}</template>
            </Column>
            <Column
                header="Possibles"
                header-class="justify-end text-right"
                body-class="text-right tabular-nums"
            >
                <template #body="{ data }">{{
                    nombreTexte(data.possibles)
                }}</template>
            </Column>
            <Column
                header="Bloqués"
                header-class="justify-end text-right"
                body-class="text-right tabular-nums"
            >
                <template #body="{ data }">
                    <span
                        :class="{
                            'text-amber-600 dark:text-amber-400':
                                data.bloques > 0,
                        }"
                        >{{ nombreTexte(data.bloques) }}</span
                    >
                </template>
            </Column>
            <Column
                v-for="c in [
                    { champ: 'rotations', titre: 'Rotations' },
                    { champ: 'vehicules_utilises', titre: 'Véhicules sortis' },
                    { champ: 'jours_vehicule', titre: 'Jours-véhicule' },
                ] as const"
                :key="c.champ"
                :header="c.titre"
                header-class="justify-end text-right"
                body-class="text-right tabular-nums whitespace-nowrap"
            >
                <template #body="{ data }">
                    <span
                        :title="
                            data[c.champ] === null
                                ? sourceIndisponible(data)
                                : undefined
                        "
                        >{{ ouTiret(data[c.champ]) }}</span
                    >
                </template>
            </Column>
        </DataTable>
        <p
            class="mt-4 text-[13px] text-[var(--p-text-muted-color)]"
            data-testid="vehicules-note-grossiste"
        >
            Livraison grossiste : vente à un client grossiste en mode livraison.
            <template v-if="grossiste && grossiste.vehicules === 0">
                Aucun véhicule actif du parc n’a l’usage Grossiste : à cocher
                sur la fiche du véhicule pour lui confier ces livraisons.
            </template>
            <template
                v-else-if="
                    blocs.ventes && grossiste && grossiste.rotations === 0
                "
            >
                Aucune livraison grossiste sur la période.
            </template>
        </p>
    </div>
</template>
