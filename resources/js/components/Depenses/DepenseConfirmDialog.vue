<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Send } from 'lucide-vue-next';

interface TypeInfo {
    libelle: string;
    categorie_label: string;
}

defineProps<{
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
        <DialogContent
            class="flex max-h-[90dvh] flex-col max-sm:inset-x-0 max-sm:top-auto max-sm:bottom-0 max-sm:w-full max-sm:max-w-none max-sm:translate-x-0 max-sm:translate-y-0 max-sm:rounded-t-2xl max-sm:rounded-b-none max-sm:pb-[max(1rem,env(safe-area-inset-bottom))] sm:max-w-md max-sm:[&>button]:flex max-sm:[&>button]:size-11 max-sm:[&>button]:items-center max-sm:[&>button]:justify-center"
        >
            <DialogHeader class="shrink-0 pr-10 text-left">
                <DialogTitle class="flex items-center gap-2">
                    <Send class="h-4 w-4" />
                    Confirmer la soumission
                </DialogTitle>
            </DialogHeader>

            <div
                class="min-h-0 overflow-y-auto py-2 [overflow-wrap:anywhere] [&_dd]:min-w-0"
            >
                <DialogDescription class="mb-4 text-sm text-muted-foreground">
                    Vérifiez les informations avant de soumettre pour
                    validation.
                </DialogDescription>

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

            <DialogFooter class="shrink-0 flex-col gap-2 sm:flex-row">
                <Button
                    variant="outline"
                    class="max-sm:min-h-11"
                    :disabled="processing"
                    @click="emit('cancel')"
                >
                    Retour à l'édition
                </Button>
                <Button
                    class="max-sm:min-h-11"
                    :disabled="processing"
                    @click="emit('confirm')"
                >
                    <Send class="mr-1.5 h-3.5 w-3.5" />
                    <span v-if="processing">Envoi en cours…</span>
                    <span v-else>Confirmer l'envoi</span>
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
