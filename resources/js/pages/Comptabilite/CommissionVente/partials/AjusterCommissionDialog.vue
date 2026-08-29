<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { SlidersHorizontal } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import InputNumber from 'primevue/inputnumber';
import Select from 'primevue/select';
import Textarea from 'primevue/textarea';
import { computed, ref, watch } from 'vue';

interface MotifOption {
    value: string;
    label: string;
}

interface Props {
    visible: boolean;
    title: string;
    /** Somme des montants théoriques des parts CREEE concernées — jamais modifiée. */
    montantTheorique: number;
    motifs: MotifOption[];
    processing?: boolean;
    errors?: Record<string, string>;
}

const props = withDefaults(defineProps<Props>(), {
    processing: false,
    errors: () => ({}),
});

const emit = defineEmits<{
    (e: 'update:visible', val: boolean): void;
    (
        e: 'submit',
        payload: { montant: number; motif: string; commentaire: string | null },
    ): void;
}>();

const localVisible = computed({
    get: () => props.visible,
    set: (val) => emit('update:visible', val),
});

const ajustement = ref(0);
const motif = ref<string | null>(null);
const commentaire = ref('');

watch(
    () => props.visible,
    (open) => {
        if (open) {
            ajustement.value = 0;
            motif.value = null;
            commentaire.value = '';
        }
    },
);

const montantRetenu = computed(() =>
    Math.max(0, Math.round(props.montantTheorique + (ajustement.value ?? 0))),
);

function fmt(n: number) {
    return new Intl.NumberFormat('fr-FR').format(Math.round(n)) + ' GNF';
}

function close() {
    emit('update:visible', false);
}

function handleSubmit() {
    if (!motif.value) return;
    emit('submit', {
        montant: montantRetenu.value,
        motif: motif.value,
        commentaire: commentaire.value.trim() || null,
    });
}
</script>

<template>
    <Dialog
        v-model:visible="localVisible"
        modal
        :header="title"
        :style="{ width: '440px' }"
        :draggable="false"
    >
        <div class="space-y-4 py-2">
            <div class="flex items-center justify-between text-sm">
                <span class="text-muted-foreground">Montant calculé</span>
                <span class="font-medium tabular-nums">{{
                    fmt(montantTheorique)
                }}</span>
            </div>

            <div>
                <Label class="mb-1.5 block text-sm">Ajustement (GNF)</Label>
                <InputNumber
                    v-model="ajustement"
                    class="w-full"
                    input-class="w-full"
                    :use-grouping="true"
                    :allow-empty="false"
                />
                <p
                    v-if="errors?.montant"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ errors.montant }}
                </p>
            </div>

            <div
                class="flex items-center justify-between rounded-lg border bg-muted/40 px-3 py-2.5 text-sm"
            >
                <span class="text-muted-foreground">Montant retenu</span>
                <span class="font-semibold tabular-nums">{{
                    fmt(montantRetenu)
                }}</span>
            </div>

            <div>
                <Label class="mb-1.5 block text-sm">Motif <span class="text-destructive">*</span></Label>
                <Select
                    v-model="motif"
                    :options="motifs"
                    option-label="label"
                    option-value="value"
                    placeholder="Sélectionner un motif"
                    class="w-full"
                />
                <p v-if="errors?.motif" class="mt-1 text-xs text-destructive">
                    {{ errors.motif }}
                </p>
            </div>

            <div>
                <Label class="mb-1.5 block text-sm">Commentaire (optionnel)</Label>
                <Textarea
                    v-model="commentaire"
                    rows="2"
                    class="w-full"
                    maxlength="500"
                />
            </div>
        </div>

        <template #footer>
            <Button variant="outline" :disabled="processing" @click="close">
                Annuler
            </Button>
            <Button :disabled="processing || !motif" @click="handleSubmit">
                <SlidersHorizontal v-if="!processing" class="mr-1.5 h-4 w-4" />
                <span
                    v-else
                    class="mr-2 inline-block h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent"
                />
                Ajuster
            </Button>
        </template>
    </Dialog>
</template>
