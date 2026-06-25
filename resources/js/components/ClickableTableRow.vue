<script setup lang="ts">
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/vue3';

/**
 * <tr> cliquable pour les pages de liste : clic ou Entrée/Espace ouvre `href`,
 * sauf si l'événement provient d'un élément interactif de la ligne (bouton,
 * lien, menu "...", checkbox, etc.) — ces éléments gardent leur propre
 * comportement sans déclencher la navigation de la ligne.
 */
const props = withDefaults(
    defineProps<{
        href: string;
        disabled?: boolean;
        ariaLabel?: string;
    }>(),
    { disabled: false, ariaLabel: undefined },
);

const IGNORE_SELECTOR =
    'button, a, input, select, textarea, [role="button"], [data-no-row-click]';

function isInteractiveTarget(target: EventTarget | null): boolean {
    return target instanceof Element && !!target.closest(IGNORE_SELECTOR);
}

function navigate() {
    if (props.disabled) return;
    router.visit(props.href);
}

function handleClick(event: MouseEvent) {
    if (props.disabled || isInteractiveTarget(event.target)) return;
    navigate();
}

function handleKeydown(event: KeyboardEvent) {
    if (props.disabled || isInteractiveTarget(event.target)) return;
    if (event.key !== 'Enter' && event.key !== ' ') return;
    event.preventDefault();
    navigate();
}
</script>

<template>
    <tr
        :class="
            cn(
                'transition-colors',
                !disabled &&
                    'cursor-pointer hover:bg-muted/50 focus-visible:bg-muted/50 focus-visible:outline-none',
            )
        "
        :role="disabled ? undefined : 'link'"
        :tabindex="disabled ? undefined : 0"
        :aria-label="ariaLabel"
        @click="handleClick"
        @keydown="handleKeydown"
    >
        <slot />
    </tr>
</template>
