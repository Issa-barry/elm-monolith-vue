<script setup lang="ts">
import MontantInput from '@/components/MontantInput.vue';
import { Button } from '@/components/ui/button';
import {
    dateEffetProposee,
    libellesPrimesActuelles,
    moisVersementPropose,
    periodeDuMois,
    primeDejaAttribuee,
    SANS_FIN,
    type PrimeOccupee,
} from '@/lib/primesAttribuees';
import { Plus, Trash2 } from 'lucide-vue-next';
import InputNumber from 'primevue/inputnumber';
import Select from 'primevue/select';
import { computed, ref, watch } from 'vue';

export interface TypePrime {
    value: string;
    label: string;
    valorise: boolean;
    mode_calcul: string;
    periodicite: string;
    periodicite_label: string;
    nature_montant: string;
    cumul_autorise: boolean;
    montant_defaut: number | null;
    taux_defaut: number | null;
}

export interface LignePrime {
    cle: number;
    type_remuneration_id: string;
    date_debut: string;
    mois: string;
    montant: number | null;
    taux: number | null;
    /** Date changée à la main : elle ne suit plus la date de début du contrat. */
    date_modifiee: boolean;
}

/**
 * Primes ajoutées à la création du contrat (ADR 0048) : plusieurs lignes, comme les produits d'une
 * vente. Toujours validées à l'enregistrement ; les règles sont celles de « Primes des salariés »,
 * revérifiées par le serveur (ContratService::creerAvecPrimes).
 */
const props = defineProps<{
    types: TypePrime[];
    premierJourModifiable: string | null;
    /** Primes déjà attribuées au salarié choisi. */
    primesEmploye: PrimeOccupee[];
    employeChoisi: boolean;
    dateDebutContrat: string;
    dateFinContrat: string;
    errors: Record<string, string | undefined>;
}>();

const lignes = defineModel<LignePrime[]>({ required: true });

const emit = defineEmits<{ 'effacer-erreurs': [] }>();

const aujourdhui = new Date().toLocaleDateString('en-CA');
const compteur = ref(0);

const primesActuelles = computed(() =>
    libellesPrimesActuelles(props.primesEmploye, aujourdhui),
);

function typeDe(ligne: LignePrime): TypePrime | undefined {
    return props.types.find((t) => t.value === ligne.type_remuneration_id);
}

const dateProposee = computed(() =>
    dateEffetProposee(props.dateDebutContrat, props.premierJourModifiable),
);
const moisPropose = computed(() =>
    moisVersementPropose(props.dateDebutContrat, props.premierJourModifiable),
);

/** Période visée par ce type sur cette ligne : ses dates, sinon celles proposées. */
function periodeVisee(
    t: TypePrime,
    ligne: LignePrime | null,
): [string, string] {
    if (t.periodicite === 'ponctuelle') {
        return periodeDuMois(
            ligne?.mois || moisPropose.value || aujourdhui.slice(0, 7),
        );
    }

    return [
        ligne?.date_debut || dateProposee.value || aujourdhui,
        props.dateFinContrat || SANS_FIN,
    ];
}

function dejaAttribue(t: TypePrime, ligne: LignePrime | null): boolean {
    const [du, au] = periodeVisee(t, ligne);

    return primeDejaAttribuee(
        props.primesEmploye,
        t.value,
        t.cumul_autorise,
        du,
        au,
    );
}

/** Ni déjà attribuée au salarié, ni déjà choisie sur une autre ligne ; le choix en cours reste. */
function typesProposes(ligne: LignePrime | null): TypePrime[] {
    return props.types.filter((t) => {
        if (ligne && t.value === ligne.type_remuneration_id) return true;
        const autreLigne = lignes.value.some(
            (l) => l !== ligne && l.type_remuneration_id === t.value,
        );

        return !autreLigne && !dejaAttribue(t, ligne);
    });
}

const peutAjouter = computed(
    () => props.employeChoisi && typesProposes(null).length > 0,
);

function ajouter() {
    lignes.value = [
        ...lignes.value,
        {
            cle: ++compteur.value,
            type_remuneration_id: '',
            date_debut: dateProposee.value,
            mois: moisPropose.value,
            montant: null,
            taux: null,
            date_modifiee: false,
        },
    ];
}

function retirer(ligne: LignePrime) {
    lignes.value = lignes.value.filter((l) => l !== ligne);
    // Les erreurs sont repérées par numéro de ligne : elles ne correspondent plus.
    emit('effacer-erreurs');
}

function choisirType(ligne: LignePrime, id: string) {
    const t = props.types.find((x) => x.value === id);
    ligne.type_remuneration_id = id;
    // Valeur par défaut du type : proposée, toujours modifiable, jamais imposée (AFF-005).
    ligne.montant = t?.montant_defaut ?? null;
    ligne.taux = t?.taux_defaut ?? null;
}

function erreursDe(index: number): string[] {
    const prefixe = `primes.${index}.`;

    return Object.entries(props.errors)
        .filter(([cle, message]) => cle.startsWith(prefixe) && !!message)
        .map(([, message]) => message as string);
}

// Les dates non modifiées à la main suivent celle du contrat.
watch([dateProposee, moisPropose], ([date, mois]) => {
    for (const ligne of lignes.value) {
        if (!ligne.date_modifiee) {
            ligne.date_debut = date;
            ligne.mois = mois;
        }
    }
});

// Autre salarié : une prime qu'il possède déjà est retirée du choix.
watch(
    () => props.primesEmploye,
    () => {
        for (const ligne of lignes.value) {
            const t = typeDe(ligne);
            if (t && dejaAttribue(t, ligne)) {
                ligne.type_remuneration_id = '';
                ligne.montant = null;
                ligne.taux = null;
            }
        }
    },
);
</script>

<template>
    <div class="space-y-3 border-t pt-5" data-testid="primes-contrat">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h4 class="text-sm font-medium">Primes et avantages</h4>
                <p class="text-xs text-muted-foreground">
                    Facultatif. Les primes ajoutées ici sont validées à
                    l’enregistrement et figurent dans le PDF du contrat.
                </p>
            </div>
            <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="!peutAjouter"
                data-testid="ajouter-prime"
                @click="ajouter"
            >
                <Plus class="mr-1.5 h-4 w-4" />
                Ajouter une prime
            </Button>
        </div>

        <p
            v-if="employeChoisi"
            class="text-xs text-muted-foreground"
            data-testid="primes-actuelles"
        >
            <template v-if="primesActuelles.length">
                Déjà attribuées :
                <span class="text-foreground">{{
                    primesActuelles.join(' · ')
                }}</span>
            </template>
            <template v-else>Aucune prime attribuée.</template>
        </p>
        <p v-else class="text-xs text-muted-foreground">
            Choisissez d’abord l’employé.
        </p>
        <p
            v-if="types.length === 0"
            class="text-xs text-muted-foreground"
            data-testid="aucun-type-prime"
        >
            Aucun type de prime actif : un type doit être validé dans Paramètres
            → Primes et avantages.
        </p>
        <p
            v-else-if="employeChoisi && !peutAjouter && lignes.length === 0"
            class="text-xs text-muted-foreground"
            data-testid="toutes-primes-attribuees"
        >
            Ce salarié possède déjà toutes les primes disponibles.
        </p>

        <div
            v-for="(ligne, index) in lignes"
            :key="ligne.cle"
            class="space-y-3 rounded-lg border p-3"
            :data-testid="`ligne-prime-${index}`"
        >
            <div class="flex items-start gap-2">
                <div class="min-w-0 flex-1">
                    <label
                        :for="`prime-type-${ligne.cle}`"
                        class="mb-1.5 block text-xs font-medium"
                        >Prime ou avantage
                        <span class="text-destructive">*</span></label
                    >
                    <Select
                        :model-value="ligne.type_remuneration_id"
                        :input-id="`prime-type-${ligne.cle}`"
                        :options="typesProposes(ligne)"
                        option-label="label"
                        option-value="value"
                        placeholder="Choisir une prime"
                        class="w-full"
                        @update:model-value="choisirType(ligne, $event)"
                    />
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="mt-6 shrink-0 text-destructive"
                    :aria-label="`Retirer la ligne ${index + 1}`"
                    :title="`Retirer la ligne ${index + 1}`"
                    @click="retirer(ligne)"
                >
                    <Trash2 class="h-4 w-4" />
                </Button>
            </div>

            <div v-if="typeDe(ligne)" class="grid gap-3 sm:grid-cols-2">
                <div v-if="typeDe(ligne)?.nature_montant === 'variable'">
                    <p class="mb-1.5 text-xs font-medium">Montant</p>
                    <p class="text-xs text-muted-foreground">
                        Saisi chaque mois : aucun montant permanent.
                    </p>
                </div>
                <div
                    v-else-if="
                        typeDe(ligne)?.mode_calcul ===
                        'pourcentage_salaire_base'
                    "
                >
                    <label
                        :for="`prime-taux-${ligne.cle}`"
                        class="mb-1.5 block text-xs font-medium"
                        >Taux (% du salaire de base)
                        <span class="text-destructive">*</span></label
                    >
                    <InputNumber
                        v-model="ligne.taux"
                        :input-id="`prime-taux-${ligne.cle}`"
                        :min="0"
                        :max="100"
                        :max-fraction-digits="2"
                        locale="fr-FR"
                        suffix=" %"
                        class="w-full"
                        input-class="w-full"
                        @input="(e: any) => (ligne.taux = e.value)"
                    />
                </div>
                <div v-else>
                    <label
                        :for="`prime-montant-${ligne.cle}`"
                        class="mb-1.5 block text-xs font-medium"
                        >Montant <span class="text-destructive">*</span></label
                    >
                    <MontantInput
                        :id="`prime-montant-${ligne.cle}`"
                        v-model="ligne.montant"
                        unite="GNF"
                        placeholder="Ex : 200 000"
                    />
                </div>

                <div v-if="typeDe(ligne)?.periodicite === 'ponctuelle'">
                    <label
                        :for="`prime-mois-${ligne.cle}`"
                        class="mb-1.5 block text-xs font-medium"
                        >Mois de versement
                        <span class="text-destructive">*</span></label
                    >
                    <input
                        :id="`prime-mois-${ligne.cle}`"
                        v-model="ligne.mois"
                        type="month"
                        class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        @change="ligne.date_modifiee = true"
                    />
                </div>
                <div v-else>
                    <label
                        :for="`prime-date-${ligne.cle}`"
                        class="mb-1.5 block text-xs font-medium"
                        >À partir du
                        <span class="text-destructive">*</span></label
                    >
                    <input
                        :id="`prime-date-${ligne.cle}`"
                        v-model="ligne.date_debut"
                        type="date"
                        class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        @change="ligne.date_modifiee = true"
                    />
                </div>

                <p class="text-xs text-muted-foreground sm:col-span-2">
                    {{ typeDe(ligne)?.periodicite_label }}
                    <template v-if="typeDe(ligne)?.valorise">
                        · avantage en nature : valorisé, jamais versé dans le
                        net à payer</template
                    >
                </p>
            </div>

            <p
                v-for="message in erreursDe(index)"
                :key="message"
                class="text-xs text-destructive"
            >
                {{ message }}
            </p>
        </div>
    </div>
</template>
