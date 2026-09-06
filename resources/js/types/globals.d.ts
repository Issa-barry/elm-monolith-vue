import { AppPageProps } from '@/types/index';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    interface PageProps extends InertiaPageProps, AppPageProps {}
}

declare module 'vue' {
    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}

// Constantes injectées à la compilation par le bloc `define` de vite.config.ts
// (PWA — cf. docs/pwa.md) : remplacées littéralement dans le bundle, jamais
// lues à l'exécution comme de vraies variables.
declare const __PWA_ENABLED__: boolean;
declare const __PWA_BUILD_DIR__: string;
