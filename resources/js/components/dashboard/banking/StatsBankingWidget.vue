<template>
    <div class="col-span-12 md:col-span-6 xl:col-span-4">
        <div class="card h-full relative overflow-hidden border-transparent">
            <svg
                id="visual"
                viewBox="0 0 900 600"
                xmlns="http://www.w3.org/2000/svg"
                xmlnsXlink="http://www.w3.org/1999/xlink"
                version="1.1"
                class="absolute left-0 top-0 h-full w-full"
                preserveAspectRatio="none"
            >
                <rect x="0" y="0" width="900" height="600" fill="var(--p-primary-600)"></rect>
                <path
                    d="M0 400L30 386.5C60 373 120 346 180 334.8C240 323.7 300 328.3 360 345.2C420 362 480 391 540 392C600 393 660 366 720 355.2C780 344.3 840 349.7 870 352.3L900 355L900 601L870 601C840 601 780 601 720 601C660 601 600 601 540 601C480 601 420 601 360 601C300 601 240 601 180 601C120 601 60 601 30 601L0 601Z"
                    fill="var(--p-primary-500)"
                    strokeLinecap="round"
                    strokeLinejoin="miter"
                ></path>
            </svg>

            <div class="relative z-20 text-white">
                <div class="text-xl font-semibold mb-4">Total Factures</div>
                <div class="text-2xl mb-6 font-bold">0 GNF </div>
                <div class="text-sm">0 facture(s)</div>
            </div>
        </div>
    </div>

    <div class="col-span-12 md:col-span-6 xl:col-span-4">
        <div class="card h-full flex flex-col items-center justify-center text-center">
            <span class="text-xl text-foreground mb-4 font-medium">Factures payees</span>
            <span class="text-2xl text-primary font-bold mb-4">0 GNF </span>
            <span class="text-sm text-muted-foreground">0 facture(s) payee(s)</span>
        </div>
    </div>

    <div class="col-span-12 md:col-span-6 xl:col-span-4">
        <div class="card h-full flex flex-col items-center justify-center text-center">
            <span class="text-xl text-foreground mb-4 font-medium">Reste a encaisser</span>
            <span class="text-2xl text-primary font-bold mb-4">0 GNF </span>
            <span class="text-sm text-muted-foreground">0 impayee(s) - 0 annulee(s)</span>
        </div>
    </div>
</template>
