<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { AlertCircle, ExternalLink, RefreshCw } from 'lucide-vue-next';
import type { PartageCommissionDetails } from './partage-commission';

defineProps<{
    details: PartageCommissionDetails | null;
    equipeUrl: string | null;
    checking: boolean;
    checkFailed: boolean;
}>();

defineEmits<{ retry: [] }>();
</script>

<template>
    <section
        class="mt-3 rounded-lg border border-destructive/25 bg-destructive/5 p-3 text-sm"
        data-testid="partage-commission-bloquant"
        aria-label="Partage de commission à corriger"
        aria-live="polite"
    >
        <div class="flex items-start gap-2">
            <AlertCircle
                class="mt-0.5 size-4 shrink-0 text-destructive"
                aria-hidden="true"
            />
            <div class="min-w-0 flex-1">
                <h3 class="font-semibold text-foreground">
                    Partage de commission à corriger
                </h3>
                <p class="mt-1 text-xs leading-relaxed text-muted-foreground">
                    La commande ne peut pas être enregistrée avec ce partage.
                    <span v-if="details" class="font-semibold text-foreground"
                        >{{ details.vehicule_nom }} ·
                        {{ details.processus_libelle }}</span
                    >
                </p>
            </div>
        </div>

        <div class="mt-2 flex flex-wrap items-center gap-2">
            <Button
                v-if="equipeUrl"
                as-child
                variant="outline"
                size="sm"
                class="h-8 text-xs"
            >
                <a
                    :href="equipeUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    aria-label="Voir l’équipe (nouvel onglet)"
                >
                    Voir l’équipe
                    <ExternalLink class="size-3.5" aria-hidden="true" />
                </a>
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                class="h-8 text-xs"
                :disabled="checking"
                @click="$emit('retry')"
            >
                <RefreshCw
                    class="size-3.5"
                    :class="{ 'animate-spin': checking }"
                    aria-hidden="true"
                />
                {{ checking ? 'Vérification…' : 'Vérifier à nouveau' }}
            </Button>
        </div>
        <p v-if="checkFailed" class="mt-2 text-xs text-destructive">
            Vérification indisponible. Réessayez dans un instant.
        </p>
    </section>
</template>
