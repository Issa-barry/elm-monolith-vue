<div class="pied-page">
    @if($document->organisation->piedPage)
        {{-- Pied de page officiel de l'organisation (Paramètres → Organisation), sur chaque page. --}}
        <div class="pied-officiel">
            @foreach($document->organisation->piedPage as $ligne)
                <p class="coupe{{ $loop->first ? ' premiere' : '' }}">{{ $ligne }}</p>
            @endforeach
        </div>
    @endif
    <table>
        <tbody><tr>
            <td class="coupe" style="width: 82%;">{{ $document->mention ?? $document->organisation->nom.' — '.$document->reference }}@if($document->infoPied) — {{ $document->infoPied }}@endif</td>
            <td class="pagination" style="width: 18%;">&nbsp;</td>
        </tr></tbody>
    </table>
</div>
