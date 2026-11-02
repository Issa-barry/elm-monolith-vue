<script setup lang="ts">
import InfoTooltip from '@/components/InfoTooltip.vue';
import MontantInput from '@/components/MontantInput.vue';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type PrimeOccupee } from '@/lib/primesAttribuees';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { AlertTriangle, ArrowLeft, FileText, Save } from 'lucide-vue-next';
import Select from 'primevue/select';
import { computed } from 'vue';
import ConditionsContrat from './partials/ConditionsContrat.vue';
import PrimesContrat, {
    type LignePrime,
    type TypePrime,
} from './partials/PrimesContrat.vue';

// Salaire : saisi et affiché seulement avec rh-remunerations.read (ignoré par le serveur sinon).
const { can } = usePermissions();

interface Option {
    value: string;
    label: string;
}

const props = defineProps<{
    employes: Option[];
    type_contrat_options: Option[];
    statut_contrat_options: Option[];
    unites_periode_essai: Option[];
    employe_id_prefill: string | null;
    pdg: { nom: string; qualite: string } | null;
    message_pdg_absent: string;
    // null sans le droit d'attribuer ET de valider une prime : section masquée, refus serveur.
    primes_contrat: {
        types: TypePrime[];
        premier_jour_modifiable: string | null;
        par_employe: Record<string, PrimeOccupee[]>;
    } | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Contrats', href: '/backoffice/contrats' },
    { title: 'Nouveau', href: '#' },
];

const form = useForm({
    employe_id: props.employe_id_prefill ?? '',
    type_contrat: 'cdi',
    date_debut: '',
    date_fin: '',
    salaire_base: null as number | null,
    periode_essai_duree: null as number | null,
    periode_essai_unite: null as string | null,
    heures_hebdomadaires: null as number | null,
    motif_cdd: null as string | null,
    primes: [] as LignePrime[],
});

const isCdd = computed(() => form.type_contrat === 'cdd');
// Refus serveur : aucun PDG désigné (clé `pdg`, hors des champs du formulaire).
const erreurPdg = computed(
    () => (form.errors as Record<string, string | undefined>).pdg,
);

const primesEmploye = computed(
    () => props.primes_contrat?.par_employe[form.employe_id] ?? [],
);
const erreursFormulaire = computed(
    () => form.errors as Record<string, string | undefined>,
);

function submit() {
    form.transform((donnees) => ({
        ...donnees,
        // Sur le serveur, une prime a une date de début : le 1er du mois si elle est ponctuelle.
        primes: donnees.primes.map((ligne) => {
            const type = props.primes_contrat?.types.find(
                (t) => t.value === ligne.type_remuneration_id,
            );
            const ponctuelle = type?.periodicite === 'ponctuelle';

            return {
                type_remuneration_id: ligne.type_remuneration_id,
                date_debut: ponctuelle
                    ? ligne.mois && `${ligne.mois}-01`
                    : ligne.date_debut,
                montant: ligne.montant,
                taux: ligne.taux,
            };
        }),
    })).post('/backoffice/contrats');
}
</script>

<template>
    <Head><title>Nouveau contrat</title></Head>
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-2xl p-4 sm:p-6">
            <div class="mb-6 flex items-center gap-4">
                <Link href="/backoffice/contrats">
                    <Button variant="ghost" size="icon"
                        ><ArrowLeft class="h-4 w-4"
                    /></Button>
                </Link>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Nouveau contrat
                </h1>
            </div>

            <div
                v-if="!pdg"
                class="mb-6 flex gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-300"
                data-testid="pdg-absent"
            >
                <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
                {{ message_pdg_absent }}
            </div>
            <div
                v-else
                class="mb-6 flex gap-2 rounded-lg border bg-muted/40 px-4 py-3 text-sm text-muted-foreground"
            >
                <FileText class="mt-0.5 h-4 w-4 shrink-0" />
                <span>
                    Le PDF du contrat sera préparé automatiquement à
                    l’enregistrement, avec les informations de l’organisation,
                    du salarié et du signataire :
                    <strong class="text-foreground">{{ pdg.nom }}</strong
                    >, {{ pdg.qualite }}.
                </span>
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
                            placeholder="Sélectionner un employé"
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
                            <p
                                v-if="form.errors.type_contrat"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.type_contrat }}
                            </p>
                        </div>
                        <div class="hidden sm:block" />
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

                    <PrimesContrat
                        v-if="primes_contrat"
                        v-model="form.primes"
                        :types="primes_contrat.types"
                        :premier-jour-modifiable="
                            primes_contrat.premier_jour_modifiable
                        "
                        :primes-employe="primesEmploye"
                        :employe-choisi="!!form.employe_id"
                        :date-debut-contrat="form.date_debut"
                        :date-fin-contrat="isCdd ? form.date_fin : ''"
                        :errors="erreursFormulaire"
                        @effacer-erreurs="form.clearErrors()"
                    />

                    <ConditionsContrat
                        v-model:duree="form.periode_essai_duree"
                        v-model:unite="form.periode_essai_unite"
                        v-model:heures="form.heures_hebdomadaires"
                        v-model:motif="form.motif_cdd"
                        :unites="unites_periode_essai"
                        :est-cdd="isCdd"
                        :requis="true"
                        :errors="form.errors"
                    />

                    <p
                        v-if="erreurPdg"
                        class="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive"
                    >
                        {{ erreurPdg }}
                    </p>
                </div>

                <div class="flex items-center justify-between">
                    <Link href="/backoffice/contrats">
                        <Button type="button" variant="outline"
                            ><ArrowLeft class="mr-2 h-4 w-4" />Annuler</Button
                        >
                    </Link>
                    <Button
                        type="submit"
                        :disabled="form.processing || !pdg"
                        data-testid="creer-contrat"
                    >
                        <Save class="mr-2 h-4 w-4" />
                        {{
                            form.processing
                                ? 'Enregistrement…'
                                : 'Créer le contrat'
                        }}
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
