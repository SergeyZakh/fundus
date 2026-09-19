{{-- Vor den Kopfleisten-Links („Bereiche“, „Themen“ …). Schaltet den Zen-Modus ein und aus: nur der Artikel
     auf Weiß, ohne Seitenleisten, Aktionen und Kopfleisten-Links (wiki.css „Zen-Modus“, wiki.js leistenKnopf).
     Sichtbar nur auf Seiten mit Leisten. --}}
@if(user()->hasAppAccess())
    <button type="button" class="fundus-leisten-knopf" data-fundus-leisten-knopf
            title="Zen-Modus" aria-label="Zen-Modus" aria-pressed="false">
        <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 9V5a1 1 0 0 1 1-1h4M15 4h4a1 1 0 0 1 1 1v4M20 15v4a1 1 0 0 1-1 1h-4M9 20H5a1 1 0 0 1-1-1v-4"/>
        </svg>
    </button>
@endif
