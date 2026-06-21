<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Send } from 'lucide-vue-next';

interface TypeInfo {
    libelle: string;
    categorie_label: string;
}

const props = defineProps<{
    visible: boolean;
    processing: boolean;
    concerneLabel: string | null;
    type: TypeInfo | null;
    vehiculeNom: string | null;
    vehiculeImmatriculation: string | null;
    montant: number | '';
    siteNom: string | null;
    commentaire: string;
}>();

const emit = defineEmits<{
    'update:visible': [value: boolean];
    confirm: [];
    cancel: [];
}>();

function fmt(n: number | '') {
    if (n === '') return '—';
    return (
        Number(n).toLocaleString('fr-FR', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0,
        }) + ' GNF'
    );
}
</script>

<template>
    <Dialog
        :open="visible"
        @update:open="
            (v) => {
                if (!v && !processing) emit('update:visible', false);
            }
        "
    >
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <Send class="h-4 w-4" />
                    Confirmer la soumission
                </DialogTitle>
            </DialogHeader>

            <div class="py-2">
                <p class="mb-4 text-sm text-muted-foreground">
                    Vérifiez les informations avant de soumettre pour
                    validation.
                </p>

                <dl class="divide-y rounded-lg border text-sm">
                    <div class="grid grid-cols-3 gap-1 px-3 py-2.5">
                        <dt class="text-muted-foreground">Concerné</dt>
                        <dd class="col-span-2 font-medium">
                            {{ concerneLabel ?? '—' }}
                        </dd>
                    </div>

                    <div class="grid grid-cols-3 gap-1 px-3 py-2.5">
                        <dt class="text-muted-foreground">Type</dt>
                        <dd class="col-span-2">
                            <span class="font-medium">{{
                                type?.libelle ?? '—'
                            }}</span>
                            <span
                                v-if="type?.categorie_label"
                                class="ml-1.5 text-xs text-muted-foreground"
                                >({{ type.categorie_label }})</span
                            >
                        </dd>
                    </div>

                    <div
                        v-if="vehiculeNom"
                        class="grid grid-cols-3 gap-1 px-3 py-2.5"
                    >
                        <dt class="text-muted-foreground">Véhicule</dt>
                        <dd class="col-span-2">
                            {{ vehiculeNom }}
                            <span
                                v-if="vehiculeImmatriculation"
                                class="ml-1 font-mono text-xs text-muted-foreground"
                                >{{ vehiculeImmatriculation }}</span
                            >
                        </dd>
                    </div>

                    <div class="grid grid-cols-3 gap-1 px-3 py-2.5">
                        <dt class="text-muted-foreground">Montant</dt>
                        <dd class="col-span-2 font-semibold tabular-nums">
                            {{ fmt(montant) }}
                        </dd>
                    </div>

                    <div
                        v-if="siteNom"
                        class="grid grid-cols-3 gap-1 px-3 py-2.5"
                    >
                        <dt class="text-muted-foreground">Site</dt>
                        <dd class="col-span-2">{{ siteNom }}</dd>
                    </div>

                    <div
                        v-if="commentaire"
                        class="grid grid-cols-3 gap-1 px-3 py-2.5"
                    >
                        <dt class="text-muted-foreground">Commentaire</dt>
                        <dd class="col-span-2 whitespace-pre-line">
                            {{ commentaire }}
                        </dd>
                    </div>
                </dl>
            </div>

            <DialogFooter>
                <Button
                    variant="outline"
                    :disabled="processing"
                    @click="emit('cancel')"
                >
                    Retour à l'édition
                </Button>
                <Button :disabled="processing" @click="emit('confirm')">
                    <Send class="mr-1.5 h-3.5 w-3.5" />
                    <span v-if="processing">Envoi en cours…</span>
                    <span v-else>Confirmer l'envoi</span>
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
