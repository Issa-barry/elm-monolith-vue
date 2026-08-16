<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { MoreHorizontal, Plus, ShieldCheck, Trash2 } from 'lucide-vue-next';

interface Role {
    id: number;
    name: string;
    label: string;
    code: string | null;
    is_system: boolean;
    users_count: number;
    permissions_count: number;
    updated_at: string | null;
}

const props = defineProps<{
    roles: Role[];
    totalPerms: number;
}>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Roles & Permissions',
        href: '/backoffice/roles',
    },
];

function roleDotClass(name: string): string {
    if (name === 'super_admin') return 'bg-violet-500';
    if (name === 'admin_entreprise') return 'bg-blue-500';
    if (name === 'commerciale') return 'bg-emerald-500';
    if (name === 'comptable') return 'bg-amber-500';

    return 'bg-slate-400';
}

function formatLastEdit(value: string | null): string {
    if (!value) return '-';

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) return '-';

    return new Intl.DateTimeFormat('fr-FR', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(date);
}

function usersLabel(count: number): string {
    return `${count} ${count > 1 ? 'utilisateurs' : 'utilisateur'}`;
}

function destroyRole(role: Role) {
    if (role.is_system) return;

    if (
        confirm(
            `Supprimer le rôle « ${role.label} » ? Cette action est irréversible.`,
        )
    ) {
        router.delete(`/backoffice/roles/${role.id}`);
    }
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems" :hide-mobile-header="true">
        <Head title="Roles & Permissions" />

        <SettingsLayout>
            <!-- Desktop table -->
            <div class="hidden space-y-6 sm:block">
                <div class="flex items-center justify-between gap-4">
                    <HeadingSmall
                        title="Roles"
                        description="Liste des roles du projet"
                    />
                    <Link href="/backoffice/roles/create">
                        <Button size="sm">
                            <Plus class="mr-2 h-4 w-4" />
                            Nouveau rôle
                        </Button>
                    </Link>
                </div>

                <div class="overflow-hidden rounded-xl border bg-card">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr
                                    class="border-b bg-muted/20 text-xs tracking-wide text-muted-foreground uppercase"
                                >
                                    <th
                                        class="px-6 py-3 text-left font-semibold"
                                    >
                                        Nom du role
                                    </th>
                                    <th
                                        class="px-6 py-3 text-left font-semibold"
                                    >
                                        Derniere modification
                                    </th>
                                    <th
                                        class="px-6 py-3 text-left font-semibold"
                                    >
                                        Utilisateurs
                                    </th>
                                    <th class="w-12 px-4 py-3"></th>
                                </tr>
                            </thead>

                            <tbody class="divide-y">
                                <tr
                                    v-for="role in props.roles"
                                    :key="role.id"
                                    class="hover:bg-muted/20"
                                >
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-3">
                                            <span
                                                class="h-2 w-2 rounded-full"
                                                :class="roleDotClass(role.name)"
                                            />
                                            <div>
                                                <p
                                                    class="flex items-center gap-2 font-medium"
                                                >
                                                    {{ role.label }}
                                                    <span
                                                        v-if="role.code"
                                                        class="rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] tracking-wide text-muted-foreground uppercase"
                                                    >
                                                        {{ role.code }}
                                                    </span>
                                                </p>
                                                <p
                                                    class="text-xs text-muted-foreground"
                                                >
                                                    {{ role.permissions_count }}
                                                    /
                                                    {{ props.totalPerms }}
                                                    permissions
                                                </p>
                                            </div>
                                        </div>
                                    </td>

                                    <td class="px-6 py-4 text-muted-foreground">
                                        {{ formatLastEdit(role.updated_at) }}
                                    </td>

                                    <td class="px-6 py-4 text-muted-foreground">
                                        {{ usersLabel(role.users_count) }}
                                    </td>

                                    <td class="px-4 py-4 text-right">
                                        <DropdownMenu>
                                            <DropdownMenuTrigger as-child>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    class="h-8 w-8"
                                                >
                                                    <MoreHorizontal
                                                        class="h-4 w-4"
                                                    />
                                                </Button>
                                            </DropdownMenuTrigger>

                                            <DropdownMenuContent
                                                align="end"
                                                class="w-56"
                                            >
                                                <DropdownMenuLabel
                                                    >Role
                                                    Management</DropdownMenuLabel
                                                >
                                                <DropdownMenuSeparator />

                                                <DropdownMenuItem
                                                    :as-child="true"
                                                >
                                                    <Link
                                                        :href="`/backoffice/roles/${role.id}/edit`"
                                                        as="button"
                                                        class="block w-full"
                                                    >
                                                        <ShieldCheck
                                                            class="mr-2 h-4 w-4"
                                                        />
                                                        {{
                                                            role.name ===
                                                            'super_admin'
                                                                ? 'View permissions'
                                                                : 'Edit permissions'
                                                        }}
                                                    </Link>
                                                </DropdownMenuItem>

                                                <template
                                                    v-if="!role.is_system"
                                                >
                                                    <DropdownMenuSeparator />
                                                    <DropdownMenuItem
                                                        :disabled="
                                                            role.users_count > 0
                                                        "
                                                        variant="destructive"
                                                        @click="
                                                            destroyRole(role)
                                                        "
                                                    >
                                                        <Trash2
                                                            class="mr-2 h-4 w-4"
                                                        />
                                                        {{
                                                            role.users_count > 0
                                                                ? 'Supprimer (réaffectez les utilisateurs)'
                                                                : 'Supprimer'
                                                        }}
                                                    </DropdownMenuItem>
                                                </template>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Mobile list -->
            <div class="space-y-3 sm:hidden">
                <Link href="/backoffice/roles/create">
                    <Button size="sm" class="w-full">
                        <Plus class="mr-2 h-4 w-4" />
                        Nouveau rôle
                    </Button>
                </Link>
                <div
                    v-for="role in props.roles"
                    :key="role.id"
                    class="flex items-center justify-between rounded-xl border bg-card px-4 py-3"
                >
                    <div class="flex items-center gap-3">
                        <span
                            class="h-2.5 w-2.5 shrink-0 rounded-full"
                            :class="roleDotClass(role.name)"
                        />
                        <div>
                            <p class="text-sm font-medium">
                                {{ role.label }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ usersLabel(role.users_count) }}
                            </p>
                        </div>
                    </div>
                    <Link :href="`/backoffice/roles/${role.id}/edit`">
                        <Button variant="outline" size="sm">
                            <ShieldCheck class="h-4 w-4" />
                        </Button>
                    </Link>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
