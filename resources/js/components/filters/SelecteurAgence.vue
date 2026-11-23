<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import Select from 'primevue/select';
import { computed, ref, watch } from 'vue';

/**
 * Filtre agence d'un tableau de bord (Stat-Commissions) : une seule agence, ou « Toutes les
 * agences » — celles du périmètre de l'utilisateur, pas forcément toute l'organisation. Les choix
 * viennent du serveur, qui refuse toute agence hors du périmètre ; le choix recharge la page avec
 * `site_id`, en conservant les autres filtres (période).
 */
const props = defineProps<{
    /** Adresse de la page, rechargée avec l'agence choisie. */
    url: string;
    agences: { value: string; label: string }[];
    /** Agence affichée ; null = toutes les agences du périmètre. */
    agence: string | null;
    /** Autres filtres de la page à conserver (ex. période). */
    parametres?: Record<string, string>;
}>();

// Valeur de « Toutes les agences » dans la liste, jamais envoyée au serveur (pas de `site_id`).
// Pas de chaîne vide : PrimeVue la traite comme « aucun choix » et n'afficherait pas le libellé.
const TOUTES = 'toutes';

const options = computed(() => [
    { value: TOUTES, label: 'Toutes les agences' },
    ...props.agences,
]);

const choix = ref(props.agence ?? TOUTES);
const chargement = ref(false);
const erreur = ref<string | null>(null);

function onChoix(valeur: string | null): void {
    const agence = valeur ?? TOUTES;
    if (agence === (props.agence ?? TOUTES)) return;
    choix.value = agence;

    router.get(
        props.url,
        {
            ...props.parametres,
            ...(agence !== TOUTES ? { site_id: agence } : {}),
        },
        {
            preserveState: true,
            preserveScroll: true,
            onStart: () => {
                chargement.value = true;
                erreur.value = null;
            },
            onFinish: () => (chargement.value = false),
            onError: (erreurs) => {
                erreur.value = erreurs.site_id ?? 'Agence invalide.';
                choix.value = props.agence ?? TOUTES;
            },
        },
    );
}

// L'agence active est celle renvoyée par le serveur.
watch(
    () => props.agence,
    (agence) => (choix.value = agence ?? TOUTES),
);
</script>

<template>
    <div
        class="flex max-w-full flex-col items-stretch gap-1.5 sm:items-end"
        data-testid="selecteur-agence"
    >
        <Select
            :model-value="choix"
            :options="options"
            option-label="label"
            option-value="value"
            aria-label="Agence"
            :disabled="chargement"
            :loading="chargement"
            class="min-w-56 text-sm"
            data-testid="agence-choix"
            @update:model-value="onChoix"
        />
        <p
            v-if="erreur"
            role="alert"
            class="text-xs text-destructive"
            data-testid="agence-erreur"
        >
            {{ erreur }}
        </p>
    </div>
</template>
