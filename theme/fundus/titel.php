<?php

/*
 * Berufstitel neben den Namen im Wiki (Artikel, Kommentare, Aktivität, Kopfleiste, Profil).
 * BookStack kennt kein Feld dafür; deshalb pflegt die Technik die Liste hier.
 *
 * Schlüssel: die E-Mail-Adresse des Kontos, klein geschrieben (so, wie sie aus Microsoft 365 kommt).
 * „stufe“ bestimmt die Farbe der Plakette (wiki.css, .fundus-titel):
 *   leitung  schwarz   Geschäftsführung, CEO, Bereichsleitung
 *   senior   blau      Senior Consultant, Teamleitung
 *   junior   türkis    Consultant, Junior Consultant
 *   azubi    grün      Auszubildende, Werkstudierende
 *   team     grau      alle anderen (Verwaltung, Vertrieb …)
 * Wer nicht in der Liste steht, bekommt keinen Titel; das Profilbild erscheint trotzdem.
 * Nach einer Änderung genügt ein Neuladen der Seite.
 */
return [
    // 'vorname.nachname@firma.example' => ['titel' => 'Teamleitung', 'stufe' => 'senior'],
];
