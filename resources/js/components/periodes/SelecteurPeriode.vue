<script setup lang="ts">
import { Input } from '@/components/ui/input';
import type { PeriodeFiltre } from '@/types/periode';
import { router } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Popover from 'primevue/popover';
import Select from 'primevue/select';
import { computed, ref, useId, watch } from 'vue';

/**
 * Filtre de période unique d'un tableau de bord (Stat-Ventes, Stat-Commissions) : il recharge la
 * page avec `periode=<clé>` ou `date_debut` + `date_fin` (« Période personnalisée »), et le serveur
 * recalcule toutes les cartes et tous les graphiques sur cette période (Periode, ADR 0057). Les
 * choix et les bornes viennent du serveur ; le navigateur ne calcule aucune date.
 *
 * La ligne de filtres ne contient que la liste : les dates d'une période personnalisée se saisissent
 * dans une fenêtre contextuelle (Popover) ouverte sous la liste, sans déplacer le reste de la page.
 */
const props = defineProps<{
    /** Adresse de la page, rechargée avec la période choisie. */
    url: string;
    periode: PeriodeFiltre;
    /** Autres filtres de la page à conserver (ex. `site_id` de l'agence choisie). */
    parametres?: Record<string, string>;
}>();

const PERSONNALISEE = 'personnalisee';
const id = useId();

const ancre = ref<HTMLElement | null>(null);
const fenetre = ref<InstanceType<typeof Popover> | null>(null);
const fenetreOuverte = ref(false);

const periodeChoisie = ref(props.periode.cle);
// Dates proposées à l'ouverture : les bornes de la période affichée.
const dateDebut = ref(props.periode.date_debut ?? '');
const dateFin = ref(props.periode.date_fin ?? '');
const chargement = ref(false);
const erreurServeur = ref<string | null>(null);

// Mêmes règles que le serveur (Periode::regles) : début et fin obligatoires, début ≤ fin.
const erreurSaisie = computed<string | null>(() => {
    if (!fenetreOuverte.value || (!dateDebut.value && !dateFin.value)) {
        return null;
    }
    if (!dateDebut.value) return 'Renseignez la date de début.';
    if (!dateFin.value) return 'Renseignez la date de fin.';

    return dateDebut.value > dateFin.value
        ? 'La date de fin doit être postérieure ou égale à la date de début.'
        : null;
});

const peutAppliquer = computed(
    () =>
        !chargement.value &&
        erreurSaisie.value === null &&
        dateDebut.value !== '' &&
        dateFin.value !== '',
);

const erreurDates = computed(() => erreurSaisie.value ?? erreurServeur.value);

/** « 2026-11-01 » → « 01/11/2026 », sans passer par l'horloge du navigateur. */
function dateFr(iso: string): string {
    const [annee, mois, jour] = iso.split('-');
    return `${jour}/${mois}/${annee}`;
}

// Libellé de la liste : les dates appliquées pour une période personnalisée.
function libelle(valeur: string | null | undefined): string {
    if (
        valeur === PERSONNALISEE &&
        props.periode.cle === PERSONNALISEE &&
        props.periode.date_debut &&
        props.periode.date_fin
    ) {
        return `${dateFr(props.periode.date_debut)} – ${dateFr(props.periode.date_fin)}`;
    }

    return props.periode.options.find((o) => o.value === valeur)?.label ?? '';
}

function visiter(
    parametres: Record<string, string>,
    apresSucces?: () => void,
): void {
    router.get(
        props.url,
        { ...props.parametres, ...parametres },
        {
            preserveState: true,
            preserveScroll: true,
            onStart: () => {
                chargement.value = true;
                erreurServeur.value = null;
            },
            onSuccess: () => apresSucces?.(),
            onFinish: () => (chargement.value = false),
            // Période refusée par le serveur : son message, jamais un repli silencieux.
            onError: (erreurs) => {
                erreurServeur.value =
                    erreurs.date_debut ??
                    erreurs.date_fin ??
                    erreurs.periode ??
                    'Période invalide.';
            },
        },
    );
}

function ouvrirFenetre(evenement: Event): void {
    dateDebut.value = props.periode.date_debut ?? '';
    dateFin.value = props.periode.date_fin ?? '';
    erreurServeur.value = null;
    // Après le clic sur l'option : ouverte pendant ce clic, la fenêtre se refermerait aussitôt.
    setTimeout(() => fenetre.value?.show(evenement, ancre.value ?? undefined));
}

// `change` est émis à chaque choix, même identique : rechoisir « Période personnalisée » rouvre
// la fenêtre pour modifier les dates.
function onChoix(evenement: {
    originalEvent: Event;
    value: string | null;
}): void {
    const valeur = evenement.value;
    if (!valeur) return;
    erreurServeur.value = null;

    if (valeur === PERSONNALISEE) {
        ouvrirFenetre(evenement.originalEvent);
        return;
    }
    if (valeur !== props.periode.cle) visiter({ periode: valeur });
}

function appliquer(): void {
    if (!peutAppliquer.value) return;
    visiter({ date_debut: dateDebut.value, date_fin: dateFin.value }, () =>
        fenetre.value?.hide(),
    );
}

// Fenêtre fermée sans appliquer : la liste revient à la période réellement affichée.
function onFermeture(): void {
    fenetreOuverte.value = false;
    periodeChoisie.value = props.periode.cle;
}

// La période active est celle renvoyée par le serveur. Les dates en cours de saisie ne sont pas
// écrasées tant que la fenêtre est ouverte (ex. refus du serveur : la saisie reste à corriger).
watch(
    () => props.periode,
    (periode) => {
        if (fenetreOuverte.value) return;
        periodeChoisie.value = periode.cle;
        dateDebut.value = periode.date_debut ?? '';
        dateFin.value = periode.date_fin ?? '';
    },
);
</script>

<template>
    <div
        ref="ancre"
        class="flex max-w-full flex-col items-stretch gap-1.5 sm:items-end"
        data-testid="selecteur-periode"
    >
        <Select
            v-model="periodeChoisie"
            :options="periode.options"
            option-label="label"
            option-value="value"
            aria-label="Période"
            :disabled="chargement"
            :loading="chargement"
            class="min-w-56 text-sm"
            data-testid="periode-choix"
            @change="onChoix"
        >
            <template #value="{ value }">{{ libelle(value) }}</template>
        </Select>

        <p
            v-if="erreurServeur && !fenetreOuverte"
            role="alert"
            class="text-xs text-destructive"
            data-testid="periode-erreur"
        >
            {{ erreurServeur }}
        </p>

        <Popover
            ref="fenetre"
            @show="fenetreOuverte = true"
            @hide="onFermeture"
        >
            <form
                class="w-[min(30rem,calc(100vw-3rem))] space-y-3"
                data-testid="periode-dates"
                @submit.prevent="appliquer"
            >
                <p class="text-sm font-semibold">Période personnalisée</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label
                            :for="`${id}-debut`"
                            class="text-xs text-muted-foreground"
                            >Date de début</label
                        >
                        <Input
                            :id="`${id}-debut`"
                            v-model="dateDebut"
                            type="date"
                            required
                            :max="dateFin || undefined"
                            :disabled="chargement"
                            :aria-invalid="erreurDates !== null"
                            class="h-10"
                            data-testid="periode-date-debut"
                        />
                    </div>
                    <div class="space-y-1">
                        <label
                            :for="`${id}-fin`"
                            class="text-xs text-muted-foreground"
                            >Date de fin</label
                        >
                        <Input
                            :id="`${id}-fin`"
                            v-model="dateFin"
                            type="date"
                            required
                            :min="dateDebut || undefined"
                            :disabled="chargement"
                            :aria-invalid="erreurDates !== null"
                            class="h-10"
                            data-testid="periode-date-fin"
                        />
                    </div>
                </div>
                <p
                    v-if="erreurDates"
                    role="alert"
                    class="text-xs text-destructive"
                    data-testid="periode-erreur"
                >
                    {{ erreurDates }}
                </p>
                <div class="flex justify-end">
                    <Button
                        type="submit"
                        label="Appliquer"
                        :loading="chargement"
                        :disabled="!peutAppliquer"
                        class="!h-10"
                        data-testid="periode-appliquer"
                    />
                </div>
            </form>
        </Popover>
    </div>
</template>
