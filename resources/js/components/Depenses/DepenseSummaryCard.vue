<script setup lang="ts">
import { AlertCircle, CheckCircle, Info, ReceiptText } from 'lucide-vue-next';
import { computed } from 'vue';

type VehiculeContext = 'interne' | 'externe_avec_proprietaire' | 'externe_sans_proprietaire' | null;

interface Vehicule {
    nom_vehicule: string;
    immatriculation: string;
    categorie: string;
    site_nom: string | null;
    proprietaire_nom: string | null;
    has_proprietaire: boolean;
}

interface TypeInfo {
    libelle: string;
    impact_message: string;
}

const props = defineProps<{
    categorie: string | null;
    categorieLabel: string | null;
    type: TypeInfo | null;
    vehicule: Vehicule | null;
    vehiculeContext: VehiculeContext;
    beneficiaireLabel: string | null;
    siteNom: string | null;
    montant: number | string;
    commentaire: string;
}>();

const hasContent = computed(() => !!props.type);

const categorieBadge = computed(() => {
    const map: Record<string, string> = {
        vehicule: 'bg-emerald-100 text-emerald-700',
        proprietaire: 'bg-purple-100 text-purple-700',
        livreur: 'bg-amber-100 text-amber-700',
        employe: 'bg-blue-100 text-blue-700',
        interne: 'bg-slate-100 text-slate-600',
    };
    return map[props.categorie ?? ''] ?? 'bg-muted text-muted-foreground';
});

const concerneReel = computed<string | null>(() => {
    if (props.vehiculeContext === 'interne') return 'Agence ELM';
    if (props.vehiculeContext === 'externe_avec_proprietaire') return props.vehicule?.proprietaire_nom ?? null;
    if (props.vehiculeContext === 'externe_sans_proprietaire') return null;
    if (props.categorie === 'interne') return props.siteNom ? `Agence — ${props.siteNom}` : 'Agence ELM';
    return props.beneficiaireLabel;
});

const impactConfig = computed(() => {
    if (props.vehiculeContext === 'interne') {
        return {
            icon: 'info' as const,
            cls: 'bg-blue-50 border-blue-200 text-blue-800',
            text: `Véhicule interne ELM${props.vehicule?.site_nom ? ` — site ${props.vehicule.site_nom}` : ''}. La dépense est comptabilisée comme charge entreprise.`,
        };
    }
    if (props.vehiculeContext === 'externe_avec_proprietaire') {
        return {
            icon: 'check' as const,
            cls: 'bg-emerald-50 border-emerald-200 text-emerald-800',
            text: `Cette dépense sera déduite de la prochaine commission de ${props.vehicule?.proprietaire_nom}.`,
        };
    }
    if (props.vehiculeContext === 'externe_sans_proprietaire') {
        return {
            icon: 'alert' as const,
            cls: 'bg-amber-50 border-amber-300 text-amber-800',
            text: "Ce véhicule n'a pas de propriétaire enregistré. Corrigez la fiche véhicule pour pouvoir valider.",
        };
    }
    if (props.type?.impact_message) {
        return {
            icon: 'info' as const,
            cls: 'bg-muted/60 border-border text-foreground',
            text: props.type.impact_message,
        };
    }
    return null;
});

const montantFormate = computed<string | null>(() => {
    const n = typeof props.montant === 'number' ? props.montant : parseFloat(String(props.montant));
    if (!n || isNaN(n) || n <= 0) return null;
    return new Intl.NumberFormat('fr-FR').format(n) + ' GNF';
});
</script>

<template>
    <div class="rounded-xl border bg-card shadow-sm">
        <!-- Header -->
        <div class="flex items-center gap-2 border-b px-4 py-3">
            <ReceiptText class="h-4 w-4 text-muted-foreground" />
            <span class="text-sm font-semibold">Récapitulatif</span>
        </div>

        <!-- Empty state -->
        <div v-if="!hasContent" class="px-4 py-10 text-center">
            <div class="mx-auto mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-muted">
                <ReceiptText class="h-5 w-5 text-muted-foreground" />
            </div>
            <p class="text-sm text-muted-foreground">Sélectionnez une catégorie et un type de dépense pour afficher le récapitulatif.</p>
        </div>

        <!-- Content -->
        <div v-else class="divide-y divide-border">

            <!-- Catégorie + Type -->
            <div class="px-4 py-3 space-y-2">
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium" :class="categorieBadge">
                        {{ categorieLabel }}
                    </span>
                </div>
                <p class="text-sm font-semibold leading-snug">{{ type?.libelle }}</p>
            </div>

            <!-- Concerné -->
            <div class="px-4 py-3 space-y-2">
                <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Concerné</p>
                <div class="space-y-1 text-sm">
                    <div v-if="categorie === 'vehicule' && vehicule" class="flex flex-col gap-0.5">
                        <div class="flex justify-between">
                            <span class="text-muted-foreground">Véhicule</span>
                            <span class="font-medium">{{ vehicule.nom_vehicule }}</span>
                        </div>
                        <div class="flex justify-between text-xs text-muted-foreground">
                            <span>Immatriculation</span>
                            <span class="font-mono">{{ vehicule.immatriculation }}</span>
                        </div>
                    </div>
                    <div v-if="concerneReel" class="flex justify-between">
                        <span class="text-muted-foreground">
                            {{ categorie === 'vehicule' ? 'Propriétaire' : categorieLabel }}
                        </span>
                        <span class="font-medium">{{ concerneReel }}</span>
                    </div>
                    <div v-else-if="categorie !== 'interne'" class="text-xs text-muted-foreground italic">
                        — Non sélectionné
                    </div>
                </div>
            </div>

            <!-- Détails -->
            <div class="px-4 py-3 space-y-2">
                <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Détails</p>
                <div class="space-y-1 text-sm">
                    <div v-if="montantFormate" class="flex justify-between">
                        <span class="text-muted-foreground">Montant</span>
                        <span class="font-semibold tabular-nums">{{ montantFormate }}</span>
                    </div>
                    <div v-else class="flex justify-between">
                        <span class="text-muted-foreground">Montant</span>
                        <span class="text-muted-foreground italic text-xs">Non saisi</span>
                    </div>
                    <div v-if="siteNom" class="flex justify-between">
                        <span class="text-muted-foreground">Site</span>
                        <span>{{ siteNom }}</span>
                    </div>
                    <div v-if="commentaire" class="pt-1">
                        <span class="text-xs text-muted-foreground">Commentaire</span>
                        <p class="mt-0.5 text-xs leading-relaxed text-foreground/80 line-clamp-3">{{ commentaire }}</p>
                    </div>
                </div>
            </div>

            <!-- Impact financier -->
            <div v-if="impactConfig" class="px-4 py-3">
                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Impact financier</p>
                <div class="flex items-start gap-2 rounded-lg border p-2.5 text-xs" :class="impactConfig.cls">
                    <CheckCircle v-if="impactConfig.icon === 'check'" class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    <AlertCircle v-else-if="impactConfig.icon === 'alert'" class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    <Info v-else class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    <p class="leading-relaxed">{{ impactConfig.text }}</p>
                </div>
            </div>
        </div>
    </div>
</template>
