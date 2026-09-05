<script setup lang="ts">
import IdentityQrBadge from '@/components/identity/IdentityQrBadge.vue';
import ScannerModal from '@/components/scanner/ScannerModal.vue';
import { formatPhoneDisplay } from '@/lib/utils';
import { router, usePage } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Select from 'primevue/select';
import Tooltip from 'primevue/tooltip';
import { computed, ref, watch } from 'vue';

const vTooltip = Tooltip;
const page = usePage();
const props = defineProps<{ periode?: string; qrPayload?: string | null }>();

const scannerVisible = ref(false);

const periodOptions = [
    { label: "Aujourd'hui", value: 'aujourd_hui' },
    { label: 'Hier', value: 'hier' },
    { label: 'Cette semaine', value: 'cette_semaine' },
    { label: 'Semaine dernière', value: 'semaine_derniere' },
    { label: 'Ce mois', value: 'ce_mois' },
    { label: 'Mois dernier', value: 'mois_dernier' },
    { label: 'T1', value: 't1' },
    { label: 'T2', value: 't2' },
    { label: 'T3', value: 't3' },
    { label: 'T4', value: 't4' },
    { label: 'S1', value: 's1' },
    { label: 'S2', value: 's2' },
    { label: 'Cette année', value: 'cette_annee' },
    { label: 'Tout', value: 'tout' },
];

const selectedPeriod = ref(
    periodOptions.find((p) => p.value === props.periode) ?? periodOptions[4],
);

function changePeriod() {
    router.get(
        '/backoffice/dashboard',
        { periode: selectedPeriod.value.value },
        { preserveState: true, preserveScroll: true },
    );
}

watch(
    () => props.periode,
    (val) => {
        const found = periodOptions.find((p) => p.value === val);
        if (found) selectedPeriod.value = found;
    },
);

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

const roleLabels: Record<string, string> = {
    super_admin: 'Super administrateur',
    admin_entreprise: 'Administrateur',
    manager: 'Manager',
    commerciale: 'Commercial',
    comptable: 'Comptable',
    client: 'Client',
    proprietaire: 'Propriétaire',
    livreur: 'Livreur',
};

const displayRole = computed(() => {
    const role = page.props.auth.roles?.[0];
    return role ? (roleLabels[role] ?? role) : null;
});

const identityMeta = computed(() =>
    [
        displayRole.value,
        user.value?.telephone
            ? formatPhoneDisplay(user.value.telephone)
            : null,
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

            <div class="ml-auto flex items-center gap-2">
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
                <Select
                    v-model="selectedPeriod"
                    :options="periodOptions"
                    option-label="label"
                    class="min-w-56 text-sm"
                    @change="changePeriod"
                />
            </div>
        </div>
    </div>

    <ScannerModal v-model:visible="scannerVisible" />
</template>
