<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { cn } from '@/lib/utils';
import type { LigneAgent, MoyenColonne, TotauxAgents } from '@/types/rapports';
import { Link } from '@inertiajs/vue3';
import { ArrowUpRight } from 'lucide-vue-next';
import { computed } from 'vue';
import { pluriel } from './format';
import { attenue, n, REMISE, TD, TH, TOTAL_ENCAISSE } from './tableau';

// Rapport d'activité : une ligne par agent, même présentation que le tableau des agences (Rapport par
// agence). Les agents remettent à LEUR agence de rattachement (ADR 0064), jamais à la trésorerie
// principale. Activité = période choisie ; remise = cumul jusqu'à la fin de la période :
// net à remettre (encaissé + approvisionnements − charges validées) − déjà remis = reste.
const props = defineProps<{
    lignes: LigneAgent[];
    totaux: TotauxAgents;
    moyens: MoyenColonne[];
    /** Lien vers le détail des opérations d'un agent (même page filtrée sur l'agent). */
    hrefAgent: (agentId: string) => string;
    /** Titre du tableau (« Détail de ma situation » dans Ma situation). */
    titre?: string;
    /** Liens vers les opérations de l'agent (inutiles dans Ma situation : la page est déjà la sienne). */
    sansLien?: boolean;
}>();

const moyen = (l: LigneAgent, cle: string) => l.par_moyen?.[cle]?.montant ?? 0;
const colonnesEncaissements = computed(() => props.moyens.length + 1);
const agences = (l: LigneAgent) =>
    l.agences.length > 0 ? l.agences.join(', ') : '—';
</script>

<template>
    <div>
        <!-- Tableau (≥ 1024 px) -->
        <section
            class="hidden min-w-0 overflow-hidden rounded-xl border bg-card lg:block"
            aria-labelledby="titre-tableau-agents"
        >
            <div
                class="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3"
            >
                <h2 id="titre-tableau-agents" class="text-sm font-semibold">
                    {{ titre ?? 'Détail par agent' }}
                    <span class="font-normal text-muted-foreground"
                        >· {{ pluriel(lignes.length, 'agent') }}</span
                    >
                </h2>
                <p class="text-xs text-muted-foreground">Montants en GNF</p>
            </div>
            <div
                role="region"
                aria-label="Activité par agent — tableau à défilement horizontal"
                class="w-full max-w-full overflow-x-auto"
            >
                <table
                    class="w-full min-w-max border-collapse text-sm"
                    data-testid="rapport-agents-tableau"
                >
                    <thead>
                        <tr class="text-xs text-muted-foreground">
                            <th
                                scope="col"
                                class="sticky left-0 z-10 bg-card px-4 pt-2.5"
                            />
                            <th scope="col" colspan="2" />
                            <th
                                scope="colgroup"
                                :colspan="colonnesEncaissements"
                                class="border-l px-3 pt-2.5 text-left font-medium"
                            >
                                Encaissements de la période
                            </th>
                            <th scope="colgroup" colspan="3" />
                            <th
                                scope="colgroup"
                                colspan="5"
                                :class="
                                    cn(
                                        'border-l px-3 pt-2.5 text-left font-semibold text-foreground',
                                        REMISE,
                                    )
                                "
                            >
                                Remise à l'agence de rattachement
                                <span class="font-normal text-muted-foreground"
                                    >· cumul à la fin de la période</span
                                >
                            </th>
                            <th scope="col" />
                        </tr>
                        <tr class="border-b text-muted-foreground">
                            <th
                                scope="col"
                                class="sticky left-0 z-10 bg-card px-4 py-2.5 text-left text-xs font-medium"
                            >
                                Agent
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2.5 text-left text-xs font-medium"
                            >
                                Agence
                            </th>
                            <th scope="col" :class="TH">Ventes</th>
                            <th
                                v-for="(m, i) in moyens"
                                :key="m.cle"
                                scope="col"
                                :class="cn(TH, i === 0 && 'border-l')"
                            >
                                {{ m.libelle }}
                            </th>
                            <th
                                scope="col"
                                :class="
                                    cn(
                                        TH,
                                        TOTAL_ENCAISSE,
                                        'font-semibold text-foreground',
                                    )
                                "
                            >
                                Total encaissé
                            </th>
                            <th scope="col" :class="TH">Dettes clients</th>
                            <th scope="col" :class="TH">Charges saisies</th>
                            <th scope="col" :class="TH">Charges validées</th>
                            <th
                                scope="col"
                                :class="cn(TH, REMISE, 'border-l')"
                                data-testid="entete-net"
                            >
                                Net à remettre
                            </th>
                            <th scope="col" :class="cn(TH, REMISE)">
                                Déjà remis
                            </th>
                            <th scope="col" :class="cn(TH, REMISE)">
                                En transit
                            </th>
                            <th
                                scope="col"
                                :class="cn(TH, REMISE, 'text-foreground')"
                            >
                                Reste à remettre
                            </th>
                            <th
                                scope="col"
                                :class="
                                    cn(
                                        REMISE,
                                        'px-3 py-2.5 text-left text-xs font-medium',
                                    )
                                "
                            >
                                Statut
                            </th>
                            <th scope="col" class="px-3 py-2.5">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="l in lignes"
                            :key="l.agent_id ?? 'inconnu'"
                            data-testid="rapport-agent-ligne"
                        >
                            <td
                                class="sticky left-0 z-10 bg-card px-4 py-3 align-top font-medium"
                            >
                                <Link
                                    v-if="l.agent_id && !sansLien"
                                    :href="hrefAgent(l.agent_id)"
                                    class="hover:text-primary hover:underline"
                                    >{{ l.agent }}</Link
                                >
                                <span
                                    v-else
                                    :class="
                                        !l.agent_id && 'text-muted-foreground'
                                    "
                                    >{{ l.agent }}</span
                                >
                            </td>
                            <td
                                class="px-3 py-3 align-top text-sm whitespace-nowrap text-muted-foreground"
                            >
                                {{ agences(l) }}
                            </td>
                            <td :class="cn(TD, attenue(l.ventes_montant))">
                                {{ n(l.ventes_montant) }}
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{
                                        pluriel(l.ventes_nombre, 'vente')
                                    }}</span
                                >
                            </td>
                            <td
                                v-for="(m, i) in moyens"
                                :key="m.cle"
                                :class="
                                    cn(
                                        TD,
                                        i === 0 && 'border-l',
                                        attenue(moyen(l, m.cle)),
                                    )
                                "
                            >
                                {{ n(moyen(l, m.cle)) }}
                            </td>
                            <td
                                :class="
                                    cn(
                                        TD,
                                        TOTAL_ENCAISSE,
                                        'font-semibold',
                                        attenue(l.encaisse_montant),
                                    )
                                "
                                data-testid="rapport-agent-encaisse"
                            >
                                {{ n(l.encaisse_montant) }}
                                <span
                                    v-if="l.pour_autres_agences"
                                    class="block text-xs font-normal text-muted-foreground"
                                    >dont {{ n(l.pour_autres_agences) }} autres
                                    agences</span
                                >
                            </td>
                            <td :class="cn(TD, attenue(l.dettes_montant))">
                                {{ n(l.dettes_montant) }}
                                <span
                                    v-if="l.dettes_nombre"
                                    class="block text-xs text-muted-foreground"
                                    >{{
                                        pluriel(l.dettes_nombre, 'facture')
                                    }}</span
                                >
                            </td>
                            <td
                                :class="cn(TD, attenue(l.charges_saisies))"
                                data-testid="rapport-agent-charges"
                            >
                                {{ n(l.charges_saisies) }}
                                <span
                                    v-if="l.charges_en_attente"
                                    class="block text-xs text-amber-700 dark:text-amber-400"
                                    >dont {{ n(l.charges_en_attente) }} en
                                    attente</span
                                >
                            </td>
                            <td
                                :class="cn(TD, attenue(l.charges_payees))"
                                data-testid="rapport-agent-charges-validees"
                            >
                                {{ n(l.charges_payees) }}
                            </td>
                            <template v-if="l.remise">
                                <td
                                    :class="
                                        cn(
                                            TD,
                                            REMISE,
                                            'border-l',
                                            attenue(l.remise.net_a_remettre),
                                        )
                                    "
                                    data-testid="rapport-agent-net"
                                >
                                    {{ n(l.remise.net_a_remettre) }}
                                </td>
                                <td
                                    :class="
                                        cn(
                                            TD,
                                            REMISE,
                                            attenue(l.remise.deja_remis),
                                        )
                                    "
                                    data-testid="rapport-agent-remis"
                                >
                                    {{ n(l.remise.deja_remis) }}
                                    <span
                                        v-if="l.remise.remis"
                                        class="block text-xs text-muted-foreground"
                                        >dont {{ n(l.remise.remis) }} sur la
                                        période</span
                                    >
                                </td>
                                <td
                                    :class="
                                        cn(
                                            TD,
                                            REMISE,
                                            attenue(l.remise.en_transit),
                                        )
                                    "
                                >
                                    {{ n(l.remise.en_transit) }}
                                </td>
                                <td
                                    :class="
                                        cn(
                                            TD,
                                            REMISE,
                                            'font-semibold',
                                            attenue(l.remise.reste_a_remettre),
                                        )
                                    "
                                    data-testid="rapport-agent-reste"
                                >
                                    {{ n(l.remise.reste_a_remettre) }}
                                </td>
                                <td
                                    :class="
                                        cn(
                                            REMISE,
                                            'px-3 py-3 align-top whitespace-nowrap',
                                        )
                                    "
                                >
                                    <StatusDot
                                        :status="l.remise.statut"
                                        :label="l.remise.statut_label"
                                    />
                                </td>
                            </template>
                            <td
                                v-else
                                colspan="5"
                                :class="
                                    cn(
                                        REMISE,
                                        'border-l px-3 py-3 text-right align-top text-xs text-muted-foreground',
                                    )
                                "
                            >
                                Aucune caisse dédiée
                            </td>
                            <td class="px-3 py-2.5 align-top">
                                <Link
                                    v-if="l.agent_id && !sansLien"
                                    :href="hrefAgent(l.agent_id)"
                                    :aria-label="`Voir les opérations de ${l.agent}`"
                                    title="Voir les opérations de l'agent"
                                    data-testid="rapport-agent-detail"
                                    class="inline-flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-primary focus-visible:outline-2 focus-visible:outline-ring"
                                >
                                    <ArrowUpRight
                                        class="h-4 w-4"
                                        aria-hidden="true"
                                    />
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="lignes.length === 0">
                            <td
                                :colspan="moyens.length + 13"
                                class="px-4 py-10 text-center text-muted-foreground"
                            >
                                Aucune activité d'agent sur la période.
                            </td>
                        </tr>
                    </tbody>
                    <tfoot v-if="lignes.length > 1">
                        <tr
                            class="border-t-2 bg-muted font-semibold"
                            data-testid="rapport-agents-total"
                        >
                            <td class="sticky left-0 z-10 bg-muted px-4 py-3">
                                Total
                            </td>
                            <td />
                            <td :class="TD">{{ n(totaux.ventes_montant) }}</td>
                            <td
                                v-for="(m, i) in moyens"
                                :key="m.cle"
                                :class="cn(TD, i === 0 && 'border-l')"
                            >
                                {{ n(totaux.par_moyen?.[m.cle] ?? 0) }}
                            </td>
                            <td :class="cn(TD, 'border-x')">
                                {{ n(totaux.encaisse_montant) }}
                            </td>
                            <td :class="TD">{{ n(totaux.dettes_montant) }}</td>
                            <td :class="TD">{{ n(totaux.charges_saisies) }}</td>
                            <td :class="TD">{{ n(totaux.charges_payees) }}</td>
                            <td :class="cn(TD, 'border-l')">
                                {{ n(totaux.net_a_remettre) }}
                            </td>
                            <td :class="TD">{{ n(totaux.deja_remis) }}</td>
                            <td :class="TD">{{ n(totaux.en_transit) }}</td>
                            <td :class="TD">
                                {{ n(totaux.reste_a_remettre) }}
                            </td>
                            <td colspan="2" />
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p class="border-t px-4 py-2.5 text-xs text-muted-foreground">
                Chaque agent remet à son agence de rattachement. Net à remettre
                = encaissé en espèces et Mobile Money + approvisionnements reçus
                − charges validées, en cumul jusqu'à la fin de la période ;
                reste = net − déjà remis (réceptions confirmées par l'agence).
                Une remise en transit fait encore partie du reste. Seules les
                charges validées sont déduites.
            </p>
        </section>

        <!-- Liste empilée (< 1024 px, deux colonnes sur tablette) -->
        <ul
            class="grid grid-cols-1 items-start gap-3 sm:grid-cols-2 lg:hidden"
            data-testid="rapport-agents-liste-mobile"
        >
            <li
                v-for="l in lignes"
                :key="l.agent_id ?? 'inconnu'"
                class="overflow-hidden rounded-xl border bg-card"
            >
                <div class="flex items-start justify-between gap-2 px-4 pt-3">
                    <div class="min-w-0">
                        <p class="font-semibold">{{ l.agent }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{ agences(l) }}
                        </p>
                    </div>
                    <StatusDot
                        v-if="l.remise"
                        :status="l.remise.statut"
                        :label="l.remise.statut_label"
                    />
                </div>
                <dl
                    class="grid grid-cols-2 gap-x-3 gap-y-1.5 px-4 py-3 text-sm"
                >
                    <dt class="text-muted-foreground">Ventes</dt>
                    <dd class="text-right tabular-nums">
                        {{ n(l.ventes_montant) }}
                    </dd>
                    <template v-for="m in moyens" :key="m.cle">
                        <dt class="text-muted-foreground">{{ m.libelle }}</dt>
                        <dd class="text-right tabular-nums">
                            {{ n(moyen(l, m.cle)) }}
                        </dd>
                    </template>
                    <dt class="font-medium">Total encaissé</dt>
                    <dd class="text-right font-semibold tabular-nums">
                        {{ n(l.encaisse_montant) }}
                    </dd>
                    <dt class="text-muted-foreground">Dettes clients</dt>
                    <dd class="text-right tabular-nums">
                        {{ n(l.dettes_montant) }}
                    </dd>
                    <dt class="text-muted-foreground">Charges saisies</dt>
                    <dd class="text-right tabular-nums">
                        {{ n(l.charges_saisies) }}
                    </dd>
                    <dt class="text-muted-foreground">Charges validées</dt>
                    <dd class="text-right tabular-nums">
                        {{ n(l.charges_payees) }}
                    </dd>
                </dl>
                <dl
                    v-if="l.remise"
                    :class="
                        cn(
                            REMISE,
                            'grid grid-cols-2 gap-x-3 gap-y-1.5 border-t px-4 py-3 text-sm',
                        )
                    "
                >
                    <dt class="text-muted-foreground">Net à remettre</dt>
                    <dd class="text-right tabular-nums">
                        {{ n(l.remise.net_a_remettre) }}
                    </dd>
                    <dt class="text-muted-foreground">Déjà remis</dt>
                    <dd class="text-right tabular-nums">
                        {{ n(l.remise.deja_remis) }}
                    </dd>
                    <dt class="text-muted-foreground">En transit</dt>
                    <dd class="text-right tabular-nums">
                        {{ n(l.remise.en_transit) }}
                    </dd>
                    <dt class="font-medium">Reste à remettre</dt>
                    <dd class="text-right font-semibold tabular-nums">
                        {{ n(l.remise.reste_a_remettre) }}
                    </dd>
                </dl>
                <p
                    v-else
                    :class="
                        cn(
                            REMISE,
                            'border-t px-4 py-2.5 text-xs text-muted-foreground',
                        )
                    "
                >
                    Aucune caisse dédiée
                </p>
                <div
                    v-if="l.agent_id && !sansLien"
                    class="border-t px-4 py-2.5 text-sm"
                >
                    <Link
                        :href="hrefAgent(l.agent_id)"
                        class="inline-flex items-center gap-1 font-medium text-primary"
                    >
                        Opérations
                        <ArrowUpRight class="h-4 w-4" aria-hidden="true" />
                    </Link>
                </div>
            </li>
            <li
                v-if="lignes.length === 0"
                class="rounded-xl border bg-card px-4 py-10 text-center text-sm text-muted-foreground sm:col-span-2"
            >
                Aucune activité d'agent sur la période.
            </li>
        </ul>
    </div>
</template>
