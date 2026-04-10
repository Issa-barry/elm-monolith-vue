<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { edit } from '@/routes/parametres';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { Download, Settings } from 'lucide-vue-next';
import { computed } from 'vue';

interface Parametre {
    id: number;
    cle: string;
    valeur: string | null;
    valeur_cast: string | number | boolean | null;
    type: 'string' | 'integer' | 'decimal' | 'boolean' | 'json';
    groupe: string;
    description: string | null;
}

const props = defineProps<{ parametres: Parametre[] }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Paramètres', href: '/settings/profile' },
    { title: 'Paramétrage système', href: edit().url },
];

const groupeLabels: Record<string, string> = {
    general: 'Général',
    packing: 'Packing',
    vehicules: 'Véhicules',
    cashback: 'Cashback clients',
};

const cleLabels: Record<string, string> = {
    seuil_stock_faible: 'Seuil de stock faible',
    notifications_stock_actives: 'Alertes de stock faible',
    prix_rouleau_defaut: 'Prix rouleau par défaut (GNF)',
    produit_rouleau_id: 'ID produit rouleau',
    taux_proprietaire_defaut: 'Taux propriétaire par défaut (%)',
    cashback_seuil_achat: "Seuil d'achat pour cashback (GNF)",
    cashback_montant_gain: 'Montant du cashback versé (GNF)',
};

const importTemplates = [
    {
        key: 'produits',
        title: 'Template Produits',
        description: 'Champs de création des produits.',
    },
    {
        key: 'sites',
        title: 'Template Sites',
        description: 'Champs de création des sites.',
    },
    {
        key: 'users',
        title: 'Template Utilisateurs',
        description: 'Champs de création des utilisateurs (sans mot de passe).',
    },
    {
        key: 'clients',
        title: 'Template Clients',
        description: 'Champs de création des clients.',
    },
    {
        key: 'vehicules-pack',
        title: 'Template Véhicules + Propriétaires + Livreurs',
        description:
            'Un seul fichier Excel avec 3 feuilles: propriétaires, livreurs et véhicules.',
    },
];

const grouped = computed(() => {
    const map: Record<string, Parametre[]> = {};
    for (const p of props.parametres) {
        if (!map[p.groupe]) map[p.groupe] = [];
        map[p.groupe].push(p);
    }
    return map;
});

const forms: Record<number, ReturnType<typeof useForm>> = {};

function getForm(p: Parametre) {
    if (!forms[p.id]) {
        const initial =
            p.type === 'boolean'
                ? p.valeur_cast
                    ? '1'
                    : '0'
                : (p.valeur ?? '');
        forms[p.id] = useForm({ valeur: initial });
    }
    return forms[p.id];
}

function submit(p: Parametre) {
    const form = getForm(p);
    form.put(`/settings/parametres/${p.id}`, {
        preserveScroll: true,
    });
}

function toggleBoolean(p: Parametre) {
    const form = getForm(p);
    form.valeur = form.valeur === '1' ? '0' : '1';
    submit(p);
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Paramétrage système" />

        <SettingsLayout>
            <div class="space-y-8">
                <HeadingSmall
                    title="Paramétrage système"
                    description="Configurez les paramètres globaux de l'application"
                />

                <div class="overflow-hidden rounded-xl border bg-card">
                    <div
                        class="flex items-center gap-2 border-b bg-muted/30 px-5 py-3"
                    >
                        <Download class="h-4 w-4 text-muted-foreground" />
                        <h3 class="text-sm font-semibold text-foreground">
                            Templates d'import
                        </h3>
                    </div>
                    <div class="divide-y">
                        <div
                            v-for="template in importTemplates"
                            :key="template.key"
                            class="flex items-center justify-between gap-4 px-5 py-4"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium">
                                    {{ template.title }}
                                </p>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    {{ template.description }}
                                </p>
                            </div>
                            <a
                                :href="`/settings/parametres/templates/${template.key}`"
                                class="inline-flex items-center gap-2 rounded-md border bg-background px-3 py-2 text-xs font-medium text-foreground transition-colors hover:bg-muted"
                            >
                                <Download class="h-3.5 w-3.5" />
                                Télécharger
                            </a>
                        </div>
                    </div>
                </div>

                <div
                    v-for="(params, groupe) in grouped"
                    :key="groupe"
                    class="overflow-hidden rounded-xl border bg-card"
                >
                    <div
                        class="flex items-center gap-2 border-b bg-muted/30 px-5 py-3"
                    >
                        <Settings class="h-4 w-4 text-muted-foreground" />
                        <h3 class="text-sm font-semibold text-foreground">
                            {{ groupeLabels[groupe] ?? groupe }}
                        </h3>
                    </div>

                    <div class="divide-y">
                        <div
                            v-for="p in params"
                            :key="p.id"
                            class="flex items-center gap-4 px-5 py-4"
                        >
                            <div class="min-w-0 flex-1">
                                <Label
                                    :for="`param-${p.id}`"
                                    class="text-sm font-medium"
                                >
                                    {{ cleLabels[p.cle] ?? p.cle }}
                                </Label>
                                <p
                                    v-if="p.description"
                                    class="mt-0.5 text-xs text-muted-foreground"
                                >
                                    {{ p.description }}
                                </p>
                            </div>

                            <div class="flex shrink-0 items-center gap-2">
                                <template v-if="p.type === 'boolean'">
                                    <button
                                        :id="`param-${p.id}`"
                                        type="button"
                                        role="switch"
                                        :aria-checked="
                                            getForm(p).valeur === '1'
                                        "
                                        class="relative inline-flex h-6 w-11 cursor-pointer rounded-full border-2 border-transparent transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                                        :class="
                                            getForm(p).valeur === '1'
                                                ? 'bg-primary'
                                                : 'bg-input'
                                        "
                                        :disabled="getForm(p).processing"
                                        @click="toggleBoolean(p)"
                                    >
                                        <span
                                            class="pointer-events-none block h-5 w-5 rounded-full bg-background shadow-lg ring-0 transition-transform"
                                            :class="
                                                getForm(p).valeur === '1'
                                                    ? 'translate-x-5'
                                                    : 'translate-x-0'
                                            "
                                        />
                                    </button>
                                </template>

                                <template v-else>
                                    <Input
                                        :id="`param-${p.id}`"
                                        v-model="getForm(p).valeur"
                                        :type="
                                            p.type === 'integer' ||
                                            p.type === 'decimal'
                                                ? 'number'
                                                : 'text'
                                        "
                                        :min="
                                            p.type === 'integer' ||
                                            p.type === 'decimal'
                                                ? 0
                                                : undefined
                                        "
                                        :max="
                                            p.type === 'decimal'
                                                ? 100
                                                : undefined
                                        "
                                        :step="
                                            p.type === 'decimal'
                                                ? '0.01'
                                                : undefined
                                        "
                                        class="w-36 text-right"
                                        @keyup.enter="submit(p)"
                                    />
                                    <Button
                                        size="sm"
                                        :disabled="
                                            getForm(p).processing ||
                                            !getForm(p).isDirty
                                        "
                                        @click="submit(p)"
                                    >
                                        Enregistrer
                                    </Button>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    v-if="Object.keys(grouped).length === 0"
                    class="py-12 text-center text-sm text-muted-foreground"
                >
                    Aucun paramètre configuré.
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
