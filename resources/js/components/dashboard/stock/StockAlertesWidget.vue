<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import HistoriqueModal from '@/pages/Produits/partials/HistoriqueModal.vue';
import type { LigneAlerteStock } from '@/types/dashboard-stock';
import { History } from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import { ref } from 'vue';
import type { ComponentProps } from 'vue-component-type-helpers';
import { CARTE_APOLLO, TITRE_GRAPHIQUE_APOLLO } from '../commissions/apollo';
import { quantiteTexte } from './quantites';

// Couples article × agence à surveiller, tous articles : disponibles sur l'agence, en stock
// négatif, rupture ou stock faible (règle des écrans opérationnels — l'alerte active est indiquée
// à part, elle seule déclenche badges et notifications). Les plus graves d'abord (serveur).
// « Historique » ouvre l'historique des mouvements de la page Stock, pour ce couple.
defineProps<{
    lignes: LigneAlerteStock[];
}>();

type ProprietesHistorique = ComponentProps<typeof HistoriqueModal>;

const visible = ref(false);
const chargement = ref(false);
const titre = ref('Historique du stock');
const ligneOuverte = ref<LigneAlerteStock | null>(null);
const ajustements = ref<ProprietesHistorique['ajustements']>([]);
const modifications = ref<ProprietesHistorique['modifications']>([]);
const motifs = ref<NonNullable<ProprietesHistorique['motifOptions']>>([]);
const erreur = ref<string | null>(null);

async function charger(motif: string | null): Promise<void> {
    const ligne = ligneOuverte.value;
    if (!ligne) return;
    chargement.value = true;
    erreur.value = null;

    const parametres = new URLSearchParams({
        variante_id: ligne.variante_id,
        site_id: ligne.site_id,
    });
    if (motif) parametres.set('motif', motif);

    try {
        const reponse = await fetch(
            `/backoffice/produits/${ligne.produit_id}/historique?${parametres.toString()}`,
            {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            },
        );
        if (!reponse.ok) throw new Error('Historique indisponible');
        const donnees = await reponse.json();
        ajustements.value = donnees.ajustements ?? [];
        modifications.value = donnees.modifications ?? [];
        motifs.value = donnees.motifs_disponibles ?? [];
    } catch {
        visible.value = false;
        erreur.value = 'L’historique du stock n’a pas pu être chargé.';
    } finally {
        chargement.value = false;
    }
}

function ouvrir(ligne: LigneAlerteStock): void {
    ligneOuverte.value = ligne;
    titre.value = `${ligne.article} · ${ligne.site}`;
    ajustements.value = [];
    modifications.value = [];
    motifs.value = [];
    visible.value = true;
    void charger(null);
}
</script>

<template>
    <div :class="CARTE_APOLLO">
        <div :class="TITRE_GRAPHIQUE_APOLLO" class="mb-1">Alertes de stock</div>
        <p class="mb-5 text-[13px] text-[var(--p-text-muted-color)]">
            Tous articles, sur les agences où l’article est disponible. Seules
            les lignes à alerte active déclenchent badges et notifications.
        </p>
        <p
            v-if="erreur"
            role="alert"
            class="mb-3 text-sm text-destructive"
            data-testid="historique-erreur"
        >
            {{ erreur }}
        </p>
        <DataTable
            :value="lignes"
            :data-key="(l: LigneAlerteStock) => `${l.variante_id}|${l.site_id}`"
            size="small"
            scrollable
            paginator
            :rows="10"
            data-testid="stock-alertes"
        >
            <Column header="Statut">
                <template #body="{ data }">
                    <StatusDot
                        :status="data.statut"
                        :label="data.statut_label"
                    />
                </template>
            </Column>
            <Column field="article" header="Article" />
            <Column field="site" header="Agence" />
            <Column
                header="Disponible"
                header-class="justify-end text-right"
                body-class="text-right tabular-nums"
            >
                <template #body="{ data }">{{
                    quantiteTexte(data.disponible)
                }}</template>
            </Column>
            <Column
                header="Physique / réservé"
                header-class="justify-end text-right"
                body-class="text-right tabular-nums whitespace-nowrap"
            >
                <template #body="{ data }"
                    >{{ quantiteTexte(data.physique) }} /
                    {{ quantiteTexte(data.reservee) }}</template
                >
            </Column>
            <Column
                header="Seuil"
                header-class="justify-end text-right"
                body-class="text-right tabular-nums"
            >
                <template #body="{ data }">{{
                    quantiteTexte(data.seuil)
                }}</template>
            </Column>
            <Column header="Alerte">
                <template #body="{ data }">{{
                    data.alerte_active ? 'Active' : 'Inactive'
                }}</template>
            </Column>
            <Column header="" body-class="text-right">
                <template #body="{ data }">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-sm text-[var(--p-primary-color)] hover:bg-[var(--p-content-hover-background)]"
                        :aria-label="`Historique du stock : ${data.article}, ${data.site}`"
                        data-testid="alerte-historique"
                        @click="ouvrir(data)"
                    >
                        <History class="size-4" />
                        Historique
                    </button>
                </template>
            </Column>
            <template #empty>
                <div class="py-6 text-center text-[var(--p-text-muted-color)]">
                    Aucune rupture ni stock faible
                </div>
            </template>
        </DataTable>
        <HistoriqueModal
            v-model:visible="visible"
            :ajustements="ajustements"
            :modifications="modifications"
            :motif-options="motifs"
            :loading="chargement"
            :title="titre"
            @filter-motif="charger"
        />
    </div>
</template>
