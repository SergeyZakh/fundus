{{-- Benachrichtigungen durch Fundus (hinweise/Hinweise.php, Logik in wiki.js „hinweise“).
     Nur ein leerer Halter: Die Einträge lädt das Skript nach dem Seitenaufbau, damit keine Seite
     auf die Abfragen wartet. Steht über dem Knopf „Frag Fundus“, ohne KI unten rechts. --}}
@if(user()->hasAppAccess() && !user()->isGuest())
    <div class="fundus-hinweise" data-fundus-hinweise hidden
         data-adresse="{{ url('/fundus/hinweise') }}" data-gesehen="{{ url('/fundus/hinweise/gesehen') }}"></div>
@endif
