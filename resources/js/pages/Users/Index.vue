<script setup lang="ts">
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
    MoreVertical,
    Pencil,
    Plus,
    Search,
    Shield,
    Trash2,
    UserCircle,
} from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputText from 'primevue/inputtext';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed, ref, watch } from 'vue';

interface StaffUser {
    id: number;
    nom: string;
    prenom: string;
    nom_complet: string;
    email: string | null;
    telephone: string | null;
    code_phone_pays: string | null;
    is_active: boolean;
    roles: string[];
    is_me: boolean;
}

const props = defineProps<{ users: StaffUser[] }>();

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

const search = ref('');
const filters = ref({ global: { value: '', matchMode: 'contains' } });
watch(search, (val) => {
    filters.value.global.value = val;
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Utilisateurs', href: '/users' },
];

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
                        {{ props.users.length }} compte{{
                            props.users.length !== 1 ? 's' : ''
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

            <div class="overflow-hidden rounded-xl border bg-card">
                <DataTable
                    :value="props.users"
                    :paginator="props.users.length > 20"
                    :rows="20"
                    :global-filter-fields="['nom_complet', 'email']"
                    v-model:filters="filters"
                    data-key="id"
                    striped-rows
                    removable-sort
                    class="text-sm"
                    table-class="w-full"
                >
                    <template #header>
                        <div
                            class="flex items-center gap-3 border-b border-border bg-muted/30 px-4 py-3"
                        >
                            <IconField class="max-w-sm flex-1">
                                <InputIcon class="pointer-events-none">
                                    <Search
                                        class="h-4 w-4 text-muted-foreground"
                                    />
                                </InputIcon>
                                <InputText
                                    v-model="search"
                                    placeholder="Rechercher un utilisateur..."
                                    class="w-full text-sm"
                                />
                            </IconField>
                        </div>
                    </template>

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

                    <!-- Rôle -->
                    <Column header="Rôle" style="width: 220px">
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
                                <span
                                    v-if="!data.is_active"
                                    class="inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-medium text-red-600 dark:bg-red-900/30 dark:text-red-400"
                                >
                                    Inactif
                                </span>
                            </div>
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
                                            v-if="can('users.update')"
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
                                                isSuperAdmin &&
                                                !data.is_me
                                            "
                                        />
                                        <DropdownMenuItem
                                            v-if="isSuperAdmin && !data.is_me"
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
        </div>
    </AppLayout>
</template>
