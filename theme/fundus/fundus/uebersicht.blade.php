{{-- Kennzahlen für die Übersicht eines Themas oder Bereichs. Wird nach den Brotkrumen eingefügt
     (entities.breadcrumbs); wiki.js setzt den Block unter Titel und Beschreibung in die Karte.
     Nur die Themenansicht setzt $bookChildren, nur die Bereichsansicht $sortedVisibleShelfBooks.
     Gezählt wird mit BookStacks Rechtefilter: Jeder sieht nur Zahlen zu dem, was er lesen darf. --}}
@php
    $artikelAbfrage = null;
    $kennzahlen = [];
    $symbolFuer = null;
    if (isset($bookChildren, $book) && $book instanceof \BookStack\Entities\Models\Book) {
        $symbolFuer = $book;
        $artikelAbfrage = fn () => \BookStack\Entities\Models\Page::query()->scopes('visible')
            ->where('draft', false)->where('template', false)->where('book_id', $book->id);
        $abschnitte = collect($bookChildren)->filter(fn ($e) => $e->isA('chapter'))->count();
        $kennzahlen[] = [$abschnitte, $abschnitte === 1 ? 'Abschnitt' : 'Abschnitte'];
    } elseif (isset($sortedVisibleShelfBooks, $shelf) && $shelf instanceof \BookStack\Entities\Models\Bookshelf) {
        $symbolFuer = $shelf;
        $buchIds = collect($sortedVisibleShelfBooks)->pluck('id');
        $artikelAbfrage = fn () => \BookStack\Entities\Models\Page::query()->scopes('visible')
            ->where('draft', false)->where('template', false)->whereIn('book_id', $buchIds);
        $kennzahlen[] = [$buchIds->count(), $buchIds->count() === 1 ? 'Thema' : 'Themen'];
    }
    if ($artikelAbfrage) {
        $artikelZahl = $artikelAbfrage()->count();
        array_unshift($kennzahlen, [$artikelZahl, 'Artikel']);
        $neuester = $artikelAbfrage()->with('updatedBy')->orderByDesc('updated_at')->first();
        $symbolSvg = trim(view('fundus.symbol-svg', ['entity' => $symbolFuer])->render());
    }
@endphp
@if($artikelAbfrage)
    <div class="fundus-uebersicht" data-fundus-uebersicht>
        @if($symbolSvg !== '')
            <span class="fundus-kreis" aria-hidden="true" data-fundus-uebersicht-symbol>{!! $symbolSvg !!}</span>
        @endif
        {{-- Eine ruhige Zeile statt Pillen: „16 Artikel · 4 Abschnitte · zuletzt Überblick, 14.09.2026“ --}}
        <p class="fundus-uebersicht-neu">
            @foreach($kennzahlen as [$zahl, $wort])
                <span>{{ $zahl }} {{ $wort }}</span>
            @endforeach
            @if($neuester)
                @php($stand = $neuester->updated_at->clone()->setTimezone(config('app.display_timezone')))
                <span>zuletzt <a href="{{ $neuester->getUrl() }}">{{ $neuester->name }}</a>,
                    <time datetime="{{ $stand->toIso8601String() }}" title="{{ $dates->absolute($neuester->updated_at) }}@if($neuester->updatedBy) von {{ $neuester->updatedBy->name }}@endif">{{ $stand->format('d.m.Y') }}</time></span>
            @endif
        </p>
    </div>
@endif
