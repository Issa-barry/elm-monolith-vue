<script setup lang="ts">
defineProps<{ wide?: boolean }>();

import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { usePermissions } from '@/composables/usePermissions';
import { toUrl, urlIsActive } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editOrganisation } from '@/routes/organisation';
import { edit as editParametres } from '@/routes/parametres';
import { edit as editProfile } from '@/routes/profile';
import { show } from '@/routes/two-factor';
import { edit as editPassword } from '@/routes/user-password';
import { type NavItem } from '@/types';
import { Link, router, usePage } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import Select from 'primevue/select';
import { computed } from 'vue';

const page = usePage();
const { can, hasRole } = usePermissions();

/**
 * Téléphone (< 640 px, point de rupture `sm` de ce menu) : la gestion des rôles et permissions
 * est une interface d'administration réservée à la tablette et à l'ordinateur. Choix
 * d'ergonomie uniquement : le lien est masqué, les droits et la route serveur sont inchangés.
 */
const estTelephone = useMediaQuery('(max-width: 639px)');

const isAdmin = computed(
    () => hasRole('super_admin') || hasRole('admin_entreprise'),
);

interface SidebarNavGroup {
    title: string;
    items: NavItem[];
}

const sidebarNavGroups = computed((): SidebarNavGroup[] => {
    const groups: SidebarNavGroup[] = [
        {
            title: 'Mon compte',
            items: [
                { title: 'Profil', href: editProfile() },
                { title: 'Mot de passe', href: editPassword() },
                { title: 'Double authentification', href: show() },
                { title: 'Apparence', href: editAppearance() },
            ],
        },
    ];

    if (isAdmin.value) {
        groups.push({
            title: 'Organisation',
            items: [
                {
                    title: "Informations de l'organisation",
                    href: editOrganisation(),
                },
                ...(estTelephone.value
                    ? []
                    : [
                          {
                              title: 'Rôles et permissions',
                              href: '/backoffice/roles',
                          },
                      ]),
            ],
        });
    }

    // "Gestion" agrège plusieurs permissions indépendantes — Communications
    // (communications.manage) est une permission DÉDIÉE, distincte de
    // parametres.update (cf. rapport notifications de commande, 07/09/2026) :
    // un compte qui n'a que communications.manage doit quand même voir ce lien,
    // même sans parametres.update.
    const gestionItems: NavItem[] = [];
    if (can('parametres.update')) {
        gestionItems.push(
            { title: 'Produits', href: '/settings/produits' },
            { title: 'Validation des dépenses', href: '/settings/depenses' },
            ...(((page.props as any).module_flags?.achats as
                | boolean
                | undefined) !== false
                ? [{ title: 'Achats', href: '/settings/achats' }]
                : []),
            { title: 'Ventes', href: '/settings/ventes' },
            { title: 'Logistique', href: '/settings/logistique' },
            { title: 'Commissions', href: '/settings/commissions' },
            { title: 'Applications', href: '/settings/modules' },
            { title: 'Tableaux de bord', href: '/settings/tableaux-de-bord' },
        );
    }
    if (can('commissions.configurer_agences_payeuses')) {
        gestionItems.push({
            title: 'Agences payeuses',
            href: '/settings/commissions/agences-payeuses',
        });
    }
    if (can('communications.manage')) {
        gestionItems.push({
            title: 'Communications',
            href: '/settings/communications',
        });
    }
    if (gestionItems.length > 0) {
        groups.push({ title: 'Gestion', items: gestionItems });
    }

    // Référentiels RH (permission dédiée), seulement si le module RH est actif.
    const moduleRhActif =
        ((page.props as any).module_flags?.rh as boolean | undefined) !== false;
    const rhItems: NavItem[] = [];
    if (moduleRhActif && can('rh-referentiels.gerer')) {
        rhItems.push(
            { title: 'Fonctions RH', href: '/backoffice/fonctions-rh' },
            { title: 'Services RH', href: '/backoffice/services-rh' },
            {
                title: 'Motifs d’absence',
                href: '/backoffice/motifs-absence',
            },
            {
                title: 'Modèles de contrat',
                href: '/backoffice/modeles-contrat',
            },
        );
    }
    if (moduleRhActif && can('rh-types-remuneration.read')) {
        rhItems.push({
            title: 'Primes et avantages',
            href: '/backoffice/types-remuneration',
        });
    }
    if (rhItems.length > 0) {
        groups.push({ title: 'RH', items: rhItems });
    }

    if (can('parametres.update')) {
        groups.push({
            title: 'Administration',
            items: [
                { title: 'Imports et modèles', href: editParametres().url },
            ],
        });
    }

    return groups;
});

const currentPath = typeof window !== undefined ? window.location.pathname : '';

/** Chemin d'un lien, qu'il soit relatif ('/settings/produits') ou absolu (routes générées). */
const cheminDe = (href: NavItem['href']): string =>
    new URL(toUrl(href) ?? '', 'http://x').pathname;

// Téléphone : liste déroulante groupée à la place des onglets horizontaux, peu lisibles.
const sectionsMobile = computed(() =>
    sidebarNavGroups.value.map((group) => ({
        label: group.title,
        items: group.items.map((item) => ({
            label: item.title,
            value: toUrl(item.href) ?? '',
            chemin: cheminDe(item.href),
        })),
    })),
);

const sectionActive = computed(
    () =>
        sectionsMobile.value
            .flatMap((group) => group.items)
            .find((item) => item.chemin === currentPath)?.value ?? null,
);

function ouvrirSection(url: string | null) {
    if (url) router.visit(url);
}
</script>

<template>
    <div class="p-4 sm:p-6">
        <Heading
            title="Parametres"
            description="Gerez votre profil et les parametres de votre compte"
        />

        <div class="flex flex-col lg:flex-row lg:space-x-12">
            <aside class="w-full lg:w-48">
                <!-- Téléphone : choix de la section dans une liste groupée -->
                <div class="sm:hidden" data-testid="parametres-sections-mobile">
                    <label
                        for="parametres-section"
                        class="mb-1.5 block text-xs font-medium text-muted-foreground"
                        >Section</label
                    >
                    <Select
                        input-id="parametres-section"
                        :model-value="sectionActive"
                        :options="sectionsMobile"
                        option-label="label"
                        option-value="value"
                        option-group-label="label"
                        option-group-children="items"
                        placeholder="Choisir une section"
                        class="w-full"
                        @update:model-value="ouvrirSection"
                    />
                </div>

                <!-- Tablette et ordinateur : navigation verticale inchangée -->
                <nav class="hidden gap-1 sm:flex sm:flex-col sm:space-y-1">
                    <div
                        v-for="group in sidebarNavGroups"
                        :key="group.title"
                        class="flex shrink-0 gap-1 sm:mt-3 sm:flex-col sm:gap-0.5"
                    >
                        <p
                            class="hidden px-2 pb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase sm:block"
                        >
                            {{ group.title }}
                        </p>
                        <Button
                            v-for="item in group.items"
                            :key="toUrl(item.href)"
                            variant="ghost"
                            :class="[
                                'shrink-0 justify-start sm:w-full',
                                {
                                    'bg-muted': urlIsActive(
                                        item.href,
                                        currentPath,
                                    ),
                                },
                            ]"
                            as-child
                        >
                            <Link :href="item.href">
                                <component :is="item.icon" class="h-4 w-4" />
                                {{ item.title }}
                            </Link>
                        </Button>
                    </div>
                </nav>
            </aside>

            <Separator class="my-4 lg:hidden" />

            <div :class="['min-w-0 flex-1', !wide && 'md:max-w-2xl']">
                <section
                    :class="[
                        !wide && 'max-w-xl',
                        'max-w-full min-w-0 space-y-12',
                    ]"
                >
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
