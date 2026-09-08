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
