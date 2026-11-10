<script setup lang="ts">
/**
 * Catégories de contacts (ADR 0060, lot 6a). Sous-catégories par fonction : seules celles des
 * fournisseurs s'administrent ; client et prestataire restent en lecture seule (libellés encore
 * écrits en dur dans ventes, PDV et Prestataires). Catégories libres (ex. Partenaire) : étiquettes
 * sans effet métier. Page réservée à `contacts.gerer_categories`, revérifiée à chaque requête.
 */
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useFlashToast } from '@/composables/useFlashToast';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    Loader2,
    Lock,
    Pencil,
    Plus,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import { useToast } from 'primevue/usetoast';
import { computed, ref } from 'vue';

interface Ligne {
    id: string;
    code: string;
    libelle: string;
    is_system: boolean;
    is_active: boolean;
}

interface Section {
    categorie: string;
    titre: string;
    administrable: boolean;
    lignes: Ligne[];
}

/** Une liste affichée : sous-catégories d'une fonction, ou catégories libres (`libres`). */
interface Liste {
    cle: string;
    titre: string;
    description: string;
    administrable: boolean;
    lignes: Ligne[];
}

const props = defineProps<{ sections: Section[]; libres: Ligne[] }>();

useFlashToast();
const toast = useToast();
const { can } = usePermissions();
const peutGerer = computed(() => can('contacts.gerer_categories'));

const listes = computed<Liste[]>(() => [
    ...props.sections.map((s) => ({
        cle: s.categorie,
        titre: `Sous-catégories — ${s.titre}`,
        description: s.administrable
            ? 'Qualifications descriptives, sans effet métier.'
            : 'Lecture seule : ces libellés sont encore utilisés tels quels par les autres modules.',
        administrable: s.administrable,
        lignes: s.lignes,
    })),
    {
        cle: 'libres',
        titre: 'Catégories libres',
        description:
            'Étiquettes posées sur une fiche Personne ou Entreprise (ex. Partenaire), sans effet sur les ventes, commissions, comptabilité ou logistique.',
        administrable: true,
        lignes: props.libres,
    },
]);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Catégories de contacts', href: '#' },
];

// ── Création / renommage ────────────────────────────────────────────────────
const dialogue = ref<{ liste: Liste; ligne: Ligne | null } | null>(null);
const form = useForm({ libelle: '', categorie: '' });

function ouvrir(liste: Liste, ligne: Ligne | null = null) {
    form.libelle = ligne?.libelle ?? '';
    form.categorie = liste.cle;
    form.clearErrors();
    dialogue.value = { liste, ligne };
}

function fermer(visible: boolean) {
    if (!visible && !form.processing) dialogue.value = null;
}

function urlElement(liste: Liste, ligne: Ligne): string {
    return liste.cle === 'libres'
        ? `/backoffice/contacts/categories/libres/${ligne.id}`
        : `/backoffice/contacts/categories/sous-categories/${ligne.id}`;
}

function enregistrer() {
    if (form.processing || !dialogue.value) return;
    const { liste, ligne } = dialogue.value;
    const options = {
        preserveScroll: true,
        onSuccess: () => (dialogue.value = null),
    };
    if (ligne) {
        form.transform((d) => ({ libelle: d.libelle })).patch(
            urlElement(liste, ligne),
            options,
        );
    } else if (liste.cle === 'libres') {
        form.transform((d) => ({ libelle: d.libelle })).post(
            '/backoffice/contacts/categories/libres',
            options,
        );
    } else {
        form.transform((d) => d).post(
            '/backoffice/contacts/categories/sous-categories',
            options,
        );
    }
}

// ── Activation et ordre ─────────────────────────────────────────────────────
const enCours = ref<string | null>(null);

function erreur(erreurs: Record<string, string>) {
    toast.add({
        group: 'top',
        severity: 'error',
        summary: 'Action impossible',
        detail:
            Object.values(erreurs)[0] ?? 'L’action n’a pas pu être réalisée.',
        life: 6000,
    });
}

function basculer(liste: Liste, ligne: Ligne) {
    if (enCours.value) return;
    enCours.value = ligne.id;
    router.patch(
        urlElement(liste, ligne),
        { is_active: !ligne.is_active },
        {
            preserveScroll: true,
            onError: erreur,
            onFinish: () => (enCours.value = null),
        },
    );
}

function deplacer(liste: Liste, index: number, sens: -1 | 1) {
    if (enCours.value) return;
    const ids = liste.lignes.map((l) => l.id);
    const cible = index + sens;
    if (cible < 0 || cible >= ids.length) return;
    [ids[index], ids[cible]] = [ids[cible], ids[index]];
    enCours.value = `ordre-${liste.cle}`;
    router.post(
        '/backoffice/contacts/categories/ordre',
        { liste: liste.cle, ids },
        {
            preserveScroll: true,
            onError: erreur,
            onFinish: () => (enCours.value = null),
        },
    );
}
</script>

<template>
    <Head title="Catégories de contacts" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full space-y-6 p-4 sm:p-6">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Catégories de contacts
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Sous-catégories des fonctions et catégories libres du
                    référentiel Contacts. Rien ne se supprime : un élément
                    désactivé n'est plus proposé mais reste sur les fiches qui
                    le portent.
                </p>
            </div>

            <section
                v-for="liste in listes"
                :key="liste.cle"
                class="rounded-xl border bg-card"
                :data-testid="`categories-liste-${liste.cle}`"
            >
                <header
                    class="flex flex-wrap items-start justify-between gap-3 border-b p-4 sm:p-5"
                >
                    <div class="min-w-0">
                        <h2 class="text-sm font-semibold">{{ liste.titre }}</h2>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            {{ liste.description }}
                        </p>
                    </div>
                    <Button
                        v-if="liste.administrable && peutGerer"
                        size="sm"
                        :data-testid="`categories-ajouter-${liste.cle}`"
                        @click="ouvrir(liste)"
                    >
                        <Plus class="mr-1.5 h-4 w-4" />
                        Ajouter
                    </Button>
                </header>
                <ul class="divide-y">
                    <li
                        v-for="(ligne, index) in liste.lignes"
                        :key="ligne.id"
                        class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-5"
                        data-testid="categories-ligne"
                    >
                        <div class="min-w-0">
                            <p
                                class="flex items-center gap-1.5 text-sm font-medium"
                            >
                                {{ ligne.libelle }}
                                <Lock
                                    v-if="ligne.is_system"
                                    class="h-3.5 w-3.5 text-muted-foreground"
                                    aria-label="Système"
                                />
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ ligne.code
                                }}{{ ligne.is_system ? ' · Système' : '' }}
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <StatusDot
                                class="text-xs text-muted-foreground"
                                :status="ligne.is_active ? 'actif' : 'inactif'"
                                :label="ligne.is_active ? 'Actif' : 'Inactif'"
                            />
                            <template
                                v-if="
                                    liste.administrable &&
                                    peutGerer &&
                                    !ligne.is_system
                                "
                            >
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    title="Monter"
                                    :disabled="index === 0 || enCours !== null"
                                    @click="deplacer(liste, index, -1)"
                                >
                                    <ArrowUp class="h-4 w-4" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    title="Descendre"
                                    :disabled="
                                        index === liste.lignes.length - 1 ||
                                        enCours !== null
                                    "
                                    @click="deplacer(liste, index, 1)"
                                >
                                    <ArrowDown class="h-4 w-4" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    title="Renommer"
                                    data-testid="categories-renommer"
                                    @click="ouvrir(liste, ligne)"
                                >
                                    <Pencil class="h-4 w-4" />
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    :disabled="enCours !== null"
                                    data-testid="categories-basculer"
                                    @click="basculer(liste, ligne)"
                                >
                                    <Loader2
                                        v-if="enCours === ligne.id"
                                        class="mr-1.5 h-3.5 w-3.5 animate-spin"
                                    />
                                    {{
                                        ligne.is_active
                                            ? 'Désactiver'
                                            : 'Réactiver'
                                    }}
                                </Button>
                            </template>
                        </div>
                    </li>
                    <li
                        v-if="liste.lignes.length === 0"
                        class="px-4 py-6 text-center text-sm text-muted-foreground"
                    >
                        Aucun élément.
                    </li>
                </ul>
            </section>
        </div>

        <Dialog
            :visible="dialogue !== null"
            modal
            :header="
                dialogue?.ligne
                    ? `Renommer — ${dialogue.ligne.libelle}`
                    : `Ajouter — ${dialogue?.liste.titre ?? ''}`
            "
            :closable="!form.processing"
            :close-on-escape="!form.processing"
            :draggable="false"
            :style="{ width: 'min(28rem, 94vw)' }"
            @update:visible="fermer"
        >
            <Label for="categorie-libelle" class="mb-1.5 block"
                >Libellé <span class="text-destructive">*</span></Label
            >
            <InputText
                id="categorie-libelle"
                v-model="form.libelle"
                maxlength="100"
                class="w-full"
                data-testid="categories-libelle"
                @keyup.enter="enregistrer"
            />
            <p
                v-if="dialogue?.ligne"
                class="mt-1 text-xs text-muted-foreground"
            >
                Le code « {{ dialogue.ligne.code }} » ne change pas.
            </p>
            <p
                v-if="form.errors.libelle || form.errors.categorie"
                class="mt-2 text-xs text-destructive"
            >
                {{ form.errors.libelle ?? form.errors.categorie }}
            </p>
            <template #footer>
                <Button
                    variant="outline"
                    :disabled="form.processing"
                    @click="fermer(false)"
                >
                    Annuler
                </Button>
                <Button
                    :disabled="form.processing || form.libelle.trim() === ''"
                    data-testid="categories-enregistrer"
                    @click="enregistrer"
                >
                    <Loader2
                        v-if="form.processing"
                        class="mr-1.5 h-4 w-4 animate-spin"
                    />
                    Enregistrer
                </Button>
            </template>
        </Dialog>
    </AppLayout>
</template>
