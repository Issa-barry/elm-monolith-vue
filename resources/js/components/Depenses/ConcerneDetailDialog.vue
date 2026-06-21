<script setup lang="ts">
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Phone, User } from 'lucide-vue-next';
import { ref, watch } from 'vue';

interface ConcerneDetail {
    type: 'proprietaire' | 'livreur' | 'employe';
    nom: string;
    telephone: string;
    adresse?: string;
    equipe?: string;
    poste?: string;
    site: string;
}

const props = defineProps<{
    visible: boolean;
    beneficiaireType: string | null;
    beneficiaireId: string | null;
}>();

const emit = defineEmits<{
    'update:visible': [value: boolean];
}>();

const detail = ref<ConcerneDetail | null>(null);
const loading = ref(false);
const error = ref(false);

watch(
    () => props.visible,
    async (open) => {
        if (!open || !props.beneficiaireType || !props.beneficiaireId) return;
        loading.value = true;
        error.value = false;
        detail.value = null;
        try {
            const params = new URLSearchParams({
                type: props.beneficiaireType,
                id: props.beneficiaireId,
            });
            const res = await fetch(`/depenses/concerne-detail?${params}`, {
                headers: { Accept: 'application/json' },
            });
            if (!res.ok) throw new Error();
            detail.value = await res.json();
        } catch {
            error.value = true;
        } finally {
            loading.value = false;
        }
    },
);

const typeLabel: Record<string, string> = {
    proprietaire: 'Propriétaire',
    livreur: 'Livreur',
    employe: 'Salarié',
};
</script>

<template>
    <Dialog
        :open="visible"
        @update:open="(v) => emit('update:visible', v)"
    >
        <DialogContent class="sm:max-w-sm">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <User class="h-4 w-4 text-muted-foreground" />
                    {{
                        detail
                            ? (typeLabel[detail.type] ?? 'Concerné')
                            : 'Détail du concerné'
                    }}
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
                        <dt class="flex items-center gap-1 text-muted-foreground">
                            <Phone class="h-3 w-3" /> Téléphone
                        </dt>
                        <dd class="col-span-2">{{ detail.telephone }}</dd>
                    </div>

                    <div
                        v-if="detail.type === 'proprietaire' && detail.adresse"
                        class="grid grid-cols-3 gap-1 py-2"
                    >
                        <dt class="text-muted-foreground">Adresse</dt>
                        <dd class="col-span-2">{{ detail.adresse }}</dd>
                    </div>

                    <div
                        v-if="detail.type === 'livreur' && detail.equipe"
                        class="grid grid-cols-3 gap-1 py-2"
                    >
                        <dt class="text-muted-foreground">Équipe</dt>
                        <dd class="col-span-2">{{ detail.equipe }}</dd>
                    </div>

                    <div
                        v-if="detail.type === 'employe' && detail.poste"
                        class="grid grid-cols-3 gap-1 py-2"
                    >
                        <dt class="text-muted-foreground">Poste</dt>
                        <dd class="col-span-2">{{ detail.poste }}</dd>
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
