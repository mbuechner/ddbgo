# KWE-Aktualisierung und Queue-Abgleich

Das Speichern einer KWE prüft bei der nativen Datenbank-Queue mit einer gezielten
Leseabfrage, ob für diese Node-ID schon ein verfügbarer Auftrag existiert.
Vorhandene Integer-IDs und ältere, als String gespeicherte IDs werden erkannt.
Die Prüfung reserviert keine Einträge und verändert keine Queue-Leases.

Bereits reservierte Aufträge unterdrücken einen neuen Auftrag bewusst nicht:
Ein Edit während der Verarbeitung braucht eine nachfolgende Aktualisierung.
Der tägliche Lauf liest die verfügbaren IDs gesammelt und prüft fehlende IDs
vor dem Anlegen unter demselben Node-spezifischen Producer-Lock erneut.
Bleibt ein konkurrierender Producer auch nach kurzem Warten blockiert, wird
der Auftrag trotzdem angelegt und eine Warnung protokolliert. Ein möglicher
Doppelauftrag erhält die Aktualisierung auch bei einem fehlerhaften Producer.

Der Worker reserviert jeden Auftrag erst unmittelbar vor seiner Verarbeitung
für 600 Sekunden. Nach 300 Sekunden beginnt er keinen weiteren Auftrag mehr;
der gerade bearbeitete Auftrag wird noch abgeschlossen. Wartende Aufträge
verbrauchen dadurch keine Reservierungszeit. Sperren, Verbindungsfehler,
HTTP-Fehler außer 404/410 und ungültige XML-Antworten behalten den Auftrag für
einen späteren Versuch. Die Datenbank-Queue verzögert diese Wiederholung um
600 Sekunden; Core macht sie bei einer nachfolgenden Queue-Bereinigung wieder
verfügbar. Andere Backends geben Fehlversuche erst am Ende des Laufs frei,
damit derselbe Auftrag nicht sofort erneut verarbeitet wird. HTTP 404/410
beendet den Auftrag ohne Änderung am KWE-Knoten.

Die Anzahl der Queue-Abfragen beim einzelnen Speichern hängt nicht mehr von
der Zahl der Einträge ab. Ohne Index auf dem serialisierten Datenfeld kann die
Datenbank intern weiterhin Einträge dieser Queue durchsuchen. Die Optimierung
erspart die bisherigen Claim-/Release-Abfragen und Schreibzugriffe pro Eintrag;
sie garantiert keine konstante SQL-Laufzeit.

`DdbgoCjServiceProvider` erweitert ausschließlich die unveränderte Core-Factory
`queue.database`. Andere Queue-Namen verwenden weiterhin die Core-Klasse.
Bei eigenen Backend-Overrides oder einem alten Service-Container nutzt der
Worker den bisherigen Queue-Abgleich. Seine Konstruktorargumente bleiben gleich.

Nach dem Deployment `drush cr` ausführen und den Web-Container ebenfalls erneuern,
falls der laufende Webserver noch alte Services verwendet. Eine Neuindexierung,
Konfigurationsänderung oder Datenbankmigration ist nicht erforderlich.

Regressionstests:

```sh
php web/modules/custom/ddbgo_cj/tests/php/kwe-queue-worker.test.php
php web/modules/custom/ddbgo_cj/tests/php/kwe-queue-membership.test.php
php vendor/bin/drush.php php:script web/modules/custom/ddbgo_cj/tests/php/kwe-queue-database.test.php
php vendor/bin/drush.php php:script web/modules/custom/ddbgo_cj/tests/php/kwe-queue-processing.test.php
```

Die ersten beiden Tests verwenden isolierte Fixtures; der Membership-Test
benötigt die PHP-Erweiterung SQLite3 für seine Datenbank im Arbeitsspeicher.
Die nativen Datenbanktests verwenden zufällig benannte Test-Queues in einer
zurückgerollten Transaktion. Sie verarbeiten keine echten Aufträge, rufen keine
API auf und speichern keine Inhalte oder Konfiguration. Der Processing-Test
prüft zusätzlich die Reservierung während der Core-Queue-Bereinigung, einen
gleichzeitigen URI-Wechsel und verzögerte Wiederholungen nach einem HTTP-Fehler.
