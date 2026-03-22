<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import HeadingSmall from '@/components/HeadingSmall.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { MoreHorizontal, ShieldCheck } from 'lucide-vue-next';

interface Role {
    id: number;
    name: string;
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
        href: '/roles',
    },
];

const roleLabels: Record<string, string> = {
    super_admin: 'Super Admin',
    admin_entreprise: 'Admin Entreprise',
    commerciale: 'Commerciale',
    comptable: 'Comptable',
};

function displayRoleName(name: string): string {
    return roleLabels[name] ?? name;
}

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
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Roles & Permissions" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall
                    title="Roles"
                    description="Liste des roles du projet"
                />

                <div class="overflow-hidden rounded-xl border bg-card">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b bg-muted/20 text-xs uppercase tracking-wide text-muted-foreground">
                                    <th class="px-6 py-3 text-left font-semibold">Nom du role</th>
                                    <th class="px-6 py-3 text-left font-semibold">Derniere modification</th>
                                    <th class="px-6 py-3 text-left font-semibold">Utilisateurs</th>
                                    <th class="w-12 px-4 py-3"></th>
                                </tr>
                            </thead>

                            <tbody class="divide-y">
                                <tr v-for="role in props.roles" :key="role.id" class="hover:bg-muted/20">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-3">
                                            <span class="h-2 w-2 rounded-full" :class="roleDotClass(role.name)" />
                                            <div>
                                                <p class="font-medium">{{ displayRoleName(role.name) }}</p>
                                                <p class="text-xs text-muted-foreground">
                                                    {{ role.permissions_count }} / {{ props.totalPerms }} permissions
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
                                                <Button variant="ghost" size="icon" class="h-8 w-8">
                                                    <MoreHorizontal class="h-4 w-4" />
                                                </Button>
                                            </DropdownMenuTrigger>

                                            <DropdownMenuContent align="end" class="w-56">
                                                <DropdownMenuLabel>Role Management</DropdownMenuLabel>
                                                <DropdownMenuSeparator />

                                                <DropdownMenuItem :as-child="true">
                                                    <Link :href="`/roles/${role.id}/edit`" as="button" class="block w-full">
                                                        <ShieldCheck class="mr-2 h-4 w-4" />
                                                        {{ role.name === 'super_admin' ? 'View permissions' : 'Edit permissions' }}
                                                    </Link>
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
