{{-- Kennzahlen für die Listen aller Themen (/books) und aller Bereiche (/shelves).
     Wird vor books.parts.list bzw. shelves.parts.list eingefügt; wiki.js setzt den Block unter den Titel.
     shelves.parts.list nutzt auch die Startseite, daher die Prüfung auf die Adresse. --}}
@php
    $alleArtikel = fn () => \BookStack\Entities\Models\Page::query()->scopes('visible')->where('draft', false)->where('template', false);
    $zahlen = null;
    if (request()->is('books') && isset($books)) {
        $zahlen = [
            [$books->total(), $books->total() === 1 ? 'Thema' : 'Themen'],
            [$alleArtikel()->count(), 'Artikel'],
        ];
    } elseif (request()->is('shelves') && isset($shelves)) {
        $themen = \BookStack\Entities\Models\Book::query()->scopes('visible')->count();
        $zahlen = [
            [$shelves->total(), $shelves->total() === 1 ? 'Bereich' : 'Bereiche'],
            [$themen, $themen === 1 ? 'Thema' : 'Themen'],
            [$alleArtikel()->count(), 'Artikel'],
        ];
    }
    $neuester = $zahlen ? $alleArtikel()->with('updatedBy')->orderByDesc('updated_at')->first() : null;
@endphp
@if($zahlen)
    <div class="fundus-uebersicht" data-fundus-liste-kopf>
        <dl class="fundus-kennzahlen">
            @foreach($zahlen as [$zahl, $wort])
                <div><dd>{{ $zahl }}</dd><dt>{{ $wort }}</dt></div>
            @endforeach
        </dl>
        @if($neuester)
            @php($stand = $neuester->updated_at->clone()->setTimezone(config('app.display_timezone')))
            <p class="fundus-uebersicht-neu">
                Zuletzt geändert:
                <a href="{{ $neuester->getUrl() }}">{{ $neuester->name }}</a>
                am <time datetime="{{ $stand->toIso8601String() }}">{{ $stand->format('d.m.Y') }}</time>
                @if($neuester->updatedBy) von <a href="{{ $neuester->updatedBy->getProfileUrl() }}">{{ $neuester->updatedBy->name }}</a>@endif
            </p>
        @endif
    </div>
@endif
