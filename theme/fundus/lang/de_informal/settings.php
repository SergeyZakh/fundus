<?php

// Begriffe wie in entities.php: Bereich · Thema · Abschnitt · Artikel.
// Nur Einstellungen, in denen die Begriffe Inhalte meinen; „Seite“ als Webseite bleibt. Dazu die Texte,
// die im Original noch siezen (unten).
return [
    'app_default_editor' => 'Standard-Editor für Artikel',
    'app_default_editor_desc' => 'Welcher Editor beim Bearbeiten neuer Artikel verwendet wird. Pro Artikel änderbar, sofern die Rechte es erlauben.',
    'app_homepage_desc' => 'Wähle einen Artikel, der statt der normalen Startseite angezeigt wird. Rechte am Artikel werden dafür ignoriert.',
    'app_homepage_select' => 'Artikel auswählen',
    'app_disable_comments_desc' => 'Schaltet Kommentare in allen Artikeln ab. Vorhandene Kommentare werden nicht angezeigt.',
    'bookshelf_color' => 'Farbe für Bereiche',
    'book_color' => 'Farbe für Themen',
    'chapter_color' => 'Farbe für Abschnitte',
    'page_color' => 'Farbe für Artikel',
    'page_draft_color' => 'Farbe für Entwürfe',
    'sorting_book_default' => 'Standard-Sortierregel für Themen',
    'sorting_book_default_desc' => 'Sortierregel, die neue Themen bekommen. Bestehende Themen bleiben unverändert; pro Thema änderbar.',
    'sort_rule_assigned_to_x_books' => ':count Thema zugewiesen|:count Themen zugewiesen',
    'sort_rule_delete_desc' => 'Diese Sortierregel entfernen. Themen mit dieser Regel werden wieder von Hand sortiert.',
    'sort_rule_delete_warn_books' => 'Diese Sortierregel wird in :count Themen verwendet. Bist du sicher, dass du sie löschen möchtest?',
    'sort_rule_delete_warn_default' => 'Diese Sortierregel ist der Standard für Themen. Bist du sicher, dass du sie löschen möchtest?',
    'sort_rule_operations_desc' => 'Ziehe Sortierschritte aus der Liste der verfügbaren Schritte hierher. Sie werden von oben nach unten angewendet. Beim Speichern gelten Änderungen für alle zugewiesenen Themen.',
    'sort_rule_op_chapters_first' => 'Abschnitte zuerst',
    'sort_rule_op_chapters_last' => 'Abschnitte zuletzt',
    'maint_image_cleanup_desc' => 'Durchsucht Artikel und Versionen nach ungenutzten und doppelten Bildern. Sichere vorher Datenbank und Bilder.',
    'maint_delete_images_only_in_revisions' => 'Auch Bilder löschen, die nur in alten Versionen von Artikeln vorkommen',
    'maint_recycle_bin_desc' => 'Gelöschte Bereiche, Themen, Abschnitte und Artikel landen im Papierkorb und lassen sich wiederherstellen oder endgültig löschen. Ältere Einträge können je nach Einstellung nach einer Weile automatisch entfernt werden.',
    'role_manage_entity_permissions' => 'Rechte aller Themen, Abschnitte und Artikel verwalten',
    'role_manage_own_entity_permissions' => 'Rechte eigener Themen, Abschnitte und Artikel verwalten',
    'role_manage_page_templates' => 'Vorlagen verwalten',
    'role_editor_change' => 'Editor eines Artikels wechseln',
    'role_asset_desc' => 'Diese Rechte gelten standardmäßig im ganzen Wiki. Rechte an einzelnen Themen, Abschnitten und Artikeln haben Vorrang.',
    'role_controlled_by_page_delete' => 'Gesteuert durch das Recht, Artikel zu löschen',

    // Im Original von BookStacks de_informal noch in der Sie-Form.
    'sorting_page_limits_desc' => 'Lege fest, wie viele Einträge die Listen im Wiki pro Seite zeigen. Weniger Einträge laden schneller, mehr ersparen das Blättern. Am besten ein Vielfaches von 6.',
    'recycle_bin_destroy_confirm' => 'Dieser Schritt löscht das Element samt allem, was darin liegt, endgültig. Das lässt sich nicht rückgängig machen. Bist du sicher, dass du es endgültig löschen möchtest?',
    'users_role_desc' => 'Wähle, welche Rollen die Person bekommt. Hat sie mehrere Rollen, gelten die Rechte aller Rollen zusammen.',
];
