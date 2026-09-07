<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import QrCodeTicket from '@/components/print/QrCodeTicket.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { formatGNF, formatPhoneDisplay } from '@/lib/utils';
import type { VenteMobile } from '@/types/vente-mobile';
import { useMediaQuery } from '@vueuse/core';
import { ChevronRight, ShoppingBag, Truck, X } from 'lucide-vue-next';
import Drawer from 'primevue/drawer';
import { computed, ref, watch } from 'vue';

const props = defineProps<{ commandes: VenteMobile[] }>();
const isMobile = useMediaQuery('(max-width: 639px)');
const visible = ref(false);
const selectedId = ref<string | null>(null);
const selected = computed(() =>
    props.commandes.find((commande) => commande.id === selectedId.value),
);
let lastTrigger: HTMLButtonElement | null = null;

function openDetail(commande: VenteMobile, event: MouseEvent) {
    lastTrigger = event.currentTarget as HTMLButtonElement;
    selectedId.value = commande.id;
    visible.value = true;
}

function restoreFocus() {
    if (isMobile.value && lastTrigger?.isConnected) lastTrigger.focus();
    selectedId.value = null;
}

watch([isMobile, selected], ([mobile, commande]) => {
    if (!mobile || !commande) visible.value = false;
});

const detailRows = computed(() => {
    const commande = selected.value;
    if (!commande) return [];
    return [
        { label: 'Nature', value: commande.processus_label },
        { label: 'Date', value: commande.created_at },
        { label: 'Agence', value: commande.site_nom || '—' },
        { label: 'Client', value: commande.client_nom || 'Non renseigné' },
        {
            label: 'Téléphone',
            value: commande.client_telephone
                ? formatPhoneDisplay(commande.client_telephone)
                : '—',
        },
        {
            label: 'Chauffeur',
            value: commande.chauffeur_nom || 'Non renseigné',
        },
    ];
});
</script>

<template>
    <div class="space-y-2.5 p-4" aria-label="Liste des ventes">
        <button
            v-for="commande in commandes"
            :key="commande.id"
            type="button"
            class="block w-full rounded-2xl border border-border/60 bg-card p-3.5 text-left transition-colors hover:bg-muted/20 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none active:bg-muted/40"
            aria-haspopup="dialog"
            :aria-label="`${commande.reference}, ${commande.statut_label}. Voir les détails`"
            @click="openDetail(commande, $event)"
        >
            <div class="flex items-center gap-3">
                <Avatar class="size-12 rounded-xl">
                    <AvatarImage
                        v-if="commande.vehicule_photo_url"
                        :src="commande.vehicule_photo_url"
                        alt=""
                        class="object-cover"
                        loading="lazy"
                    />
                    <AvatarFallback
                        class="rounded-xl bg-muted/60 text-muted-foreground"
                    >
                        <Truck
                            v-if="
                                commande.vehicule_nom ||
                                commande.vehicule_immatriculation
                            "
                            class="size-5"
                            aria-hidden="true"
                        />
                        <ShoppingBag v-else class="size-5" aria-hidden="true" />
                    </AvatarFallback>
                </Avatar>
                <div class="min-w-0 flex-1">
                    <p
                        class="text-sm font-semibold break-words text-foreground"
                    >
                        {{ commande.reference }}
                    </p>
                    <p class="mt-1 truncate text-xs text-muted-foreground">
                        {{ commande.vehicule_nom || 'Sans véhicule' }}
                    </p>
                    <p
                        v-if="commande.vehicule_immatriculation"
                        class="mt-0.5 text-xs text-muted-foreground"
                    >
                        {{ commande.vehicule_immatriculation }}
                    </p>
                </div>
                <ChevronRight
                    class="size-4 shrink-0 text-muted-foreground/60"
                    aria-hidden="true"
                />
            </div>
            <div
                class="mt-3 flex items-start justify-between gap-3 border-t border-border/50 pt-2.5"
            >
                <div class="min-w-0 text-xs text-muted-foreground">
                    <p>{{ commande.processus_label }}</p>
                    <p class="mt-1 tabular-nums">{{ commande.created_at }}</p>
                </div>
                <StatusDot
                    :status="commande.statut"
                    :label="commande.statut_label"
                    size="sm"
                    class="max-w-[55%] whitespace-normal"
                />
            </div>
        </button>
    </div>

    <Drawer
        v-model:visible="visible"
        position="bottom"
        modal
        dismissable
        close-on-escape
        block-scroll
        :show-close-icon="false"
        class="vente-mobile-details"
        aria-labelledby="vente-mobile-detail-title"
        @after-hide="restoreFocus"
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
                <div class="flex items-start justify-between gap-3 pb-2">
                    <h2
                        id="vente-mobile-detail-title"
                        class="min-w-0 text-sm font-semibold break-words"
                    >
                        {{ selected?.reference }}
                    </h2>
                    <StatusDot
                        v-if="selected"
                        :status="selected.statut"
                        :label="selected.statut_label"
                        size="sm"
                        class="max-w-[48%] shrink-0 whitespace-normal"
                    />
                </div>
            </div>
        </template>

        <div v-if="selected" class="space-y-5 pt-3">
            <div
                role="group"
                :aria-label="`QR code de la commande ${selected.reference}`"
                class="rounded-2xl border border-border/60 p-4 text-center"
            >
                <QrCodeTicket
                    :key="selected.id"
                    :url="selected.reference"
                    :size="176"
                    label=""
                />
                <p class="mt-1 text-xs text-muted-foreground">
                    Scannez pour identifier la commande
                </p>
                <p class="mt-2 text-xs font-semibold">
                    {{ selected.reference }}
                </p>
            </div>

            <div class="flex items-center gap-3 rounded-2xl bg-muted/40 p-3">
                <Avatar class="size-16 rounded-xl">
                    <AvatarImage
                        v-if="selected.vehicule_photo_url"
                        :src="selected.vehicule_photo_url"
                        :alt="selected.vehicule_nom || 'Véhicule'"
                        class="object-cover"
                    />
                    <AvatarFallback
                        class="rounded-xl bg-muted text-muted-foreground"
                    >
                        <Truck
                            v-if="
                                selected.vehicule_nom ||
                                selected.vehicule_immatriculation
                            "
                            class="size-6"
                            aria-hidden="true"
                        />
                        <ShoppingBag v-else class="size-6" aria-hidden="true" />
                    </AvatarFallback>
                </Avatar>
                <div class="min-w-0">
                    <p class="text-xs text-muted-foreground">Véhicule</p>
                    <p class="mt-1 text-sm font-medium break-words">
                        {{ selected.vehicule_nom || 'Sans véhicule' }}
                    </p>
                    <p
                        v-if="selected.vehicule_immatriculation"
                        class="mt-0.5 text-xs text-muted-foreground"
                    >
                        {{ selected.vehicule_immatriculation }}
                    </p>
                </div>
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
                aria-label="Montants de la commande"
                class="rounded-2xl border border-border/60 p-4"
            >
                <div
                    class="flex flex-wrap items-baseline justify-between gap-2"
                >
                    <h3 class="text-sm text-muted-foreground">
                        Total commande
                    </h3>
                    <p class="text-lg font-semibold tabular-nums">
                        {{ formatGNF(selected.total_commande) }}
                    </p>
                </div>
                <dl
                    v-if="
                        selected.facture_montant_encaisse !== null ||
                        selected.facture_montant_restant !== null
                    "
                    class="mt-3 space-y-2 border-t border-border/60 pt-3 text-sm"
                >
                    <div
                        v-if="selected.facture_montant_encaisse !== null"
                        class="flex flex-wrap justify-between gap-2"
                    >
                        <dt class="text-muted-foreground">Déjà payé</dt>
                        <dd class="font-medium tabular-nums">
                            {{ formatGNF(selected.facture_montant_encaisse) }}
                        </dd>
                    </div>
                    <div
                        v-if="selected.facture_montant_restant !== null"
                        class="flex flex-wrap justify-between gap-2"
                    >
                        <dt class="text-muted-foreground">Restant à payer</dt>
                        <dd class="font-medium tabular-nums">
                            {{ formatGNF(selected.facture_montant_restant) }}
                        </dd>
                    </div>
                </dl>
                <div
                    v-if="selected.facture_statut_label"
                    class="mt-3 border-t border-border/60 pt-3"
                >
                    <StatusDot
                        :status="selected.facture_statut"
                        :label="selected.facture_statut_label"
                        size="sm"
                    />
                </div>
            </section>
        </div>
    </Drawer>
</template>

<style scoped>
:global(.vente-mobile-details.p-drawer.p-component) {
    width: 100%;
    max-width: 40rem;
    height: auto;
    max-height: 85dvh;
    margin: 0 auto;
    overflow: hidden;
    border-radius: 1.5rem 1.5rem 0 0;
}

:global(.vente-mobile-details .p-drawer-header) {
    padding: 0.75rem 1.25rem 0;
}

:global(.vente-mobile-details.p-drawer .p-drawer-content) {
    min-height: 0;
    height: auto;
    padding: 0 1.25rem calc(env(safe-area-inset-bottom) + 1.25rem);
    overscroll-behavior: contain;
}
</style>
