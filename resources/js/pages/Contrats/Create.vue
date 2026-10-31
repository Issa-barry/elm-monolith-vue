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
    employe_id_prefill: string | null;
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
    statut_contrat: 'actif',
});

const isCdd = computed(() => form.type_contrat === 'cdd');

function submit() {
    form.post('/backoffice/contrats');
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
