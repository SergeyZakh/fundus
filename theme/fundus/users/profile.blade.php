{{-- Profilseite einer Person. Ersetzt BookStacks users/profile.blade.php (sonst hängt das Theme nur Bausteine ein):
     Deren Aufbau – vier gleiche Listen untereinander, Zahlen klein am Rand – ließ sich nicht per Baustein ändern.
     Nach einem BookStack-Update mit resources/views/users/profile.blade.php vergleichen (Entwicklerdoku, „Update“).
     Daten wie im Original: $user, $activity, $recentlyCreated, $assetCounts, $dates. --}}
@extends('layouts.simple')

@section('body')
    @php
        $titel = \FundusAnmeldung\Anmeldung::titelFuer([$user])[$user->id] ?? null;
        // Initialen und Farbe wie in wiki.js (avatarElement), damit die Person überall gleich aussieht.
        $teile = preg_split('/\s+/u', trim($user->name)) ?: ['?'];
        $kuerzel = mb_strtoupper(mb_substr($teile[0], 0, 1) . (count($teile) > 1 ? mb_substr(end($teile), 0, 1) : ''));
        $summe = 0;
        foreach (mb_str_split($user->name) as $zeichen) {
            $summe = ($summe * 31 + mb_ord($zeichen)) % 4294967296;
        }
        $suche = fn (string $typ) => url('/search?term=' . urlencode('{created_by:' . $user->slug . '} {type:' . $typ . '}'));
        $bearbeiten = user()->id === $user->id ? url('/my-account/profile')
            : (userCan('users-manage') ? url('/settings/users/' . $user->id) : null);
        $zahlen = [
            ['pages', 'page', 'Artikel', 'Artikel', 'page'],
            ['chapters', 'chapter', 'Abschnitt', 'Abschnitte', 'chapter'],
            ['books', 'book', 'Thema', 'Themen', 'book'],
            ['shelves', 'bookshelf', 'Bereich', 'Bereiche', 'bookshelf'],
        ];
        $weitere = [
            ['chapters', 'Abschnitte', 'chapter'],
            ['books', 'Themen', 'book'],
            ['shelves', 'Bereiche', 'bookshelf'],
        ];
    @endphp

    <div class="container medium pt-xl fundus-profil">

        <section class="fundus-profil-kopf">
            @if($user->image_id)
                <img class="fundus-avatar" style="--groesse: 88px" src="{{ $user->getAvatar(176) }}" alt="">
            @else
                <span class="fundus-avatar fundus-initialen farbe-{{ $summe % 6 }}" style="--groesse: 88px" aria-hidden="true">{{ $kuerzel ?: '?' }}</span>
            @endif
            <div class="fundus-profil-wer">
                <h1>{{ $user->name }}</h1>
                <div class="fundus-profil-meta">
                    @if($titel && $titel['titel'])
                        <span class="fundus-titel stufe-{{ $titel['stufe'] }}">{{ $titel['titel'] }}</span>
                    @endif
                    @foreach($user->roles as $rolle)
                        <span class="fundus-profil-rolle">{{ $rolle->display_name }}</span>
                    @endforeach
                    <span class="fundus-profil-seit">Im Wiki seit {{ $user->created_at->format('d.m.Y') }}</span>
                </div>
            </div>
            @if($bearbeiten)
                <a class="fundus-profil-bearbeiten" href="{{ $bearbeiten }}">
                    <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.4 3.6a2.1 2.1 0 1 1 3 3L7 19l-4 1 1-4z"/></svg>
                    Profil bearbeiten
                </a>
            @endif
        </section>

        <section class="fundus-profil-zahlen" aria-label="Angelegte Inhalte">
            @foreach($zahlen as [$schluessel, $typ, $eins, $viele, $symbol])
                @php $n = $assetCounts[$schluessel] ?? 0; @endphp
                <a href="{{ $suche($typ) }}" class="fundus-profil-zahl text-{{ $symbol }}">
                    <span class="symbol">@icon($symbol)</span>
                    <b>{{ $n }}</b>
                    <span>{{ $n === 1 ? $eins : $viele }}</span>
                </a>
            @endforeach
        </section>

        <div class="fundus-profil-spalten">
            <div class="fundus-profil-haupt">
                <section class="fundus-profil-karte">
                    <header>
                        <h2>Zuletzt angelegte Artikel</h2>
                        @if(count($recentlyCreated['pages']) > 0)
                            <a href="{{ $suche('page') }}">Alle anzeigen</a>
                        @endif
                    </header>
                    @if(count($recentlyCreated['pages']) > 0)
                        <ul class="fundus-profil-liste">
                            @foreach($recentlyCreated['pages'] as $seite)
                                <li>
                                    <a href="{{ $seite->getUrl() }}">
                                        <span class="titel">{{ $seite->name }}</span>
                                        <span class="auszug">{{ $seite->getExcerpt(110) }}</span>
                                    </a>
                                    <span class="wo">
                                        @if($seite->book){{ $seite->book->name }} · @endif{{ $dates->relative($seite->created_at) }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="fundus-profil-leer">{{ $user->name }} hat noch keine Artikel angelegt.</p>
                    @endif
                </section>

                <div class="fundus-profil-weitere">
                    @foreach($weitere as [$schluessel, $name, $typ])
                        <section class="fundus-profil-karte">
                            <header>
                                <h2>{{ $name }}</h2>
                                @if(count($recentlyCreated[$schluessel]) > 0)
                                    <a href="{{ $suche($typ) }}">Alle</a>
                                @endif
                            </header>
                            @if(count($recentlyCreated[$schluessel]) > 0)
                                <ul class="fundus-profil-liste kurz">
                                    @foreach($recentlyCreated[$schluessel] as $eintrag)
                                        <li>
                                            <a href="{{ $eintrag->getUrl() }}"><span class="titel">{{ $eintrag->name }}</span></a>
                                            <span class="wo">{{ $dates->relative($eintrag->created_at) }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @else
                                <p class="fundus-profil-leer">Noch keine.</p>
                            @endif
                        </section>
                    @endforeach
                </div>
            </div>

            <aside class="fundus-profil-seite">
                <section class="fundus-profil-karte" id="recent-user-activity">
                    <header><h2>Letzte Aktivität</h2></header>
                    @include('common.activity-list', ['activity' => $activity])
                </section>
            </aside>
        </div>
    </div>
@stop
