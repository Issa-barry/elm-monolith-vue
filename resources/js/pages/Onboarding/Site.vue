<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Head, useForm } from '@inertiajs/vue3';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import { computed } from 'vue';

interface Option {
    value: string;
    label: string;
}

const props = defineProps<{
    types_suggeres: Option[];
    types_tous: Option[];
    organisation_nom?: string | null;
}>();

const form = useForm({
    type: null as string | null,
    ville: '',
    quartier: '',
});

// Les types suggérés (adaptés au domaine d'activité choisi à l'installation) sont mis en avant,
// mais jamais imposés — "Voir tous les types" reste disponible en cas de diversification.
const afficherTousLesTypes = computed(
    () => props.types_suggeres.length === props.types_tous.length,
);

function submit() {
    form.post('/onboarding/site');
}
</script>

<template>
    <Head title="Votre premier site" />

    <div class="flex min-h-dvh flex-col items-center bg-background px-4 py-10">
        <div class="w-full max-w-xl space-y-8">
            <div class="text-center">
                <h1 class="text-2xl font-semibold tracking-tight">
                    Configurons votre premier site
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    <template v-if="organisation_nom"
                        >Dernière étape pour {{ organisation_nom }} —
                    </template>
                    ce site devient automatiquement votre site principal.
                </p>
            </div>

            <form
                class="grid gap-5 rounded-xl border bg-card p-6 shadow-sm"
                @submit.prevent="submit"
            >
                <div class="grid gap-2">
                    <Label
                        >Type de site
                        <span class="text-destructive">*</span></Label
                    >
                    <Select
                        v-model="form.type"
                        :options="
                            afficherTousLesTypes ? types_tous : types_suggeres
                        "
                        option-label="label"
                        option-value="value"
                        placeholder="Sélectionner…"
                        class="w-full"
                        :class="{ 'p-invalid': form.errors.type }"
                        autofocus
                    />
                    <InputError :message="form.errors.type" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="site-ville"
                            >Ville
                            <span class="text-destructive">*</span></Label
                        >
                        <InputText
                            id="site-ville"
                            v-model="form.ville"
                            class="w-full"
                            :class="{ 'p-invalid': form.errors.ville }"
                        />
                        <InputError :message="form.errors.ville" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="site-quartier"
                            >Quartier
                            <span class="text-destructive">*</span></Label
                        >
                        <InputText
                            id="site-quartier"
                            v-model="form.quartier"
                            class="w-full"
                            :class="{ 'p-invalid': form.errors.quartier }"
                        />
                        <InputError :message="form.errors.quartier" />
                    </div>
                </div>

                <Button
                    type="submit"
                    class="w-full"
                    :disabled="form.processing"
                >
                    <Spinner v-if="form.processing" />
                    Terminer et accéder à ELM
                </Button>
            </form>
        </div>
    </div>
</template>
