import { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from 'lucide-vue-next';

// ── RBAC ─────────────────────────────────────────────────────────────────────
export type Resource =
    | 'clients'
    | 'prestataires'
    | 'livreurs'
    | 'proprietaires'
    | 'vehicules'
    | 'equipes-livraison'
    | 'sites'
    | 'produits'
    | 'packings'
    | 'ventes'
    | 'achats'
    | 'users'
    | 'parametres';
export type CrudAction = 'create' | 'read' | 'update' | 'delete';
export type PermissionKey = `${Resource}.${CrudAction}`;
export type PermissionsMap = Partial<Record<PermissionKey, boolean>>;
export type AppRole =
    | 'super_admin'
    | 'admin_entreprise'
    | 'manager'
    | 'commerciale'
    | 'comptable'
    | 'client';

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
    badge?: number;
    items?: NavItem[];
}

export type ModuleFlagKey =
    | 'ventes'
    | 'achats'
    | 'packings'
    | 'prestataires'
    | 'vehicules'
    | 'produits'
    | 'sites'
    | 'utilisateurs'
    | 'inscription'
    | 'cashback';

export type AppPageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    name: string;
    appVersion: string;
    appVersionLabel: string;
    quote: { message: string; author: string };
    auth: Auth;
    sidebarOpen: boolean;
    stock_alertes: { ruptures: number; faibles: number; total: number };
    module_flags: Partial<Record<ModuleFlagKey, boolean>>;
};

export interface Organization {
    id: number;
    name: string;
    slug: string;
}

export interface User {
    id: number;
    prenom: string;
    nom: string;
    name: string;
    email: string | null;
    telephone: string | null;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    organization: Organization | null;
}

export type BreadcrumbItemType = BreadcrumbItem;
