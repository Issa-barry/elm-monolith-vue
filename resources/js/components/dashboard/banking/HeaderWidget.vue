<script setup lang="ts">
import IdentityQrBadge from '@/components/identity/IdentityQrBadge.vue';
import SelecteurPeriode from '@/components/periodes/SelecteurPeriode.vue';
import ScannerModal from '@/components/scanner/ScannerModal.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatPhoneDisplay } from '@/lib/utils';
import { dashboard } from '@/routes';
import type { PeriodeFiltre } from '@/types/periode';
import { usePage } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Tooltip from 'primevue/tooltip';
import { computed, ref } from 'vue';

const vTooltip = Tooltip;
const page = usePage();
defineProps<{
    /** Filtre unique de la page (Periode, ADR 0057) : toutes les cartes et tous les graphiques. */
    periode?: PeriodeFiltre;
    qrPayload?: string | null;
}>();

const scannerVisible = ref(false);

const user = computed(() => page.props.auth.user);
const defaultSite = computed(() => page.props.auth.default_site ?? null);

const displayName = computed(() => {
    const firstName = user.value?.prenom?.trim();
    const lastName = user.value?.nom?.trim();
    const fullName = [firstName, lastName].filter(Boolean).join(' ');

    return fullName || user.value?.name?.trim() || 'Utilisateur';
});

// site.label est calculé côté serveur (Site::getLabelAttribute()) — même source que
// UserInfo.vue, pour que tous les écrans affichent le même nom sans reconstruire la
// concaténation "{type} de {nom}" ici (risque de doublon avec un nom déjà auto-descriptif).
const displaySite = computed(() => {
    if (!defaultSite.value) return 'Aucun site affecté';
    return defaultSite.value.label;
});

const { roleLabel } = usePermissions();

const displayRole = computed(() => {
    const role = page.props.auth.roles?.[0];
    return role ? roleLabel(role) : null;
});

const identityMeta = computed(() =>
    [
        displayRole.value,
        user.value?.telephone ? formatPhoneDisplay(user.value.telephone) : null,
    ]
        .filter(Boolean)
        .join(' · '),
);

const initials = computed(() =>
    displayName.value
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join(''),
);
</script>

<template>
    <div class="col-span-12">
        <div
            class="flex w-full items-center gap-4 rounded-2xl border border-border bg-card p-3.5 sm:hidden"
        >
            <IdentityQrBadge
                :qr-value="qrPayload"
                :name="displayName"
                :subtitle="displaySite"
                :size="68"
                show-caption
            />

            <div class="min-w-0 flex-1">
                <h1 class="truncate text-base font-semibold tracking-tight">
                    {{ displayName }}
                </h1>
                <p
                    v-if="identityMeta"
                    class="mt-0.5 truncate text-xs text-muted-foreground"
                >
                    {{ identityMeta }}
                </p>
                <p class="mt-0.5 truncate text-xs text-muted-foreground/80">
                    {{ displaySite }}
                </p>
                <Button
                    type="button"
                    label="Scanner"
                    icon="pi pi-camera"
                    size="small"
                    class="mt-2.5 !h-8 !px-3 !text-xs"
                    @click="scannerVisible = true"
                />
            </div>
        </div>

        <div class="hidden items-center gap-6 sm:flex">
            <div class="flex items-center gap-4">
                <div
                    class="flex h-16 w-16 flex-shrink-0 items-center justify-center rounded-full bg-primary text-xl font-semibold text-primary-foreground"
                >
                    {{ initials }}
                </div>
                <div class="flex flex-col items-start">
                    <h1 class="text-2xl font-semibold tracking-tight">
                        {{ displayName }}
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ displaySite }}
                    </p>
                </div>
            </div>

            <div class="ml-auto flex items-start gap-2">
                <Button
                    type="button"
                    v-tooltip.bottom="'Telecharger'"
                    icon="pi pi-download"
                    outlined
                    rounded
                    class="!h-10 !w-10"
                />
                <Button
                    type="button"
                    v-tooltip.bottom="'Envoyer rapport'"
                    icon="pi pi-send"
                    rounded
                    class="!h-10 !w-10"
                />
                <SelecteurPeriode
                    v-if="periode"
                    :url="dashboard().url"
                    :periode="periode"
                />
            </div>
        </div>
    </div>

    <ScannerModal v-model:visible="scannerVisible" />
</template>
