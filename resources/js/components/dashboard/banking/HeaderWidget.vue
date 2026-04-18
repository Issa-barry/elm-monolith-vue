<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Select from 'primevue/select';
import Tooltip from 'primevue/tooltip';
import { computed, ref, watch } from 'vue';

const vTooltip = Tooltip;
const page = usePage();
const props = defineProps<{ periode?: string }>();

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
        '/dashboard',
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

const displaySite = computed(() => {
    if (!defaultSite.value) return 'Aucun site affecte';
    return `${defaultSite.value.type_label} de ${defaultSite.value.nom}`;
});

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
        <div class="flex flex-col items-center gap-3 sm:flex-row sm:gap-6">
            <div class="flex flex-col items-center gap-3 sm:flex-row sm:gap-4">
                <div
                    class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-primary text-base font-semibold text-primary-foreground sm:h-16 sm:w-16 sm:text-xl"
                >
                    {{ initials }}
                </div>
                <div class="flex flex-col items-center sm:items-start">
                    <h1
                        class="text-xl font-semibold tracking-tight sm:text-2xl"
                    >
                        {{ displayName }}
                    </h1>
                    <p
                        class="mt-0.5 text-xs text-muted-foreground sm:mt-1 sm:text-sm"
                    >
                        Site : {{ displaySite }}
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-1.5 sm:ml-auto sm:gap-2">
                <Button
                    type="button"
                    v-tooltip.bottom="'Telecharger'"
                    icon="pi pi-download"
                    outlined
                    rounded
                    class="!h-8 !w-8 sm:!h-10 sm:!w-10"
                />
                <Button
                    type="button"
                    v-tooltip.bottom="'Envoyer rapport'"
                    icon="pi pi-send"
                    rounded
                    class="!h-8 !w-8 sm:!h-10 sm:!w-10"
                />
                <Select
                    v-model="selectedPeriod"
                    :options="periodOptions"
                    option-label="label"
                    class="min-w-40 text-xs sm:min-w-56 sm:text-sm"
                    @change="changePeriod"
                />
            </div>
        </div>
    </div>
</template>
