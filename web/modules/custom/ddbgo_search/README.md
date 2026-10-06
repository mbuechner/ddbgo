# Zusammengesetzte Sparte der KWE-Suche

Der Search-API-Prozessor `ddbgo_kwe_sector_label` liefert den ursprünglichen
Text für das weiterhin `aggregated_sparte` genannte Indexfeld. Er liest die
Taxonomiebezeichnungen über Search APIs Feldextraktion und bildet
`Sparte (Untersparte)`. Ohne Untersparte entfällt der Klammerzusatz; ohne Sparte
erscheint nur die Untersparte. Sind beide Werte leer, wird kein Wert hinzugefügt.

Die Zusammensetzung gilt für Indexierung und Ergebnisdarstellung. Die bestehende
Volltextsuche und Hervorhebung verwenden dieselbe Feld-ID. Node- und
Taxonomiewerte werden ausschließlich gelesen. Nach Import der Indexkonfiguration
alle KWE-Einträge zur Neuindexierung vormerken und anschließend indexieren:

```sh
drush search-api:reset-tracker suche_kwe
drush search-api:index suche_kwe
```

Ein Löschen des bestehenden Index ist dafür nicht erforderlich.

# Änderungen an rückwärts verknüpften Inhalten

`ReverseReferenceTracker` merkt betroffene Personen und Aggregatoren zur
Neuindexierung vor, wenn ihre verknüpften KWE, Bestände, Aggregatoren oder
Personen-Paragraphen angelegt, geändert oder gelöscht werden. Auch neue
Zuordnungen, entfernte Zuordnungen, Publikationsstatus und Rollenbezeichnungen
werden berücksichtigt. Vorherige Beziehungen werden vor dem Speichern bzw.
Löschen festgehalten; Paragraphen werden über ihre referenzierten Revisionen
gelesen. Nur aktive, beschreibbare Indizes mit den betroffenen Prozessorfeldern
und passenden Bundles/Sprachen werden aktualisiert.

Nach dem Deployment den Cache neu aufbauen, damit die neuen Hooks und der
Service erkannt werden. Bereits veraltete Suchtexte einmalig neu indexieren:

```sh
drush cr
drush search-api:reset-tracker personen
drush search-api:index personen
drush search-api:reset-tracker aggregator
drush search-api:index aggregator
```

Die isolierten Regressionstests benötigen keine Drupal-Datenbank und schreiben
keine Inhalte oder Konfiguration:

```sh
php web/modules/custom/ddbgo_search/tests/php/reverse-reference-tracker.test.php
```

# Gebündelte Personen-Verknüpfungen

Die Prozessoren für Personen-KWE, Personen-Bestände und Personen-Aggregatoren
bereiten die ursprünglichen Feldwerte einer Views-Suchseite gemeinsam vor der
Hervorhebung auf. `PersonRelations` fragt die Verknüpfungen einmal pro Inhaltstyp
ab und lädt die benötigten Nodes, Paragraphen und Rollen gesammelt. Beim Lesen
der Paragraphen-Referenzen verwenden die Prozessoren die skalaren `target_id`
Werte, damit dabei keine einzelnen Revisionen nachgeladen werden.

Die bestehende Ausgabe bleibt erhalten: SQL berücksichtigt Verknüpfungen in
allen Übersetzungen; Links und Rollen verwenden wie bisher die geladenen
Default-Entitäten und Paragraphen. Bei Titeln, die die Datenbank gleich sortiert,
übernimmt eine zusätzliche Einzelabfrage die bisherige Reihenfolge. Die vorbereiteten
Relationen gelten nur während der Feldextraktion; außerhalb einer Views-Suche
bleibt die bisherige Einzelverarbeitung verfügbar.

Fehlt der neue Service noch in einem zwischengespeicherten Web-Container,
verwenden die Prozessoren ebenfalls die bisherige Einzelverarbeitung. Die
Personenliste bleibt damit verfügbar, bis der Container erneuert wurde.

Hervorhebung und Views verwenden anschließend dieselben ursprünglichen
Feldwerte. Cache-Tags für Nodes, Paragraphen, Rollen und URL-Aliase halten auch
gespeicherte Suchergebnisse nach Änderungen aktuell. Die Indexfelder und ihre
Werte ändern sich nicht; eine Neuindexierung ist für diese Optimierung nicht
nötig. Nach dem Deployment den Cache mit `drush cr` neu aufbauen.

Die isolierten und nativen Regressionstests lassen sich so ausführen:

```sh
php web/modules/custom/ddbgo_search/tests/php/person-relations.test.php
php vendor/bin/drush.php php:script web/modules/custom/ddbgo_search/tests/php/person-search-batch.test.php
php vendor/bin/drush.php php:script web/modules/custom/ddbgo_search/tests/php/person-role-output.test.php
php vendor/bin/drush.php php:script web/modules/custom/ddbgo_search/tests/php/kwe-sector-dependencies.test.php
```

Rollenbezeichnungen werden als Text in die verlinkte HTML-Ausgabe eingefügt.
Auch Zeichen wie `<` und `&` bleiben deshalb in Batch- und Einzelverarbeitung
sichtbar. Der native Ausgabetest verwendet dafür ausschließlich ungespeicherte
Rollenkopien in einem isolierten Speichercache.

`KweSectorDependenciesSubscriber` registriert die Namen von Sparte und
Untersparte als Abhängigkeiten des berechneten KWE-Feldes bei Search API.
Änderungen und Löschungen dieser Begriffe merken die referenzierenden KWEs zur
Neuindexierung vor. Nach dem Deployment `drush cr` ausführen, damit der neue
Subscriber und das erneuerte Beziehungsmapping aktiv werden. Bereits veraltete
KWE-Suchtexte wie oben beschrieben einmalig neu indexieren.
