<script setup lang="ts">
/**
 * Vue du livreur sur sa propre fiche (scan QR depuis l'espace client) : accès rapides,
 * jamais la fiche backoffice complète (cf. FicheLivreurView.vue).
 */
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import ClientLayout from '@/layouts/ClientLayout.vue';
import { formatPhoneDisplay } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ChevronRight,
    ReceiptText,
    Truck,
    UserRound,
    Users,
} from 'lucide-vue-next';
import { ref } from 'vue';
import type { LivreurData } from './types';

const props = defineProps<{
    livreur: LivreurData;
    commissions_url: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/client/dashboard' },
    { title: props.livreur.nom_complet ?? 'Livreur', href: '#' },
];

const showInfo = ref(false);
</script>

<template>
    <Head :title="`${livreur.nom_complet ?? 'Livreur'} — Livreur`" />

    <ClientLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <div
            class="flex min-h-[70vh] w-full items-center justify-center p-4 sm:p-6"
        >
            <div class="w-full max-w-md space-y-4">
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <div class="flex items-start gap-4">
                        <div
                            class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground"
                        >
                            <Truck class="h-6 w-6" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h1
                                    class="text-xl font-semibold tracking-tight"
                                >
                                    {{ livreur.nom_complet ?? '—' }}
                                </h1>
                                <StatusDot
                                    :label="
                                        livreur.is_active ? 'Actif' : 'Inactif'
                                    "
                                    :dot-class="
                                        livreur.is_active
                                            ? 'bg-emerald-500'
                                            : 'bg-zinc-400 dark:bg-zinc-500'
                                    "
                                    class="text-sm text-muted-foreground"
                                />
                            </div>
                            <p
                                class="mt-0.5 font-mono text-sm text-muted-foreground"
                            >
                                {{
                                    formatPhoneDisplay(
                                        livreur.telephone,
                                        null,
                                    ) ??
                                    livreur.telephone ??
                                    '—'
                                }}
                            </p>
                        </div>
                    </div>

                    <div v-if="showInfo" class="mt-4 space-y-3 border-t pt-4">
                        <div class="grid grid-cols-2 gap-3">
                            <div class="rounded-lg border bg-background p-3">
                                <p class="text-xs text-muted-foreground">
                                    Compte
                                </p>
                                <p class="mt-0.5 text-sm font-medium">
                                    {{ livreur.has_account ? 'Oui' : 'Non' }}
                                </p>
                            </div>
                            <div class="rounded-lg border bg-background p-3">
                                <p class="text-xs text-muted-foreground">
                                    Équipes
                                </p>
                                <p class="mt-0.5 text-sm font-medium">
                                    {{ livreur.equipes.length || '—' }}
                                </p>
                            </div>
                        </div>

                        <div
                            v-if="livreur.equipes.length"
                            class="rounded-lg border bg-background p-3"
                        >
                            <p
                                class="mb-2 flex items-center gap-1.5 text-xs text-muted-foreground"
                            >
                                <Users class="h-3.5 w-3.5" />
                                Équipes de livraison
                            </p>
                            <div class="space-y-1">
                                <div
                                    v-for="equipe in livreur.equipes"
                                    :key="equipe.id"
                                    class="flex items-center justify-between text-sm"
                                >
                                    <span class="font-medium">{{
                                        equipe.vehicule_nom
                                    }}</span>
                                    <span
                                        class="text-xs text-muted-foreground capitalize"
                                    >
                                        {{ equipe.role }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <p
                    class="px-1 text-xs font-medium tracking-wider text-muted-foreground uppercase"
                >
                    Accès rapide
                </p>

                <div class="space-y-2">
                    <button
                        type="button"
                        class="block w-full text-left"
                        @click="showInfo = !showInfo"
                    >
                        <div
                            class="flex items-center gap-4 rounded-xl border bg-card px-4 py-4 shadow-sm transition-colors hover:bg-muted/50"
                            :class="
                                showInfo
                                    ? 'border-blue-200 bg-blue-50/50 dark:border-blue-800 dark:bg-blue-950/30'
                                    : ''
                            "
                        >
                            <div
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-secondary text-secondary-foreground"
                            >
                                <UserRound class="h-5 w-5" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="font-medium">Fiche livreur</p>
                                <p class="text-sm text-muted-foreground">
                                    {{
                                        showInfo
                                            ? 'Masquer les informations'
                                            : 'Voir informations, équipes et statut'
                                    }}
                                </p>
                            </div>
                            <ChevronRight
                                class="h-4 w-4 shrink-0 text-muted-foreground transition-transform"
                                :class="showInfo ? 'rotate-90' : ''"
                            />
                        </div>
                    </button>

                    <a :href="commissions_url" class="block">
                        <div
                            class="flex items-center gap-4 rounded-xl border bg-card px-4 py-4 shadow-sm transition-colors hover:bg-muted/50"
                        >
                            <div
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-secondary text-secondary-foreground"
                            >
                                <ReceiptText class="h-5 w-5" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="font-medium">Mes commissions</p>
                                <p class="text-sm text-muted-foreground">
                                    Voir mes gains et commissions
                                </p>
                            </div>
                            <ChevronRight
                                class="h-4 w-4 shrink-0 text-muted-foreground"
                            />
                        </div>
                    </a>
                </div>

                <div class="pt-2 text-center">
                    <Link href="/client/dashboard">
                        <Button
                            variant="ghost"
                            size="sm"
                            class="text-muted-foreground"
                        >
                            <ArrowLeft class="mr-1.5 h-4 w-4" />
                            Retour à mon espace
                        </Button>
                    </Link>
                </div>
            </div>
        </div>
    </ClientLayout>
</template>
