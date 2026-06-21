<script setup lang="ts">
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Truck } from 'lucide-vue-next';
import { ref, watch } from 'vue';

interface VehiculeDetail {
    nom: string;
    immatriculation: string;
    type: string;
    proprietaire: string;
    site: string;
    categorie: string;
}

const props = defineProps<{
    visible: boolean;
    vehiculeId: string | null;
}>();

const emit = defineEmits<{
    'update:visible': [value: boolean];
}>();

const detail = ref<VehiculeDetail | null>(null);
const loading = ref(false);
const error = ref(false);

watch(
    () => props.visible,
    async (open) => {
        if (!open || !props.vehiculeId) return;
        loading.value = true;
        error.value = false;
        detail.value = null;
        try {
            const res = await fetch(
                `/depenses/vehicule-detail?id=${props.vehiculeId}`,
                { headers: { Accept: 'application/json' } },
            );
            if (!res.ok) throw new Error();
            detail.value = await res.json();
        } catch {
            error.value = true;
        } finally {
            loading.value = false;
        }
    },
);
</script>

<template>
    <Dialog
        :open="visible"
        @update:open="(v) => emit('update:visible', v)"
    >
        <DialogContent class="sm:max-w-sm">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <Truck class="h-4 w-4 text-muted-foreground" />
                    Véhicule
                </DialogTitle>
            </DialogHeader>

            <div class="py-2">
                <div
                    v-if="loading"
                    class="flex items-center justify-center py-8 text-sm text-muted-foreground"
                >
                    Chargement…
                </div>

                <div
                    v-else-if="error"
                    class="rounded-lg border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive"
                >
                    Impossible de charger les informations.
                </div>

                <dl v-else-if="detail" class="divide-y text-sm">
                    <div class="grid grid-cols-3 gap-1 py-2">
                        <dt class="text-muted-foreground">Nom</dt>
                        <dd class="col-span-2 font-medium">{{ detail.nom }}</dd>
                    </div>

                    <div class="grid grid-cols-3 gap-1 py-2">
                        <dt class="text-muted-foreground">Immatriculation</dt>
                        <dd class="col-span-2 font-mono">
                            {{ detail.immatriculation }}
                        </dd>
                    </div>

                    <div class="grid grid-cols-3 gap-1 py-2">
                        <dt class="text-muted-foreground">Type</dt>
                        <dd class="col-span-2">{{ detail.type }}</dd>
                    </div>

                    <div class="grid grid-cols-3 gap-1 py-2">
                        <dt class="text-muted-foreground">Propriétaire</dt>
                        <dd class="col-span-2">{{ detail.proprietaire }}</dd>
                    </div>

                    <div class="grid grid-cols-3 gap-1 py-2">
                        <dt class="text-muted-foreground">Site</dt>
                        <dd class="col-span-2">{{ detail.site }}</dd>
                    </div>
                </dl>
            </div>
        </DialogContent>
    </Dialog>
</template>
