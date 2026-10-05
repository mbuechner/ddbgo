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
