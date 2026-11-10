<script setup lang="ts">
/** Bloc « Coordonnées » commun aux fiches Personne et Entreprise (lecture seule). */
import { formatPhoneDisplay } from '@/lib/utils';
import { computed } from 'vue';
import CopierTelephone from './CopierTelephone.vue';

const props = defineProps<{
    telephone: string | null;
    telephoneSecondaire: string | null;
    codePhonePays: string | null;
    email: string | null;
    pays: string | null;
    ville: string | null;
    quartier: string | null;
    adresse: string | null;
    latitude: string | null;
    longitude: string | null;
}>();

const telephone = computed(() =>
    formatPhoneDisplay(props.telephone, props.codePhonePays),
);
const secondaire = computed(() =>
    formatPhoneDisplay(props.telephoneSecondaire, props.codePhonePays),
);
const position = computed(() =>
    props.latitude && props.longitude
        ? `${props.latitude}, ${props.longitude}`
        : null,
);
</script>

<template>
    <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
        <div>
            <dt class="text-xs text-muted-foreground">Téléphone</dt>
            <dd class="mt-0.5 flex items-center gap-1 tabular-nums">
                {{ telephone || '—' }}
                <CopierTelephone
                    v-if="props.telephone"
                    :telephone="props.telephone"
                />
            </dd>
        </div>
        <div>
            <dt class="text-xs text-muted-foreground">Téléphone secondaire</dt>
            <dd class="mt-0.5 tabular-nums">{{ secondaire || '—' }}</dd>
        </div>
        <div>
            <dt class="text-xs text-muted-foreground">E-mail</dt>
            <dd class="mt-0.5 break-all">{{ email || '—' }}</dd>
        </div>
        <div>
            <dt class="text-xs text-muted-foreground">Pays</dt>
            <dd class="mt-0.5">{{ pays || '—' }}</dd>
        </div>
        <div>
            <dt class="text-xs text-muted-foreground">Ville</dt>
            <dd class="mt-0.5">{{ ville || '—' }}</dd>
        </div>
        <div>
            <dt class="text-xs text-muted-foreground">Quartier</dt>
            <dd class="mt-0.5">{{ quartier || '—' }}</dd>
        </div>
        <div class="sm:col-span-2">
            <dt class="text-xs text-muted-foreground">Adresse</dt>
            <dd class="mt-0.5">{{ adresse || '—' }}</dd>
        </div>
        <div v-if="position" class="sm:col-span-2">
            <dt class="text-xs text-muted-foreground">Position GPS</dt>
            <dd class="mt-0.5 tabular-nums">{{ position }}</dd>
        </div>
    </dl>
</template>
