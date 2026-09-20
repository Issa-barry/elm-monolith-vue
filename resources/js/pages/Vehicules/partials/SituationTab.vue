<script setup lang="ts">
import { Input } from '@/components/ui/input';
import { queryDe } from '@/composables/useUrlTab';
import type {
    SituationPeriode,
    SituationPeriodeCle,
    SituationVentesData,
} from '@/types/vehicule-situation';
import { router, usePage } from '@inertiajs/vue3';
import { ArrowRight } from 'lucide-vue-next';
import Select from 'primevue/select';
import { computed, ref, watch } from 'vue';
import SituationVentesSection from './situation/SituationVentesSection.vue';

const props = defineProps<{
    vehiculeId: string;
    vehiculeRecherche: string;
    periode: SituationPeriode;
    ventes: SituationVentesData;
}>();

const page = usePage();

// Paramètres qui portent la période dans l'URL : toujours remplacés ensemble.
const PARAMS_PERIODE = ['situation_periode', 'date_from', 'date_to'];

const periodeChoisie = ref<SituationPeriodeCle>(props.periode.cle);
const dateDebut = ref(props.periode.date_debut ?? '');
const dateFin = ref(props.periode.date_fin ?? '');

const modePersonnalise = computed(
    () => periodeChoisie.value === 'personnalisee',
);

const erreur = computed<string | null>(() => {
    if (!modePersonnalise.value || (!dateDebut.value && !dateFin.value)) {
        return null;
    }
    if (!dateDebut.value) {
        return 'Renseignez la date de début.';
    }
    if (!dateFin.value) {
        return 'Renseignez la date de fin.';
    }

    return dateDebut.value > dateFin.value
        ? 'La date de début doit précéder la date de fin.'
        : null;
});

// Le filtre se rejoue côté serveur (backend source de vérité) et ne change que la période :
// l'onglet et les autres paramètres de l'URL (ex. processus) sont conservés.
function visiter(parametresPeriode: Record<string, string>): void {
    const autres = Object.fromEntries(
        Object.entries(queryDe(page.url)).filter(
            ([cle]) => !PARAMS_PERIODE.includes(cle),
        ),
    );

    router.get(
        `/backoffice/vehicules/${props.vehiculeId}`,
        { ...autres, tab: 'situation', ...parametresPeriode },
        { preserveScroll: true, preserveState: true, replace: true },
    );
}

function onChoix(valeur: SituationPeriodeCle | null): void {
    if (!valeur) {
        return;
    }
    periodeChoisie.value = valeur;

    // « Période personnalisée » n'interroge le serveur qu'une fois les deux dates valides.
    if (valeur === 'personnalisee' || valeur === props.periode.cle) {
        return;
    }
    visiter(valeur === 'tout' ? {} : { situation_periode: valeur });
}

function appliquerPersonnalisee(): void {
    if (
        !modePersonnalise.value ||
        erreur.value ||
        !dateDebut.value ||
        !dateFin.value
    ) {
        return;
    }
    if (
        props.periode.cle === 'personnalisee' &&
        props.periode.date_debut === dateDebut.value &&
        props.periode.date_fin === dateFin.value
    ) {
        return;
    }
    visiter({ date_from: dateDebut.value, date_to: dateFin.value });
}

watch([dateDebut, dateFin], appliquerPersonnalisee);

// La période active est celle du serveur : chaque réponse réinitialise la saisie.
watch(
    () => props.periode,
    (periode) => {
        periodeChoisie.value = periode.cle;
        dateDebut.value = periode.date_debut ?? '';
        dateFin.value = periode.date_fin ?? '';
    },
);
</script>

<template>
    <div class="space-y-8">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <h2 class="text-xl font-semibold tracking-tight">
                Situation du véhicule
            </h2>

            <div class="flex flex-col items-end gap-2">
                <div class="flex flex-wrap items-center justify-end gap-2">
                    <label
                        for="situation-periode"
                        class="text-sm text-muted-foreground"
                    >
                        Période
                    </label>
                    <Select
                        :model-value="periodeChoisie"
                        :options="periode.options"
                        option-label="label"
                        option-value="value"
                        input-id="situation-periode"
                        class="w-56"
                        @update:model-value="onChoix"
                    />

                    <template v-if="modePersonnalise">
                        <label
                            for="situation-date-debut"
                            class="text-sm text-muted-foreground"
                        >
                            Du
                        </label>
                        <Input
                            id="situation-date-debut"
                            v-model="dateDebut"
                            type="date"
                            :max="dateFin || undefined"
                            :aria-invalid="erreur !== null"
                            class="h-10 w-40"
                        />
                        <ArrowRight
                            class="h-4 w-4 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <label
                            for="situation-date-fin"
                            class="text-sm text-muted-foreground"
                        >
                            Au
                        </label>
                        <Input
                            id="situation-date-fin"
                            v-model="dateFin"
                            type="date"
                            :min="dateDebut || undefined"
                            :aria-invalid="erreur !== null"
                            class="h-10 w-40"
                        />
                    </template>
                </div>

                <p
                    v-if="erreur"
                    role="alert"
                    class="text-xs text-destructive"
                    data-testid="situation-periode-erreur"
                >
                    {{ erreur }}
                </p>
            </div>
        </div>

        <SituationVentesSection
            :vehicule-recherche="vehiculeRecherche"
            :periode="periode"
            :data="ventes"
        />
    </div>
</template>
