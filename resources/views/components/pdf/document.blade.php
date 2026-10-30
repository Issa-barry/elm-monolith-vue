@props(['document'])
{{--
    Modèle officiel des factures et bons (docs/documents-pdf.md) : toute facture ou tout bon PDF
    passe par ce composant, alimenté par un App\Support\Documents\DocumentPdf, comme le récapitulatif
    de contrat RH (sections et signatures, sans tableau de lignes). Les blocs vivent dans
    resources/views/pdf/modele : les modifier change tous les documents à la fois. Le slot par défaut
    accueille un complément propre à un document, affiché après les totaux.
    Les éléments fixes (pied, filigrane) précèdent le contenu pour que DomPDF les répète sur chaque page.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8" />
<title>{{ $document->reference }}</title>
@include('pdf.modele.styles', ['document' => $document])
</head>
<body data-modele="document-officiel" data-type="{{ $document->type }}">
@include('pdf.modele.pied', ['document' => $document])
@if($document->filigrane)
    <div class="filigrane">{{ $document->filigrane }}</div>
@endif
<div class="card">
    @include('pdf.modele.entete', ['document' => $document])
    @include('pdf.modele.parties', ['document' => $document])
    @if($document->colonnes)
        @include('pdf.modele.lignes', ['document' => $document])
    @endif
    @include('pdf.modele.sections', ['document' => $document])
    @include('pdf.modele.resume', ['document' => $document])
    @include('pdf.modele.signatures', ['document' => $document])
    {{ $slot }}
</div>
</body>
</html>
