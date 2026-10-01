# Änderungen

Format nach [Keep a Changelog](https://keepachangelog.com/de/1.1.0/), Versionen nach [Semantic Versioning](https://semver.org/lang/de/).

## Unveröffentlicht

### Neu

- `einrichten.py --texte` bringt die Beschreibungen von Bereichen, Themen und Abschnitten auf den
  Stand des Skripts. Ohne die Option setzt es sie wie bisher nur beim Anlegen, damit Änderungen der
  Redaktion bleiben.

### Sicherheit

- **Frag Fundus nimmt je Person höchstens 10 Fragen in der Minute an.** Ollama rechnet eine Antwort
  nach der anderen; ohne Grenze konnte eine Person oder ein Skript mit ihrer Sitzung den Chat für alle
  blockieren. Wer darüber kommt, bekommt eine Meldung und fragt nach einer Minute weiter.
- **Gäste sehen keine Personenliste mehr.** War in BookStack der öffentliche Zugriff eingeschaltet,
  stand in jeder Seite die Liste aller Konten mit Namen und Berufstitel. Die Startseite begrüßte
  Gäste außerdem mit „Hallo Guest“ und zeigte ihnen ein leeres Aktivitätsraster. Gäste sehen jetzt
  nur Bereiche, „Zuletzt geändert“ und „Häufig gebraucht“.
- **Texterkennung ohne Verbindung nach außen.** Der Dienst `ocr` hängt nur noch an einem internen
  Docker-Netz, das ihn mit dem Wiki verbindet. Selbst bei einer Lücke im PDF- oder Bildparser kann er
  keine Daten hinausschicken.
- **Keycloak 26.8.0** (vorher 26.7.4). Behebt drei als kritisch eingestufte Lücken in mitgelieferten
  Bibliotheken (Netty, Bouncy Castle). Anmeldetest mit 11 Prüfungen grün.
- Bricht die Verbindung zu Ollama ab, nennt der Chat keine interne Adresse mehr. Die genaue Meldung
  steht im Protokoll des Wikis.

### Geändert

- **BookStack 26.09.1** (vorher 26.05.5). BookStack übergibt an Artikelansicht und Startseite
  weniger Daten, weil die Seitenleisten jetzt eigene Blöcke sind. Ohne Anpassung fehlten Artikelkopf,
  Rückmeldung und die ganze Startseite von Fundus. Die Bausteine erkennen ihre Seite jetzt anders und
  holen die Listen selbst; mit älteren Versionen laufen sie weiter.
- Ollama 0.34.4 (vorher 0.34.2).
- Rauchtest, Handbuch-Bilder und Vorstellungsbilder steuern Chrome über ein gemeinsames Modul
  `skripte/wiki-browser.mjs` statt über drei fast gleiche Kopien. Ihre Hilfsskripte im Wiki-Container
  räumen jetzt alle drei wieder ab.

### Behoben

- Nach schnellem Doppelklick auf „Kopieren“ im Chat blieb der Haken dauerhaft stehen.
- Die Startseite zählte Vorlagen als Artikel, die übrigen Übersichten nicht.
- Der Dienst `sicherung` hinterließ bei jedem Neuanlegen des Containers ein leeres namenloses Volume.
- `.env.example` nannte zwei echte Domains als Beispiel.

## [0.1.0] – 2026-10-01

Erste öffentliche Version, als Vorabversion gekennzeichnet.

### Neu

- **Theme `fundus` für BookStack 26.05.5.** Die Startseite zeigt die Bereiche mit ihren Themen,
  die eigene Aktivität und Links zu den Werkzeugen der Firma. Artikel bekommen einen Kopf mit Stand
  und Lesezeit, dazu gibt es einen Zen-Modus und die Schnellsuche mit Strg + K. Die Oberfläche ist
  deutsch und spricht mit Du, und sie nennt Bereich, Thema, Abschnitt und Artikel statt Regal, Buch,
  Kapitel und Seite.

- **KI-Chat „Frag Fundus“** über Ollama. Er antwortet nur aus Artikeln, die die fragende Person
  lesen darf, nennt die Quellen und springt auf Klick zur Fundstelle im Artikel. Ollama läuft als
  Dienst im Stapel und lädt seine Modelle beim ersten Start selbst, ein vorhandener Ollama-Server
  geht ebenso.

- **Texterkennung für PDFs und Bilder** (Dienst `ocr`). Ihr Text wird durchsuchbar und steht der
  KI zur Verfügung. Nimmt man ein Bild aus dem Artikel, verschwindet auch sein Text.

- **Rückmeldungen, Prüffristen und Hinweise.** Unter jedem Artikel lässt sich melden, was veraltet
  oder falsch ist. Wer den Artikel verantwortet, sieht das am Fundus-Knopf, ebenso fällige Prüfungen.

- **Nächtliche Sicherung** mit verschlüsselter Kopie außer Haus per rclone und Wiederherstellung per
  Skript. Scheitert ein Schritt, wird die Sicherung verworfen und den Admins gemeldet.

- **Anmeldung über OIDC** mit mitgeliefertem Keycloak-Realm. Die Rollen folgen den Gruppen des
  Anmeldedienstes, Berufstitel pflegen Admins im Wiki.

- **Einrichtung per Skript.** `einrichten.py` legt Rollen, Bereiche, Vorlagen und das Handbuch für
  Mitarbeitende an, mit `--beispiele` auch drei Beispielartikel und zwei Beispielkonten.
  `env-anlegen.py` erzeugt die `.env` mit Zufallswerten.

- **Betrieb hinter einem Reverse Proxy.** Nach außen offen sind nur Wiki, draw.io und auf Wunsch
  Keycloak, als Vorgabe nur auf `127.0.0.1`. E-Mails gehen über den SMTP-Server der Firma
  (`MAIL_*`). Alle Dienste laufen mit `no-new-privileges`.

- **Anleitungen.** `docs/START.md` führt ohne technische Vorkenntnisse bis zum eingerichteten Wiki
  unter Windows, `docs/ENTWICKLUNG.md` beschreibt Aufbau, Betrieb, Update und Tests.

- **Prüfungen** in GitHub Actions, Dependabot für Images und Actions, dazu Theme-Tests, ein
  Rauchtest für die Oberfläche und ein Test der Anmeldung mit echtem Keycloak.

[0.1.0]: https://github.com/SergeyZakh/fundus/releases/tag/v0.1.0
