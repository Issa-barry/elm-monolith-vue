<script setup lang="ts">
import { usePermissions } from '@/composables/usePermissions';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Logistique → Réceptions : distingue l'origine de la réception — transfert entre agences
 * (module Logistique) ou bon de commande fournisseur (module Achats, ADR 0021). Chaque onglet
 * n'apparaît que si son module est actif et sa permission accordée ; rien n'est affiché s'il n'y
 * a qu'une origine possible.
 */
const props = defineProps<{ actif: 'transferts' | 'fournisseurs' }>();

const { can } = usePermissions();
const page = usePage();

const moduleActif = (cle: string): boolean =>
    ((page.props as any).module_flags?.[cle] as boolean | undefined) !== false;

const onglets = computed(() =>
    [
        {
            cle: 'transferts',
            libelle: 'Transferts',
            href: '/backoffice/logistique/receptions',
            visible: moduleActif('logistique') && can('logistique.read'),
        },
        {
            cle: 'fournisseurs',
            libelle: 'Commandes fournisseurs',
            href: '/backoffice/logistique/receptions-fournisseurs',
            visible: moduleActif('achats') && can('receptions.read'),
        },
    ].filter((o) => o.visible || o.cle === props.actif),
);
</script>

<template>
    <nav
        v-if="onglets.length > 1"
        class="flex gap-1 overflow-x-auto border-b"
        aria-label="Origine des réceptions"
    >
        <Link
            v-for="o in onglets"
            :key="o.cle"
            :href="o.href"
            class="-mb-px border-b-2 px-4 py-2 text-sm font-medium whitespace-nowrap transition-colors"
            :class="
                o.cle === actif
                    ? 'border-primary text-foreground'
                    : 'border-transparent text-muted-foreground hover:text-foreground'
            "
            :aria-current="o.cle === actif ? 'page' : undefined"
        >
            {{ o.libelle }}
        </Link>
    </nav>
</template>
