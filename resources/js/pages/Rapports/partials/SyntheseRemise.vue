<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { cn } from '@/lib/utils';
import type { RemiseAgent } from '@/types/rapports';
import { Info } from 'lucide-vue-next';
import { dateFr } from './format';
import { n, REMISE } from './tableau';

// « Ma situation » : calcul de la remise à mon agence, ligne à ligne (ADR 0064) —
// encaissé + approvisionnements − charges validées = net ; net − déjà remis = reste.
defineProps<{ remise: RemiseAgent | null }>();
</script>

<template>
    <section
        class="overflow-hidden rounded-xl border bg-card"
        aria-labelledby="titre-ma-remise"
        data-testid="ma-remise"
    >
        <div
            class="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3"
        >
            <h2 id="titre-ma-remise" class="text-sm font-semibold">
                Ma remise à
                {{
                    remise?.agences.length
                        ? remise.agences.join(', ')
                        : 'mon agence'
                }}
                <span v-if="remise" class="font-normal text-muted-foreground"
                    >· cumul du {{ dateFr(remise.depuis) }} à la fin de la
                    période · GNF</span
                >
            </h2>
            <StatusDot
                v-if="remise"
                :status="remise.statut"
                :label="remise.statut_label"
            />
        </div>
        <dl
            v-if="remise"
            :class="cn(REMISE, 'divide-y text-sm')"
            data-testid="ma-remise-calcul"
        >
            <div class="flex items-baseline justify-between gap-3 px-4 py-2">
                <dt>Encaissé en espèces et Mobile Money</dt>
                <dd class="shrink-0 whitespace-nowrap tabular-nums">
                    {{ n(remise.encaisse) }}
                </dd>
            </div>
            <div
                v-if="remise.approvisionnements"
                class="flex items-baseline justify-between gap-3 px-4 py-2"
            >
                <dt>+ Approvisionnements reçus de l'agence</dt>
                <dd class="shrink-0 whitespace-nowrap tabular-nums">
                    {{ n(remise.approvisionnements) }}
                </dd>
            </div>
            <div class="flex items-baseline justify-between gap-3 px-4 py-2">
                <dt>− Charges validées</dt>
                <dd
                    class="shrink-0 whitespace-nowrap tabular-nums"
                    data-testid="ma-remise-charges"
                >
                    {{ n(remise.charges_validees) }}
                </dd>
            </div>
            <div
                class="flex items-baseline justify-between gap-3 px-4 py-2 font-medium"
            >
                <dt>= Montant net à remettre</dt>
                <dd
                    class="shrink-0 whitespace-nowrap tabular-nums"
                    data-testid="ma-remise-net"
                >
                    {{ n(remise.net_a_remettre) }}
                </dd>
            </div>
            <div class="flex items-baseline justify-between gap-3 px-4 py-2">
                <dt>
                    − Déjà remis à mon agence
                    <span
                        v-if="remise.remis"
                        class="text-xs text-muted-foreground"
                        >· dont {{ n(remise.remis) }} sur la période</span
                    >
                </dt>
                <dd
                    class="shrink-0 whitespace-nowrap tabular-nums"
                    data-testid="ma-remise-remis"
                >
                    {{ n(remise.deja_remis) }}
                </dd>
            </div>
            <div
                class="flex items-baseline justify-between gap-3 px-4 py-2.5 font-semibold"
            >
                <dt>= Reste à remettre</dt>
                <dd
                    class="shrink-0 whitespace-nowrap tabular-nums"
                    data-testid="ma-remise-reste"
                >
                    {{ n(remise.reste_a_remettre) }}
                </dd>
            </div>
            <div
                class="flex items-baseline justify-between gap-3 px-4 py-2 text-muted-foreground"
            >
                <dt>dont en transit (envoyé, non confirmé)</dt>
                <dd class="shrink-0 whitespace-nowrap tabular-nums">
                    {{ n(remise.en_transit) }}
                </dd>
            </div>
        </dl>
        <div v-else class="flex items-center gap-2 px-4 py-4 text-sm">
            <Info class="h-4 w-4 shrink-0 text-blue-500" />
            <span>Aucune caisse dédiée : rien à remettre.</span>
        </div>
        <p class="border-t px-4 py-2.5 text-xs text-muted-foreground">
            Seules les charges validées sont déduites ; une remise n'est comptée
            qu'une fois reçue par votre agence. Vous remettez à votre agence,
            jamais directement à la trésorerie principale.
        </p>
    </section>
</template>
