import { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from 'lucide-vue-next';
import type { SeoDefaults } from './seo';
import type { ThemeSharedProps } from './theme';

// ── RBAC ─────────────────────────────────────────────────────────────────────
export type Resource =
    | 'clients'
    | 'prestataires'
    | 'livreurs'
    | 'proprietaires'
    | 'vehicules'
    | 'type-vehicules'
    | 'equipes-livraison'
    | 'sites'
    | 'produits'
    | 'categories'
    | 'options'
    | 'type-produits'
    | 'packings'
    | 'ventes'
    | 'achats'
    | 'fournisseurs'
    | 'depenses'
    | 'users'
    | 'parametres'
    | 'logistique'
    | 'comptabilite'
    | 'rh-employes'
    | 'rh-contrats'
    | 'rh-paie'
    | 'propositions';
export type CrudAction = 'create' | 'read' | 'update' | 'delete';
export type StandalonePermission =
    | 'logistique.commission.verser'
    | 'ventes.qte.update'
    | 'ventes.prix.update'
    | 'imports-flotte.create'
    | 'imports-flotte.read'
    | 'imports-produits.create'
    | 'imports-produits.read'
    | 'imports-vehicules-maj.create'
    | 'imports-vehicules-maj.read';
export type PermissionKey = `${Resource}.${CrudAction}` | StandalonePermission;
export type PermissionsMap = Partial<Record<PermissionKey, boolean>>;
/**
 * Nom technique d'un rôle — chaîne libre, pas une union fermée : depuis la refonte
 * rôles/permissions du 2026-09-06, une organisation peut créer des rôles personnalisés
 * (cf. /backoffice/roles), donc `roles`/`role_labels` (Auth ci-dessous) ne se limitent plus aux
 * 8 rôles historiques. Utiliser `roleLabel()` (composables/usePermissions.ts) pour son libellé
 * humain plutôt qu'un dictionnaire local — cf. Auth.role_labels.
 */
export type AppRole = string;

export interface AuthSite {
    id: number;
    nom: string;
    type: string;
    type_label: string;
    /** "{Type} de {Nom}" calculé côté serveur (Site::getLabelAttribute()) — à afficher tel quel. */
    label: string;
}

export interface Auth {
    user: User;
    permissions: PermissionsMap;
    roles: AppRole[];
    /**
     * Libellé humain de chaque rôle visible par l'organisation courante, par nom technique
     * (cf. HandleInertiaRequests::roleLabels()) — source unique remplaçant les dictionnaires
     * ROLE_LABELS locaux ; utiliser `roleLabel()` (usePermissions.ts) plutôt que d'y accéder
     * directement, pour bénéficier du fallback sur le nom technique.
     */
    role_labels: Record<string, string>;
    default_site: AuthSite | null;
}

export interface BreadcrumbItem {
    title: string;
    href?: string;
}

export interface NavItem {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
    badge?: number;
    items?: NavItem[];
    /** Groupe fonctionnel affiché comme label de section dans la sidebar (top-level uniquement). */
    group?: string;
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
    | 'cashback'
    | 'logistique';

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
    transferts_a_receptionner: number;
    module_flags: Partial<Record<ModuleFlagKey, boolean>>;
    seoDefaults: SeoDefaults;
    theme: ThemeSharedProps;
};

export interface Organization {
    id: number;
    name: string;
    slug: string;
    logo_url: string | null;
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
