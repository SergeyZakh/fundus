{{-- Kopf der Anmeldeseite. Ersetzt BookStacks leeren Platzhalter auth/parts/login-message (steht in der Karte
     direkt unter der Überschrift „Anmelden“, die wiki.css nur noch für Vorleseprogramme stehen lässt).
     Gestaltung: wiki.css, „---------- Anmeldeseite“. --}}
<div class="fundus-anmeldung-kopf">
    <img src="{{ url('/theme/fundus/icon-128.png') }}" alt="" width="56" height="56">
    <p class="titel">Willkommen bei {{ setting('app-name') }}</p>
    <p>
        @if(config('auth.method') === 'oidc')
            Melde dich mit deinem Firmenkonto an.
        @else
            Melde dich mit deiner E-Mail-Adresse und deinem Passwort an.
        @endif
    </p>
</div>
