<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import type { StockRow } from '@/types/stock';
import { useMediaQuery } from '@vueuse/core';
import {
    ChevronRight,
    History,
    MapPin,
    Package,
    PackageOpen,
    SlidersHorizontal,
    X,
} from 'lucide-vue-next';
import Drawer from 'primevue/drawer';
import { computed, ref, watch } from 'vue';

const props = defineProps<{ stocks: StockRow[] }>();
const emit = defineEmits<{
    historique: [row: StockRow];
    ajuster: [row: StockRow];
}>();
const isMobile = useMediaQuery('(max-width: 767px)');
const visible = ref(false);
const selectedKey = ref<string | null>(null);
const selected = computed(() =>
    props.stocks.find((row) => key(row) === selectedKey.value),
);
let lastTrigger: HTMLButtonElement | null = null;
let pendingAction: 'historique' | 'ajuster' | null = null;
let returningFromAction = false;

function key(row: StockRow): string {
    return `${row.variante_id}|${row.site_id}`;
}

function formatNombre(value: number | null): string {
    return value === null ? '—' : new Intl.NumberFormat('fr-FR').format(value);
}

function openDetail(row: StockRow, event: MouseEvent): void {
    lastTrigger = event.currentTarget as HTMLButtonElement;
    selectedKey.value = key(row);
    visible.value = true;
}

function openAction(action: 'historique' | 'ajuster'): void {
    pendingAction = action;
    visible.value = false;
}

function afterHide(): void {
    if (isMobile.value && lastTrigger?.isConnected) lastTrigger.focus();
    const row = selected.value;
    const action = pendingAction;
    pendingAction = null;
    if (row && action && isMobile.value) {
        returningFromAction = true;
        if (action === 'historique') emit('historique', row);
        else emit('ajuster', row);
        return;
    }
    selectedKey.value = null;
}

function returnToDetails(): void {
    if (!returningFromAction) return;
    returningFromAction = false;
    if (isMobile.value && selected.value) visible.value = true;
    else selectedKey.value = null;
}

watch([isMobile, selected], ([mobile, row]) => {
    if (!mobile || !row) {
        pendingAction = null;
        returningFromAction = false;
        visible.value = false;
    }
});

const detailRows = computed(() => {
    const row = selected.value;
    if (!row) return [];
    return [
        { label: 'Variante', value: row.variante_libelle || '—' },
        { label: 'SKU', value: row.sku || '—' },
        ...(row.categorie_nom
            ? [{ label: 'Catégorie', value: row.categorie_nom }]
            : []),
        { label: 'Physique', value: formatNombre(row.qte_physique) },
        { label: 'Réservé', value: formatNombre(row.qte_engagee) },
        { label: 'Bloqué', value: formatNombre(row.qte_bloquee) },
        { label: 'Entrant', value: formatNombre(row.qte_entrante) },
        ...(row.disponible_sur_site
            ? [
                  {
                      label: 'Seuil d’alerte',
                      value: formatNombre(row.seuil_effectif),
                  },
              ]
            : []),
    ];
});

defineExpose({ returnToDetails });
</script>

<template>
    <section class="space-y-2.5 md:hidden" aria-label="Liste du stock">
        <div
            v-if="!stocks.length"
            class="rounded-xl border bg-card px-6 py-16 text-center"
        >
            <PackageOpen class="mx-auto h-10 w-10 text-muted-foreground/40" />
            <p class="mt-3 font-medium">Aucun stock à afficher</p>
            <p class="mt-1 text-sm text-muted-foreground">
                Aucun produit gérant le stock ne correspond aux filtres actuels.
            </p>
        </div>
        <button
            v-for="row in stocks"
            :key="key(row)"
            type="button"
            data-testid="stock-card"
            aria-haspopup="dialog"
            :aria-label="`${row.produit_nom}, ${row.site_nom}. Voir les détails du stock`"
            class="block w-full rounded-2xl border border-border/60 bg-card p-3.5 text-left transition-colors hover:bg-muted/20 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none active:bg-muted/40"
            @click="openDetail(row, $event)"
        >
            <div class="flex items-center gap-3">
                <Avatar class="size-12 shrink-0 rounded-xl">
                    <AvatarImage
                        v-if="row.image_url"
                        :src="row.image_url"
                        alt=""
                        loading="lazy"
                        class="object-cover"
                    />
                    <AvatarFallback
                        class="rounded-xl bg-muted/60 text-muted-foreground"
                        ><Package class="size-5" aria-hidden="true"
                    /></AvatarFallback>
                </Avatar>
                <div class="min-w-0 flex-1">
                    <p
                        class="line-clamp-2 text-sm leading-5 font-semibold break-words"
                    >
                        {{ row.produit_nom }}
                    </p>
                    <p
                        class="mt-1 flex items-start gap-1 text-xs leading-5 text-muted-foreground"
                    >
                        <MapPin
                            class="mt-0.5 size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        <span class="break-words">{{ row.site_nom }}</span>
                    </p>
                </div>
                <ChevronRight
                    class="size-4 shrink-0 text-muted-foreground/60"
                    aria-hidden="true"
                />
            </div>
            <div
                class="mt-3 flex flex-wrap items-baseline justify-between gap-2 border-t border-border/50 pt-2.5"
            >
                <span class="text-xs text-muted-foreground">Disponible</span>
                <span
                    data-testid="stock-available"
                    class="text-xl font-semibold tabular-nums"
                    >{{ formatNombre(row.qte_disponible) }}</span
                >
            </div>
            <StatusDot
                v-if="!row.disponible_sur_site"
                label="Non disponible sur cette agence"
                dot-class="bg-zinc-400 dark:bg-zinc-500"
                class="mt-2"
            />
        </button>
    </section>

    <Drawer
        v-model:visible="visible"
        position="bottom"
        modal
        dismissable
        close-on-escape
        block-scroll
        :show-close-icon="false"
        class="stock-mobile-details"
        aria-labelledby="stock-mobile-detail-title"
        @after-hide="afterHide"
    >
        <template #header>
            <div class="w-full">
                <div class="relative flex h-7 justify-center">
                    <div
                        class="h-1 w-10 rounded-full bg-muted-foreground/25"
                        aria-hidden="true"
                    />
                    <button
                        type="button"
                        class="absolute -top-2 -right-3 flex size-11 items-center justify-center rounded-full text-muted-foreground hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        aria-label="Fermer les détails"
                        autofocus
                        @click="visible = false"
                    >
                        <X class="size-5" aria-hidden="true" />
                    </button>
                </div>
                <div
                    class="flex flex-wrap items-start justify-between gap-2 pb-2"
                >
                    <h2
                        id="stock-mobile-detail-title"
                        class="text-sm font-semibold"
                    >
                        Détails du stock
                    </h2>
                    <StatusDot
                        v-if="selected?.disponible_sur_site"
                        :status="selected.statut"
                        :label="selected.statut_label"
                        size="sm"
                    />
                    <StatusDot
                        v-else-if="selected"
                        label="Non disponible"
                        dot-class="bg-zinc-400 dark:bg-zinc-500"
                        size="sm"
                    />
                </div>
            </div>
        </template>

        <div v-if="selected" class="space-y-4 pt-3">
            <div class="flex items-center gap-3 rounded-2xl bg-muted/40 p-3">
                <Avatar class="size-16 shrink-0 rounded-xl">
                    <AvatarImage
                        v-if="selected.image_url"
                        :src="selected.image_url"
                        :alt="selected.produit_nom"
                        class="object-cover"
                    />
                    <AvatarFallback
                        class="rounded-xl bg-muted text-muted-foreground"
                        ><Package class="size-6" aria-hidden="true"
                    /></AvatarFallback>
                </Avatar>
                <div class="min-w-0">
                    <p class="text-sm leading-5 font-semibold break-words">
                        {{ selected.produit_nom }}
                    </p>
                    <p
                        class="mt-1 text-xs leading-5 break-words text-muted-foreground"
                    >
                        {{ selected.site_nom
                        }}<span v-if="selected.site_code">
                            ({{ selected.site_code }})</span
                        >
                    </p>
                </div>
            </div>
            <div
                class="flex flex-wrap items-baseline justify-between gap-2 rounded-xl border border-border/60 p-3.5"
            >
                <span class="text-sm text-muted-foreground">Disponible</span>
                <span class="text-2xl font-semibold tabular-nums">{{
                    formatNombre(selected.qte_disponible)
                }}</span>
            </div>
            <dl class="divide-y divide-border/60 text-sm">
                <div
                    v-for="row in detailRows"
                    :key="row.label"
                    class="grid grid-cols-[6rem_minmax(0,1fr)] gap-4 py-3"
                >
                    <dt class="text-muted-foreground">{{ row.label }}</dt>
                    <dd class="text-right font-medium break-words">
                        {{ row.value }}
                    </dd>
                </div>
            </dl>
            <section
                class="rounded-xl border border-border/60 p-3.5"
                aria-label="Dernier mouvement"
            >
                <h3 class="text-xs text-muted-foreground">Dernier mouvement</h3>
                <template v-if="selected.dernier_mouvement">
                    <div
                        class="mt-2 flex items-start justify-between gap-3 text-sm"
                    >
                        <p class="min-w-0 font-medium break-words">
                            {{
                                selected.dernier_mouvement.motif_label ||
                                'Motif non renseigné'
                            }}
                        </p>
                        <span
                            class="shrink-0 font-semibold tabular-nums"
                            :class="
                                selected.dernier_mouvement.type === 'entree'
                                    ? 'text-emerald-700 dark:text-emerald-400'
                                    : 'text-red-700 dark:text-red-400'
                            "
                            >{{
                                selected.dernier_mouvement.type === 'entree'
                                    ? '+'
                                    : '−'
                            }}{{
                                formatNombre(
                                    selected.dernier_mouvement.quantite,
                                )
                            }}</span
                        >
                    </div>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ selected.dernier_mouvement.date }}
                    </p>
                </template>
                <p v-else class="mt-2 text-sm">Aucun mouvement</p>
            </section>
        </div>
        <template #footer>
            <div v-if="selected" class="flex gap-2">
                <Button
                    variant="outline"
                    class="h-11 flex-1 rounded-lg"
                    data-testid="stock-history-button"
                    @click="openAction('historique')"
                    ><History class="mr-1.5 size-4" />Historique</Button
                >
                <Button
                    v-if="selected.can_ajuster"
                    variant="outline"
                    class="h-11 flex-1 rounded-lg"
                    data-testid="stock-adjust-button"
                    @click="openAction('ajuster')"
                    ><SlidersHorizontal class="mr-1.5 size-4" />Ajuster</Button
                >
            </div>
        </template>
    </Drawer>
</template>

<style scoped>
:global(.stock-mobile-details.p-drawer.p-component) {
    width: 100%;
    max-width: 40rem;
    height: auto;
    max-height: 85dvh;
    margin: 0 auto;
    overflow: hidden;
    border-radius: 1.5rem 1.5rem 0 0;
}
:global(.stock-mobile-details .p-drawer-header) {
    flex-shrink: 0;
    padding: 0.75rem 1.25rem 0;
}
:global(.stock-mobile-details.p-drawer .p-drawer-content) {
    min-height: 0;
    height: auto;
    padding: 0 1.25rem 1rem;
    overscroll-behavior: contain;
    overflow-x: hidden;
    overflow-y: auto;
}
:global(.stock-mobile-details .p-drawer-footer) {
    flex-shrink: 0;
    border-top: 1px solid var(--border);
    padding: 0.75rem 1.25rem calc(env(safe-area-inset-bottom) + 0.75rem);
}
</style>
