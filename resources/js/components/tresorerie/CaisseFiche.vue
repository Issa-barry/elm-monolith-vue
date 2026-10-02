<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { formatGNF } from '@/lib/utils';
import type { FicheCaisse } from '@/types/rapports';
import { ArrowRightLeft } from 'lucide-vue-next';

// Fiche d'une caisse dédiée sur une période (FicheCaisseService) : même composant pour l'onglet
// Caisse du rapport d'activité et la future fiche caisse (phase 4), pour n'avoir qu'un seul
// affichage de caisse. Constat tiré du grand livre : aucun comptage physique n'est enregistré.
withDefaults(
    defineProps<{
        fiche: FicheCaisse;
        debut: string;
        fin: string;
        afficherAgent?: boolean;
    }>(),
    { afficherAgent: true },
);

function dateFr(iso: string | null | undefined): string {
    return iso ? iso.slice(0, 10).split('-').reverse().join('/') : '—';
}

function montantMouvement(m: { entrees: number; sorties: number }): number {
    return m.entrees - m.sorties;
}

function anciennete(jours: number): string {
    if (jours === 0) return "aujourd'hui";
    if (jours === 1) return 'hier';
    return `il y a ${jours} j`;
}
</script>

<template>
    <div
        class="space-y-4 rounded-xl border bg-card p-4"
        data-testid="caisse-fiche"
    >
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <div>
                <h3 class="font-semibold">{{ fiche.caisse.libelle }}</h3>
                <p class="text-xs text-muted-foreground">
                    <span v-if="afficherAgent && fiche.caisse.agent_nom"
                        >{{ fiche.caisse.agent_nom }} ·
                    </span>
                    {{ fiche.caisse.site_nom ?? '—' }}
                    <span v-if="!fiche.caisse.actif"> · caisse inactive</span>
                </p>
            </div>
            <div class="text-right">
                <p class="text-xs text-muted-foreground">
                    À remettre — solde théorique
                </p>
                <p
                    class="text-lg font-bold tabular-nums"
                    data-testid="caisse-solde-actuel"
                >
                    {{ formatGNF(fiche.solde_actuel) }}
                </p>
            </div>
        </div>

        <!-- Tableau de caisse : solde de début + mouvements = solde de fin -->
        <dl class="divide-y rounded-lg border text-sm">
            <div class="flex justify-between gap-4 bg-muted/30 px-3 py-2">
                <dt>Solde au {{ dateFr(debut) }} (début de période)</dt>
                <dd class="font-medium whitespace-nowrap tabular-nums">
                    {{ formatGNF(fiche.solde_debut) }}
                </dd>
            </div>
            <div
                v-for="m in fiche.mouvements"
                :key="m.categorie"
                class="flex justify-between gap-4 px-3 py-2"
            >
                <dt class="text-muted-foreground">
                    {{ montantMouvement(m) >= 0 ? '+' : '−' }}
                    {{ m.libelle }}
                    <span class="text-xs">({{ m.nombre }})</span>
                </dt>
                <dd class="whitespace-nowrap tabular-nums">
                    {{ formatGNF(Math.abs(montantMouvement(m))) }}
                </dd>
            </div>
            <div
                v-if="fiche.mouvements.length === 0"
                class="px-3 py-2 text-muted-foreground"
            >
                Aucun mouvement sur la période.
            </div>
            <div
                class="flex justify-between gap-4 bg-muted/30 px-3 py-2 font-semibold"
            >
                <dt>= Solde au {{ dateFr(fin) }} (fin de période)</dt>
                <dd
                    class="whitespace-nowrap tabular-nums"
                    data-testid="caisse-solde-fin"
                >
                    {{ formatGNF(fiche.solde_fin) }}
                </dd>
            </div>
        </dl>

        <div
            v-if="fiche.en_cours.nombre > 0 || fiche.contestes.nombre > 0"
            class="space-y-1 text-xs"
        >
            <p
                v-if="fiche.en_cours.nombre > 0"
                class="flex items-center gap-1.5 text-blue-600 dark:text-blue-400"
            >
                <ArrowRightLeft class="h-3.5 w-3.5" />
                En cours de versement :
                {{ formatGNF(fiche.en_cours.montant) }} ({{
                    fiche.en_cours.nombre
                }}
                à confirmer par la caisse de l'agence) — hors solde.
            </p>
            <p
                v-if="fiche.contestes.nombre > 0"
                class="text-amber-700 dark:text-amber-400"
            >
                {{ fiche.contestes.nombre }} versement(s) contesté(s) :
                {{ formatGNF(fiche.contestes.montant) }} en attente de
                règlement.
            </p>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div class="space-y-1 text-sm">
                <p class="text-xs font-medium text-muted-foreground">
                    Dernier versement
                </p>
                <p v-if="fiche.dernier_versement" class="flex flex-wrap gap-2">
                    <span class="tabular-nums">{{
                        formatGNF(fiche.dernier_versement.montant)
                    }}</span>
                    <span class="text-muted-foreground"
                        >le {{ dateFr(fiche.dernier_versement.date_envoi) }} ({{
                            anciennete(
                                fiche.dernier_versement.anciennete_jours,
                            )
                        }})</span
                    >
                    <StatusDot
                        :status="fiche.dernier_versement.statut"
                        :label="fiche.dernier_versement.statut_label"
                    />
                </p>
                <p v-else class="text-muted-foreground">
                    Aucun versement enregistré.
                </p>
            </div>
            <div v-if="fiche.versements_periode.length > 0" class="text-sm">
                <p class="mb-1 text-xs font-medium text-muted-foreground">
                    Versements de la période
                </p>
                <ul class="space-y-1">
                    <li
                        v-for="v in fiche.versements_periode"
                        :key="v.id"
                        class="flex flex-wrap items-center gap-2"
                    >
                        <span class="font-mono text-xs">{{ v.reference }}</span>
                        <span class="text-muted-foreground">{{
                            dateFr(v.date_envoi)
                        }}</span>
                        <span class="tabular-nums">{{
                            formatGNF(v.montant)
                        }}</span>
                        <StatusDot :status="v.statut" :label="v.statut_label" />
                    </li>
                </ul>
            </div>
        </div>

        <div v-if="fiche.ecritures && fiche.ecritures.length > 0">
            <p class="mb-1 text-xs font-medium text-muted-foreground">
                Détail des écritures
            </p>
            <!-- Téléphone : liste empilée -->
            <ul class="divide-y rounded-lg border sm:hidden">
                <li
                    v-for="(e, i) in fiche.ecritures"
                    :key="`m-${e.piece_id}-${i}`"
                    class="space-y-0.5 px-3 py-2 text-sm"
                >
                    <div class="flex items-baseline justify-between gap-3">
                        <span>{{ e.categorie_libelle }}</span>
                        <span
                            class="font-medium tabular-nums"
                            :class="e.sortie > 0 ? 'text-muted-foreground' : ''"
                            >{{ e.sortie > 0 ? '−' : '+' }}
                            {{
                                formatGNF(e.entree > 0 ? e.entree : e.sortie)
                            }}</span
                        >
                    </div>
                    <div
                        class="flex justify-between gap-3 text-xs text-muted-foreground"
                    >
                        <span>{{ dateFr(e.date) }} · {{ e.numero }}</span>
                        <span class="tabular-nums"
                            >solde {{ formatGNF(e.solde) }}</span
                        >
                    </div>
                </li>
            </ul>
            <div class="hidden overflow-x-auto rounded-lg border sm:block">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b bg-muted/40 text-left">
                            <th class="px-3 py-2 font-medium">Date</th>
                            <th class="px-3 py-2 font-medium">Mouvement</th>
                            <th class="px-3 py-2 font-medium">Libellé</th>
                            <th class="px-3 py-2 text-right font-medium">
                                Entrée
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Sortie
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Solde
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="(e, i) in fiche.ecritures"
                            :key="`${e.piece_id}-${i}`"
                        >
                            <td class="px-3 py-2 whitespace-nowrap">
                                {{ dateFr(e.date) }}
                            </td>
                            <td class="px-3 py-2">{{ e.categorie_libelle }}</td>
                            <td class="px-3 py-2 text-muted-foreground">
                                {{ e.libelle }}
                                <span class="font-mono text-xs"
                                    >· {{ e.numero }}</span
                                >
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ e.entree > 0 ? formatGNF(e.entree) : '' }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ e.sortie > 0 ? formatGNF(e.sortie) : '' }}
                            </td>
                            <td
                                class="px-3 py-2 text-right font-medium tabular-nums"
                            >
                                {{ formatGNF(e.solde) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
