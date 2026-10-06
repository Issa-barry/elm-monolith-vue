<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import type { DepenseRow } from '@/types/depense';
import { Link } from '@inertiajs/vue3';
import { ChevronRight, MapPin, Receipt } from 'lucide-vue-next';
import DepenseActions from './DepenseActions.vue';

defineProps<{ depenses: DepenseRow[] }>();
const emit = defineEmits<{
    audit: [id: string];
    soumettre: [id: string];
    valider: [id: string];
    rejeter: [id: string];
    supprimer: [id: string];
}>();

function formatMontant(value: number): string {
    return `${new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 }).format(value)} GNF`;
}

function formatDate(value: string): string {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return value;
    return value.split('-').reverse().join('/');
}
</script>

<template>
    <section class="space-y-2.5 px-4 sm:hidden" aria-label="Liste des dépenses">
        <div
            v-if="!depenses.length"
            class="rounded-2xl border bg-card px-6 py-12 text-center"
        >
            <Receipt class="mx-auto size-10 text-muted-foreground/40" />
            <p class="mt-3 font-medium">Aucune dépense à afficher</p>
            <p class="mt-1 text-sm text-muted-foreground">
                Aucune dépense ne correspond aux critères actuels.
            </p>
        </div>
        <article
            v-for="d in depenses"
            :key="d.id"
            data-testid="depense-card"
            class="relative rounded-2xl border border-border/60 bg-card"
        >
            <div class="absolute top-2 right-2 z-[1]">
                <DepenseActions
                    :d="d"
                    @audit="emit('audit', $event)"
                    @soumettre="emit('soumettre', $event)"
                    @valider="emit('valider', $event)"
                    @rejeter="emit('rejeter', $event)"
                    @supprimer="emit('supprimer', $event)"
                />
            </div>
            <Link
                :href="`/backoffice/depenses/${d.id}`"
                :aria-label="`${d.type?.libelle ?? 'Dépense'}, ${d.beneficiaire_label ?? 'Interne'}. Voir le détail`"
                class="block rounded-2xl p-3.5 transition-colors hover:bg-muted/20 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none active:bg-muted/40"
            >
                <div class="flex items-center gap-3 pr-11">
                    <span
                        class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted/60 text-muted-foreground"
                        ><Receipt class="size-5" aria-hidden="true"
                    /></span>
                    <div class="min-w-0 flex-1">
                        <p
                            class="line-clamp-2 text-sm leading-5 font-semibold break-words"
                        >
                            {{ d.type?.libelle ?? 'Dépense' }}
                        </p>
                        <p
                            v-if="d.beneficiaire_label"
                            class="mt-0.5 text-xs leading-5 break-words text-muted-foreground"
                        >
                            {{ d.beneficiaire_label }}
                        </p>
                    </div>
                </div>
                <div
                    class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-border/50 pt-3"
                >
                    <p class="text-lg font-semibold break-words tabular-nums">
                        {{ formatMontant(d.montant) }}
                    </p>
                    <StatusDot
                        :status="d.statut"
                        :label="d.statut_label"
                        size="sm"
                    />
                </div>
                <div
                    class="mt-2 flex items-start justify-between gap-3 text-xs leading-5 text-muted-foreground"
                >
                    <div class="min-w-0">
                        <p>{{ formatDate(d.date_depense) }}</p>
                        <p v-if="d.site" class="flex items-start gap-1">
                            <MapPin
                                class="mt-0.5 size-3.5 shrink-0"
                                aria-hidden="true"
                            /><span class="break-words">{{ d.site.nom }}</span>
                        </p>
                    </div>
                    <span class="flex shrink-0 items-center gap-1"
                        >Détails<ChevronRight
                            class="size-3.5"
                            aria-hidden="true"
                    /></span>
                </div>
            </Link>
        </article>
    </section>
</template>
