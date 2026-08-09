<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Image, Star, Trash2, Upload } from 'lucide-vue-next';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed, ref } from 'vue';

export interface Media {
    id: string;
    url: string;
    thumb_url: string | null;
    is_primary: boolean;
    position: number;
}

const props = defineProps<{
    produitId: string;
    medias: Media[];
    maxPhotos?: number;
}>();

const toast = useToast();
const confirm = useConfirm();

const form = useForm<{ images: File[] }>({ images: [] });
const fileInput = ref<HTMLInputElement | null>(null);

const restant = computed(() =>
    props.maxPhotos ? props.maxPhotos - props.medias.length : null,
);

function onFilesSelected(e: Event) {
    const files = Array.from((e.target as HTMLInputElement).files ?? []);
    if (files.length === 0) return;

    form.images = files;
    form.post(`/backoffice/produits/${props.produitId}/medias`, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            form.reset();
            if (fileInput.value) fileInput.value.value = '';
            toast.add({
                severity: 'success',
                summary: 'Photo(s) ajoutée(s)',
                life: 3000,
            });
        },
        onError: () => {
            const message = Object.values(form.errors)[0];
            if (message) {
                toast.add({
                    severity: 'error',
                    summary: 'Import impossible',
                    detail: message,
                    life: 5000,
                });
            }
        },
    });
}

function definirPrincipale(media: Media) {
    router.patch(
        `/backoffice/produits/${props.produitId}/medias/${media.id}/principale`,
        {},
        { preserveScroll: true },
    );
}

function supprimer(media: Media) {
    confirm.require({
        message: media.is_primary
            ? "Supprimer cette photo ? Elle est actuellement l'image principale — une autre sera promue automatiquement. Les variantes qui l'utilisaient reprendront l'image principale du produit."
            : "Supprimer cette photo ? Les variantes qui l'utilisaient reprendront l'image principale du produit.",
        header: 'Confirmer la suppression',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Annuler',
        acceptLabel: 'Supprimer',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(
                `/backoffice/produits/${props.produitId}/medias/${media.id}`,
                { preserveScroll: true },
            );
        },
    });
}
</script>

<template>
    <div>
        <div class="mb-3 flex items-center justify-between">
            <p class="text-xs text-muted-foreground">
                {{ medias.length }}<template v-if="maxPhotos"> / {{ maxPhotos }}</template>
                photo(s)
            </p>
            <label
                class="cursor-pointer"
                :class="{ 'pointer-events-none opacity-50': restant === 0 }"
            >
                <input
                    ref="fileInput"
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    multiple
                    class="sr-only"
                    :disabled="restant === 0"
                    @change="onFilesSelected"
                />
                <span
                    class="inline-flex items-center gap-1.5 rounded-md border bg-background px-2.5 py-1.5 text-xs font-medium shadow-sm transition-colors hover:bg-muted"
                >
                    <Upload class="h-3.5 w-3.5" />
                    Ajouter des photos
                </span>
            </label>
        </div>

        <div v-if="medias.length === 0" class="rounded-lg border border-dashed p-6 text-center">
            <Image class="mx-auto h-8 w-8 text-muted-foreground/40" />
            <p class="mt-2 text-xs text-muted-foreground">Aucune photo pour ce produit.</p>
        </div>

        <div v-else class="grid grid-cols-4 gap-2 sm:grid-cols-6">
            <div
                v-for="m in medias"
                :key="m.id"
                class="group relative aspect-square overflow-hidden rounded-lg border"
                :class="{ 'ring-2 ring-primary': m.is_primary }"
            >
                <img :src="m.thumb_url ?? m.url" class="h-full w-full object-cover" alt="" />
                <div
                    class="absolute inset-0 flex items-center justify-center gap-1 bg-black/50 opacity-0 transition-opacity group-hover:opacity-100"
                >
                    <button
                        type="button"
                        :title="m.is_primary ? 'Image principale' : 'Définir comme principale'"
                        class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-white/90 text-amber-500 hover:bg-white"
                        @click="definirPrincipale(m)"
                    >
                        <Star class="h-3.5 w-3.5" :fill="m.is_primary ? 'currentColor' : 'none'" />
                    </button>
                    <button
                        type="button"
                        title="Supprimer"
                        class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-white/90 text-destructive hover:bg-white"
                        @click="supprimer(m)"
                    >
                        <Trash2 class="h-3.5 w-3.5" />
                    </button>
                </div>
                <span
                    v-if="m.is_primary"
                    class="absolute top-1 left-1 rounded bg-primary px-1.5 py-0.5 text-[10px] font-medium text-primary-foreground"
                >
                    Principale
                </span>
            </div>
        </div>
    </div>
</template>
