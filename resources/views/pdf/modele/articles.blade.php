{{-- Articles numérotés (contrat de travail) : texte brut, toujours échappé — jamais de HTML saisi. --}}
@if($document->intitule)
    <div class="intitule">{{ mb_strtoupper($document->intitule) }}</div>
@endif
@foreach($document->articles as $article)
    <div class="article">
        <div class="article-titre">Article {{ $loop->iteration }} — {{ $article['titre'] }}</div>
        @foreach($article['paragraphes'] as $paragraphe)
            <p class="coupe">{!! nl2br(e($paragraphe)) !!}</p>
        @endforeach
    </div>
@endforeach
