import { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from 'lucide-vue-next';

// ── RBAC ─────────────────────────────────────────────────────────────────────
export type Resource =
    | 'clients'
    | 'prestataires'
    | 'livreurs'
    | 'proprietaires'
    | 'produits'
    | 'packings'
    | 'users'
    | 'parametres';
export type CrudAction = 'create' | 'read' | 'update' | 'delete';
export type PermissionKey = `${Resource}.${CrudAction}`;
export type PermissionsMap = Partial<Record<PermissionKey, boolean>>;
export type AppRole = 'super_admin' | 'admin_entreprise' | 'commerciale' | 'comptable';

export interface Auth {
    user: User;
    permissions: PermissionsMap;
    roles: AppRole[];
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
    items?: NavItem[];
}

export type AppPageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    sidebarOpen: boolean;
};

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export type BreadcrumbItemType = BreadcrumbItem;
