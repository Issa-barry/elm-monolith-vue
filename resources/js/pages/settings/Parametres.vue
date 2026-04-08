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
import { Settings } from 'lucide-vue-next';
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

// ── Libellés ──────────────────────────────────────────────────────────────────
const groupeLabels: Record<string, string> = {
    general: 'Général',
    packing: 'Packing',
    vehicules: 'Véhicules',
};

const cleLabels: Record<string, string> = {
    seuil_stock_faible: 'Seuil de stock faible',
    notifications_stock_actives: 'Alertes de stock faible',
    prix_rouleau_defaut: 'Prix rouleau par défaut (GNF)',
    produit_rouleau_id: 'ID produit rouleau',
    taux_proprietaire_defaut: 'Taux propriétaire par défaut (%)',
};

// ── Regroupement ──────────────────────────────────────────────────────────────
const grouped = computed(() => {
    const map: Record<string, Parametre[]> = {};
    for (const p of props.parametres) {
        if (!map[p.groupe]) map[p.groupe] = [];
        map[p.groupe].push(p);
    }
    return map;
});

// ── Formulaires individuels ────────────────────────────────────────────────────
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

                <!-- Groupe de paramètres -->
                <div
                    v-for="(params, groupe) in grouped"
                    :key="groupe"
                    class="overflow-hidden rounded-xl border bg-card"
                >
                    <!-- En-tête groupe -->
                    <div
                        class="flex items-center gap-2 border-b bg-muted/30 px-5 py-3"
                    >
                        <Settings class="h-4 w-4 text-muted-foreground" />
                        <h3 class="text-sm font-semibold text-foreground">
                            {{ groupeLabels[groupe] ?? groupe }}
                        </h3>
                    </div>

                    <!-- Lignes de paramètres -->
                    <div class="divide-y">
                        <div
                            v-for="p in params"
                            :key="p.id"
                            class="flex items-center gap-4 px-5 py-4"
                        >
                            <!-- Label + description -->
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

                            <!-- Contrôle -->
                            <div class="flex shrink-0 items-center gap-2">
                                <!-- Boolean → toggle -->
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

                                <!-- Integer / decimal / string → input + bouton -->
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

                <!-- État vide -->
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
