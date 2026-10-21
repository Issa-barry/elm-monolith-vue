<table class="entete">
    <tbody><tr>
        <td style="width: 48%;">
            <div class="logo-cadre"><img class="logo" src="{{ $document->organisation->logo }}" alt="Logo de {{ $document->organisation->nom }}" /></div>
            @php [$nomDebut, $nomFin] = $document->organisation->nomEnDeuxTons(); @endphp
            <div class="org-nom coupe"><span class="fonce">{{ $nomDebut }}</span>@if($nomFin) <span class="clair">{{ $nomFin }}</span>@endif</div>
            @foreach($document->lignesAgence as $ligne)
                <div class="agence coupe">{{ $ligne }}</div>
            @endforeach
        </td>
        <td style="width: 52%;">
            <div class="doc-titre">{{ $document->titre }}</div>
            <table class="meta">
                @foreach($document->metadonnees as $meta)
                    <tr><td class="cle">{{ $meta['cle'] }}</td><td class="val coupe">{{ $meta['valeur'] }}</td></tr>
                @endforeach
            </table>
        </td>
    </tr></tbody>
</table>
