<script setup lang="ts">
import InfoTooltip from '@/components/InfoTooltip.vue';
import MontantInput from '@/components/MontantInput.vue';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Save } from 'lucide-vue-next';
import Select from 'primevue/select';
import { computed } from 'vue';
import ConditionsContrat from './partials/ConditionsContrat.vue';

// Salaire : saisi et affiché seulement avec rh-remunerations.read (ignoré par le serveur sinon).
const { can } = usePermissions();

interface Option {
    value: string;
    label: string;
}

interface ContratData {
    id: string;
    employe_id: string;
    type_contrat: string;
    statut_contrat: string;
    date_debut: string | null;
    date_fin: string | null;
    salaire_base: string | null;
    periode_essai_duree: number | null;
    periode_essai_unite: string | null;
    heures_hebdomadaires: string | null;
    motif_cdd: string | null;
}

const props = defineProps<{
    contrat: ContratData;
    employes: Option[];
    type_contrat_options: Option[];
    statut_contrat_options: Option[];
    unites_periode_essai: Option[];
    // Primes reprises au contrat, en lecture seule ; null sans le droit de les voir.
    primes: string[] | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Contrats', href: '/backoffice/contrats' },
    { title: 'Modifier', href: '#' },
];

const form = useForm({
    employe_id: props.contrat.employe_id,
    type_contrat: props.contrat.type_contrat,
    date_debut: props.contrat.date_debut ?? '',
    date_fin: props.contrat.date_fin ?? '',
    salaire_base:
        props.contrat.salaire_base === null
            ? null
            : Number(props.contrat.salaire_base),
    statut_contrat: props.contrat.statut_contrat,
    periode_essai_duree: props.contrat.periode_essai_duree,
    periode_essai_unite: props.contrat.periode_essai_unite,
    heures_hebdomadaires:
        props.contrat.heures_hebdomadaires === null
            ? null
            : Number(props.contrat.heures_hebdomadaires),
    motif_cdd: props.contrat.motif_cdd,
});

const isCdd = computed(() => form.type_contrat === 'cdd');

function submit() {
    form.put(`/backoffice/contrats/${props.contrat.id}`);
}
</script>

<template>
    <Head><title>Modifier le contrat</title></Head>
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-2xl p-4 sm:p-6">
            <div class="mb-6 flex items-center gap-4">
                <Link href="/backoffice/contrats">
                    <Button variant="ghost" size="icon"
                        ><ArrowLeft class="h-4 w-4"
                    /></Button>
                </Link>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Modifier le contrat
                </h1>
            </div>

            <form class="space-y-6" @submit.prevent="submit">
                <div class="space-y-5 rounded-xl border bg-card p-6 shadow-sm">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium"
                            >Employé
                            <span class="text-destructive">*</span></label
                        >
                        <Select
                            v-model="form.employe_id"
                            :options="employes"
                            option-label="label"
                            option-value="value"
                            filter
                            class="w-full"
                        />
                        <p
                            v-if="form.errors.employe_id"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ form.errors.employe_id }}
                        </p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium"
                                >Type de contrat
                                <span class="text-destructive">*</span></label
                            >
                            <Select
                                v-model="form.type_contrat"
                                :options="type_contrat_options"
                                option-label="label"
                                option-value="value"
                                class="w-full"
                            />
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium"
                                >Statut</label
                            >
                            <Select
                                v-model="form.statut_contrat"
                                :options="statut_contrat_options"
                                option-label="label"
                                option-value="value"
                                class="w-full"
                            />
                        </div>
                        <div>
                            <div class="mb-1.5 flex items-center gap-1.5">
                                <label class="text-sm font-medium"
                                    >Date de début
                                    <span class="text-destructive"
                                        >*</span
                                    ></label
                                >
                                <InfoTooltip
                                    label="Aide : date de début du contrat"
                                >
                                    Premier jour prévu d'exécution du contrat de
                                    travail. Le premier contrat d'un salarié
                                    renseigne aussi sa date d'embauche si elle
                                    est encore vide. Deux contrats d'un même
                                    salarié ne peuvent pas couvrir les mêmes
                                    jours.
                                </InfoTooltip>
                            </div>
                            <input
                                v-model="form.date_debut"
                                type="date"
                                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                :class="{
                                    'border-destructive':
                                        form.errors.date_debut,
                                }"
                            />
                            <p
                                v-if="form.errors.date_debut"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.date_debut }}
                            </p>
                        </div>
                        <div>
                            <div class="mb-1.5 flex items-center gap-1.5">
                                <label class="text-sm font-medium">
                                    Date de fin
                                    <span v-if="isCdd" class="text-destructive"
                                        >*</span
                                    >
                                    <span
                                        v-else
                                        class="text-xs text-muted-foreground"
                                        >(CDI = indéterminé)</span
                                    >
                                </label>
                                <InfoTooltip
                                    label="Aide : date de fin du contrat"
                                >
                                    Date de fin prévue du contrat, obligatoire
                                    pour un CDD. Elle est distincte de la date
                                    de sortie effective, qui s'enregistre avec «
                                    Enregistrer le départ » sur la fiche du
                                    salarié.
                                </InfoTooltip>
                            </div>
                            <input
                                v-model="form.date_fin"
                                type="date"
                                :disabled="!isCdd"
                                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50"
                                :class="{
                                    'border-destructive': form.errors.date_fin,
                                }"
                            />
                            <p
                                v-if="form.errors.date_fin"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.date_fin }}
                            </p>
                        </div>
                        <div
                            v-if="can('rh-remunerations.read')"
                            class="sm:col-span-2"
                        >
                            <div class="mb-1.5 flex items-center gap-1.5">
                                <label
                                    for="contrat-salaire"
                                    class="text-sm font-medium"
                                    >Salaire de base</label
                                >
                                <InfoTooltip label="Aide : salaire de base">
                                    Salaire de base mensuel prévu au contrat,
                                    distinct des primes et autres éléments de
                                    rémunération (écran « Primes des salariés
                                    »).
                                </InfoTooltip>
                            </div>
                            <MontantInput
                                id="contrat-salaire"
                                v-model="form.salaire_base"
                                unite="GNF"
                                placeholder="Ex : 750 000"
                                :invalid="!!form.errors.salaire_base"
                            />
                            <p
                                v-if="form.errors.salaire_base"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.salaire_base }}
                            </p>
                        </div>
                    </div>

                    <ConditionsContrat
                        v-model:duree="form.periode_essai_duree"
                        v-model:unite="form.periode_essai_unite"
                        v-model:heures="form.heures_hebdomadaires"
                        v-model:motif="form.motif_cdd"
                        :unites="unites_periode_essai"
                        :est-cdd="isCdd"
                        :requis="false"
                        :errors="form.errors"
                    />

                    <!-- Lecture seule : « Primes des salariés » garde l'historique des montants. -->
                    <div
                        v-if="primes"
                        class="space-y-1 border-t pt-5"
                        data-testid="primes-contrat-lecture"
                    >
                        <div
                            class="flex flex-wrap items-center justify-between gap-2"
                        >
                            <h4 class="text-sm font-medium">
                                Primes et avantages
                            </h4>
                            <Link
                                :href="`/backoffice/affectations-remuneration?employe_id=${contrat.employe_id}`"
                                class="text-xs underline underline-offset-2"
                            >
                                Gérer dans Primes des salariés
                            </Link>
                        </div>
                        <p
                            v-if="!primes.length"
                            class="text-xs text-muted-foreground"
                        >
                            Aucune prime ni aucun avantage.
                        </p>
                        <ul v-else class="list-inside list-disc text-sm">
                            <li v-for="prime in primes" :key="prime">
                                {{ prime }}
                            </li>
                        </ul>
                    </div>

                    <p class="text-xs text-muted-foreground">
                        À l’enregistrement, le PDF du contrat est mis à jour
                        automatiquement. Une version déjà signée est conservée
                        telle quelle : si une clause change, une nouvelle
                        version à faire signer est préparée.
                    </p>
                </div>

                <div class="flex items-center justify-between">
                    <Link href="/backoffice/contrats">
                        <Button type="button" variant="outline"
                            ><ArrowLeft class="mr-2 h-4 w-4" />Annuler</Button
                        >
                    </Link>
                    <Button type="submit" :disabled="form.processing">
                        <Save class="mr-2 h-4 w-4" />
                        {{
                            form.processing ? 'Enregistrement…' : 'Enregistrer'
                        }}
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
