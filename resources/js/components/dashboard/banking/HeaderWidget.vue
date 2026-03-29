<script setup lang="ts">
import Button from 'primevue/button';
import Select from 'primevue/select';
import Tooltip from 'primevue/tooltip';
import ArrowDownIcon from '@primevue/icons/arrowdown';
import ArrowUpIcon from '@primevue/icons/arrowup';
import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const vTooltip = Tooltip;
const page = usePage();
const selectedPeriod = ref('Aujourd\'hui');
const periodOptions = ['Aujourd\'hui', 'Cette semaine', 'Ce mois'];

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
        <div class="flex flex-col sm:flex-row items-center gap-6">
            <div class="flex flex-col sm:flex-row items-center gap-4">
                <div
                    class="w-16 h-16 flex-shrink-0 rounded-full bg-primary text-primary-foreground flex items-center justify-center text-xl font-semibold"
                >
                    {{ initials }}
                </div>
                <div class="flex flex-col items-center sm:items-start">
                    <h1 class="text-2xl font-semibold tracking-tight">
                        {{ displayName }}
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Role : {{ displayRole }}
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-2 sm:ml-auto">
                <Button
                    type="button"
                    v-tooltip.bottom="'Download'"
                    class="!h-10 !w-10 !rounded-full !border !border-primary/35 !bg-primary/10 !p-0 !text-primary hover:!bg-primary/15"
                >
                    <template #icon>
                        <ArrowDownIcon class="h-4 w-4" />
                    </template>
                </Button>
                <Button
                    type="button"
                    v-tooltip.bottom="'Send'"
                    class="!h-10 !w-10 !rounded-full !border !border-primary/35 !bg-primary/10 !p-0 !text-primary hover:!bg-primary/15"
                >
                    <template #icon>
                        <ArrowUpIcon class="h-4 w-4" />
                    </template>
                </Button>
                <Select
                    v-model="selectedPeriod"
                    :options="periodOptions"
                    class="min-w-56"
                >
                </Select>
            </div>
        </div>
    </div>
</template>
