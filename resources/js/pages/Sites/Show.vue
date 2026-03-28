<script setup lang="ts">
import { Button } from '@/components/ui/button';
import StatusDot from '@/components/StatusDot.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPhoneDisplay, phoneToTelHref } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft, Building2, Mail, MapPin, Pencil, Phone, Users,
    Globe, ChevronRight, Navigation,
} from 'lucide-vue-next';

interface Enfant {
    id: number;
    nom: string;
    code: string;
    type_label: string;
    statut: string | null;
    statut_label: string;
}

interface UserSite {
    id: number;
    name: string;
    email: string;
    role: string | null;
}

interface Site {
    id: number;
    nom: string;
    code: string;
    type: string | null;
    type_label: string;
    statut: string | null;
    statut_label: string;
    localisation: string | null;
    pays: string | null;
    ville: string | null;
    description: string | null;
    parent_id: number | null;
    parent_nom: string | null;
    latitude: number | null;
    longitude: number | null;
    telephone: string | null;
    email: string | null;
    enfants: Enfant[];
    users: UserSite[];
}

const props = defineProps<{ site: Site }>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Sites', href: '/sites' },
    { title: props.site.nom, href: '#' },
];

const FLAG_CODES: Record<string, string> = {
    'Guinée': 'gn', 'Guinée-Bissau': 'gw', 'Sénégal': 'sn', 'Mali': 'ml',
    "Côte d'Ivoire": 'ci', 'Liberia': 'lr', 'Sierra Leone': 'sl',
    'France': 'fr', 'Chine': 'cn', 'Émirats arabes unis': 'ae', 'Inde': 'in',
};

function flagUrl(pays: string) {
    const code = FLAG_CODES[pays];
    return code ? `https://flagcdn.com/20x15/${code}.png` : null;
}

function mapsUrl(lat: number, lng: number) {
    return `https://www.google.com/maps?q=${lat},${lng}`;
}
</script>

<template>
    <Head :title="site.nom" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- Mobile sticky header -->
        <div class="sticky top-0 z-10 flex items-center gap-3 border-b bg-background px-4 py-3 sm:hidden">
            <Link href="/sites">
                <Button variant="ghost" size="icon" class="h-8 w-8 shrink-0">
                    <ArrowLeft class="h-4 w-4" />
                </Button>
            </Link>
            <p class="flex-1 truncate text-center text-sm font-semibold">{{ site.nom }}</p>
            <Link v-if="can('sites.update')" :href="`/sites/${site.id}/edit`">
                <Button variant="ghost" size="icon" class="h-8 w-8 shrink-0">
                    <Pencil class="h-4 w-4" />
                </Button>
            </Link>
            <div v-else class="w-8 shrink-0" />
        </div>

        <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 sm:p-6">

            <!-- En-tête -->
            <div class="hidden sm:flex items-start justify-between gap-4">
                <div class="flex items-center gap-4">
                    <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl border bg-muted/40">
                        <Building2 class="h-7 w-7 text-muted-foreground" />
                    </div>
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">{{ site.nom }}</h1>
                        <div class="mt-1 flex flex-wrap items-center gap-x-5 gap-y-1 text-sm text-muted-foreground">
                            <span>
                                Code :
                                <span class="ml-1 font-mono text-foreground">{{ site.code }}</span>
                            </span>
                            <span>
                                Type :
                                <span class="ml-1 font-medium text-foreground">{{ site.type_label }}</span>
                            </span>
                            <StatusDot
                                :label="`Statut : ${site.statut_label}`"
                                :dot-class="site.statut === 'active' ? 'bg-emerald-500' : 'bg-zinc-400'"
                            />
                        </div>
                    </div>
                </div>
                <Link v-if="can('sites.update')" :href="`/sites/${site.id}/edit`">
                    <Button variant="outline" size="sm">
                        <Pencil class="mr-2 h-4 w-4" />
                        Modifier
                    </Button>
                </Link>
            </div>

            <div class="grid gap-4 sm:gap-6 grid-cols-1 md:grid-cols-2">

                <!-- Localisation -->
                <div class="rounded-xl border bg-card p-5">
                    <h2 class="mb-4 flex items-center gap-2 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                        <MapPin class="h-4 w-4" />
                        Localisation
                    </h2>
                    <dl class="space-y-3 text-sm">
                        <div v-if="site.pays" class="flex items-center justify-between">
                            <dt class="text-muted-foreground">Pays</dt>
                            <dd class="flex items-center gap-1.5 font-medium">
                                <img v-if="flagUrl(site.pays)" :src="flagUrl(site.pays)!" class="h-3.5 w-auto rounded-sm" />
                                {{ site.pays }}
                            </dd>
                        </div>
                        <div v-if="site.ville" class="flex items-center justify-between">
                            <dt class="text-muted-foreground">Ville</dt>
                            <dd class="font-medium">{{ site.ville }}</dd>
                        </div>
                        <div v-if="site.localisation" class="flex items-start justify-between gap-4">
                            <dt class="text-muted-foreground shrink-0">Adresse</dt>
                            <dd class="font-medium text-right">{{ site.localisation }}</dd>
                        </div>
                        <div v-if="site.latitude && site.longitude" class="flex items-center justify-between">
                            <dt class="text-muted-foreground">Coordonnées</dt>
                            <dd>
                                <a
                                    :href="mapsUrl(site.latitude, site.longitude)"
                                    target="_blank"
                                    rel="noopener"
                                    class="flex items-center gap-1 font-mono text-xs text-primary hover:underline"
                                >
                                    <Navigation class="h-3 w-3" />
                                    {{ site.latitude }}, {{ site.longitude }}
                                </a>
                            </dd>
                        </div>
                        <p v-if="!site.pays && !site.ville && !site.localisation && !site.latitude"
                           class="text-muted-foreground italic">
                            Aucune localisation renseignée.
                        </p>
                    </dl>
                </div>

                <!-- Contact -->
                <div class="rounded-xl border bg-card p-5">
                    <h2 class="mb-4 flex items-center gap-2 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                        <Phone class="h-4 w-4" />
                        Contact
                    </h2>
                    <dl class="space-y-3 text-sm">
                        <div v-if="site.telephone" class="flex items-center justify-between">
                            <dt class="text-muted-foreground">Téléphone</dt>
                            <dd>
                                <a :href="phoneToTelHref(site.telephone)" class="font-medium hover:underline">
                                    {{ formatPhoneDisplay(site.telephone) }}
                                </a>
                            </dd>
                        </div>
                        <div v-if="site.email" class="flex items-center justify-between">
                            <dt class="text-muted-foreground">Email</dt>
                            <dd>
                                <a :href="`mailto:${site.email}`" class="font-medium hover:underline">
                                    {{ site.email }}
                                </a>
                            </dd>
                        </div>
                        <p v-if="!site.telephone && !site.email" class="text-muted-foreground italic">
                            Aucun contact renseigné.
                        </p>
                    </dl>
                </div>

                <!-- Hiérarchie -->
                <div class="rounded-xl border bg-card p-5" :class="{ 'md:col-span-2': !site.description }">
                    <h2 class="mb-4 flex items-center gap-2 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                        <Globe class="h-4 w-4" />
                        Hiérarchie
                    </h2>

                    <!-- Parent -->
                    <div v-if="site.parent_nom" class="mb-3 text-sm">
                        <p class="mb-1 text-xs text-muted-foreground">Site parent</p>
                        <Link :href="`/sites/${site.parent_id}`"
                              class="flex items-center gap-2 rounded-lg border bg-muted/30 px-3 py-2 hover:bg-muted/60 transition-colors">
                            <Building2 class="h-4 w-4 text-muted-foreground" />
                            <span class="font-medium">{{ site.parent_nom }}</span>
                            <ChevronRight class="ml-auto h-4 w-4 text-muted-foreground" />
                        </Link>
                    </div>

                    <!-- Enfants -->
                    <div v-if="site.enfants.length > 0" class="text-sm">
                        <p class="mb-1 text-xs text-muted-foreground">
                            Sites enfants ({{ site.enfants.length }})
                        </p>
                        <div class="space-y-1.5">
                            <Link
                                v-for="enfant in site.enfants"
                                :key="enfant.id"
                                :href="`/sites/${enfant.id}`"
                                class="flex items-center gap-2 rounded-lg border bg-muted/30 px-3 py-2 hover:bg-muted/60 transition-colors"
                            >
                                <Building2 class="h-4 w-4 text-muted-foreground" />
                                <div class="flex-1 min-w-0">
                                    <span class="font-medium">{{ enfant.nom }}</span>
                                    <span class="ml-2 rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground">
                                        {{ enfant.code }}
                                    </span>
                                </div>
                                <StatusDot
                                    :label="enfant.statut_label"
                                    :dot-class="enfant.statut === 'active' ? 'bg-emerald-500' : 'bg-zinc-400'"
                                    class="shrink-0"
                                />
                                <ChevronRight class="h-4 w-4 text-muted-foreground" />
                            </Link>
                        </div>
                    </div>

                    <p v-if="!site.parent_nom && site.enfants.length === 0"
                       class="text-sm text-muted-foreground italic">
                        Site racine sans enfants.
                    </p>
                </div>

                <!-- Description -->
                <div v-if="site.description" class="rounded-xl border bg-card p-5">
                    <h2 class="mb-3 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                        Description
                    </h2>
                    <p class="text-sm leading-relaxed text-muted-foreground whitespace-pre-line">
                        {{ site.description }}
                    </p>
                </div>
            </div>

            <!-- Utilisateurs -->
            <div v-if="site.users.length > 0" class="rounded-xl border bg-card p-5">
                <h2 class="mb-4 flex items-center gap-2 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                    <Users class="h-4 w-4" />
                    Utilisateurs assignés ({{ site.users.length }})
                </h2>
                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    <div
                        v-for="u in site.users"
                        :key="u.id"
                        class="flex items-center gap-3 rounded-lg border bg-muted/20 px-3 py-2.5"
                    >
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold uppercase">
                            {{ u.name.charAt(0) }}
                        </div>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium">{{ u.name }}</p>
                            <p class="truncate text-xs text-muted-foreground">{{ u.email }}</p>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </AppLayout>
</template>
