<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPhoneDisplay } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Pencil } from 'lucide-vue-next';

interface Membre {
    livreur_id: string | null;
    nom: string;
    prenom: string;
    telephone: string;
    role: string;
    taux_commission: number;
    ordre: number;
}

interface EquipeData {
    id: string;
    nom: string;
    is_active: boolean;
    vehicule_nom: string | null;
    vehicule_immatriculation: string | null;
    vehicule_type_label: string | null;
    vehicule_categorie: string | null;
    vehicule_capacite_packs: number | null;
    proprietaire_nom: string | null;
    proprietaire_telephone: string | null;
    taux_commission_proprietaire: number | null;
    principal_nom: string | null;
    principal_telephone: string | null;
    nb_membres: number;
    nb_assistants: number;
    somme_taux: number;
    membres: Membre[];
}

const props = defineProps<{ equipe: EquipeData }>();
const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Véhicules', href: '/vehicules' },
    { title: 'Équipes de livraison', href: '/equipes-livraison' },
    { title: props.equipe.nom, href: '#' },
];

function roleLabel(role: string): string {
    if (role === 'principal') {
        return 'Principal';
    }

    if (role === 'assistant') {
        return 'Assistant';
    }

    return role;
}
</script>

<template>
    <Head>
        <title>Équipe {{ equipe.nom }}</title>
    </Head>

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="space-y-1">
                    <h1 class="text-2xl font-semibold tracking-tight">
                        {{ equipe.nom }}
                    </h1>
                    <div class="flex flex-wrap items-center gap-3 text-sm">
                        <StatusDot
                            :label="equipe.is_active ? 'Actif' : 'Inactif'"
                            :dot-class="
                                equipe.is_active
                                    ? 'bg-emerald-500'
                                    : 'bg-zinc-400'
                            "
                            class="text-muted-foreground"
                        />
                        <span class="text-muted-foreground">
                            {{ equipe.nb_membres }} membre(s)
                        </span>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <Link href="/equipes-livraison">
                        <Button variant="outline">
                            <ArrowLeft class="mr-2 h-4 w-4" />
                            Retour
                        </Button>
                    </Link>
                    <Link
                        v-if="can('equipes-livraison.update')"
                        :href="`/equipes-livraison/${equipe.id}/edit`"
                    >
                        <Button>
                            <Pencil class="mr-2 h-4 w-4" />
                            Modifier
                        </Button>
                    </Link>
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground uppercase">
                        Véhicule
                    </p>
                    <p class="mt-2 text-sm font-medium">
                        {{ equipe.vehicule_nom || '—' }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{
                            equipe.vehicule_immatriculation
                                ? equipe.vehicule_immatriculation
                                : 'Immatriculation non définie'
                        }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ equipe.vehicule_type_label || 'Type non défini' }}
                        <span
                            v-if="equipe.vehicule_categorie"
                            class="ml-1 capitalize"
                        >
                            · {{ equipe.vehicule_categorie }}
                        </span>
                    </p>
                </div>

                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground uppercase">
                        Propriétaire
                    </p>
                    <p class="mt-2 text-sm font-medium">
                        {{ equipe.proprietaire_nom || '—' }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{
                            equipe.proprietaire_telephone
                                ? formatPhoneDisplay(
                                      equipe.proprietaire_telephone,
                                  )
                                : 'Aucun numéro'
                        }}
                    </p>
                </div>

                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground uppercase">
                        Principal
                    </p>
                    <p class="mt-2 text-sm font-medium">
                        {{ equipe.principal_nom || '—' }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{
                            equipe.principal_telephone
                                ? formatPhoneDisplay(equipe.principal_telephone)
                                : 'Aucun numéro'
                        }}
                    </p>
                </div>

                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground uppercase">
                        Répartition
                    </p>
                    <p class="mt-2 text-sm font-medium">
                        Équipe: {{ equipe.somme_taux }}%
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Propriétaire:
                        {{ equipe.taux_commission_proprietaire ?? 0 }}%
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Capacité:
                        {{
                            equipe.vehicule_capacite_packs === null
                                ? '—'
                                : `${equipe.vehicule_capacite_packs} packs`
                        }}
                    </p>
                </div>
            </div>

            <div class="rounded-xl border bg-card shadow-sm">
                <div class="border-b px-4 py-3 sm:px-6">
                    <h2 class="font-semibold">Membres de l'équipe</h2>
                    <p class="text-sm text-muted-foreground">
                        {{ equipe.nb_membres }} membre(s) ·
                        {{ equipe.nb_assistants }} assistant(s)
                    </p>
                </div>

                <div
                    v-if="equipe.membres.length === 0"
                    class="px-6 py-10 text-center text-sm text-muted-foreground"
                >
                    Aucun membre enregistré.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[680px]">
                        <thead
                            class="border-b text-left text-sm text-muted-foreground"
                        >
                            <tr>
                                <th class="px-6 py-3 font-medium">Membre</th>
                                <th class="px-6 py-3 font-medium">Téléphone</th>
                                <th class="px-6 py-3 font-medium">Rôle</th>
                                <th class="px-6 py-3 text-right font-medium">
                                    Taux
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="membre in equipe.membres"
                                :key="`${membre.livreur_id ?? 'new'}-${membre.ordre}`"
                                class="border-b last:border-b-0"
                            >
                                <td class="px-6 py-3">
                                    <p class="text-sm font-medium">
                                        {{ membre.prenom }} {{ membre.nom }}
                                    </p>
                                </td>
                                <td
                                    class="px-6 py-3 text-sm text-muted-foreground"
                                >
                                    {{ formatPhoneDisplay(membre.telephone) }}
                                </td>
                                <td class="px-6 py-3 text-sm">
                                    {{ roleLabel(membre.role) }}
                                </td>
                                <td
                                    class="px-6 py-3 text-right font-mono text-sm"
                                >
                                    {{ membre.taux_commission }}%
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
