<script setup lang="ts">
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFlashToast } from '@/composables/useFlashToast';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { FileStack, Loader2, Plus } from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Dialog from 'primevue/dialog';
import { computed, ref } from 'vue';

interface Transmission {
    id: string;
    annee: number;
    mois: number;
    libelle_mois: string;
    version: number;
    statut: string;
    statut_label: string;
    motif: string | null;
    nb_salaries: number;
    nb_bloquantes: number;
    nb_avertissements: number;
    total_verse: number | null;
    total_valorise: number | null;
    preparee_par: string | null;
    preparee_le: string | null;
    validee_par: string | null;
    validee_le: string | null;
    transmise_par: string | null;
    transmise_le: string | null;
    statut_comptabilite: string | null;
}

const props = defineProps<{
    transmissions: Transmission[];
    filters: { annee: string };
    annees: { value: string; label: string }[];
    mois_propose: { annee: number; mois: number };
    peut_agir_toutes_agences: boolean;
}>();

useFlashToast();
const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Transmission de paie', href: '/backoffice/transmissions-paie' },
];

const filterFields = computed((): FilterField[] => [
    {
        key: 'annee',
        label: 'Année',
        type: 'select',
        options: props.annees,
        inline: true,
    },
]);

const formatGnf = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 });

function gnf(valeur: number | null): string {
    return valeur === null ? '—' : `${formatGnf.format(valeur)} GNF`;
}

// Préparer : la transmission couvre toutes les agences (le serveur le revérifie).
const peutPreparer = computed(
    () =>
        can('rh-transmissions-paie.preparer') && props.peut_agir_toutes_agences,
);

const showPreparer = ref(false);
const form = useForm({
    periode: `${props.mois_propose.annee}-${String(props.mois_propose.mois).padStart(2, '0')}`,
    motif: '',
});

/** Une version figée existe déjà pour le mois choisi : c'est une correction, motif obligatoire. */
const versionFigeeExistante = computed(() => {
    const [annee, mois] = form.periode.split('-').map(Number);
    return props.transmissions.some(
        (t) =>
            t.annee === annee &&
            t.mois === mois &&
            ['validee', 'transmise', 'remplacee'].includes(t.statut),
    );
});

const erreurMois = computed(() => {
    const erreurs = form.errors as Record<string, string>;
    return erreurs.mois ?? erreurs.annee ?? '';
});

function preparer() {
    form.transform((data) => {
        const [annee, mois] = data.periode.split('-').map(Number);
        return { annee, mois, motif: data.motif.trim() || null };
    }).post('/backoffice/transmissions-paie', {
        onSuccess: () => {
            showPreparer.value = false;
        },
    });
}

function ouvrir(t: Transmission) {
    router.get(`/backoffice/transmissions-paie/${t.id}`);
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Transmission de paie" />

        <div class="w-full space-y-5 p-4 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-xl font-semibold">Transmission de paie</h1>
                    <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
                        Chaque mois, RH prépare les éléments de rémunération
                        (salaire de base des contrats, primes et avantages
                        validés), les contrôle, les valide — la version est
                        alors figée — puis les transmet à la Comptabilité. Une
                        correction ouvre une nouvelle version ; les précédentes
                        restent consultables.
                    </p>
                </div>
                <Button
                    v-if="peutPreparer"
                    size="sm"
                    data-testid="transmission-preparer"
                    @click="showPreparer = true"
                >
                    <Plus class="mr-1.5 h-3.5 w-3.5" />
                    Préparer un mois
                </Button>
            </div>

            <DataFilters
                url="/backoffice/transmissions-paie"
                :values="filters"
                :fields="filterFields"
                :result-count="transmissions.length"
                :hide-agence-selector="true"
            />

            <div class="overflow-hidden rounded-xl border bg-card">
                <DataTable
                    :value="transmissions"
                    data-key="id"
                    striped-rows
                    class="text-sm"
                    row-hover
                    :pt="{
                        root: { class: 'w-full' },
                        bodyRow: { class: 'cursor-pointer' },
                    }"
                    @row-click="(e) => ouvrir(e.data)"
                >
                    <Column header="Mois" style="min-width: 160px">
                        <template #body="{ data }">
                            <p class="font-medium">{{ data.libelle_mois }}</p>
                            <p class="text-xs text-muted-foreground">
                                Version {{ data.version }}
                            </p>
                        </template>
                    </Column>
                    <Column header="Statut" style="min-width: 190px">
                        <template #body="{ data }">
                            <StatusDot
                                :status="data.statut"
                                :label="data.statut_label"
                            />
                            <p
                                v-if="data.statut_comptabilite"
                                class="mt-0.5 text-[11px] text-muted-foreground"
                            >
                                {{ data.statut_comptabilite }}
                            </p>
                        </template>
                    </Column>
                    <Column header="Salariés" style="width: 90px">
                        <template #body="{ data }">{{
                            data.nb_salaries
                        }}</template>
                    </Column>
                    <Column header="Total versé" style="min-width: 140px">
                        <template #body="{ data }">
                            <span class="tabular-nums">{{
                                gnf(data.total_verse)
                            }}</span>
                        </template>
                    </Column>
                    <Column
                        header="Avantages valorisés"
                        style="min-width: 140px"
                    >
                        <template #body="{ data }">
                            <span class="text-muted-foreground tabular-nums">{{
                                gnf(data.total_valorise)
                            }}</span>
                        </template>
                    </Column>
                    <Column header="Anomalies" style="min-width: 130px">
                        <template #body="{ data }">
                            <p
                                v-if="data.nb_bloquantes > 0"
                                class="text-xs font-medium text-red-600 dark:text-red-400"
                            >
                                {{ data.nb_bloquantes }} bloquante(s)
                            </p>
                            <p
                                v-if="data.nb_avertissements > 0"
                                class="text-xs text-amber-700 dark:text-amber-300"
                            >
                                {{ data.nb_avertissements }} avertissement(s)
                            </p>
                            <p
                                v-if="
                                    data.nb_bloquantes +
                                        data.nb_avertissements ===
                                    0
                                "
                                class="text-xs text-muted-foreground"
                            >
                                Aucune
                            </p>
                        </template>
                    </Column>
                    <Column header="Suivi" style="min-width: 200px">
                        <template #body="{ data }">
                            <p class="text-xs text-muted-foreground">
                                Préparée {{ data.preparee_le }}
                                <template v-if="data.preparee_par">
                                    par {{ data.preparee_par }}</template
                                >
                            </p>
                            <p
                                v-if="data.validee_le"
                                class="text-xs text-muted-foreground"
                            >
                                Validée {{ data.validee_le }} par
                                {{ data.validee_par }}
                            </p>
                            <p
                                v-if="data.transmise_le"
                                class="text-xs text-muted-foreground"
                            >
                                Transmise {{ data.transmise_le }} par
                                {{ data.transmise_par }}
                            </p>
                        </template>
                    </Column>

                    <template #empty>
                        <div
                            class="flex flex-col items-center gap-3 py-12 text-muted-foreground"
                        >
                            <FileStack class="h-10 w-10 opacity-30" />
                            <p class="text-sm">
                                Aucune transmission pour cette année.
                            </p>
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>
    </AppLayout>

    <Dialog
        v-model:visible="showPreparer"
        modal
        header="Préparer la transmission d’un mois"
        :style="{ width: 'min(460px, 95vw)' }"
        :draggable="false"
        :closable="!form.processing"
    >
        <form class="space-y-4 pt-1" @submit.prevent="preparer">
            <p class="text-sm text-muted-foreground">
                Le brouillon est calculé à partir des contrats et des
                affectations validées. Rien n’est figé tant que la version n’est
                pas validée.
            </p>
            <div>
                <Label for="tp-mois" class="mb-1.5 block text-xs font-medium">
                    Mois <span class="text-destructive">*</span>
                </Label>
                <Input id="tp-mois" v-model="form.periode" type="month" />
                <p v-if="erreurMois" class="mt-1 text-xs text-destructive">
                    {{ erreurMois }}
                </p>
            </div>
            <div v-if="versionFigeeExistante || form.errors.motif">
                <Label for="tp-motif" class="mb-1.5 block text-xs font-medium">
                    Motif de la nouvelle version
                    <span class="text-destructive">*</span>
                </Label>
                <textarea
                    id="tp-motif"
                    v-model="form.motif"
                    rows="2"
                    class="flex min-h-[60px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    placeholder="Ex : correction du salaire de M. Diallo"
                />
                <p
                    v-if="form.errors.motif"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ form.errors.motif }}
                </p>
            </div>
            <div class="flex justify-end gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="form.processing"
                    @click="showPreparer = false"
                    >Annuler</Button
                >
                <Button
                    type="submit"
                    size="sm"
                    :disabled="form.processing || !form.periode"
                >
                    <Loader2
                        v-if="form.processing"
                        class="mr-1.5 h-3.5 w-3.5 animate-spin"
                    />
                    Préparer
                </Button>
            </div>
        </form>
    </Dialog>
</template>
