<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Head, Link } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    Ban,
    Home,
    ServerCrash,
} from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';

const props = defineProps<{ status: number }>();

// Uniquement quand l'onglet a un historique : sinon le retour quitterait l'application.
const peutRevenir = ref(false);
onMounted(() => {
    peutRevenir.value = window.history.length > 1;
});

function revenir(): void {
    window.history.back();
}

const config = computed(() => {
    const map: Record<
        number,
        {
            icon: typeof Ban;
            iconClass: string;
            bgClass: string;
            title: string;
            description: string;
        }
    > = {
        403: {
            icon: Ban,
            iconClass: 'text-amber-600 dark:text-amber-400',
            bgClass: 'bg-amber-100 dark:bg-amber-950/40',
            title: 'Accès interdit',
            description:
                "Vous n'avez pas les droits nécessaires pour accéder à cette page. Contactez votre administrateur si vous pensez qu'il s'agit d'une erreur.",
        },
        404: {
            icon: AlertTriangle,
            iconClass: 'text-muted-foreground',
            bgClass: 'bg-muted',
            title: 'Page introuvable',
            description:
                "La page que vous cherchez n'existe pas ou a été déplacée. Vérifiez l'URL ou revenez à l'accueil.",
        },
        500: {
            icon: ServerCrash,
            iconClass: 'text-destructive',
            bgClass: 'bg-destructive/10',
            title: 'Erreur serveur',
            description:
                "Une erreur inattendue s'est produite. Notre équipe en a été informée. Réessayez dans quelques instants.",
        },
    };

    return (
        map[props.status] ?? {
            icon: ServerCrash,
            iconClass: 'text-destructive',
            bgClass: 'bg-destructive/10',
            title: 'Erreur inattendue',
            description: "Une erreur inattendue s'est produite.",
        }
    );
});
</script>

<template>
    <Head :title="`${status} — ${config.title}`" />

    <div
        class="flex min-h-screen flex-col items-center justify-center bg-background px-4"
    >
        <div class="w-full max-w-md text-center">
            <!-- Icône -->
            <div
                class="mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-full"
                :class="config.bgClass"
            >
                <component
                    :is="config.icon"
                    class="h-8 w-8"
                    :class="config.iconClass"
                />
            </div>

            <!-- Code HTTP -->
            <p
                class="mb-2 font-mono text-sm font-semibold tracking-widest text-muted-foreground uppercase"
            >
                Erreur {{ status }}
            </p>

            <!-- Titre -->
            <h1 class="text-2xl font-bold tracking-tight">
                {{ config.title }}
            </h1>

            <!-- Description -->
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                {{ config.description }}
            </p>

            <!-- Actions -->
            <div class="mt-8 flex flex-wrap justify-center gap-3">
                <Button v-if="peutRevenir" variant="outline" @click="revenir">
                    <ArrowLeft class="mr-2 h-4 w-4" />
                    Page précédente
                </Button>
                <Link href="/backoffice/dashboard">
                    <Button>
                        <Home class="mr-2 h-4 w-4" />
                        Retour à l'accueil
                    </Button>
                </Link>
            </div>
        </div>
    </div>
</template>
