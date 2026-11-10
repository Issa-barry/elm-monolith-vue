<script setup lang="ts">
/**
 * Modification de l'identité d'une fiche Personne ou Entreprise (ADR 0060, lot 7a). La fiche fait
 * foi pour toutes ses fonctions ; le serveur revérifie `contacts.update`, la visibilité de la fiche
 * et l'unicité du téléphone.
 */
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/vue3';
import { Loader2 } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import { computed, watch } from 'vue';

type Champ = { cle: string; label: string; requis?: boolean; pleine?: boolean };

const props = defineProps<{
    visible: boolean;
    type: 'personne' | 'entreprise';
    url: string;
    valeurs: Record<string, string | null | undefined>;
}>();

const emit = defineEmits<{ 'update:visible': [boolean] }>();

const CHAMPS_PERSONNE: Champ[] = [
    { cle: 'prenom', label: 'Prénom' },
    { cle: 'nom', label: 'Nom' },
    { cle: 'nom_complet', label: 'Nom complet (désignation)', pleine: true },
    { cle: 'surnom', label: 'Surnom' },
    { cle: 'telephone', label: 'Téléphone', requis: true },
    { cle: 'telephone_secondaire', label: 'Téléphone secondaire' },
    { cle: 'email', label: 'E-mail' },
    { cle: 'ville', label: 'Ville', requis: true },
    { cle: 'quartier', label: 'Quartier' },
    { cle: 'adresse', label: 'Adresse', pleine: true },
];

const CHAMPS_ENTREPRISE: Champ[] = [
    {
        cle: 'raison_sociale',
        label: 'Raison sociale',
        requis: true,
        pleine: true,
    },
    { cle: 'nom_commercial', label: 'Nom commercial' },
    { cle: 'identifiant_fiscal', label: 'NIF' },
    { cle: 'rccm', label: 'RCCM' },
    { cle: 'telephone', label: 'Téléphone' },
    { cle: 'telephone_secondaire', label: 'Téléphone secondaire' },
    { cle: 'email', label: 'E-mail' },
    { cle: 'ville', label: 'Ville' },
    { cle: 'quartier', label: 'Quartier' },
    { cle: 'adresse', label: 'Adresse', pleine: true },
];

const champs = computed(() =>
    props.type === 'personne' ? CHAMPS_PERSONNE : CHAMPS_ENTREPRISE,
);

const form = useForm<Record<string, string>>({});

watch(
    () => props.visible,
    (visible) => {
        if (!visible) return;
        for (const c of champs.value) {
            form[c.cle] = props.valeurs[c.cle] ?? '';
        }
        form.clearErrors();
    },
    { immediate: true },
);

function fermer(visible: boolean) {
    if (!form.processing) emit('update:visible', visible);
}

function enregistrer() {
    if (form.processing) return;
    form.patch(props.url, {
        preserveScroll: true,
        onSuccess: () => emit('update:visible', false),
    });
}

function erreur(cle: string): string | undefined {
    return (form.errors as Record<string, string | undefined>)[cle];
}
</script>

<template>
    <Dialog
        :visible="visible"
        modal
        header="Modifier les informations"
        :closable="!form.processing"
        :close-on-escape="!form.processing"
        :draggable="false"
        :style="{ width: 'min(40rem, 94vw)' }"
        @update:visible="fermer"
    >
        <p class="mb-4 text-sm text-muted-foreground">
            Ces informations valent pour toutes les fonctions de la fiche.
        </p>
        <div class="grid gap-4 sm:grid-cols-2">
            <div
                v-for="c in champs"
                :key="c.cle"
                :class="{ 'sm:col-span-2': c.pleine }"
            >
                <Label :for="`identite-${c.cle}`" class="mb-1.5 block"
                    >{{ c.label }}
                    <span v-if="c.requis" class="text-destructive"
                        >*</span
                    ></Label
                >
                <InputText
                    :id="`identite-${c.cle}`"
                    v-model="form[c.cle]"
                    class="w-full"
                    :class="{ 'p-invalid': erreur(c.cle) }"
                    :data-testid="`identite-${c.cle}`"
                />
                <p v-if="erreur(c.cle)" class="mt-1 text-xs text-destructive">
                    {{ erreur(c.cle) }}
                </p>
            </div>
        </div>
        <template #footer>
            <Button
                variant="outline"
                :disabled="form.processing"
                @click="fermer(false)"
            >
                Annuler
            </Button>
            <Button
                :disabled="form.processing"
                data-testid="identite-enregistrer"
                @click="enregistrer"
            >
                <Loader2
                    v-if="form.processing"
                    class="mr-1.5 h-4 w-4 animate-spin"
                />
                Enregistrer
            </Button>
        </template>
    </Dialog>
</template>
