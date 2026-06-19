<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPhoneDisplay } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    Building2,
    CheckCircle,
    Clock,
    MoreVertical,
    Pencil,
    Plus,
    Shield,
    Trash2,
    UserCircle,
} from 'lucide-vue-next';
import DataTableFilters from '@/components/DataTableFilters.vue';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Select from 'primevue/select';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed, ref } from 'vue';

interface StaffUser {
    id: number;
    nom: string;
    prenom: string;
    nom_complet: string;
    email: string | null;
    telephone: string | null;
    code_phone_pays: string | null;
    matricule: string | null;
    is_active: boolean;
    roles: string[];
    site: string | null;
    is_me: boolean;
}

interface PendingUser {
    id: number;
    nom: string;
    prenom: string;
    nom_complet: string;
    email: string | null;
    telephone: string | null;
    email_verified: boolean;
    created_at: string | null;
}

const props = defineProps<{
    users: StaffUser[];
    pending_registrations: PendingUser[];
}>();

const { can } = usePermissions();
const confirm = useConfirm();
const toast = useToast();
const page = usePage();

const isSuperAdmin = computed(() =>
    (page.props as any).auth?.roles?.includes('super_admin'),
);

const ROLE_LABELS: Record<string, string> = {
    super_admin: 'Super administrateur',
    admin_entreprise: 'Administrateur',
    manager: 'Manager',
    commerciale: 'Commercial(e)',
    comptable: 'Comptable',
};

const ROLE_COLORS: Record<string, string> = {
    super_admin:
        'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400',
    admin_entreprise:
        'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
    manager:
        'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
    commerciale:
        'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
    comptable:
        'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
};

function roleLabel(role: string) {
    return ROLE_LABELS[role] ?? role;
}

function roleColor(role: string) {
    return ROLE_COLORS[role] ?? 'bg-muted text-muted-foreground';
}

function initials(name: string) {
    return name
        .trim()
        .split(/\s+/)
        .map((w) => w[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();
}

const totalUsers = computed(() => props.users.length);
const activeUsers = computed(
    () => props.users.filter((u) => u.is_active).length,
);
const inactiveUsers = computed(
    () => props.users.filter((u) => !u.is_active).length,
);

const pendingSearch = ref('');
const pendingStatut = ref<string>('tous');
const activeSearch = ref('');
const activeStatut = ref<string>('tous');

function applyFilters() {
    activeSearch.value = pendingSearch.value;
    activeStatut.value = pendingStatut.value;
}

function resetFilters() {
    pendingSearch.value = '';
    pendingStatut.value = 'tous';
    activeSearch.value = '';
    activeStatut.value = 'tous';
}

const hasActiveFilters = computed(
    () => !!activeSearch.value || activeStatut.value !== 'tous',
);

const filteredUsers = computed(() => {
    let list = props.users;
    if (activeStatut.value !== 'tous') {
        list = list.filter((u) => u.is_active === (activeStatut.value === 'actif'));
    }
    const q = activeSearch.value.toLowerCase().trim();
    if (q) {
        list = list.filter(
            (u) =>
                u.nom_complet.toLowerCase().includes(q) ||
                (u.email ?? '').toLowerCase().includes(q) ||
                (u.site ?? '').toLowerCase().includes(q) ||
                (u.matricule ?? '').toLowerCase().includes(q),
        );
    }
    return list;
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Utilisateurs', href: '/users' },
];

function canActOn(u: StaffUser): boolean {
    if (u.roles.includes('super_admin') && !isSuperAdmin.value) return false;
    return true;
}

function confirmDelete(u: StaffUser) {
    confirm.require({
        message: `Supprimer le compte de ${u.nom_complet} ? Cette action est irréversible.`,
        header: 'Confirmer la suppression',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Annuler',
        acceptLabel: 'Supprimer',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(`/users/${u.id}`, {
                onSuccess: () =>
                    toast.add({
                        severity: 'success',
                        summary: 'Supprimé',
                        detail: `${u.nom_complet} a été supprimé.`,
                        life: 3000,
                    }),
            });
        },
    });
}
</script>

<template>
    <Head>
        <title>Utilisateurs</title>
    </Head>
    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <div class="flex flex-col gap-6 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Utilisateurs
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ filteredUsers.length }} compte{{
                            filteredUsers.length !== 1 ? 's' : ''
                        }}
                        staff
                    </p>
                </div>
                <Link v-if="isSuperAdmin" href="/users/create">
                    <Button>
                        <Plus class="mr-2 h-4 w-4" />
                        Nouveau compte
                    </Button>
                </Link>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-3 gap-4">
                <div class="rounded-xl border bg-card p-5">
                    <p class="text-sm text-muted-foreground">
                        Total utilisateurs
                    </p>
                    <p class="mt-1 text-3xl font-bold">{{ totalUsers }}</p>
                </div>
                <div class="rounded-xl border bg-card p-5">
                    <p class="text-sm text-muted-foreground">
                        Utilisateurs actifs
                    </p>
                    <p class="mt-1 text-3xl font-bold text-emerald-500">
                        {{ activeUsers }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5">
                    <p class="text-sm text-muted-foreground">
                        Utilisateurs inactifs
                    </p>
                    <p class="mt-1 text-3xl font-bold text-zinc-400">
                        {{ inactiveUsers }}
                    </p>
                </div>
            </div>

            <DataTableFilters
                v-model:search="pendingSearch"
                search-placeholder="Rechercher un utilisateur…"
                :has-active-filters="hasActiveFilters"
                @filter="applyFilters"
                @reset="resetFilters"
            >
                <Select
                    v-model="pendingStatut"
                    :options="[
                        { value: 'tous', label: 'Tous' },
                        { value: 'actif', label: 'Actif' },
                        { value: 'inactif', label: 'Inactif' },
                    ]"
                    option-label="label"
                    option-value="value"
                    class="w-32"
                />
            </DataTableFilters>

            <div
                class="overflow-hidden rounded-xl border bg-card"
                data-testid="staff-users-table"
            >
                <DataTable
                    :value="filteredUsers"
                    :paginator="props.users.length > 20"
                    :rows="20"
                    data-key="id"
                    striped-rows
                    removable-sort
                    class="text-sm"
                    table-class="w-full"
                >

                    <!-- Avatar + nom -->
                    <Column
                        field="nom_complet"
                        header="Utilisateur"
                        sortable
                        style="min-width: 200px"
                    >
                        <template #body="{ data }">
                            <div class="flex items-center gap-3">
                                <div
                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary"
                                >
                                    {{ initials(data.nom_complet) }}
                                </div>
                                <div>
                                    <div
                                        class="flex items-center gap-1.5 font-medium"
                                    >
                                        {{ data.nom_complet }}
                                        <span
                                            v-if="data.is_me"
                                            class="rounded bg-muted px-1.5 py-0.5 text-[10px] text-muted-foreground"
                                            >Moi</span
                                        >
                                    </div>
                                    <div class="text-xs text-muted-foreground">
                                        {{ data.email }}
                                    </div>
                                </div>
                            </div>
                        </template>
                    </Column>

                    <!-- Matricule -->
                    <Column
                        field="matricule"
                        header="Matricule"
                        sortable
                        style="width: 120px"
                    >
                        <template #body="{ data }">
                            <span
                                v-if="data.matricule"
                                class="rounded bg-muted px-2 py-0.5 font-mono text-xs text-muted-foreground"
                                >{{ data.matricule }}</span
                            >
                            <span v-else class="text-xs text-muted-foreground"
                                >—</span
                            >
                        </template>
                    </Column>

                    <!-- Téléphone -->
                    <Column
                        field="telephone"
                        header="Téléphone"
                        style="width: 180px"
                    >
                        <template #body="{ data }">
                            <span class="text-sm text-muted-foreground">{{
                                formatPhoneDisplay(
                                    data.telephone,
                                    data.code_phone_pays,
                                )
                            }}</span>
                        </template>
                    </Column>

                    <!-- Rôle -->
                    <Column
                        field="roles"
                        header="Rôle"
                        sortable
                        style="width: 180px"
                    >
                        <template #body="{ data }">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span
                                    v-for="role in data.roles"
                                    :key="role"
                                    class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium"
                                    :class="roleColor(role)"
                                >
                                    <Shield class="h-3 w-3" />
                                    {{ roleLabel(role) }}
                                </span>
                            </div>
                        </template>
                    </Column>

                    <!-- Site -->
                    <Column
                        field="site"
                        header="Site"
                        sortable
                        style="width: 180px"
                    >
                        <template #body="{ data }">
                            <span
                                v-if="data.site"
                                class="inline-flex items-center gap-1.5 text-xs text-muted-foreground"
                            >
                                <Building2 class="h-3.5 w-3.5 shrink-0" />
                                {{ data.site }}
                            </span>
                            <span v-else class="text-xs text-muted-foreground"
                                >—</span
                            >
                        </template>
                    </Column>

                    <!-- Statut -->
                    <Column
                        field="is_active"
                        header="Statut"
                        sortable
                        style="width: 100px"
                    >
                        <template #body="{ data }">
                            <StatusDot
                                :label="data.is_active ? 'Actif' : 'Inactif'"
                                :dot-class="
                                    data.is_active
                                        ? 'bg-emerald-500'
                                        : 'bg-zinc-400 dark:bg-zinc-500'
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
                                            v-if="
                                                can('users.update') &&
                                                canActOn(data)
                                            "
                                            as-child
                                        >
                                            <Link
                                                :href="`/users/${data.id}/edit`"
                                                class="flex w-full items-center gap-2"
                                            >
                                                <Pencil class="h-4 w-4" />
                                                Modifier
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator
                                            v-if="
                                                can('users.update') &&
                                                canActOn(data) &&
                                                isSuperAdmin &&
                                                !data.is_me
                                            "
                                        />
                                        <DropdownMenuItem
                                            v-if="
                                                isSuperAdmin &&
                                                canActOn(data) &&
                                                !data.is_me
                                            "
                                            class="cursor-pointer text-destructive focus:text-destructive"
                                            @click="confirmDelete(data)"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                            Supprimer
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
                            <UserCircle class="h-12 w-12 opacity-30" />
                            <p class="text-sm">Aucun utilisateur trouvé.</p>
                            <Link v-if="isSuperAdmin" href="/users/create">
                                <Button variant="outline" size="sm">
                                    <Plus class="mr-2 h-4 w-4" />
                                    Créer le premier compte
                                </Button>
                            </Link>
                        </div>
                    </template>
                </DataTable>
            </div>
            <!-- Comptes en attente (super admin seulement) -->
            <div
                v-if="isSuperAdmin && pending_registrations.length > 0"
                class="overflow-hidden rounded-xl border bg-card"
            >
                <div
                    class="border-b border-border bg-amber-50/60 px-4 py-3 dark:bg-amber-900/10"
                >
                    <div class="flex items-center gap-2">
                        <Clock
                            class="h-4 w-4 text-amber-600 dark:text-amber-400"
                        />
                        <h2
                            class="text-sm font-semibold text-amber-700 dark:text-amber-400"
                        >
                            Comptes en attente de validation
                            <span
                                class="ml-1.5 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-400"
                            >
                                {{ pending_registrations.length }}
                            </span>
                        </h2>
                    </div>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        Ces comptes ont été créés via l'inscription et n'ont pas
                        encore été associés à une organisation.
                    </p>
                </div>
                <DataTable
                    :value="pending_registrations"
                    data-key="id"
                    striped-rows
                    class="text-sm"
                    table-class="w-full"
                >
                    <Column
                        field="nom_complet"
                        header="Utilisateur"
                        style="min-width: 200px"
                    >
                        <template #body="{ data }">
                            <div class="flex items-center gap-3">
                                <div
                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-amber-100 text-xs font-semibold text-amber-700 dark:bg-amber-900/30 dark:text-amber-400"
                                >
                                    {{ initials(data.nom_complet) }}
                                </div>
                                <div>
                                    <div class="font-medium">
                                        {{ data.nom_complet }}
                                    </div>
                                    <div class="text-xs text-muted-foreground">
                                        {{ data.email }}
                                    </div>
                                </div>
                            </div>
                        </template>
                    </Column>
                    <Column
                        field="telephone"
                        header="Téléphone"
                        style="width: 180px"
                    >
                        <template #body="{ data }">
                            <span class="text-sm text-muted-foreground">{{
                                data.telephone ?? '—'
                            }}</span>
                        </template>
                    </Column>
                    <Column
                        field="email_verified"
                        header="Email vérifié"
                        style="width: 140px"
                    >
                        <template #body="{ data }">
                            <span
                                v-if="data.email_verified"
                                class="inline-flex items-center gap-1 text-xs text-emerald-600 dark:text-emerald-400"
                            >
                                <CheckCircle class="h-3.5 w-3.5" /> Vérifié
                            </span>
                            <span v-else class="text-xs text-muted-foreground"
                                >Non vérifié</span
                            >
                        </template>
                    </Column>
                    <Column
                        field="created_at"
                        header="Inscrit le"
                        style="width: 150px"
                    >
                        <template #body="{ data }">
                            <span class="text-xs text-muted-foreground">{{
                                data.created_at ?? '—'
                            }}</span>
                        </template>
                    </Column>
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
                                            class="cursor-pointer text-destructive focus:text-destructive"
                                            @click="confirmDelete(data)"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                            Supprimer
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </template>
                    </Column>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>
