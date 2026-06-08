<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import {
    CheckCircle,
    CircleOff,
    MoreVertical,
    Search,
    ShieldCheck,
    Users,
} from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed, ref, watch } from 'vue';

interface Account {
    id: string;
    nom_complet: string;
    email: string | null;
    telephone: string | null;
    is_active: boolean;
    email_verified: boolean;
    type: 'agent' | 'client' | 'inscrit';
    created_at: string | null;
}

const props = defineProps<{ accounts: Account[] }>();

const confirm = useConfirm();
const toast = useToast();

const TYPE_LABELS: Record<string, string> = {
    agent: 'Agent',
    client: 'Client',
    inscrit: 'Inscrit',
};

const TYPE_COLORS: Record<string, string> = {
    agent: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
    client: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
    inscrit:
        'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
};

function initials(name: string) {
    return name
        .trim()
        .split(/\s+/)
        .map((w) => w[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();
}

const AVATAR_COLORS: Record<string, string> = {
    agent: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
    client: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
    inscrit:
        'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
};

// Filtres
const search = ref('');
const statusFilter = ref('tous');
const typeFilter = ref('tous');
const filters = ref({ global: { value: '', matchMode: 'contains' } });

watch(search, (val) => {
    filters.value.global.value = val;
});

const filteredAccounts = computed(() => {
    return props.accounts.filter((a) => {
        const matchStatus =
            statusFilter.value === 'tous' ||
            (statusFilter.value === 'actif' && a.is_active) ||
            (statusFilter.value === 'inactif' && !a.is_active);

        const matchType =
            typeFilter.value === 'tous' || a.type === typeFilter.value;

        return matchStatus && matchType;
    });
});

// Stats
const total = computed(() => props.accounts.length);
const actifs = computed(() => props.accounts.filter((a) => a.is_active).length);
const inactifs = computed(
    () => props.accounts.filter((a) => !a.is_active).length,
);
const inscrits = computed(
    () => props.accounts.filter((a) => a.type === 'inscrit').length,
);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Comptes', href: '/comptes' },
];

function confirmToggle(a: Account) {
    const action = a.is_active ? 'bloquer' : 'débloquer';
    confirm.require({
        message: `Voulez-vous ${action} le compte de ${a.nom_complet} ?`,
        header: a.is_active ? 'Bloquer le compte' : 'Débloquer le compte',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Annuler',
        acceptLabel: a.is_active ? 'Bloquer' : 'Débloquer',
        acceptClass: a.is_active ? 'p-button-danger' : 'p-button-success',
        accept: () => {
            router.patch(
                `/comptes/${a.id}/toggle-active`,
                {},
                {
                    onSuccess: () =>
                        toast.add({
                            severity: a.is_active ? 'warn' : 'success',
                            summary: a.is_active
                                ? 'Compte bloqué'
                                : 'Compte débloqué',
                            detail: `${a.nom_complet} a été ${a.is_active ? 'bloqué' : 'débloqué'}.`,
                            life: 3000,
                        }),
                },
            );
        },
    });
}
</script>

<template>
    <Head>
        <title>Comptes</title>
    </Head>
    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <div class="flex flex-col gap-6 p-6">
            <!-- En-tête -->
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Comptes</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Tous les comptes de la plateforme
                </p>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div class="rounded-xl border bg-card p-5">
                    <p class="text-sm text-muted-foreground">Total</p>
                    <p class="mt-1 text-3xl font-bold">{{ total }}</p>
                </div>
                <div class="rounded-xl border bg-card p-5">
                    <p class="text-sm text-muted-foreground">Actifs</p>
                    <p class="mt-1 text-3xl font-bold text-emerald-500">
                        {{ actifs }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5">
                    <p class="text-sm text-muted-foreground">Bloqués</p>
                    <p class="mt-1 text-3xl font-bold text-red-500">
                        {{ inactifs }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5">
                    <p class="text-sm text-muted-foreground">En attente</p>
                    <p class="mt-1 text-3xl font-bold text-amber-500">
                        {{ inscrits }}
                    </p>
                </div>
            </div>

            <!-- Tableau -->
            <div class="overflow-hidden rounded-xl border bg-card">
                <DataTable
                    :value="filteredAccounts"
                    :paginator="filteredAccounts.length > 25"
                    :rows="25"
                    :global-filter-fields="[
                        'nom_complet',
                        'email',
                        'telephone',
                    ]"
                    v-model:filters="filters"
                    data-key="id"
                    striped-rows
                    removable-sort
                    class="text-sm"
                    table-class="w-full"
                >
                    <template #header>
                        <div
                            class="flex flex-wrap items-center gap-3 border-b border-border bg-muted/30 px-4 py-3"
                        >
                            <IconField class="max-w-sm flex-1">
                                <InputIcon class="pointer-events-none">
                                    <Search
                                        class="h-4 w-4 text-muted-foreground"
                                    />
                                </InputIcon>
                                <InputText
                                    v-model="search"
                                    placeholder="Rechercher un compte..."
                                    class="w-full text-sm"
                                />
                            </IconField>
                            <Select
                                v-model="typeFilter"
                                :options="[
                                    { value: 'tous', label: 'Tous les types' },
                                    { value: 'agent', label: 'Agents' },
                                    { value: 'client', label: 'Clients' },
                                    { value: 'inscrit', label: 'Inscrits' },
                                ]"
                                option-label="label"
                                option-value="value"
                                class="w-40"
                            />
                            <Select
                                v-model="statusFilter"
                                :options="[
                                    { value: 'tous', label: 'Tous statuts' },
                                    { value: 'actif', label: 'Actif' },
                                    { value: 'inactif', label: 'Bloqué' },
                                ]"
                                option-label="label"
                                option-value="value"
                                class="w-36"
                            />
                        </div>
                    </template>

                    <!-- Utilisateur -->
                    <Column
                        field="nom_complet"
                        header="Compte"
                        sortable
                        style="min-width: 220px"
                    >
                        <template #body="{ data }">
                            <div class="flex items-center gap-3">
                                <div
                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-semibold"
                                    :class="AVATAR_COLORS[data.type]"
                                >
                                    {{ initials(data.nom_complet) }}
                                </div>
                                <div>
                                    <div class="font-medium">
                                        {{ data.nom_complet }}
                                    </div>
                                    <div class="text-xs text-muted-foreground">
                                        {{ data.email ?? data.telephone }}
                                    </div>
                                </div>
                            </div>
                        </template>
                    </Column>

                    <!-- Téléphone -->
                    <Column
                        field="telephone"
                        header="Téléphone"
                        style="width: 160px"
                    >
                        <template #body="{ data }">
                            <span class="text-sm text-muted-foreground">{{
                                data.telephone ?? '—'
                            }}</span>
                        </template>
                    </Column>

                    <!-- Type -->
                    <Column
                        field="type"
                        header="Type"
                        sortable
                        style="width: 130px"
                    >
                        <template #body="{ data }">
                            <span
                                class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="TYPE_COLORS[data.type]"
                            >
                                {{ TYPE_LABELS[data.type] }}
                            </span>
                        </template>
                    </Column>

                    <!-- Email vérifié -->
                    <Column
                        field="email_verified"
                        header="Email"
                        style="width: 130px"
                    >
                        <template #body="{ data }">
                            <span
                                v-if="data.email"
                                class="inline-flex items-center gap-1 text-xs"
                                :class="
                                    data.email_verified
                                        ? 'text-emerald-600 dark:text-emerald-400'
                                        : 'text-muted-foreground'
                                "
                            >
                                <CheckCircle
                                    v-if="data.email_verified"
                                    class="h-3.5 w-3.5"
                                />
                                {{
                                    data.email_verified
                                        ? 'Vérifié'
                                        : 'Non vérifié'
                                }}
                            </span>
                            <span v-else class="text-xs text-muted-foreground"
                                >—</span
                            >
                        </template>
                    </Column>

                    <!-- Inscrit le -->
                    <Column
                        field="created_at"
                        header="Inscrit le"
                        sortable
                        style="width: 130px"
                    >
                        <template #body="{ data }">
                            <span class="text-xs text-muted-foreground">{{
                                data.created_at ?? '—'
                            }}</span>
                        </template>
                    </Column>

                    <!-- Statut -->
                    <Column
                        field="is_active"
                        header="Statut"
                        sortable
                        style="width: 110px"
                    >
                        <template #body="{ data }">
                            <StatusDot
                                :label="data.is_active ? 'Actif' : 'Bloqué'"
                                :dot-class="
                                    data.is_active
                                        ? 'bg-emerald-500'
                                        : 'bg-red-500'
                                "
                                class="text-muted-foreground"
                            />
                        </template>
                    </Column>

                    <!-- Actions -->
                    <Column header="" style="width: 56px">
                        <template #body="{ data }">
                            <div class="flex justify-end">
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="h-8 w-8"
                                        >
                                            <MoreVertical class="h-4 w-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent
                                        align="end"
                                        class="w-44"
                                    >
                                        <DropdownMenuItem
                                            class="cursor-pointer"
                                            :class="
                                                data.is_active
                                                    ? 'text-destructive focus:text-destructive'
                                                    : 'text-emerald-600 focus:text-emerald-600'
                                            "
                                            @click="confirmToggle(data)"
                                        >
                                            <CircleOff
                                                v-if="data.is_active"
                                                class="h-4 w-4"
                                            />
                                            <ShieldCheck
                                                v-else
                                                class="h-4 w-4"
                                            />
                                            {{
                                                data.is_active
                                                    ? 'Bloquer'
                                                    : 'Débloquer'
                                            }}
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </template>
                    </Column>

                    <template #empty>
                        <div
                            class="flex flex-col items-center gap-3 py-16 text-muted-foreground"
                        >
                            <Users class="h-12 w-12 opacity-30" />
                            <p class="text-sm">Aucun compte trouvé.</p>
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>
