<style>
    @font-face {
        font-family: PoppinsInvoice;
        font-style: normal;
        font-weight: 400;
        src: url("{{ str_replace('\\', '/', resource_path('fonts/poppins/Poppins-Regular.ttf')) }}") format('truetype');
    }
    @font-face {
        font-family: PoppinsInvoice;
        font-style: normal;
        font-weight: 500;
        src: url("{{ str_replace('\\', '/', resource_path('fonts/poppins/Poppins-Medium.ttf')) }}") format('truetype');
    }
    @font-face {
        font-family: PoppinsInvoice;
        font-style: normal;
        font-weight: 600;
        src: url("{{ str_replace('\\', '/', resource_path('fonts/poppins/Poppins-SemiBold.ttf')) }}") format('truetype');
    }
    @font-face {
        font-family: PoppinsInvoice;
        font-style: normal;
        font-weight: 700;
        src: url("{{ str_replace('\\', '/', resource_path('fonts/poppins/Poppins-Bold.ttf')) }}") format('truetype');
    }

    /* Apollo 6.2.0 (template « Invoice ») : racine 14px, Poppins 400/500/600/700, .card à 28px de
       padding. Tableaux plutôt que flexbox : DomPDF ne gère pas flexbox.
       1px CSS = 0.75pt dans DomPDF ; ne pas réduire les tailles à l'impression. */
    @page { margin: 28px 28px 64px; }
    body { margin: 0; padding: 0; font-family: PoppinsInvoice, DejaVu Sans, sans-serif; font-size: 14px; font-weight: 400; line-height: 1.2; color: #334155; }
    table { border-collapse: collapse; }
    .card { padding: 28px; background: #fff; }
    .coupe { word-wrap: break-word; overflow-wrap: break-word; }

    .entete { width: 100%; table-layout: fixed; border-bottom: 1px solid #e2e8f0; }
    .entete > tbody > tr > td { vertical-align: middle; padding: 0 0 28px; }
    .logo-cadre { width: 48px; height: 50px; }
    .logo { max-width: 48px; max-height: 50px; }
    .org-nom { margin: 14px 0; font-size: 31.5px; line-height: 35px; font-weight: 700; color: #0f172a; }
    .agence { margin-bottom: 7px; }
    .agence:last-child { margin-bottom: 0; }
    .doc-titre { margin: 0 0 14px; font-size: 21px; line-height: 28px; font-weight: 600; text-align: right; }
    .meta { width: auto; margin-left: auto; }
    .meta td { padding: 0 0 7px; vertical-align: top; }
    .meta tr:last-child td { padding-bottom: 0; }
    .meta .cle { font-weight: 600; padding-right: 42px; white-space: nowrap; }
    .meta .val { text-align: right; }

    .parties { width: 100%; table-layout: fixed; margin: 28px 0 70px; }
    .parties > tbody > tr > td { width: 50%; padding: 0; vertical-align: top; }
    .parties > tbody > tr > td.suivante { padding-left: 28px; }
    .partie-titre { margin: 0 0 14px; font-size: 21px; line-height: 28px; font-weight: 500; }
    .partie p { margin: 0 0 7px; }
    .partie p:last-child { margin-bottom: 0; }

    .lignes { width: 100%; table-layout: fixed; }
    .lignes thead { display: table-header-group; }
    .lignes tr { page-break-inside: avoid; }
    .lignes th, .lignes td { padding: 14px 0; border-bottom: 1px solid #e2e8f0; font-size: 14px; line-height: 1.2; font-weight: 400; vertical-align: top; }
    .lignes th { font-weight: 600; text-align: left; white-space: nowrap; }
    .lignes .num { padding-left: 14px; padding-right: 14px; text-align: right; }
    .lignes th.num { white-space: nowrap; }
    .lignes .num.dernier { padding-right: 0; }
    .lignes .detail { display: block; margin-top: 3px; font-size: 11px; color: #64748b; }
    .lignes .vide { color: #64748b; }

    .resume { margin-top: 70px; page-break-inside: avoid; }
    .pied-resume { width: 100%; table-layout: fixed; }
    .pied-resume > tbody > tr > td { padding: 0; vertical-align: top; }
    .notes-titre { margin-bottom: 14px; font-weight: 600; }
    .totaux { width: auto; margin-left: auto; }
    .totaux td { padding: 0 0 7px; }
    .totaux tr:last-child td { padding-bottom: 0; }
    .totaux .cle { padding-right: 42px; font-weight: 600; white-space: nowrap; }
    .totaux .val { font-weight: 400; text-align: right; white-space: nowrap; }

    .encadre { margin-top: 42px; padding: 14px; border: 1px solid #e2e8f0; border-radius: 6px; page-break-inside: avoid; }
    .encadre-titre { margin-bottom: 7px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; }
    .encadre p { margin: 0 0 4px; }
    .encadre p:last-child { margin-bottom: 0; }
    .ton-ok { color: #047857; }
    .ton-attention { color: #b45309; }
    .ton-danger { color: #b91c1c; }

    .pied-page { position: fixed; bottom: -44px; left: 28px; right: 28px; font-size: 10.5px; line-height: 1.2; color: #64748b; }
    .pied-page table { width: 100%; table-layout: fixed; }
    .pied-page td { padding: 0; vertical-align: bottom; }
    .pied-page .pagination { padding-left: 14px; }
    .filigrane { position: fixed; top: 330px; left: -28px; width: 760px; text-align: center; font-size: 84px; font-weight: 700; color: rgba(220, 38, 38, 0.10); transform: rotate(-30deg); }
</style>
