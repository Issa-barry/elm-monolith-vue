<script setup lang="ts">
import ClientLayout from '@/layouts/ClientLayout.vue';
import type { ActorPayload, VehiculeOption } from '@/types/client-space';
import { Head } from '@inertiajs/vue3';
import { Car, X } from 'lucide-vue-next';
import { onMounted, onUnmounted, ref } from 'vue';

defineProps<{
    actor: ActorPayload;
    owner_vehicules: VehiculeOption[];
}>();

const lightboxUrl = ref<string | null>(null);
const lightboxAlt = ref('');

function openLightbox(url: string, alt: string) {
    lightboxUrl.value = url;
    lightboxAlt.value = alt;
}
function closeLightbox() {
    lightboxUrl.value = null;
}

function onKeydown(e: KeyboardEvent) {
    if (e.key === 'Escape') closeLightbox();
}
onMounted(() => document.addEventListener('keydown', onKeydown));
onUnmounted(() => document.removeEventListener('keydown', onKeydown));
</script>

<template>
    <ClientLayout>
        <Head title="Mon espace - Vehicules" />

        <div class="space-y-6">
            <div>
                <h1 class="text-2xl font-semibold">Mes vehicules</h1>
                <p class="mt-1 text-muted-foreground">
                    Liste des vehicules rattaches a votre profil proprietaire.
                </p>
            </div>

            <div class="rounded-xl border border-border bg-card p-5">
                <p class="text-sm text-muted-foreground">
                    Total vehicules proprietaire: {{ owner_vehicules.length }}
                </p>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{
                        actor.organization_name
                            ? `Organisation: ${actor.organization_name}`
                            : 'Organisation non rattachee'
                    }}
                </p>
            </div>

            <div
                v-if="!actor.proprietaire_id"
                class="rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-700 dark:text-amber-300"
            >
                Votre compte n'est pas lie a un profil proprietaire.
            </div>

            <div v-else class="rounded-xl border border-border bg-card p-5">
                <div
                    v-if="owner_vehicules.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    Aucun vehicule proprietaire trouve pour le moment.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr
                                class="border-b border-border text-left text-muted-foreground"
                            >
                                <th class="py-2 pr-4 font-medium">Photo</th>
                                <th class="py-2 pr-4 font-medium">
                                    Nom vehicule
                                </th>
                                <th class="py-2 pr-4 font-medium">
                                    Immatriculation
                                </th>
                                <th class="py-2 pr-4 font-medium">Type</th>
                                <th class="py-2 pr-0 font-medium">
                                    Capacite (packs)
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="vehicule in owner_vehicules"
                                :key="vehicule.id"
                                class="border-b border-border/70"
                            >
                                <td class="py-2 pr-4">
                                    <div
                                        class="h-10 w-10 overflow-hidden rounded-lg border bg-muted"
                                        :class="
                                            vehicule.photo_url
                                                ? 'cursor-zoom-in'
                                                : ''
                                        "
                                        @click="
                                            vehicule.photo_url &&
                                            openLightbox(
                                                vehicule.photo_url,
                                                vehicule.nom_vehicule,
                                            )
                                        "
                                    >
                                        <img
                                            v-if="vehicule.photo_url"
                                            :src="vehicule.photo_url"
                                            :alt="vehicule.nom_vehicule"
                                            class="h-full w-full object-cover"
                                        />
                                        <div
                                            v-else
                                            class="flex h-full w-full items-center justify-center"
                                        >
                                            <Car
                                                class="h-5 w-5 text-muted-foreground/40"
                                            />
                                        </div>
                                    </div>
                                </td>
                                <td class="py-2 pr-4">
                                    {{ vehicule.nom_vehicule }}
                                </td>
                                <td class="py-2 pr-4">
                                    {{ vehicule.immatriculation ?? '-' }}
                                </td>
                                <td class="py-2 pr-4">
                                    {{ vehicule.type_label }}
                                </td>
                                <td class="py-2 pr-0">
                                    {{ vehicule.capacite_packs ?? '-' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Lightbox -->
        <Teleport to="body">
            <div
                v-if="lightboxUrl"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4"
                @click.self="closeLightbox"
            >
                <div class="relative max-h-full max-w-3xl">
                    <button
                        type="button"
                        class="absolute -top-3 -right-3 flex h-8 w-8 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/20"
                        @click="closeLightbox"
                    >
                        <X class="h-5 w-5" />
                    </button>
                    <img
                        :src="lightboxUrl"
                        :alt="lightboxAlt"
                        class="max-h-[80vh] max-w-full rounded-xl object-contain shadow-2xl"
                    />
                    <p class="mt-2 text-center text-sm text-white/70">
                        {{ lightboxAlt }}
                    </p>
                </div>
            </div>
        </Teleport>
    </ClientLayout>
</template>
