<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFlashToast } from '@/composables/useFlashToast';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowLeft,
    ArrowUp,
    Loader2,
    Plus,
    Trash2,
} from 'lucide-vue-next';
import { useConfirm } from 'primevue/useconfirm';
import { computed, ref } from 'vue';

interface Article {
    titre: string;
    texte: string;
}

interface Variable {
    cle: string;
    libelle: string;
    groupe: string;
    source: string;
}

const props = defineProps<{
    modele: {
        type_contrat: string;
        type_contrat_label: string;
        titre: string;
        articles: Article[];
        statut: string;
        statut_label: string;
        enregistre: boolean;
        revision: number;
    };
    variables: Variable[];
}>();

useFlashToast();
const confirm = useConfirm();

const breadcrumbItems: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Modèles de contrat', href: '/backoffice/modeles-contrat' },
    {
        title: props.modele.type_contrat_label,
        href: `/backoffice/modeles-contrat/${props.modele.type_contrat}/edit`,
    },
];

const form = useForm({
    titre: props.modele.titre,
    articles: props.modele.articles.map((a) => ({ ...a })),
});

const actif = computed(() => props.modele.statut === 'actif');
const changementStatut = ref(false);

const groupes = computed(() => {
    const parGroupe = new Map<string, Variable[]>();
    for (const v of props.variables) {
        parGroupe.set(v.groupe, [...(parGroupe.get(v.groupe) ?? []), v]);
    }
    return [...parGroupe.entries()];
});

const SOURCE_LIBELLE: Record<string, string> = {
    dossier: 'repris automatiquement',
    avantages: 'primes validées',
};

function ajouterArticle() {
    form.articles.push({ titre: '', texte: '' });
}

function supprimerArticle(index: number) {
    form.articles.splice(index, 1);
}

function deplacer(index: number, sens: -1 | 1) {
    const cible = index + sens;
    if (cible < 0 || cible >= form.articles.length) return;
    const [article] = form.articles.splice(index, 1);
    form.articles.splice(cible, 0, article);
}

function enregistrer() {
    form.put(`/backoffice/modeles-contrat/${props.modele.type_contrat}`, {
        preserveScroll: true,
        onSuccess: () => form.defaults(),
    });
}

function jeton(cle: string): string {
    return `{{${cle}}}`;
}

const variableCopiee = ref<string | null>(null);
async function copierVariable(cle: string) {
    try {
        await navigator.clipboard.writeText(jeton(cle));
        variableCopiee.value = cle;
        setTimeout(() => {
            if (variableCopiee.value === cle) variableCopiee.value = null;
        }, 1500);
    } catch {
        variableCopiee.value = null;
    }
}

function changerStatut(action: 'activer' | 'desactiver') {
    const envoyer = () =>
        router.patch(
            `/backoffice/modeles-contrat/${props.modele.type_contrat}/${action}`,
            {},
            {
                preserveScroll: true,
                onStart: () => (changementStatut.value = true),
                onFinish: () => (changementStatut.value = false),
            },
        );

    if (action === 'desactiver') {
        envoyer();
        return;
    }

    confirm.require({
        header: `Activer le modèle ${props.modele.type_contrat_label}`,
        message:
            'Confirmez que ce texte a été relu et adapté au droit applicable. Les prochains contrats générés reprendront ce texte ; les contrats déjà générés ne changent pas.',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Annuler',
        acceptLabel: 'Activer',
        accept: envoyer,
    });
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems" :hide-mobile-header="true">
        <Head :title="`Modèle ${modele.type_contrat_label}`" />

        <SettingsLayout wide>
            <div class="space-y-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="space-y-2">
                        <Link
                            href="/backoffice/modeles-contrat"
                            class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                        >
                            <ArrowLeft class="h-4 w-4" /> Modèles de contrat
                        </Link>
                        <HeadingSmall
                            :title="`Modèle ${modele.type_contrat_label}`"
                            description="Texte brut, un paragraphe par ligne vide. Les variables {{…}} sont remplacées à la génération."
                        />
                        <div class="flex items-center gap-3 text-xs">
                            <StatusDot
                                :status="modele.statut"
                                :label="modele.statut_label"
                            />
                            <span class="text-muted-foreground">
                                {{
                                    modele.enregistre
                                        ? `Révision ${modele.revision}`
                                        : 'Trame de l’application, pas encore enregistrée'
                                }}
                            </span>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-if="actif"
                            variant="outline"
                            size="sm"
                            :disabled="changementStatut || form.processing"
                            data-testid="modele-desactiver"
                            @click="changerStatut('desactiver')"
                        >
                            Repasser en brouillon
                        </Button>
                        <Button
                            v-else
                            variant="outline"
                            size="sm"
                            :disabled="
                                changementStatut ||
                                form.processing ||
                                form.isDirty
                            "
                            :title="
                                form.isDirty
                                    ? 'Enregistrez d’abord vos modifications'
                                    : undefined
                            "
                            data-testid="modele-activer"
                            @click="changerStatut('activer')"
                        >
                            <Loader2
                                v-if="changementStatut"
                                class="mr-1.5 h-3.5 w-3.5 animate-spin"
                            />
                            Activer le modèle
                        </Button>
                        <Button
                            size="sm"
                            :disabled="form.processing || changementStatut"
                            data-testid="modele-enregistrer"
                            @click="enregistrer"
                        >
                            <Loader2
                                v-if="form.processing"
                                class="mr-1.5 h-3.5 w-3.5 animate-spin"
                            />
                            Enregistrer
                        </Button>
                    </div>
                </div>

                <p
                    v-if="!actif"
                    class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-300"
                >
                    Modèle en brouillon : aucun {{ modele.type_contrat_label }}
                    ne peut être généré. Relisez le texte, faites-le valider
                    juridiquement, puis activez-le.
                </p>

                <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_18rem]">
                    <div class="space-y-4">
                        <div>
                            <Label for="modele-titre" class="mb-1.5 block"
                                >Intitulé du contrat</Label
                            >
                            <Input
                                id="modele-titre"
                                v-model="form.titre"
                                maxlength="150"
                            />
                            <p
                                v-if="form.errors.titre"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.titre }}
                            </p>
                        </div>

                        <p
                            v-if="form.errors.articles"
                            class="text-xs text-destructive"
                        >
                            {{ form.errors.articles }}
                        </p>

                        <div
                            v-for="(article, index) in form.articles"
                            :key="index"
                            class="space-y-2 rounded-xl border bg-card p-4"
                            :data-testid="`article-${index}`"
                        >
                            <div class="flex items-center gap-2">
                                <span
                                    class="shrink-0 text-xs font-medium text-muted-foreground"
                                    >Article {{ index + 1 }}</span
                                >
                                <Input
                                    v-model="article.titre"
                                    maxlength="150"
                                    placeholder="Titre de l’article"
                                    class="h-8"
                                />
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="h-8 w-8 shrink-0"
                                    title="Monter"
                                    :disabled="index === 0"
                                    @click="deplacer(index, -1)"
                                >
                                    <ArrowUp class="h-4 w-4" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="h-8 w-8 shrink-0"
                                    title="Descendre"
                                    :disabled="
                                        index === form.articles.length - 1
                                    "
                                    @click="deplacer(index, 1)"
                                >
                                    <ArrowDown class="h-4 w-4" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="h-8 w-8 shrink-0 text-destructive"
                                    title="Supprimer l’article"
                                    :disabled="form.articles.length === 1"
                                    @click="supprimerArticle(index)"
                                >
                                    <Trash2 class="h-4 w-4" />
                                </Button>
                            </div>
                            <p
                                v-if="form.errors[`articles.${index}.titre`]"
                                class="text-xs text-destructive"
                            >
                                {{ form.errors[`articles.${index}.titre`] }}
                            </p>
                            <textarea
                                v-model="article.texte"
                                rows="4"
                                maxlength="5000"
                                class="flex min-h-[90px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            />
                            <p
                                v-if="form.errors[`articles.${index}.texte`]"
                                class="text-xs text-destructive"
                            >
                                {{ form.errors[`articles.${index}.texte`] }}
                            </p>
                        </div>

                        <Button
                            variant="outline"
                            size="sm"
                            @click="ajouterArticle"
                        >
                            <Plus class="mr-2 h-4 w-4" /> Ajouter un article
                        </Button>
                    </div>

                    <aside class="space-y-4 lg:sticky lg:top-4 lg:self-start">
                        <div class="rounded-xl border bg-card p-4">
                            <p class="text-sm font-medium">Variables</p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Toutes les variables sont reprises
                                automatiquement (organisation, salarié, contrat,
                                PDG). Une variable vide bloque le PDF et indique
                                où la compléter : rien n’est jamais inventé.
                            </p>
                            <div
                                v-for="[groupe, liste] in groupes"
                                :key="groupe"
                                class="mt-3"
                            >
                                <p
                                    class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                                >
                                    {{ groupe }}
                                </p>
                                <ul class="mt-1 space-y-1">
                                    <li
                                        v-for="v in liste"
                                        :key="v.cle"
                                        class="flex items-start justify-between gap-2"
                                    >
                                        <div class="min-w-0">
                                            <code
                                                class="block truncate text-xs"
                                                >{{ jeton(v.cle) }}</code
                                            >
                                            <span
                                                class="text-[11px] text-muted-foreground"
                                                >{{ v.libelle }} ·
                                                {{
                                                    SOURCE_LIBELLE[v.source]
                                                }}</span
                                            >
                                        </div>
                                        <button
                                            type="button"
                                            class="shrink-0 rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                                            title="Cliquez pour copier la variable"
                                            @click="copierVariable(v.cle)"
                                        >
                                            <i
                                                :class="
                                                    variableCopiee === v.cle
                                                        ? 'pi pi-check'
                                                        : 'pi pi-clipboard'
                                                "
                                                class="text-xs"
                                            />
                                        </button>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </aside>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
