<script setup lang="ts">
import Button from 'primevue/button';
import Select from 'primevue/select';
import Tooltip from 'primevue/tooltip';
import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const vTooltip = Tooltip;
const page = usePage();
const selectedPeriod = ref("Aujourd'hui");
const periodOptions = ["Aujourd'hui", 'Cette semaine', 'Ce mois'];

const ROLE_LABELS: Record<string, string> = {
    super_admin: 'Super Admin',
    admin_entreprise: 'Admin entreprise',
    manager: 'Manager',
    commerciale: 'Commerciale',
    comptable: 'Comptable',
    client: 'Client',
};

const user = computed(() => page.props.auth.user);
const roles = computed(() => (page.props.auth.roles as string[]) ?? []);

const displayName = computed(() => {
    const firstName = user.value?.prenom?.trim();
    const lastName = user.value?.nom?.trim();
    const fullName = [firstName, lastName].filter(Boolean).join(' ');

    return fullName || user.value?.name?.trim() || 'Utilisateur';
});

const displayRole = computed(() => {
    const firstRole = roles.value[0];
    return firstRole ? (ROLE_LABELS[firstRole] ?? firstRole) : 'Aucun role';
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
                    <h1 class="text-xl font-semibold tracking-tight sm:text-2xl">
                        {{ displayName }}
                    </h1>
                    <p class="mt-0.5 text-xs text-muted-foreground sm:mt-1 sm:text-sm">
                        Role : {{ displayRole }}
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
                    class="min-w-40 text-xs sm:min-w-56 sm:text-sm"
                />
            </div>
        </div>
    </div>
</template>
