<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, CheckCircle2 } from 'lucide-vue-next';
import { computed } from 'vue';

// ── Types ─────────────────────────────────────────────────────────────────────

interface PaymentDetail {
    paid_at: string | null;
    montant: number;
    mode_paiement: string | null;
}

interface PartRow {
    id: number;
    transfert_reference: string | null;
    taux_commission: number;
    montant_brut: number;
    frais_supplementaires: number;
    montant_net: number;
    montant_verse: number;
    montant_restant: number;
    earned_at: string | null;
    statut: string | null;
    statut_label: string;
    statut_dot_class: string;
    payments: PaymentDetail[];
}

// ── Props ─────────────────────────────────────────────────────────────────────

const props = defineProps<{
    vehicule: { id: number; nom: string; immatriculation: string | null };
    beneficiaire: { id: number; type: string; nom: string };
    parts: PartRow[];
}>();

// ── Breadcrumbs ───────────────────────────────────────────────────────────────

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Commissions', href: '/logistique/commissions' },
    {
        title: props.vehicule.nom,
        href: `/logistique/commissions/vehicules/${props.vehicule.id}`,
    },
    { title: props.beneficiaire.nom, href: '' },
];

// ── Totaux ────────────────────────────────────────────────────────────────────

const totals = computed(() => ({
    brut: props.parts.reduce((s, p) => s + p.montant_brut, 0),
    net: props.parts.reduce((s, p) => s + p.montant_net, 0),
    verse: props.parts.reduce((s, p) => s + p.montant_verse, 0),
    restant: props.parts.reduce((s, p) => s + p.montant_restant, 0),
}));

// ── Formatage ─────────────────────────────────────────────────────────────────

function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}
</script>

<template>
    <Head :title="`Relevé — ${beneficiaire.nom}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-6xl space-y-6 px-4 py-6 sm:px-6">
            <!-- ── En-tête ──────────────────────────────────────────────────── -->
            <div class="flex items-start gap-3">
                <Link
                    :href="`/logistique/commissions/vehicules/${vehicule.id}`"
                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground hover:bg-muted/80"
                >
                    <ArrowLeft class="h-4 w-4" />
                </Link>
                <div>
                    <p
                        class="text-xs font-semibold tracking-[0.14em] text-muted-foreground uppercase"
                    >
                        Relevé —
                        {{
                            beneficiaire.type === 'livreur'
                                ? 'Livreur'
                                : 'Propriétaire'
                        }}
                    </p>
                    <p class="mt-0.5 text-xl font-semibold">
                        {{ beneficiaire.nom }}
                    </p>
                    <p class="mt-0.5 text-sm text-muted-foreground">
                        {{ vehicule.nom }}
                        <span v-if="vehicule.immatriculation" class="font-mono"
                            >({{ vehicule.immatriculation }})</span
                        >
                    </p>
                </div>
            </div>

            <!-- ── Synthèse ───────────────────────────────────────────────────── -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-lg border bg-card px-4 py-3 text-center">
                    <p
                        class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        Brut total
                    </p>
                    <p class="mt-0.5 font-semibold tabular-nums">
                        {{ formatGNF(totals.brut) }}
                    </p>
                </div>
                <div class="rounded-lg border bg-card px-4 py-3 text-center">
                    <p
                        class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        Net total
                    </p>
                    <p class="mt-0.5 font-semibold tabular-nums">
                        {{ formatGNF(totals.net) }}
                    </p>
                </div>
                <div class="rounded-lg border bg-card px-4 py-3 text-center">
                    <p
                        class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        Versé
                    </p>
                    <p
                        class="mt-0.5 font-semibold text-emerald-600 tabular-nums dark:text-emerald-400"
                    >
                        {{ formatGNF(totals.verse) }}
                    </p>
                </div>
                <div
                    class="rounded-lg border bg-card px-4 py-3 text-center"
                    :class="
                        totals.restant > 0
                            ? 'border-amber-200 dark:border-amber-900'
                            : ''
                    "
                >
                    <p
                        class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        Restant
                    </p>
                    <p
                        class="mt-0.5 font-semibold tabular-nums"
                        :class="
                            totals.restant > 0
                                ? 'text-amber-600 dark:text-amber-400'
                                : 'text-foreground'
                        "
                    >
                        {{ formatGNF(totals.restant) }}
                    </p>
                </div>
            </div>

            <!-- ── Relevé détaillé ──────────────────────────────────────────── -->
            <div class="overflow-hidden rounded-xl border bg-card shadow-sm">
                <div class="border-b px-5 py-3.5">
                    <h2
                        class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Détail par transfert ({{ parts.length }})
                    </h2>
                </div>

                <div v-if="parts.length > 0" class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b bg-muted/40">
                                <th
                                    class="px-4 py-3 text-left font-medium text-muted-foreground"
                                >
                                    Transfert
                                </th>
                                <th
                                    class="px-4 py-3 text-left font-medium text-muted-foreground"
                                >
                                    Acquis le
                                </th>
                                <th
                                    class="px-4 py-3 text-right font-medium text-muted-foreground"
                                >
                                    Net
                                </th>
                                <th
                                    class="px-4 py-3 text-right font-medium text-muted-foreground"
                                >
                                    Versé
                                </th>
                                <th
                                    class="px-4 py-3 text-right font-medium text-muted-foreground"
                                >
                                    Restant
                                </th>
                                <th
                                    class="px-4 py-3 text-left font-medium text-muted-foreground"
                                >
                                    Statut
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="part in parts" :key="part.id">
                                <!-- Ligne principale -->
                                <tr
                                    class="border-b transition-colors hover:bg-muted/10"
                                >
                                    <td
                                        class="px-4 py-3 font-mono text-sm font-semibold text-primary"
                                    >
                                        {{ part.transfert_reference ?? '—' }}
                                    </td>
                                    <td
                                        class="px-4 py-3 text-muted-foreground tabular-nums"
                                    >
                                        {{ part.earned_at ?? '—' }}
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right font-semibold tabular-nums"
                                    >
                                        {{ formatGNF(part.montant_net) }}
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right text-emerald-600 tabular-nums dark:text-emerald-400"
                                    >
                                        {{ formatGNF(part.montant_verse) }}
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right font-semibold tabular-nums"
                                        :class="
                                            part.montant_restant > 0
                                                ? 'text-amber-600 dark:text-amber-400'
                                                : 'text-muted-foreground'
                                        "
                                    >
                                        {{ formatGNF(part.montant_restant) }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <StatusDot
                                            :label="part.statut_label"
                                            :dot-class="part.statut_dot_class"
                                            class="text-xs text-muted-foreground"
                                        />
                                    </td>
                                </tr>
                                <!-- Sous-lignes : paiements alloués -->
                                <tr
                                    v-for="(pmt, pi) in part.payments"
                                    :key="`pmt-${part.id}-${pi}`"
                                    class="border-b bg-emerald-50/40 text-xs dark:bg-emerald-950/10"
                                >
                                    <td
                                        class="py-1.5 pr-4 pl-8 text-muted-foreground italic"
                                        colspan="3"
                                    >
                                        <CheckCircle2
                                            class="mr-1 inline-block h-3 w-3 text-emerald-500"
                                        />
                                        Paiement du {{ pmt.paid_at ?? '—' }}
                                        <span
                                            v-if="pmt.mode_paiement"
                                            class="ml-1"
                                            >({{ pmt.mode_paiement }})</span
                                        >
                                    </td>
                                    <td
                                        colspan="3"
                                        class="py-1.5 pr-4 text-right font-semibold text-emerald-700 tabular-nums dark:text-emerald-400"
                                    >
                                        {{ formatGNF(pmt.montant) }}
                                    </td>
                                    <td />
                                </tr>
                            </template>
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 bg-muted/20 font-semibold">
                                <td
                                    colspan="3"
                                    class="px-4 py-2.5 text-xs font-bold text-muted-foreground uppercase"
                                >
                                    Total
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums">
                                    {{ formatGNF(totals.net) }}
                                </td>
                                <td
                                    class="px-4 py-2.5 text-right text-emerald-600 tabular-nums dark:text-emerald-400"
                                >
                                    {{ formatGNF(totals.verse) }}
                                </td>
                                <td
                                    class="px-4 py-2.5 text-right tabular-nums"
                                    :class="
                                        totals.restant > 0
                                            ? 'text-amber-600 dark:text-amber-400'
                                            : 'text-muted-foreground'
                                    "
                                >
                                    {{ formatGNF(totals.restant) }}
                                </td>
                                <td />
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div
                    v-else
                    class="py-12 text-center text-sm text-muted-foreground"
                >
                    Aucune commission enregistrée pour ce bénéficiaire sur ce
                    véhicule.
                </div>
            </div>
        </div>
    </AppLayout>
</template>
