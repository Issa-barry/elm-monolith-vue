<script setup lang="ts">
/**
 * Bloc "Dérogation impayés" — extrait de Vehicules/Show.vue (implémentation d'origine) pour être
 * réutilisé tel quel sur la fiche client (cf. rapport du 28/08/2026 : même principe pour les deux
 * entités, DerogationImpayesService mutualise déjà la règle de cohérence côté serveur — ce
 * composant mutualise l'équivalent côté UI, jamais deux implémentations indépendantes).
 *
 * Toggle + plafond envoyés dans le même appel PATCH (cf. updateUrl). `seuil` est resynchronisé
 * sur les props à chaque succès/erreur, jamais laissé diverger durablement de l'état serveur.
 */
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { router } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    active: boolean;
    seuil: number | null;
    seuilGlobal: number;
    updateUrl: string;
    canUpdate: boolean;
    /** Ex: "ce véhicule" / "ce client" — utilisé dans le texte d'aide et les messages d'erreur. */
    entiteLabel: string;
}>();

const toast = useToast();

const derogationActive = ref(props.active);
const derogationMontant = ref<number | null>(props.seuil);
const derogationProcessing = ref(false);
const derogationMontantError = ref<string | null>(null);

watch(
    () => props.active,
    (v) => {
        derogationActive.value = v;
    },
);
watch(
    () => props.seuil,
    (v) => {
        derogationMontant.value = v;
    },
);

// Formatage "2 000 000" pendant la saisie — même pattern que #seuil-impayes-input
// (settings/Ventes.vue) : un input texte simple plutôt que PrimeVue InputNumber, dont le reste
// du projet n'active jamais le groupement de milliers (cf. CapacitesEditor.vue, TypeVehicules).
function formatMontant(val: number | null): string {
    return val !== null && val > 0
        ? new Intl.NumberFormat('fr-FR').format(val)
        : '';
}

const derogationMontantDisplay = ref(formatMontant(derogationMontant.value));
// Id fixe (jamais randomisé) : ce composant n'a jamais qu'une seule instance sur une page
// donnée (Vehicules/Show.vue OU Clients/Show.vue, jamais les deux ensemble) — un id stable
// est nécessaire pour les sélecteurs e2e (cf. vente-controle-impayes-flow.spec.ts, qui cible
// #seuil_derogation_impayes directement).
const inputId = 'seuil_derogation_impayes';

watch(derogationMontant, (val) => {
    if (document.activeElement?.id !== inputId) {
        derogationMontantDisplay.value = formatMontant(val);
    }
});

function onMontantInput(e: Event) {
    const input = e.target as HTMLInputElement;
    const raw = input.value.replace(/\D/g, '');
    derogationMontant.value = raw ? parseInt(raw, 10) : null;
    derogationMontantDisplay.value = formatMontant(derogationMontant.value);
    input.value = derogationMontantDisplay.value;
    derogationMontantError.value = null;
}

function onMontantFocus() {
    derogationMontantDisplay.value = formatMontant(derogationMontant.value);
}

function onMontantBlur() {
    derogationMontantDisplay.value = formatMontant(derogationMontant.value);
}

function saveDerogation(active: boolean) {
    if (derogationProcessing.value) return;

    if (
        active &&
        (derogationMontant.value === null || derogationMontant.value <= 0)
    ) {
        derogationMontantError.value =
            "Renseignez un plafond d'impayés autorisé pour activer la dérogation.";
        return;
    }
    derogationMontantError.value = null;
    derogationProcessing.value = true;

    router.patch(
        props.updateUrl,
        {
            derogation_impayes_autorisee: active,
            seuil_derogation_impayes: derogationMontant.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                toast.add({
                    severity: 'success',
                    summary: 'Dérogation impayés',
                    detail: active
                        ? 'Dérogation activée.'
                        : 'Dérogation désactivée.',
                    life: 4000,
                    group: 'top',
                });
            },
            onError: (errors) => {
                // Revert : l'état serveur (props) n'a pas changé, le Switch/champ local ne
                // doivent pas rester sur une valeur jamais persistée.
                derogationActive.value = props.active;
                derogationMontant.value = props.seuil;
                derogationMontantError.value =
                    errors.seuil_derogation_impayes ?? null;
                toast.add({
                    severity: 'error',
                    summary: 'Dérogation impayés',
                    detail:
                        errors.derogation_impayes_autorisee ??
                        errors.seuil_derogation_impayes ??
                        'Impossible de mettre à jour la dérogation.',
                    life: 5000,
                    group: 'top',
                });
            },
            onFinish: () => {
                derogationProcessing.value = false;
            },
        },
    );
}

function onToggleDerogation(checked: boolean) {
    derogationActive.value = checked;
    derogationMontantError.value = null;
    // Désactivation : action autonome, sans plafond à confirmer — persistée tout de suite.
    // Activation : reste en attente d'un plafond saisi, confirmé via "Enregistrer".
    if (!checked) {
        saveDerogation(false);
    }
}

function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}

// Rien à enregistrer tant que le plafond saisi n'a pas divergé de la dernière valeur
// persistée côté serveur (props.seuil, resynchronisé après chaque succès/erreur) : le bouton
// reste désactivé après une sauvegarde et tant qu'aucune modification n'est en attente.
const isMontantDirty = computed(() => derogationMontant.value !== props.seuil);
</script>

<template>
    <div class="rounded-lg border bg-background p-4">
        <div class="flex items-center justify-between gap-3">
            <p class="text-sm font-medium">Dérogation impayés</p>
            <Switch
                aria-label="Dérogation impayés"
                :model-value="derogationActive"
                :disabled="derogationProcessing || !canUpdate"
                @update:model-value="onToggleDerogation($event)"
            />
        </div>

        <template v-if="!derogationActive">
            <p class="mt-1.5 text-xs text-muted-foreground">
                Seuil standard appliqué : {{ formatGNF(seuilGlobal) }}
            </p>
        </template>
        <template v-else>
            <div class="mt-3 space-y-1.5">
                <label
                    :for="inputId"
                    class="text-xs font-medium text-muted-foreground"
                >
                    Montant maximum (GNF)
                </label>
                <div class="flex items-center gap-2">
                    <input
                        :id="inputId"
                        type="text"
                        inputmode="numeric"
                        :value="derogationMontantDisplay"
                        placeholder="Ex: 500 000"
                        class="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-sm"
                        :class="{
                            'border-destructive': derogationMontantError,
                        }"
                        :disabled="derogationProcessing || !canUpdate"
                        @input="onMontantInput"
                        @focus="onMontantFocus"
                        @blur="onMontantBlur"
                    />
                    <Button
                        size="sm"
                        :disabled="
                            derogationProcessing ||
                            !canUpdate ||
                            (!isMontantDirty && props.active)
                        "
                        @click="saveDerogation(true)"
                    >
                        {{
                            derogationProcessing
                                ? 'Enregistrement…'
                                : 'Enregistrer'
                        }}
                    </Button>
                </div>
                <p
                    v-if="derogationMontantError"
                    class="text-xs text-destructive"
                >
                    {{ derogationMontantError }}
                </p>
                <p v-else class="text-xs font-medium text-muted-foreground">
                    Maximum d'impayés autorisé pour {{ entiteLabel }}.
                </p>
            </div>
        </template>
    </div>
</template>
