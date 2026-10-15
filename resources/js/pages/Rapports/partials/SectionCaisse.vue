<script setup lang="ts">
import CaisseFiche from '@/components/tresorerie/CaisseFiche.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { formatGNF } from '@/lib/utils';
import type { RapportActivite } from '@/types/rapports';
import { Info } from 'lucide-vue-next';
import { computed } from 'vue';
import EnTeteSection from './EnTeteSection.vue';
import { dateFr } from './format';

// Section Caisse : vue agence (une ligne par caisse dédiée) ou, quand un agent est ciblé,
// fiche complète de chaque caisse (CaisseFiche, même composant que la future fiche caisse).
const props = withDefaults(
    defineProps<{
        caisse: RapportActivite['caisse'];
        maSituation: boolean;
        debut: string;
        fin: string;
        /** `mobile_money` : comptes Mobile Money des agents (ADR 0027), mêmes données qu'une caisse. */
        nature?: 'caisse' | 'mobile_money';
    }>(),
    { nature: 'caisse' },
);

const textes = computed(() =>
    props.nature === 'mobile_money'
        ? {
              titre: props.maSituation
                  ? 'Mon compte Mobile Money'
                  : 'Comptes Mobile Money des agents',
              aide: "Tiré du grand livre : solde de début + mouvements = solde de fin. Le solde est ce que l'agent détient encore ; les transferts envoyés sont déjà partis vers l'agence.",
              aucunTitre: 'Aucun compte Mobile Money dédié',
              aucunTexte: props.maSituation
                  ? "Vous n'avez pas de compte Mobile Money : vous ne pouvez pas encaisser en Mobile Money."
                  : 'Aucun compte Mobile Money dédié à un agent dans ce périmètre.',
              solde: "à transférer à l'agence",
              colonneSolde: 'À transférer',
              dernier: 'Dernier transfert',
              pied: 'Choisissez un agent pour voir le détail des écritures de son compte Mobile Money.',
          }
        : {
              titre: props.maSituation ? 'Ma caisse' : 'Caisses dédiées',
              aide: "Tiré du grand livre : solde de début + mouvements = solde de fin. « À remettre » est un solde théorique : aucun comptage physique n'est enregistré.",
              aucunTitre: 'Aucune caisse dédiée',
              aucunTexte: props.maSituation
                  ? "Vous n'avez pas de caisse dédiée : vos encaissements en espèces ne peuvent pas être suivis ici."
                  : 'Aucune caisse dédiée à un agent dans ce périmètre.',
              solde: 'à remettre (théorique)',
              colonneSolde: 'À remettre',
              dernier: 'Dernier versement',
              pied: 'Choisissez un agent pour voir le détail des écritures de sa caisse.',
          },
);
</script>

<template>
    <section class="space-y-3">
        <EnTeteSection :titre="textes.titre" :aide="textes.aide" />

        <Alert v-if="caisse.aucune_caisse" data-testid="caisse-aucune">
            <Info class="text-blue-500" />
            <AlertTitle>{{ textes.aucunTitre }}</AlertTitle>
            <AlertDescription>{{ textes.aucunTexte }}</AlertDescription>
        </Alert>

        <template v-else-if="caisse.detail">
            <CaisseFiche
                v-for="f in caisse.fiches"
                :key="f.caisse.id"
                :fiche="f"
                :debut="debut"
                :fin="fin"
                :afficher-agent="!maSituation"
            />
        </template>

        <template v-else>
            <div class="rounded-xl border bg-card">
                <!-- Téléphone : une carte par caisse -->
                <ul class="divide-y sm:hidden" data-testid="liste-mobile">
                    <li
                        v-for="f in caisse.fiches"
                        :key="f.caisse.id"
                        class="space-y-1 px-3 py-2.5"
                        data-testid="caisse-ligne"
                    >
                        <div class="flex items-baseline justify-between gap-3">
                            <span class="text-sm font-medium">{{
                                f.caisse.agent_nom ?? '—'
                            }}</span>
                            <span class="text-sm font-semibold tabular-nums">{{
                                formatGNF(f.solde_actuel)
                            }}</span>
                        </div>
                        <div
                            class="flex justify-between gap-3 text-xs text-muted-foreground"
                        >
                            <span>{{ f.caisse.site_nom ?? '—' }}</span>
                            <span>{{ textes.solde }}</span>
                        </div>
                        <div class="text-xs text-muted-foreground">
                            Début {{ formatGNF(f.solde_debut) }} → fin
                            {{ formatGNF(f.solde_fin) }}
                            <span
                                v-if="f.en_cours.montant > 0"
                                class="text-blue-600 dark:text-blue-400"
                            >
                                · en cours
                                {{ formatGNF(f.en_cours.montant) }}</span
                            >
                        </div>
                    </li>
                </ul>

                <!-- À partir de 640 px : tableau -->
                <div class="hidden overflow-x-auto sm:block">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b bg-muted/40 text-left">
                                <th class="px-3 py-2 font-medium">Agent</th>
                                <th class="px-3 py-2 font-medium">Agence</th>
                                <th class="px-3 py-2 text-right font-medium">
                                    Solde début
                                </th>
                                <th class="px-3 py-2 text-right font-medium">
                                    Entrées
                                </th>
                                <th class="px-3 py-2 text-right font-medium">
                                    Sorties
                                </th>
                                <th class="px-3 py-2 text-right font-medium">
                                    Solde fin
                                </th>
                                <th class="px-3 py-2 text-right font-medium">
                                    {{ textes.colonneSolde }}
                                </th>
                                <th class="px-3 py-2 font-medium">
                                    {{ textes.dernier }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="f in caisse.fiches"
                                :key="f.caisse.id"
                                class="hover:bg-muted/30"
                                data-testid="caisse-ligne"
                            >
                                <td class="px-3 py-2 font-medium">
                                    {{ f.caisse.agent_nom ?? '—' }}
                                </td>
                                <td class="px-3 py-2">
                                    {{ f.caisse.site_nom ?? '—' }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{ formatGNF(f.solde_debut) }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{ formatGNF(f.total_entrees) }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{ formatGNF(f.total_sorties) }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{ formatGNF(f.solde_fin) }}
                                </td>
                                <td
                                    class="px-3 py-2 text-right font-semibold tabular-nums"
                                >
                                    {{ formatGNF(f.solde_actuel) }}
                                    <div
                                        v-if="f.en_cours.montant > 0"
                                        class="text-xs font-medium text-blue-600 dark:text-blue-400"
                                    >
                                        En cours :
                                        {{ formatGNF(f.en_cours.montant) }}
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    <template v-if="f.dernier_versement">
                                        {{
                                            dateFr(
                                                f.dernier_versement.date_envoi,
                                            )
                                        }}
                                        <span class="text-muted-foreground">
                                            ({{
                                                f.dernier_versement
                                                    .anciennete_jours
                                            }}
                                            j)</span
                                        >
                                    </template>
                                    <span v-else class="text-muted-foreground"
                                        >Aucun</span
                                    >
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t bg-muted/20 font-semibold">
                                <td colspan="2" class="px-3 py-2">Total</td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{ formatGNF(caisse.resume.solde_debut) }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{ formatGNF(caisse.resume.entrees) }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{ formatGNF(caisse.resume.sorties) }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{ formatGNF(caisse.resume.solde_fin) }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{ formatGNF(caisse.resume.solde_actuel) }}
                                </td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <p class="text-xs text-muted-foreground">{{ textes.pied }}</p>
        </template>
    </section>
</template>
