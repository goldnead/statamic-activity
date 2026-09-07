<?php

return [

    // Beschriftungen der Einstellungs-Seite. Die Seite selbst gehört
    // statamic-brand-context; dieses Addon liefert nur die Feldliste
    // (Support\Settings) und die Wörter dazu.

    'permission_manage_settings' => 'Einstellungen des Ereignisspeichers verwalten',

    'groups' => [

        'recording' => [
            'title' => 'Aufzeichnung',
            'description' => 'Ob überhaupt geschrieben wird. Die Kennung der Anwendung und die Warteschlange bleiben in config/activity.php: die eine steht auf jeder schon geschriebenen Zeile und zerschneidet die Vergangenheit, wenn sie wechselt, die andere gehört zum Betrieb der Maschine.',
        ],

        'context' => [
            'title' => 'Anfragekontext',
            'description' => 'Was von der Anfrage in der Zeile landet. Jedes Feld ist einzeln schaltbar, weil jedes ein anderes Gewicht hat. IP-Adressen werden nie erfasst; die Kennung des Browsers nur als grobe Kategorie, nie im Wortlaut.',
        ],

        'sanitizer' => [
            'title' => 'Filter vor dem Schreiben',
            'description' => 'Läuft bei jedem Schreibvorgang. Das ist die Durchsetzungsstelle für Regeln wie „diese Ereignisart verlässt nie ihr eigenes System" und „dieses Feld wird nirgends gespiegelt". Was hier gefiltert wird, kommt gar nicht erst in die Datenbank, und was schon drin ist, holt kein Filter zurück.',
        ],

        'retention' => [
            'title' => 'Aufbewahrung',
            'description' => 'Beides greift erst, wenn activity:prune beziehungsweise activity:anonymize läuft, und beides ist dann unumkehrbar. Prüfen Sie eine Änderung vorher mit dem Zusatz --dry-run: der Befehl sagt Ihnen, wie viele Zeilen betroffen wären, ohne eine anzufassen. Die Werte gelten je Marke, die beiden Befehle laufen dagegen über den gesamten Bestand und lesen den Wert der Marke, unter der sie gestartet werden. Aufbewahrungsfristen je Ereignisart bleiben in config/activity.php.',
        ],

        'cp' => [
            'title' => 'Control Panel',
            'description' => 'Die Leseansicht. Ob es sie gibt, bleibt in config/activity.php: das wird gelesen, während die Routen entstehen, und käme hier erst nach dem nächsten Deploy an.',
        ],

    ],

    'fields' => [

        'enabled' => [
            'label' => 'Ereignisse aufzeichnen',
            'description' => 'Aus wird nichts geschrieben und kein Produzent feuert. Der gemeinte Fall ist eine Kopie der Produktionsdaten auf einem Testsystem, die nicht anfangen soll, eigene Ereignisse zu erzeugen. Vorhandene Zeilen bleiben; es entstehen nur keine neuen.',
        ],

        'context_capture' => [
            'label' => 'Anfragekontext überhaupt erfassen',
            'description' => 'Der Hauptschalter über die vier Felder darunter. Aus wird von der Anfrage nichts mitgeschrieben, egal was die einzelnen Schalter sagen. Wirkt ab dem nächsten Ereignis, nicht rückwirkend.',
        ],
        'context_utm' => [
            'label' => 'Kampagnenparameter (utm_*)',
            'description' => 'Woher der Besuch kam, so wie es in der Adresszeile stand. Aus verlieren Sie die Zuordnung von Ereignissen zu Kampagnen ab diesem Zeitpunkt; bereits geschriebene Zeilen behalten sie.',
        ],
        'context_referrer' => [
            'label' => 'Verweisende Seite',
            'description' => 'Die Adresse, von der der Besucher kam. Kann eine fremde Seite mitsamt ihren Parametern enthalten, deshalb einzeln schaltbar.',
        ],
        'context_page_url' => [
            'label' => 'Aufgerufene Seite',
            'description' => 'Die eigene Adresse, auf der das Ereignis passierte. Bei Seiten, deren Adresse selbst schon etwas über die Person sagt, ist das mehr als eine Wegmarke.',
        ],
        'context_user_agent_category' => [
            'label' => 'Art des Geräts',
            'description' => 'Eine grobe Kategorie, nie die vollständige Browser-Kennung. Aus steht in der Zeile gar nichts über das Gerät.',
        ],

        'sanitizer_strip_keys' => [
            'label' => 'Zu entfernende Schlüssel',
            'description' => 'Namen, die vor dem Schreiben aus Eigenschaften und Kontext entfernt werden, als Teilzeichenkette und in jeder Tiefe: „token" trifft auch „access_token". Hier gehört ein Name hinein, sobald irgendwo ein neues Feld mit einem Geheimnis auftaucht. Das Streichen eines Namens wirkt sofort auf neue Zeilen und holt aus alten nichts zurück.',
        ],
        'sanitizer_blocked_event_types' => [
            'label' => 'Gesperrte Ereignisarten',
            'description' => 'Ereignisarten, die gar nicht erst geschrieben werden. Das ist die Stelle, an der eine Regel wie „dieser Bereich spiegelt nie in einen zentralen Speicher" tatsächlich durchgesetzt wird, und nicht nur dokumentiert ist.',
        ],
        'sanitizer_max_payload_bytes' => [
            'label' => 'Höchstgröße einer Nutzlast (Bytes)',
            'description' => 'Was darüber liegt, wird gekürzt, nicht verworfen. Kleiner setzen kürzt ab dem nächsten Ereignis; bereits geschriebene Zeilen bleiben so lang, wie sie sind.',
        ],

        'retention_days' => [
            'label' => 'Aufbewahrung (Tage)',
            'description' => 'Zeilen, die älter sind, löscht der nächste Lauf von activity:prune endgültig. Diese Zahl zu verkleinern ist deshalb kein Einstellen, sondern ein Löschauftrag mit Wirkung ab dem nächsten Lauf: von 365 auf 90 bedeutet, dass beim nächsten Lauf neun Monate Bestand verschwinden. Leer behält alles. Prüfen Sie eine Verkleinerung vorher mit activity:prune --dry-run.',
        ],
        'retention_anonymize_after_days' => [
            'label' => 'Anonymisieren nach (Tagen)',
            'description' => 'Meist das, was Sie statt Löschen wollen: der nächste Lauf von activity:anonymize entfernt aus älteren Zeilen die personenbezogenen Felder und lässt die zählbare Tatsache stehen. Auch das ist unumkehrbar. Leer heißt: nur auf ausdrückliche Anweisung, etwa auf eine Löschanfrage hin.',
        ],

        'cp_per_page' => [
            'label' => 'Zeilen je Seite',
            'description' => 'Wie viele Ereignisse die Leseansicht auf einmal zeigt, solange niemand etwas anderes wählt.',
        ],

    ],

];
