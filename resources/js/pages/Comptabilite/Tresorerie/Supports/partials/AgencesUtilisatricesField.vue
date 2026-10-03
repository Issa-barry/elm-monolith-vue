<script setup lang="ts">
import { Label } from '@/components/ui/label';
import { computed } from 'vue';

// Agences qui utilisent un compte commun (ADR 0016). L'agence détentrice l'utilise toujours : elle
// est cochée et non modifiable ici, le serveur l'ajoute de toute façon.
const props = defineProps<{
    sites: { id: string; nom: string }[];
    detentriceId: string;
    error?: string;
    idPrefix: string;
}>();

const selection = defineModel<string[]>({ required: true });

const autres = computed(() =>
    props.sites.filter((s) => s.id !== props.detentriceId),
);
const detentrice = computed(() =>
    props.sites.find((s) => s.id === props.detentriceId),
);

function basculer(id: string, coche: boolean) {
    selection.value = coche
        ? [...selection.value.filter((s) => s !== id), id]
        : selection.value.filter((s) => s !== id);
}
</script>

<template>
    <div data-testid="agences-utilisatrices">
        <Label class="mb-1.5 block text-xs font-medium">
            Agences qui utilisent ce compte
            <span class="text-destructive">*</span>
        </Label>
        <div class="grid gap-1.5 rounded-lg border p-3 sm:grid-cols-2">
            <label
                v-if="detentrice"
                class="flex items-center gap-2 text-sm text-muted-foreground"
            >
                <input type="checkbox" checked disabled class="h-4 w-4" />
                {{ detentrice.nom }} (détentrice)
            </label>
            <label
                v-for="s in autres"
                :key="s.id"
                :for="`${idPrefix}-${s.id}`"
                class="flex cursor-pointer items-center gap-2 text-sm"
            >
                <input
                    :id="`${idPrefix}-${s.id}`"
                    type="checkbox"
                    class="h-4 w-4"
                    :checked="selection.includes(s.id)"
                    :data-testid="`agence-utilisatrice-${s.id}`"
                    @change="
                        basculer(
                            s.id,
                            ($event.target as HTMLInputElement).checked,
                        )
                    "
                />
                {{ s.nom }}
            </label>
        </div>
        <p class="mt-1 text-xs text-muted-foreground">
            L'argent reçu sur ce compte reste détenu par l'agence détentrice,
            quelle que soit l'agence qui encaisse.
        </p>
        <p v-if="error" class="mt-1 text-xs text-destructive">{{ error }}</p>
    </div>
</template>
