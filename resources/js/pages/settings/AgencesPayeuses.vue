<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { Building2, Landmark, Loader2, Save } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Select from 'primevue/select';
import { useToast } from 'primevue/usetoast';
import { computed, ref } from 'vue';

type Mode = 'centralisee' | 'rattachement' | 'agence';

interface SiteOption {
    id: string;
    nom: string;
}

interface Regle {
    type: string;
    libelle: string;
    rattachement: string;
    mode: Mode;
    site_id: string | null;
}

const props = defineProps<{
    sites: SiteOption[];
    tresorerie_principale: SiteOption | null;
    agence_centralisee_id: string | null;
    regles: Regle[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Parametres', href: '/settings/profile' },
    {
        title: 'Agences payeuses',
        href: '/settings/commissions/agences-payeuses',
    },
];

const { can } = usePermissions();
const toast = useToast();

const form = useForm({
    agence_centralisee_id: props.agence_centralisee_id,
    regles: Object.fromEntries(
        props.regles.map((r) => [r.type, { mode: r.mode, site_id: r.site_id }]),
    ) as Record<string, { mode: Mode; site_id: string | null }>,
});

const modeOptions: { value: Mode; label: string }[] = [
    { value: 'centralisee', label: 'Agence centralisée' },
    { value: 'rattachement', label: 'Agence de rattachement' },
    { value: 'agence', label: 'Autre agence' },
];

const optionsAgenceCentralisee = computed(() => [
    {
        value: null,
        label: props.tresorerie_principale
            ? `Trésorerie principale (${props.tresorerie_principale.nom})`
            : 'Trésorerie principale (non désignée)',
    },
    ...props.sites.map((s) => ({ value: s.id, label: s.nom })),
]);

const optionsAgences = computed(() =>
    props.sites.map((s) => ({ value: s.id, label: s.nom })),
);

const nomAgenceCentralisee = computed(() => {
    if (form.agence_centralisee_id) {
        return (
            props.sites.find((s) => s.id === form.agence_centralisee_id)?.nom ??
            '—'
        );
    }

    return props.tresorerie_principale?.nom ?? 'Aucune';
});

function agencePayeuse(regle: Regle): string {
    const r = form.regles[regle.type];
    if (r.mode === 'centralisee') return nomAgenceCentralisee.value;
    if (r.mode === 'agence') {
        return props.sites.find((s) => s.id === r.site_id)?.nom ?? '—';
    }

    return regle.rattachement;
}

function onModeChange(type: string, mode: Mode) {
    form.regles[type].mode = mode;
    if (mode !== 'agence') form.regles[type].site_id = null;
}

const confirmation = ref(false);

function enregistrer() {
    form.put('/settings/commissions/agences-payeuses', {
        preserveScroll: true,
        onSuccess: (page) => {
            confirmation.value = false;
            const message = (page.props.flash as Record<string, string>)
                ?.success;
            toast.add({
                severity: 'success',
                summary: 'Agences payeuses',
                detail: message,
                life: 5000,
            });
        },
        onError: () => {
            confirmation.value = false;
        },
    });
}

function erreur(cle: string): string | undefined {
    return (form.errors as Record<string, string>)[cle];
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Agences payeuses" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall
                    title="Agences payeuses des commissions"
                    description="Choisissez l'agence qui paie les fiches de commission de chaque catégorie de bénéficiaire."
                />

                <div class="overflow-hidden rounded-xl border bg-card">
                    <div
                        class="flex items-center gap-2 border-b bg-muted/30 px-5 py-3"
                    >
                        <Landmark class="h-4 w-4 text-muted-foreground" />
                        <h3 class="text-sm font-semibold text-foreground">
                            Agence centralisée
                        </h3>
                    </div>

                    <div class="space-y-2 px-5 py-4">
                        <p class="text-xs text-muted-foreground">
                            Une seule agence paie toutes les catégories réglées
                            sur « Agence centralisée ». La changer ici les
                            déplace toutes en une fois.
                        </p>
                        <Select
                            v-model="form.agence_centralisee_id"
                            :options="optionsAgenceCentralisee"
                            option-label="label"
                            option-value="value"
                            :disabled="form.processing"
                            class="w-full sm:w-80"
                        />
                        <p
                            v-if="erreur('agence_centralisee_id')"
                            class="text-xs text-red-600"
                        >
                            {{ erreur('agence_centralisee_id') }}
                        </p>
                    </div>
                </div>

                <div class="overflow-hidden rounded-xl border bg-card">
                    <div
                        class="flex items-center gap-2 border-b bg-muted/30 px-5 py-3"
                    >
                        <Building2 class="h-4 w-4 text-muted-foreground" />
                        <h3 class="text-sm font-semibold text-foreground">
                            Agence payeuse par bénéficiaire
                        </h3>
                    </div>

                    <div class="divide-y">
                        <div
                            v-for="regle in regles"
                            :key="regle.type"
                            class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-start sm:justify-between"
                        >
                            <div class="min-w-0 sm:w-40">
                                <p class="text-sm font-medium text-foreground">
                                    {{ regle.libelle }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    Payés par : {{ agencePayeuse(regle) }}
                                </p>
                            </div>

                            <div class="flex flex-1 flex-col gap-2 sm:max-w-md">
                                <Select
                                    :model-value="form.regles[regle.type].mode"
                                    :options="modeOptions"
                                    option-label="label"
                                    option-value="value"
                                    :disabled="form.processing"
                                    class="w-full"
                                    @update:model-value="
                                        onModeChange(regle.type, $event)
                                    "
                                />
                                <p
                                    v-if="
                                        form.regles[regle.type].mode ===
                                        'rattachement'
                                    "
                                    class="text-xs text-muted-foreground"
                                >
                                    Agence de rattachement :
                                    {{ regle.rattachement }}.
                                </p>
                                <Select
                                    v-if="
                                        form.regles[regle.type].mode ===
                                        'agence'
                                    "
                                    v-model="form.regles[regle.type].site_id"
                                    :options="optionsAgences"
                                    option-label="label"
                                    option-value="value"
                                    placeholder="Choisir l'agence"
                                    :disabled="form.processing"
                                    class="w-full"
                                />
                                <p
                                    v-if="
                                        erreur(`regles.${regle.type}.site_id`)
                                    "
                                    class="text-xs text-red-600"
                                >
                                    {{ erreur(`regles.${regle.type}.site_id`) }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <p class="text-xs text-muted-foreground">
                    Les responsables de l'agence de rattachement gardent la
                    lecture des commissions ; seule l'agence payeuse peut les
                    payer. Les fiches non entièrement payées passent à la
                    nouvelle agence payeuse ; les paiements déjà faits ne
                    changent pas.
                </p>

                <div
                    v-if="can('commissions.configurer_agences_payeuses')"
                    class="flex justify-end"
                >
                    <Button
                        :disabled="form.processing || !form.isDirty"
                        @click="confirmation = true"
                    >
                        <Save class="mr-2 h-4 w-4" />
                        Enregistrer
                    </Button>
                </div>
            </div>
        </SettingsLayout>

        <Dialog
            v-model:visible="confirmation"
            modal
            header="Changer les agences payeuses ?"
            :closable="!form.processing"
            :style="{ width: '28rem' }"
        >
            <p class="text-sm text-foreground">
                Les fiches de commission non entièrement payées passeront à leur
                nouvelle agence payeuse, avec leurs écritures comptables. Les
                paiements déjà faits gardent leur agence.
            </p>
            <template #footer>
                <Button
                    variant="outline"
                    :disabled="form.processing"
                    @click="confirmation = false"
                >
                    Annuler
                </Button>
                <Button :disabled="form.processing" @click="enregistrer">
                    <Loader2
                        v-if="form.processing"
                        class="mr-2 h-4 w-4 animate-spin"
                    />
                    Confirmer
                </Button>
            </template>
        </Dialog>
    </AppLayout>
</template>
