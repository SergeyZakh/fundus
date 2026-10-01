{{-- Startseite (Einstellung „Startseite: Regale“, im Wiki „Bereiche“). Wird nach shelves.parts.list eingefügt;
     wiki.css blendet BookStacks eigene Regalliste auf der Startseite aus. Nur die Startseite
     liefert $recentlyUpdatedPages mit, daran erkennen wir sie (die Liste gibt es auch unter /shelves).
     Alle Abfragen sind die rechtegefilterten von BookStack: Jeder sieht nur, was er lesen darf. --}}
@if(isset($recentlyUpdatedPages))
    @php
        $keineVorlage = fn ($e) => !($e instanceof \BookStack\Entities\Models\Page && $e->template);
        $zuletzt = $recentlyUpdatedPages->filter($keineVorlage)->take(6);
        // Meistgelesene Seiten über alle Nutzer, nicht persönliche Favoriten:
        // Neue Kollegen sollen sofort sehen, was im Team oft gebraucht wird.
        $haeufig = app(\BookStack\Entities\Queries\QueryPopular::class)->run(12, 1, ['page'])->filter($keineVorlage)->take(5);
        $vorname = explode(' ', trim(user()->name))[0];
        // Symbol eines Bereichs oder Themas, sonst das BookStack-Standardsymbol des Typs.
        $symbol = function ($entity, string $ersatz) {
            $svg = trim(view('fundus.symbol-svg', ['entity' => $entity])->render());
            return $svg !== '' ? $svg : (new \BookStack\Util\SvgIcon($ersatz))->toHtml();
        };
        $proRegal = 9;
    @endphp
    @push('body-class', 'fundus-start ')

    <div class="fundus-start-kopf">
        <div>
            <h1>Hallo {{ $vorname }}</h1>
            <p>Willkommen in {{ setting('app-name') }}: Anleitungen, Abläufe und Wissen an einem Ort.</p>
        </div>
        <button type="button" class="fundus-grosse-suche" data-fundus-suche-oeffnen>
            <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <span>Wonach suchst du?</span>
            <kbd>Strg K</kbd>
        </button>
    </div>

    @include('fundus.aktivitaet')

    <div class="fundus-start-raster">
        <div class="fundus-start-regale">
            @foreach($shelves as $shelf)
                @php($buecher = $shelf->visibleBooks()->get())
                <section class="fundus-regal" aria-labelledby="fundus-regal-{{ $shelf->id }}">
                    <div class="fundus-regal-kopf">
                        <span class="fundus-kreis" aria-hidden="true">{!! $symbol($shelf, 'bookshelf') !!}</span>
                        <div class="fundus-regal-text">
                            <h2 id="fundus-regal-{{ $shelf->id }}"><a href="{{ $shelf->getUrl() }}">{{ $shelf->name }}</a></h2>
                            <p>{{ $shelf->getExcerpt(120) }}</p>
                        </div>
                        @if($buecher->count())
                            <a href="{{ $shelf->getUrl() }}" class="fundus-alle">Alle {{ $buecher->count() }} →</a>
                        @endif
                    </div>
                    @if($buecher->count())
                        <ul class="fundus-buecher">
                            @foreach($buecher->take($proRegal) as $buch)
                                @php($seiten = $buch->pages()->scopes('visible')->where('draft', false)->count())
                                <li>
                                    <a href="{{ $buch->getUrl('?shelf=' . $shelf->id) }}" class="fundus-buch">
                                        <span class="fundus-kreis klein" aria-hidden="true">{!! $symbol($buch, 'book') !!}</span>
                                        <span class="fundus-buch-text">
                                            <span class="fundus-buch-name">{{ $buch->name }}</span>
                                            <span class="fundus-buch-info">{{ $seiten === 0 ? 'Noch leer' : $seiten . ' Artikel' }}</span>
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                        @if($buecher->count() > $proRegal)
                            <a href="{{ $shelf->getUrl() }}" class="fundus-mehr">+ {{ $buecher->count() - $proRegal }} weitere Themen</a>
                        @endif
                    @else
                        <p class="fundus-leer">Hier ist noch nichts für dich freigegeben.</p>
                    @endif
                </section>
            @endforeach
        </div>

        <aside class="fundus-start-listen" aria-label="Aktuelles">
            {{-- Links zu eigenen Werkzeugen der Firma, aus FUNDUS_WERKZEUGE (.env): „Name|Adresse|Beschreibung“,
                 mehrere mit Semikolon getrennt. Leer: kein Kasten. --}}
            @php($werkzeuge = collect(explode(';', (string) env('FUNDUS_WERKZEUGE', '')))
                ->map(fn ($w) => array_map('trim', explode('|', $w)) + [1 => '', 2 => ''])
                ->filter(fn ($w) => $w[0] !== '' && preg_match('#^https?://#', $w[1])))
            @if($werkzeuge->isNotEmpty())
                <section class="fundus-liste" aria-labelledby="fundus-werkzeuge">
                    <h2 id="fundus-werkzeuge">Werkzeuge</h2>
                    @foreach($werkzeuge as [$name, $adresse, $beschreibung])
                        <a class="fundus-zeile" href="{{ $adresse }}" target="_blank" rel="noopener">
                            <span class="fundus-kreis klein fundus-kreis-gut" aria-hidden="true">{!! view('fundus.symbol-svg-datei', ['name' => 'werkzeug'])->render() !!}</span>
                            <span class="fundus-zeile-text">
                                <span class="fundus-zeile-name">{{ $name }}</span>
                                @if($beschreibung !== '')
                                    <span class="fundus-zeile-meta">{{ $beschreibung }}</span>
                                @endif
                            </span>
                            <span class="fundus-extern" aria-label="öffnet in neuem Tab">↗</span>
                        </a>
                    @endforeach
                </section>
            @endif
            {{-- Artikel, die ich verantworte und die zur Prüfung anstehen (pruefung/Pruefung.php). Leer: kein Kasten. --}}
            @php($anstehend = \FundusPruefung\Pruefung::anstehendFuer(user()->id))
            @if($anstehend->isNotEmpty())
                <section class="fundus-liste fundus-zu-pruefen" aria-labelledby="fundus-zu-pruefen">
                    <h2 id="fundus-zu-pruefen">Zu prüfen <span class="anzahl">{{ $anstehend->count() }}</span></h2>
                    @foreach($anstehend->take(5) as $eintrag)
                        @php($buch = $eintrag['seite']->book)
                        <a class="fundus-zeile" href="{{ $eintrag['seite']->getUrl() }}">
                            <span class="fundus-kreis klein" aria-hidden="true">{!! $buch ? $symbol($buch, 'book') : (new \BookStack\Util\SvgIcon('page'))->toHtml() !!}</span>
                            <span class="fundus-zeile-text">
                                <span class="fundus-zeile-name">{{ $eintrag['seite']->name }}</span>
                                @if($buch)<span class="fundus-zeile-meta">{{ $buch->name }}</span>@endif
                            </span>
                            <span class="fundus-status {{ $eintrag['lage'] === 'faellig' ? 'acht' : 'bald' }}"
                                  title="Fällig am {{ $eintrag['faellig_am']->setTimezone(config('app.display_timezone'))->format('d.m.Y') }}">
                                {{ $eintrag['lage'] === 'faellig' ? 'fällig' : 'bis ' . $eintrag['faellig_am']->setTimezone(config('app.display_timezone'))->format('d.m.') }}
                            </span>
                        </a>
                    @endforeach
                    @if($anstehend->count() > 5)
                        <p class="fundus-leer">und {{ $anstehend->count() - 5 }} weitere</p>
                    @endif
                </section>
            @endif
            {{-- Offene Hinweise „veraltet/falsch“ zu Artikeln, die ich verantworte (hinweise/Hinweise.php). --}}
            @php($offeneHinweise = \FundusHinweise\Hinweise::rueckmeldungen(user()->id))
            @if($offeneHinweise)
                <section class="fundus-liste fundus-zu-pruefen" aria-labelledby="fundus-offene-hinweise">
                    <h2 id="fundus-offene-hinweise">Offene Hinweise <span class="anzahl">{{ count($offeneHinweise) }}</span></h2>
                    @foreach(array_slice($offeneHinweise, 0, 5) as $hinweis)
                        <a class="fundus-zeile" href="{{ $hinweis['adresse'] }}">
                            <span class="fundus-kreis klein fundus-kreis-hinweis" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 22V4a1 1 0 0 1 .4-.8A6 6 0 0 1 8 2c3 0 5 2 8 2a6 6 0 0 0 3.6-1.2 1 1 0 0 1 1.4.9v10a1 1 0 0 1-.4.8A6 6 0 0 1 17 16c-3 0-5-2-8-2a8 8 0 0 0-5 2"/></svg>
                            </span>
                            <span class="fundus-zeile-text">
                                <span class="fundus-zeile-name">{{ $hinweis['titel'] }}</span>
                                <span class="fundus-zeile-meta" title="{{ $hinweis['text'] }}">{{ $hinweis['text'] }}</span>
                            </span>
                            <span class="fundus-zeit">{{ \Carbon\Carbon::parse($hinweis['zeit'])->locale(config('app.locale'))->shortRelativeToNowDiffForHumans() }}</span>
                        </a>
                    @endforeach
                    @if(count($offeneHinweise) > 5)
                        <p class="fundus-leer">und {{ count($offeneHinweise) - 5 }} weitere</p>
                    @endif
                </section>
            @endif
            @foreach([
                ['id' => 'zuletzt', 'titel' => 'Zuletzt geändert', 'eintraege' => $zuletzt, 'zeit' => true, 'mehr' => ['/pages/recently-updated', 'Alle Änderungen'], 'leer' => trans('entities.no_pages_recently_updated')],
                ['id' => 'haeufig', 'titel' => 'Häufig gebraucht', 'eintraege' => $haeufig, 'zeit' => false, 'mehr' => null, 'leer' => 'Noch keine Aufrufe gezählt.'],
                ['id' => 'entwuerfe', 'titel' => 'Meine Entwürfe', 'eintraege' => collect($draftPages), 'zeit' => true, 'mehr' => null, 'leer' => null],
                ['id' => 'favoriten', 'titel' => 'Meine Favoriten', 'eintraege' => collect($favourites), 'zeit' => false, 'mehr' => ['/favourites', 'Alle Favoriten'], 'leer' => null],
            ] as $liste)
                @continue(!$liste['leer'] && $liste['eintraege']->isEmpty())
                <section class="fundus-liste" aria-labelledby="fundus-{{ $liste['id'] }}">
                    <h2 id="fundus-{{ $liste['id'] }}">{{ $liste['titel'] }}</h2>
                    @forelse($liste['eintraege'] as $eintrag)
                        @php($buch = $eintrag instanceof \BookStack\Entities\Models\Page ? $eintrag->book : null)
                        <a class="fundus-zeile" href="{{ $eintrag->getUrl() }}">
                            <span class="fundus-kreis klein" aria-hidden="true">{!! $buch ? $symbol($buch, 'book') : (new \BookStack\Util\SvgIcon($eintrag->getType()))->toHtml() !!}</span>
                            <span class="fundus-zeile-text">
                                <span class="fundus-zeile-name">{{ $eintrag->name }}</span>
                                @if($buch)<span class="fundus-zeile-meta">{{ $buch->name }}</span>@endif
                            </span>
                            @if($liste['id'] === 'entwuerfe')
                                <span class="fundus-status acht">Entwurf</span>
                            @elseif($liste['zeit'])
                                <span class="fundus-zeit" title="{{ $dates->absolute($eintrag->updated_at) }}">{{ $eintrag->updated_at->locale(config('app.locale'))->shortRelativeToNowDiffForHumans() }}</span>
                            @endif
                        </a>
                    @empty
                        <p class="fundus-leer">{{ $liste['leer'] }}</p>
                    @endforelse
                    @if($liste['mehr'])
                        <a href="{{ url($liste['mehr'][0]) }}" class="fundus-mehr">{{ $liste['mehr'][1] }}</a>
                    @endif
                </section>
            @endforeach
        </aside>
    </div>

    <p class="fundus-dank">
        Fundus läuft mit <a href="https://www.bookstackapp.com" target="_blank" rel="noopener">BookStack</a>,
        freier Software von Dan Brown und der BookStack-Community. Danke für eure Arbeit!
    </p>
@endif
