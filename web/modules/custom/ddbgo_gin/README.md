# Darstellung und Interaktion

Statisches HTML wird in Twig ausgegeben. PHP stellt die notwendigen Daten,
Berechtigungen und Cache-Abhängigkeiten bereit. JavaScript ist auf Interaktionen
und die unten beschriebenen Gin-Korrekturen begrenzt.

## Templates

- `form-element--ddbgo-gin`, `fieldset--ddbgo-gin`, `details--ddbgo-gin` und
  `datetime-wrapper--ddbgo-gin` übernehmen die Struktur der Gin-Formularwrapper.
  `ddbgo-help.html.twig` setzt Button und Tooltip gemeinsam zusammen. Details
  bindet beide Teile getrennt ein, damit sein Hilfetext außerhalb von Summary bleibt.
  `ddbgo-help-toggle.html.twig` liefert Button-Typ, zugänglichen
  Namen und die Zuordnung zur Beschreibung über `aria-describedby`. Die Beschreibung
  erhält `role="tooltip"` und folgt unmittelbar dem Button (bei Details
  direkt nach dem Summary). Bestehende Beschreibungs-IDs bleiben erhalten.
  Die Namen stehen damit auch vor dem Start von JavaScript und in AJAX-Antworten
  im HTML. Suchhilfen bleiben in der Views-Konfiguration, Feldhilfen in ihrer bisherigen Konfiguration.
- `ddbgo-workspace-navigation.html.twig` und `menu--ddbgo-gin.html.twig` rendern
  Schaltflächen und Linklisten ohne zusätzliche Gin-Toolbar-Elemente.
  Der Block ermittelt den aktiven Bereich und den aktuellen Link serverseitig.
  Seine Cache-Kontexte berücksichtigen Route, URL-Pfad und Benutzerrechte.
  Sind nach der Zugriffsprüfung alle Links eines Menüs ausgeblendet, entfällt
  auch dessen Schaltfläche. Die Cache-Metadaten des leeren Menübaums bleiben
  erhalten. Das verhindert einen PHP-Fehler bei eingeschränkten Rollen, etwa
  ohne Zugriff auf die Personenliste und das Anlegeformular.
- `ddbgo-page-actions.html.twig` platziert Reiter und Lesezeichen nebeneinander.
  Page-Preprocessing verwendet den vorhandenen Flag-Link-Builder. Das
  `flag--ddbgo-gin.html.twig` blendet die ursprüngliche Full-View-Ausgabe nur auf
  der zugehörigen Inhaltsseite mit sichtbaren primären Reitern im Header aus.
  Andere Flag-Ausgaben verwenden weiterhin das Originaltemplate von Flag.
  Sichtbarkeit und Cache-Abhängigkeiten der Reiter werden mit berücksichtigt.
- Pager und Blöcke für verknüpfte Inhalte verwenden bereits eigene Twig-Templates.

Die Formularwrapper sind gezielte Kopien, da Gin dort keine überschreibbaren
Twig-Blöcke für die Hilfeschaltflächen anbietet. Bei Gin-Updates diese vier Dateien
mit den Originalen unter `web/themes/contrib/gin/templates/form/` vergleichen.
Die Overrides gelten nur für Gin und davon abgeleitete Themes.

Die Tooltip-Gestaltung nutzt Gins Variablen `--gin-tooltip-bg`, `--gin-border-s`
und `--gin-shadow-l2`, mit einem kleinen Richtungspfeil. Gins installierte
`gin/tooltip`-Library erzeugt das Markup per JavaScript und bietet selbst keine
ARIA-Zuordnung, Escape-Behandlung oder Hover-Persistenz auf dem Hilfetext. Deshalb
bleiben das Twig-Markup und die gezielte Interaktions-Library hier erforderlich.

## Footer und Menü „Fußzeile“

Der Footer enthält in dieser Reihenfolge **Seitenübersicht** (Sitemap),
**Kontakt**, **Barrierefreiheit**, **Nutzungsbedingungen** und **Impressum**.
Alle fünf Einträge sind statische Menüdefinitionen in
`ddbgo_gin.links.menu.yml`. „Seitenübersicht“ verwendet `route_name: sitemap.page`;
die übrigen vier Einträge zeigen weiterhin vorläufig mit `route_name: '<front>'`
auf die Startseite. Für diese vier Einträge werden noch keine Zielseiten angelegt.
Der Sitemap-Link ist über die Zugriffsprüfung seiner Route für Gäste ausgeblendet.

Titel und Ziele werden in dieser YAML-Datei gepflegt. Sobald ein Ziel feststeht,
`route_name` und gegebenenfalls `route_parameters` setzen oder stattdessen `url`
angeben. Die stabilen IDs `ddbgo_gin.footer_impressum`,
`ddbgo_gin.footer_contact`, `ddbgo_gin.footer_terms`,
`ddbgo_gin.footer_accessibility` und `ddbgo_gin.footer_sitemap` beibehalten.
Reihenfolge und Aktivierung können weiterhin unter **Struktur → Menüs →
Fußzeile** (`/admin/structure/menu/manage/footer`) geändert werden; beim
Konfigurationsexport landen diese Anpassungen in
`core.menu.static_menu_link_overrides.yml`. Dort ist auch
der bisherige statische Core-Kontaktlink deaktiviert, damit bei aktiviertem
Kontaktmodul kein zweiter Kontaktlink erscheint.

Die Ausgabe verwendet den Core-Block `system_menu_block:footer`:

- **Gin Frontend:** `block.block.gin_frontend_footer.yml` platziert ihn in der
  vorhandenen Region `footer`. Das Theme liefert selbst das Footer-Landmark,
  das Custom-Modul passt dessen Darstellung an.
- **Gin und Gin Login:** Diese haben keine Footer-Region. `hook_page_bottom()`
  rendert denselben konfigurierten Block in `ddbgo-footer.html.twig` nach dem
  Seiteninhalt. Das gilt auch für die Loginseite. Block-Sichtbarkeit, deaktivierter
  Block, Menü-Zugriffsprüfung und Cache-Abhängigkeiten werden berücksichtigt.
  Gin Frontend erhält diese zusätzliche Ausgabe nicht.
- Der Core-Menüblock liefert die benannte Navigation „Website-Informationen“
  mit einer visuell ausgeblendeten Überschrift. `ddbgo_gin.footer.css` richtet
  die Einträge auf großen Bildschirmen rechtsbündig aus. Bis `48em` werden sie
  zentriert und dürfen natürlich umbrechen. Der Footer übernimmt den
  Seitenhintergrund; eine am Inhalt ausgerichtete, eingerückte Trennlinie ersetzt
  die bisherige kontrastierende Fläche mit Schatten. Kleinere Innenabstände und
  der entfallene Zeilenabstand machen ihn kompakter, ohne die Links zu verkleinern.
  Wie die übrigen Textlinks sind sie normalerweise
  punktiert unterstrichen; bei Hover und Tastaturfokus entfällt die Unterstreichung.
  Die Farben für Normalzustand, Hover und Klick entsprechen Gins Linkfarben.
  Schriftgröße und Abstände folgen ebenfalls Gin.
  `list-style: none` wird auch direkt am `li` gesetzt, da Claro dort
  standardmäßig Aufzählungspunkte festlegt. Tastaturfokus erhält Gins Fokusring,
  Windows-Kontrastdesigns zusätzlich eine Kontur. Eigene Hover-Animationen sind
  nicht vorgesehen.

Auf mobilen Geräten bleiben alle Links direkt sichtbar. Flex-Wrapping,
umbrechbare Beschriftungen und kleinere Seitenabstände verhindern, dass eine
starre Menüzeile über den Bildschirm hinausragt. Die Klickflächen sind
mindestens `2.75rem` (bei Standardschriftgröße 44 Pixel) hoch. Mehrere Zeilen
vergrößern den Footer im Dokumentfluss, ohne Inhalte zu überdecken.

Gin und Gin Frontend verwenden `enable_darkmode: auto`. Helle und dunkle
Darstellung folgen daher der Farbschema-Präferenz von Betriebssystem/Browser.
Der Footer nutzt in beiden Modi dieselben Gin-Variablen. Eine unterschiedliche
Scrollposition, Bildschirmhöhe oder Geräteschriftgröße kann zusätzlich die
sichtbaren Abstände verändern. Der Seitenkopf wird mobil nicht ausgeblendet,
sondern scrollt mit dem Inhalt. Diese Anpassung ändert weder den Darkmode noch
die vom Gerät gewählte Schriftgröße.

Kurze Seiten füllen per CSS-Flexlayout mindestens den sichtbaren Bildschirm
(`100dvh`, mit `100vh` als Fallback). Der Footer sitzt dadurch am unteren Rand;
bei langen Seiten folgt er dem Inhalt im normalen Dokumentfluss. Seine Höhe
bleibt auch bei Zeilenumbrüchen flexibel. Das Layout wird nur bei vorhandenem
Footer aktiviert. Gins Toolbar-Abstände zählen durch `box-sizing: border-box`
zur Gesamthöhe. Auf Gin Login wird die bisherige Mindesthöhe des Formularbereichs
zurückgesetzt, damit auch dort der Footer in die verfügbare Höhe passt.

Die Blockplatzierung und Menü-Overrides werden mit `drush config:import`
übernommen. Anschließend `drush cache:rebuild` ausführen, damit Drupal auch die
statischen Menüdefinitionen aus dem Modul neu einliest. Die fünf Einträge werden
auf anderen Instanzen damit automatisch verfügbar; eine manuelle Anlage als
Inhaltsentitäten ist nicht erforderlich. Dafür gibt es weder eine Helper- oder
Updateklasse noch zusätzlichen JavaScript-Code.

Die zuvor lokal angelegten fünf Inhalts-Menüeinträge wurden durch die statischen
Definitionen ersetzt, damit der Footer keine doppelten Einträge enthält.

Zur Prüfung `/search`, `/node/add/person`, `/admin/structure`, `/user/login`
sowie 403-/404-Seiten aufrufen: jeweils ein Footer mit benannter Navigation
und eingebundener Footer-Library. Angemeldete Benutzer sehen fünf Einträge;
Gäste sehen die vier Einträge ohne Seitenübersicht.

Die visuelle Browserprüfung steht noch aus: kurze/lange Seiten einschließlich
Login, schmale Ansicht/Zoom, Gin Hell/Dunkel, Tastaturfokus und Windows-Kontrastdesign
prüfen. Sobald Ziele eingetragen werden, zusätzlich deren Erreichbarkeit als
angemeldeter und anonymer Benutzer prüfen.

## Seitenübersicht

Drupal Sitemap `8.x-2.6` stellt unter `/sitemap` eine HTML-Seite mit
dem Titel „Seitenübersicht“ bereit. Die Einstellungen sind unter
`/admin/config/search/sitemap` erreichbar und in
`config/sync/sitemap.settings.yml` exportiert. Aktiv sind ausschließlich die
Menü-Plugins `menu:start`, `menu:kwe`, `menu:aggregator`, `menu:person`,
`menu:bestand`, `menu:einstellungen`, `menu:account` und `menu:footer` mit den
Abschnittstiteln „Allgemein“, „KWE“, „Aggregatoren“, „Personen“, „Bestände“,
„Einstellungen“, „Benutzermenü“ und „Informationen“.
`show_disabled: false` lässt deaktivierte Einträge
weg; `menu_depth: 9` übernimmt auch untergeordnete Menüebenen. Taxonomie-,
Book- und RSS-Ausgaben sind nicht aktiviert; eine XML-Sitemap wird nicht erzeugt.
Die kurze Einleitung wird über „Sitemap message“ (`message.value`, Format
`plain_text`) gepflegt: „Hier finden Sie die zentralen Bereiche und Funktionen
von DDBgo auf einen Blick.“

Die Abschnittsüberschriften bleiben semantisch `h2`, verwenden über die
bestehende Library `frontend_layout` aber Gins `--gin-font-size-h3`.
Abstände, Zeilenhöhe und Schriftschnitt entsprechen bereits beiden Gin-Ebenen.
Die CSS-Regel gilt nur für die Abschnittsüberschriften der Seitenübersicht;
ein zusätzliches Template oder JavaScript ist nicht nötig.

Die Berechtigung `access sitemap` wird ausschließlich der Rolle
`authenticated` zugewiesen. Die Seitenübersicht ist damit nur nach Anmeldung
erreichbar; die einzelnen Menüziele behalten ihre eigenen Zugriffsprüfungen.
Das gilt auch für die Links im Menü „Einstellungen“: Die Aufnahme dieses
Menüs gewährt keine zusätzlichen Verwaltungsrechte. Das Core-Benutzermenü
`account` ergänzt „Mein Konto“ und „Abmelden“; der Kontolink führt zum jeweils
angemeldeten Benutzer und listet keine anderen Benutzerkonten auf.

Die vorhandenen Arbeitsmenüs und das Footer-Menü werden wiederverwendet.
Sie enthalten die generischen Anlege-, Such- und Listenansichten, die
Startseite sowie die Informationsseiten. Die vier Footer-Platzhalter führen
weiterhin zu `<front>`; spätere Änderungen ihrer Ziele werden automatisch
in der Seitenübersicht übernommen. Die vorhandene allgemeine Inhaltsseite
`page` mit der ID 1 ist bereits über die
Startseite abgedeckt. Künftige allgemeine Seiten einem der ausgewählten Menüs
hinzufügen. Keine Links auf einzelne Bestands-, Aggregator-, KWE- oder
Personendatensätze aufnehmen; die Seitenübersicht listet die Menüziele und
liest nicht die einzelnen Ergebniszeilen der verlinkten Views aus.

Die vorhandenen Inhalts-Menülinks, etwa zu Startseite und Anlegeformularen,
sind Datenbankinhalte und gehören nicht zum Konfigurationsexport. Auf dem
Zielsystem müssen diese Arbeitsmenüs entsprechend gepflegt sein; die
Sitemap-Konfiguration erzeugt ihre Inhalts-Menülinks nicht neu.

Deployment: `composer install`, anschließend `drush cim` und `drush cr`
ausführen. Der Konfigurationsimport aktiviert das Modul und übernimmt seine
Einstellungen sowie die Berechtigung. Danach angemeldeten und anonymen Zugriff,
Footer-Link, sichtbare Menüziele und den Ausschluss einzelner Datensätze manuell
prüfen.

Lokal wurden vollständige HTML-Antworten über Drupals HTTP-Kernel geprüft:
Administrator und reine Rolle `authenticated` erhalten HTTP 200 mit dem
korrekten Seitentitel und Footer-Link; Gäste erhalten HTTP 403 ohne diesen
Link und ohne Sitemap-Inhalt. Alle ausgegebenen Ziele entsprechen den
generischen Menüeinträgen, einschließlich des Abschnitts „Informationen“.
Nach Ergänzung von „Einstellungen“ und „Benutzermenü“ wurden Einleitung,
acht semantische H2-Abschnitte und eingebundene Frontend-CSS erneut geprüft:
Administratoren sehen vier Einstellungslinks, die reine Rolle `authenticated`
keinen davon; beide erhalten die zwei eigenen Kontoaktionen. Gäste erhalten
weiterhin HTTP 403.
Die Prüfung wurde nach einem Rollenwechsel wiederholt. Zusätzlich geprüft:
Leere zugriffsbeschränkte Arbeitsmenüs erzeugen keinen Schalter und behalten
ihre Cache-Metadaten. Die visuelle Browserprüfung steht noch aus.

## Kategorien unter „Meine Lesezeichen“

Die Kategorien auf `/bookmarks` werden als Überschriften der Ebene `h2`
ausgegeben. Dafür schreibt die View `flag_bookmark` das Standardfeld `type`
mit `<h2>{{ type }}</h2>` um. Das Feld bleibt mit `exclude: true` als eigene
Tabellenspalte ausgeblendet. Die Gruppierung übernimmt den gerenderten Inhalt
(`rendered: true`) einschließlich HTML (`rendered_strip: false`).

Gin setzt diesen Gruppentitel in `caption`. Damit bleibt der Tabellenname
erhalten und die Kategorien sind zusätzlich über die Überschriftennavigation
erreichbar. Der Block besitzt eigene Felddefinitionen und übernimmt diese
Umschreibung nicht. Dafür sind weder eine Templatekopie noch JavaScript nötig.

Die Überschriften bleiben semantisch `h2`, verwenden optisch aber Gins
`h3`-Schriftgröße. Eine auf diese View-Seite begrenzte Regel in
`ddbgo_gin.frontend-layout.css` setzt dafür `--gin-font-size-h3` ein.
Abstände, Zeilenhöhe und Schriftschnitt sind bei Gin für `h2` und `h3`
bereits gleich und werden unverändert übernommen.

Nach dem Deployment `drush config:import` und `drush cr` ausführen. Anschließend
`/bookmarks` mit mehreren Kategorien manuell prüfen: HeadingsMap soll jede
Kategorie als `h2` unter der Seitenüberschrift aufführen; die Tabellen sollen
weiterhin den jeweiligen Kategorienamen tragen. Darstellung und Abstände
sowie die unveränderte Ausgabe des Lesezeichen-Blocks ebenfalls prüfen.

## Lesezeichen vor dem Entfernen bestätigen

Einzelne Lesezeichen und die Sammelaktion unter `/bookmarks` führen auf eine
gemeinsame Drupal-Bestätigungsseite. Sie nennt
die betroffenen Titel und bietet „Lesezeichen entfernen“ sowie „Abbrechen“.
Der Hinweis stellt klar, dass die zugehörigen Inhalte erhalten bleiben.
Nach Bestätigung oder Abbruch geht es zurück zur Ausgangsseite einschließlich
ihrer Such-/Seitenparameter. Auf den Detailseiten von Person, KWE, Bestand und
Aggregator bleiben Setzen und Entfernen unmittelbar möglich. Zusätzliches
JavaScript oder eigene Templates sind nicht erforderlich.

Flag kann seine eingebaute Bestätigungsseite nur für Setzen **und** Entfernen
gemeinsam aktivieren. Deshalb ersetzt `hook_preprocess_flag()` gezielt die
Entfernen-Links ausschließlich auf `view.flag_bookmark.page` durch
`/bookmarks/remove/{flagging}`. Die View verwendet für ihre Sammelaktion
die Action `ddbgo_bookmark_remove`: Die geerbte Core-Implementierung merkt die
Auswahl nur im privaten TempStore vor; sie löscht noch nichts.

`BookmarkRemoveConfirmForm` hält die angezeigten IDs in einem signierten,
an Benutzer und Sitzung gebundenen Formularwert fest (sechs Stunden gültig).
Erst nach einem gültigen Formular-POST mit Drupals zusätzlichem CSRF-Schutz werden
die entsprechenden Flagging-Entitäten entfernt. Besitz, persönliches Lesezeichen,
Flag-Berechtigung und Zugriff auf den Inhalt werden erneut geprüft. Ein
inzwischen entferntes und neu gesetztes Lesezeichen bleibt bei Bestätigung
des alten Formulars erhalten. Bereits geöffnete Bestätigungsformulare behalten
ihre jeweilige Auswahl; eine spätere Auswahl eines anderen Tabs wird nicht
mitgelöscht. Bei abgelaufenem Formular muss die Auswahl erneut erfolgen.
Die vorhandenen direkten Flag-Modulrouten und APIs werden nicht gesperrt.

Deployment: `drush config:import` und `drush cr`. Der Import legt die neue
Action an und stellt die Lesezeichen-View darauf um. Der Integrationstest
verwendet ausschließlich eigene temporäre Datensätze mit Transaktions-Rollback:

```sh
drush php:script web/modules/custom/ddbgo_gin/tests/php/bookmark-removal.test.php
```

Lokal bestanden 47 Aktions-/Formularprüfungen sowie 21 ergänzende Prüfungen
gerenderter HTTP-Kernel-Antworten: Einzel-/Sammelbestätigung, CSRF-Schutz,
Abbrechen-Link, Weiterleitungen und unveränderte direkte AJAX-Aktionen auf
einer Personen-Detailseite. Sämtliche temporären Testdatensätze wurden
zurückgerollt. Die bestehenden 98 PHP-/Twig-Prüfungen bestanden ebenfalls.

Im Browser zusätzlich Einzel-/Sammelauswahl, Abbrechen, Rückkehr zu einer
gefilterten Liste, Tastaturfokus und Screenreader-Ausgabe prüfen; diese
visuelle Prüfung steht noch aus.

## Leere verknüpfte Einträge

Das Inline-Paragraphs-Widget (`entity_reference_paragraphs`) zeigt bei leeren
Feldern keinen generischen Hinweis „Noch kein Seitenabschnitt hinzugefügt.“
mehr. Feldüberschrift, Pflichtfeldmarkierung, Hilfetext und Hinzufügen-Aktionen
bleiben erhalten. Ein gezielter Widget-Alter-Hook entfernt nur das Textelement
aus dem Render-Array, auch bei AJAX-Neuaufbau und künftig ergänzten Feldern
dieses Widget-Typs. Das Contrib-Widget erzeugt den Hinweis direkt in PHP und
bietet dafür weder eine eigene Vorlage noch eine Einstellung zum Ausblenden.

## E-Mail-Beschriftung im Personenformular

Die Beschriftung „E-Mail“ des Mehrfachfelds `node.person.field_email` wird in Gin
und davon abgeleiteten Themes als `span` ausgegeben. Sie bezeichnet eine
Formularfeldgruppe und keinen eigenen Dokumentabschnitt. Dadurch entfällt der
Sprung von der Seitenüberschrift `h1` zu einem `h4` für dieses Feld.

Drupal Core erzeugt das `h4` fest in
`web/core/lib/Drupal/Core/Field/FieldPreprocess.php`; das E-Mail-Widget bietet
keine Einstellung für dessen Tag. Deshalb markiert
`hook_field_widget_complete_email_default_form_alter()` nur das E-Mail-Widget
für Personen. `hook_preprocess_field_multiple_value_form()` ersetzt anschließend nur
den noch vorhandenen Core-Tag `h4` dieses markierten Tabellenlabels durch `span`.
Die Markierung erfolgt beim Widgetaufbau statt anhand der Route und gilt daher
beim Anlegen, Bearbeiten und erneuten Aufbau durch AJAX. Erst beim Rendern wird
geprüft, ob Gin oder ein abgeleitetes Theme aktiv ist. Die Konfiguration von
Feldstandardwerten und KWE-E-Mail-Felder bleiben unverändert.

Die vorhandenen Klassen, die Tabellenkopfzelle `th`, die einzelnen Input-Labels
und die Hinzufügen-/Entfernen-Aktionen bleiben erhalten. Die Darstellung folgt
weiterhin den bestehenden Claro-/Gin-Klassen. Eine Templatekopie oder zusätzliches
JavaScript ist dafür nicht erforderlich.

Nach dem Deployment `drush cr` ausführen. Zur manuellen Prüfung eine Person
anlegen und bearbeiten sowie E-Mail-Zeilen per AJAX hinzufügen und entfernen:
Beschriftung und Layout müssen erhalten bleiben; HeadingsMap darf „E-Mail“
nicht mehr als Überschrift aufführen. Ein KWE-Formular dient als Gegenprüfung
für die Begrenzung auf Personen.

## Abstände in Detailansichten und Formularen

Der äußere Node-Formularcontainer in Gin Frontend hat keinen eigenen Hintergrund,
Rahmen, Schatten oder Innenabstand. Die Tab-/Abschnittsflächen übernehmen die
Gliederung; so entfällt die doppelte Einrückung beim Anlegen und Bearbeiten.

`ddbgo_gin.section-spacing.css` verwendet für vollständige Node-Anzeigen,
Node-Anlage-/Bearbeitungsformulare und aufklappbare Verknüpfungsblöcke gemeinsame
Innenabstände: 24 Pixel ab 48em,
darunter 16 Pixel (über Gins rem-basierte Abstandsvariablen). Tab-Inhalte
beginnen mit diesem Abstand unter der Trennlinie; Gins überlappende Tab-Abstände
werden dafür in Anzeige und Formular zurückgesetzt. Native aufklappbare Abschnitte
behalten einen seitlichen Innenabstand, während Tabs die Einrückung ihrer
äußeren Karte verwenden. Auf kleinen Bildschirmen ist diese äußere Karte
kompakter, damit sich die Einrückungen nicht unnötig addieren.

Tab-Reiter erhalten bei Mausbedienung einen dezenten Hover-Schatten; inaktive
Reiter heben sich um 1 Pixel an. Die aktive Unterstreichung bleibt an ihrem Platz.
Der Tastaturfokus ist separat umrandet; bei reduzierter Bewegung entfällt die
Anhebung samt Übergang. Diese Regeln gelten in Detailansichten und Formularen.
Die primären Seitenaktionen „Ansicht“, „Bearbeiten“, „Löschen“ und „Revisionen“
verwenden dieselben Effekt- und Fokusregeln; ihre aktive Gin-Markierung bleibt bestehen.
Die Regeln greifen am Aktionsblock selbst, auch beim Bearbeiten ohne Lesezeichen-Container.
Das Lesezeichen daneben verwendet eine gleich hohe, abgerundete Schaltfläche mit
Icon und sichtbarem Text in dezenter Schrift. Auf kleinen Bildschirmen steht es
unter den Reitern. Gesetzte Lesezeichen sind gefüllt und farbig hinterlegt; der
Aktionsname und Mouseover-Hinweis bleiben auch nach Flag-AJAX erhalten.

Feldzeilen erhalten 16 bzw. 12 Pixel vertikalen Innenabstand. Ausschlie?lich
in der vollst?ndigen Bestandsanzeige erhalten die drei Objektfelder die Klasse
`ddbgo-object-list` und den Paragraph-Anzeigemodus `ddbgo_object_details`.
Die verschachtelten Objektangaben verwenden denselben eigenen Anzeigemodus;
Tabellen, andere Inhaltstypen, Themes und Formularvorschauen bleiben unabh?ngig.
Die geteilte Standard-Anzeigekonfiguration wird zur Laufzeit nur geklont.

Objektangaben stehen vollst?ndig untereinander, getrennt durch dezente Linien.
Medientyp und Objektzahl stehen in getrennten Zeilen. Innere Labels verwenden
normale Schriftst?rke und eine dezente Textfarbe, ?u?ere Labels bleiben fett.
Verschachtelte Objektangaben behalten ihre Beschriftung und eine seitliche Linie.
Personen behalten ihre Karten mit Rolle ?ber dem verlinkten Namen; Kontakte
ihre Karten mit Datum, Bemerkung und Verbindungslinie.

DDB-Objekte, Europeanas Content-Tier und Europeanas Metadata-Tier stehen direkt
im Anzeigeabschnitt Europeana/Archivportal. Kurze Titel werden nur dort und in
den drei Bestand-Formularwidgets gesetzt; die globalen Feldlabels bleiben erhalten.
Nur diese Widgets erhalten `ddbgo-object-widget`. Medientyp und Objektzahl
stehen bei gen?gend Platz nebeneinander, auf schmalen Fl?chen untereinander.
Verschachtelte Objektgruppen stehen auf einer eigenen Zeile mit seitlicher Linie.
Eingaben, Hilfetexte, Fehlermeldungen, Sortierung und Paragraphs-Aktionen bleiben
verf?gbar. Die Markierung wird auch beim AJAX-Neuaufbau gesetzt.
Beide Gestaltungen liegen in `ddbgo_gin.object-paragraphs.css`.

Die Anzeigemodus-Konfigurationen liegen in `config/sync` und werden mit dem
regulären Konfigurationsimport übernommen. Anschließend kann die Darstellung
mit dem bestehenden Rendering-Test geprüft werden:

```sh
drush php:script web/modules/custom/ddbgo_gin/tests/php/paragraph-display.test.php
```

Der Test rendert verschachtelte Beispiele ohne Speichern, pr?ft vollst?ndige
Werte einschlie?lich Tier 0, Reihenfolge, die Trennung der Anzeigemodi und echte
Formular-Widgets einschlie?lich der Abgrenzung zu Personen und Kontakten.
Eine visuelle Pr?fung in Desktop- und Mobilbrowser erg?nzt diese Strukturtests.

Andere Mehrfachwerte stehen mit 8 Pixel
Abstand untereinander. Die Anpassungen verändern weder die Tab-Steuerung noch
die Reihenfolge der Inhalte. Diese Feldzeilen-/Listenregeln bleiben auf die
Anzeige begrenzt; die bestehenden Abstände zwischen Formulareingaben bleiben
erhalten. Am Anfang und Ende eines Formularabschnitts werden zusätzliche
Feldränder ausgeglichen. Paragraph-Unterformulare erhalten 16 Pixel Abstand
unter ihrer Überschrift, ohne zusätzliche seitliche Einrückung. Das Personenformular
erhält den normalen Kartenabstand auch ohne einzelne Tab-Unterbereiche.
Lange Texte sowie die Zeile mit Paragraph-Überschrift und Aktionen dürfen
auf schmalen Bildschirmen umbrechen. Das Template
`input--ddbgo-gin-paragraph-add.html.twig` gibt Hinzufügen-Aktionen als native
Submit-Buttons mit umbrechender Beschriftung aus; Name, Wert, ID und
AJAX-Attribute bleiben erhalten.

Die Library `section_spacing` wird über `theme_components` in Gin und Gin
Frontend geladen. Die CSS-Selektoren erfassen auch AJAX-neuaufgebaute
Node-Formulare. Suchfilter behalten ihre unabhängigen Abstände und Raster.

## Einheitliche Formularfelder

`ddbgo_gin.form-controls.css` wird über `theme_components` auf Gin und Gin
Frontend geladen. Native Texteingaben, Auswahlfelder, Textareas, Select2 und
Tagify nutzen die Breite ihrer vorhandenen Formular- oder Suchfilterspalte.
Die Höhe und Farben folgen Gins Variablen. Mehrfachauswahlen und lange
Select2-Werte dürfen umbrechen und wachsen; Textareas behalten ihre Zeilenanzahl.
Datum/Uhrzeit, Checkboxen, Radios, Dateiuploads, Sortiergewichte und kompakte
Editor-Auswahlen behalten ihre eigenen Maße.

Select2 erhält für Einfach- und Mehrfachauswahl denselben dekorativen Pfeil,
Gin-Farben auch im ausgelagerten Ergebnisfenster sowie sichtbare Fokus-, Fehler-
und deaktivierte Zustände. Der Pfeil fängt keine Zeigerereignisse ab. Die
Originalfelder, Labels, ARIA-Attribute, Tastatursteuerung, AJAX-Suche,
Auswahlreihenfolge und Löschfunktionen bleiben beim jeweiligen Widget.
Die Breitenregel überschreibt ausschließlich die inline gesetzte Breite des
Select2-Auswahlcontainers, nicht die Positionierung seines Ergebnisfensters.
Dadurch passen auch in geschlossenen Tabs initialisierte Widgets später in ihre
Spalte. Neue Felder mit denselben Widget-Klassen werden automatisch erfasst.

Die gemeinsamen Select2-Regeln erfassen sowohl den Default-Skin von Gin Frontend
als auch den Gin-Skin des Select2-Moduls. Zusätzliche Innenabstände um dessen
Suchbereich und Auswahlwerte werden ausgeglichen. Leere und einzeilig befüllte
Mehrfachauswahlen haben damit dieselbe Mindesthöhe wie Texteingaben; bei Umbruch
wachsen sie weiter. Formularabschnitte verwenden diese Regeln ebenfalls, ohne
eigene Select2-Größenregeln in `ddbgo_gin.frontend-layout.css`.
Die interne Auswahlliste erhält außerdem keinen Listenabstand: Drupals
allgemeine Listeneinrückung würde selbst bei leerer Auswahl den Suchbereich
mit dem Platzhalter in eine zusätzliche Zeile verschieben.

### Tagify-Hilfsinput und offener Beschriftungsbefund

Die Korrektur aus DDBGO-51 gilt in `ddbgo_gin.form-controls.css` zentral für
Gin und davon abgeleitete Themes. `.tagify + input.tagify-select-widget`
blendet mit `display: none` nur das zusätzliche, unbeschriftete Hilfsinput
eines initialisierten Tagify-Select-Widgets aus. Die bisherige Begrenzung auf
die Bestandssuche entfällt; die Regel greift auch beim Anlegen, Bearbeiten und
nach AJAX-Neuaufbau. Das ursprüngliche `select` für Werteübermittlung und
Validierung sowie das sichtbare Tagify-Widget bleiben erhalten. Zusätzlicher
JavaScript-Code ist dafür nicht erforderlich.

**Offen, nur dokumentiert:** Die sichtbare Eingabe `.tagify__input` trägt
derzeit das generische `aria-label="Tags input field"` statt eines Bezugs zur
konkreten Feldbeschriftung. Die CSS-Korrektur löst diesen eigenen Befund
ausdrücklich nicht. Dafür müsste die bestehende Tagify-Integration den
Labelbezug übernehmen.

Nach dem Deployment `drush cr` ausführen. Suche sowie Anlege- und
Bearbeitungsformulare manuell prüfen: Das zusätzliche Hilfsinput darf nicht
mehr als unbeschriftetes Eingabefeld erscheinen. Tags hinzufügen und entfernen,
einschließlich nach AJAX-Neuaufbau, und die übermittelten beziehungsweise
gespeicherten Werte kontrollieren.

### Auswahl des Widgets

Kleine, feste Listen mit Einfachauswahl verwenden in `core.entity_form_display.*`
das Core-Widget `options_select`. Als Richtwert wurden bis zu 20 vorhandene
Begriffe zugrunde gelegt, etwa Ja/Nein, Status, Sparte, Bundesland, Medientyp und
Europeana-Tiers.

Mehrfachauswahllisten verwenden unabhängig von ihrer Größe Select2. Das
Dropdown bleibt dadurch kompakt; ausgewählte Werte erscheinen als einzeln
entfernbare Einträge. Dies gilt auch für Datenformat, Lieferweg, Coding da Vinci-
Events, die Ausrichtung nach Sparte und Sichtbarkeit. Die Feldkardinalität,
vorhandene Werte, Auswahlmöglichkeiten und bedingte Feldabhängigkeiten werden
beim Widget-Wechsel nicht geändert. Die Bestandstags behalten ausdrücklich
Tagify mit Such-Dropdown, Mehrfachauswahl und Begriffs-IDs.

Select2 bleibt bei umfangreichen oder erweiterbaren Listen: Bestandsart,
geografische Ausrichtung, Länder, Personenrollen sowie Verweise auf Aggregatoren,
KWEs und Personen. „Titel“ bleibt trotz der kleinen Liste ebenfalls Select2,
weil neue Titel ausdrücklich direkt im Personenformular anlegbar bleiben sollen.
Die Entscheidung steht in der Konfiguration und wird nicht beim Seitenaufruf
anhand einer Datenbankzählung getroffen. Bei stark wachsenden Listen die
Widget-Auswahl erneut prüfen.

Alle sieben konfigurierten Neuanlagen sind in `field.field.*.description`
erklärt: Bestandsart und geografische Ausrichtung bei Aggregatoren, akademischer
Titel bei Personen, Rollen in den drei Personen-Paragraphen und die Person im
Bestands-Personen-Paragraphen. Der Hilfetext erklärt Eingabe, Auswahl und Anlage
beim Speichern; er nutzt die bestehenden Hilfe-Templates. Bestehende
Beschreibungen bleiben enthalten. Neue KWE-Personenrollen verwenden wie die
Auswahlliste das Vokabular `personenrolle`.

```sh
node web/modules/custom/ddbgo_gin/tests/js/form-controls.test.cjs
```

Die erzeugte lokale HTML-Datei verwendet die installierten Gin-, Select2- und
Tagify-Dateien und benötigt keine Anmeldung. Sie prüft Feldmaße, Umbrüche,
Suchfunktion, Tastaturauswahl, Escape, Fokus, Löschen, deaktivierte/fehlerhafte
Felder und die Initialisierung in einem versteckten Bereich. Mit schmalem
Fenster wiederholen. Die URL-Parameter `?mode=frontend` und `?mode=gin` prüfen
zusätzlich Formularabschnitte in Gin Frontend bzw. den Gin-Skin von Select2.
Die Höhenprüfung umfasst auch eine bereits ausgewählte Option.
Ergänzend die echten Formulare und Suchfilter sowie
Dunkelmodus und hohen Kontrast prüfen; dies ersetzt keinen Screenreader-Test.

## Zugängliche Namen und Beschreibungen für Select2

Select2 versteckt das ursprüngliche `<select>` und erzeugt eigene Fokusziele.
In der installierten Bibliothek 4.1.0 ist die Einzelauswahl nur mit dem aktuell
gewählten Text beziehungsweise Platzhalter beschriftet; Suchfelder heißen nur
„Suchen“. Das native Feldlabel und `aria-describedby` werden nicht übernommen.
Deshalb fehlt beim Tabben der Kontext, etwa „Titel“ oder „Person“.

`ddbgo_gin.select2-accessibility.js` überträgt die vorhandenen Zuordnungen auf
die Auswahl, das Suchfeld im Dropdown und das Suchfeld der Mehrfachauswahl:

- Ein vorhandenes `aria-labelledby` oder `aria-label` des Originalfelds hat
  Vorrang. Sonst verweist `aria-labelledby` auf die echten `<label>`-Elemente
  des Felds. Bestehende Label-IDs bleiben erhalten; fehlende werden eindeutig
  ergänzt. Beschriftungstexte und Übersetzungen werden nicht dupliziert.
- Der Feldname bleibt bei einer anderen Auswahl gleich. Die bisherige Wert-ID
  wird nicht zusätzlich als Name oder Beschreibung verwendet: Im geprüften
  Chromium-Accessibility-Tree liefert die Einzelauswahl ihren Wert bereits
  getrennt vom Namen. Ein zweiter Verweis würde eine Doppelansage begünstigen.
- `aria-describedby` des Originalfelds wird mit vorhandenen Verweisen am
  Fokusziel zusammengeführt. Insbesondere bleibt bei Mehrfachauswahl der
  Verweis auf die ausgewählten Einträge erhalten. Gin-Hilfetexte sind über ihre
  bestehenden IDs auch als zunächst versteckte Tooltips verfügbar, ohne die
  Hilfeschaltfläche vorher zu öffnen.

Die Library wird in `hook_library_info_alter()` als Abhängigkeit von
`select2/select2` eingebunden und gilt damit für alle mit der Drupal-Integration
erzeugten Select2-Felder, unabhängig von Feldnamen und Theme. Eine Mikrotask
wartet auf die synchronen Drupal-Behaviors. Das Contrib-Ereignis `select2-init`
liegt vor der Initialisierung; `select2:open` wäre für das Benennen des Suchfelds
zu spät, weil Select2 zuvor den Fokus setzt. Die Suchfelder werden deshalb
bereits im geschlossenen Zustand vorbereitet. `once` markiert die erzeugte
Auswahl statt des Originalfelds, damit auch AJAX-Neuaufbau und erneute
Initialisierung erfasst werden. Zusätzliche Listener oder DOM-Beobachter sind
nicht nötig.

Der Fix ändert nur die Textzuordnungen. Tastaturverhalten, Auswahl und Neuanlage
bleiben bei Select2. Der separate Befund zu Popup-Rollen und `aria-controls`
wird damit nicht behoben. Fehlt schon am Originalfeld ein Name, bleibt die
Select2-Beschriftung als Rückfall erhalten; der Fix erfindet keine Feldtexte.
Ein neues Modul, Composer-Patch oder Konfigurationsimport ist nicht nötig.
Nach dem Deployment `drush cr` ausführen.

Lokaler Regressionstest mit der installierten Bibliothek und Drupal-Integration:

```sh
node web/modules/custom/ddbgo_gin/tests/js/select2-accessibility.test.cjs
```

Die ausgegebene HTML-Datei im Browser öffnen. Geprüft werden Einzelauswahl,
Mehrfachauswahl, versteckte Beschreibungen, explizite ARIA-Namen, Suchfeld beim
ersten Fokus, bestehende IDs, wiederholtes Attach, erneute Initialisierung,
AJAX-Austausch sowie Suche, Enter und Escape. Es werden keine Serveranfragen
oder Formularübermittlungen ausgeführt. Attributprüfungen allein belegen noch
keine korrekte Screenreader-Ansage: Im Accessibility-Tree zusätzlich Namen,
Wert und Beschreibung prüfen, anschließend mit NVDA oder VoiceOver auf den
Anlege-/Bearbeitungsformularen testen, einschließlich neu hinzugefügter
Personen-Paragraphen und Felder ohne Hilfetext.

Die Fixture wurde lokal im isolierten Edge erfolgreich ausgeführt. Dessen
Accessibility-Tree bestätigt Feldnamen, Werte und Beschreibungen für Einzel-
und Mehrfachauswahl, die Dropdown-Suche, explizite ARIA-Namen und das nachgeladene
Feld. Der manuelle Screenreadertest auf den tatsächlichen Formularen steht aus.

## Tastaturbedienung der Bestandstags

Tab und Shift+Tab dienen in Tagify-Select-Feldern nur der Fokusnavigation.
Die Vorschlagsliste wird geschlossen, ohne eine Option zu übernehmen. Auch
ein eingegebener Suchtext wird beim Verlassen nicht automatisch als Tag
ausgewählt. Enter, Pfeiltasten, Mausklicks und die Entfernen-Schaltflächen
behalten ihre vorhandene Funktion.

Die installierte Tagify-Bibliothek behandelt Tab sowohl im Eingabefeld als auch
im Dropdown als Vervollständigungs-/Auswahltaste. Nur `addTagOn` umzustellen
reicht deshalb nicht aus. Drupal Tagify stellt dafür keine Widget-Einstellung
bereit; eine Twig- oder reine Konfigurationsänderung löst das Verhalten nicht.
`ddbgo_gin.tagify-keyboard.js` fängt Tab am Widget vor diesen Handlern ab und
deaktiviert die automatische Übernahme bei Tab/Blur. Zusätzlich wird
`focusInputOnRemove` abgeschaltet, damit der Fokus auf den Entfernen-Schaltern
bleiben kann, statt beim Rückwärtstabben sofort ins Eingabefeld zurückzuspringen.
Das Script verwendet weder
`preventDefault()` noch eigene Fokuswechsel: Der Browser bestimmt weiterhin
das vorherige/nächste Fokusziel, einschließlich vorhandener Entfernen-Schalter.

Die Einbindung erfolgt zentral über die Library `tagify/default`, unabhängig
vom Theme. Betroffen sind Tagify-Select-Felder, einschließlich Bestandstags
in Anlege-/Bearbeitungsformularen und Suchfiltern. Autocomplete- und Select2-
Widgets werden nicht verändert. Die Initialisierung wartet auf die vorhandenen
Drupal-Behaviors; `once` verhindert doppelte Handler, auch bei AJAX-Neuaufbau.
Nach dem Deployment `drush cr` ausführen; ein Konfigurationsimport ist nicht nötig.

Die Testseite verwendet die installierte Tagify-Bibliothek und den Drupal-Wrapper
mit lokalen Optionen und abgefangenen Formularübermittlungen:

```sh
node web/modules/custom/ddbgo_gin/tests/js/tagify-keyboard.test.cjs
```

Die ausgegebene HTML-Datei im Browser öffnen. Synthetische Tastaturereignisse
können Auswahländerungen und blockierte Events prüfen, aber keine native
Tab-Fokusbewegung. Diese zusätzlich mit echten Tab-/Shift+Tab-Tastendrücken
zwischen den Testfeldern sowie in `/node/add/bestand`, beim Bearbeiten und
unter `/search/bestand` prüfen. Der Browserdurchlauf steht lokal noch aus.

## Verbleibendes JavaScript

- `ddbgo_gin.node-tabs.js`: Überträgt den ausgewählten Inhaltsreiter über einen
  URL-Anker zwischen Ansicht und Bearbeiten desselben Datensatzes (KWE, Bestand,
  Aggregator). Auch Kontextlinks und Öffnen in einem neuen Browser-Tab verwenden
  diesen Anker. Bestehende URL-Parameter und andere Sprungziele bleiben erhalten.
  Abweichende Gruppennamen in Anzeige und Formular werden zugeordnet; verschachtelte
  Reiter öffnen auch ihre übergeordneten Bereiche. Auf kleinen Bildschirmen wird
  der entsprechende Details-Abschnitt geöffnet. Validierungsfehler haben Vorrang,
  und AJAX-Aktualisierungen setzen den Reiter nicht auf den anfänglichen Wert zurück.
- `ddbgo_gin.unique-field-submit.js`: Verhindert fehlende Feldwerte beim Speichern
  während einer Dublettenprüfung. Das Script wird über die Library von
  `unique_field_ajax` geladen, unabhängig vom Theme. Es wartet auf laufende
  Formularanfragen einschließlich ihrer DOM-Aktualisierungen und setzt den
  Speichervorgang genau einmal mit dem ursprünglichen Submit-Button fort.
  Ein zusätzlicher Eventloop-Schritt berücksichtigt die beim Verlassen eines
  Feldes erst zeitversetzt gestartete Prüfung. Pflichtfeldvalidierung und
  Drupals Schutz gegen doppeltes Absenden bleiben aktiv. Bei AJAX-Fehlern oder
  einem Formular-Reset wird der vorgemerkte Speichervorgang abgebrochen.

- `ddbgo_gin.workspace-navigation.js`: Öffnen/Schließen, Escape und Fokus,
  mobile Menübedienung sowie Positionierung am Bildschirmrand. Markierungen
  und HTML-Struktur werden hier nicht mehr nachträglich ergänzt.
- `ddbgo_gin.form-help.js`: Öffnet die in Twig gerenderten Hilfetexte bei Hover
  und Tastaturfokus. Twig rendert die Tooltip-Texte bereits mit `hidden`, damit sie
  auch vor dem Laden von CSS/JavaScript und in AJAX-Antworten nicht aufblitzen
  oder das Layout verschieben. Escape schließt ohne Fokuswechsel, Klick/Touch
  hält die Hilfe bis zum nächsten Klick, Fokuswechsel oder Klick außerhalb offen.
  Der Mauszeiger
  bleibt ein normaler Pfeil. Der Tooltip bleibt beim Überfahren seines Textes
  sichtbar. Die Popover API verhindert Abschneiden durch Container; die Position
  passt sich Fenstergröße und Scrollen an. Hilfen innerhalb von Details werden
  während der Anzeige vorübergehend an `body` angehängt, weil geschlossene Details
  auch ihre Popovers verbergen. Danach kehren sie an die ursprüngliche Stelle
  zurück; ihre ID und Screenreader-Zuordnung ändern sich nicht. Ohne JavaScript
  bleibt der Text im Formular über die CSS-Abfrage `scripting: none` lesbar.
  Gin verwendet für diese Buttons einen anderen Selektor und überschreibt ihre Attribute daher nicht.
- `ddbgo_gin.tagify-keyboard.js`: Stellt die normale Tab-/Shift+Tab-Navigation
  in Tagify-Select-Feldern sicher; siehe „Tastaturbedienung der Bestandstags“.
- `ddbgo_gin.select2-accessibility.js`: Überträgt Feldnamen und Hilfetext-Verweise
  auf Select2s Fokusziele; siehe „Zugängliche Namen und Beschreibungen für Select2“.
- `ddbgo_gin.exposed-filters.js`: Automatisches Absenden bei geänderter Auswahl
  im zugrunde liegenden Select. Tagify kann während der Löschanimation bereits
  ein `change` auslösen, bevor `remove` die Option abwählt. Unveränderte Werte
  und doppelte Ereignisse werden deshalb nicht abgeschickt. Der Browsertest
  `node web/modules/custom/ddbgo_gin/tests/js/exposed-filters.test.cjs` prüft mit
  den installierten Widgets das Entfernen aus einer bis drei Auswahlen bei
  verschiedenen Animationszeiten sowie Initialisierung, Hinzufügen und erneutes
  Auswählen. Die ausgegebene HTML-Datei lokal im Browser öffnen; sie sendet keine
  Anfragen an DDBgo.
- `ddbgo_gin.toolbar-navigation.js`: Kompatibilitätskorrektur für Gins
  Verwaltungsnavigation. Ein Klick auf einen Verwaltungslink bzw. dessen
  Beschriftung folgt dem Ziel; der Aufklapp-Auslöser bleibt bedienbar. Das greift
  in Gins Ereignisbehandlung ein und lässt sich nicht allein durch statisches
  Markup ersetzen. Bei Änderungen an Gins Toolbar erneut prüfen.
- Flags AJAX-Verhalten wird weiterverwendet. Es gibt kein eigenes JavaScript
  zur Benennung der Hilfeschaltflächen oder zum Verschieben des Lesezeichens.

Formularvalidierung, Normalisierung und Filteraufbau bleiben in PHP/Form API.
CSS übernimmt Gestaltung, Umbrüche und responsive Anordnung.

Nach Änderungen an Templates, Theme-Hooks oder Libraries: `drush cr`.
Zur Prüfung Anlegeformulare aller vier Inhaltstypen, alle sieben Suchseiten,
Inhaltsseiten mit Lesezeichen und die mobile Navigation öffnen. Hilfenamen
bereits im Seitenquelltext kontrollieren; Klick, Tastatur, AJAX und einen
zweiten Seitenaufruf mit gefülltem Cache ebenfalls prüfen.

## Regressionstest für den Reiterwechsel zwischen Ansicht und Bearbeiten

```sh
node --test web/modules/custom/ddbgo_gin/tests/js/node-tabs.test.cjs
```

Die Tests prüfen die Navigationslogik mit einem kleinen DOM-/Field-Group-Adapter,
ohne Datenbank: Reiterübernahme, URL-Parameter, fremde Links, Gruppenzuordnung,
verschachtelte Reiter, mobile Details, Tastaturbedienung, AJAX und Fehlervorrang.
Die Darstellung und das Zusammenspiel mit dem echten Formular zusätzlich im
Browser prüfen.

## Regressionstest für das Speichern bei laufender Dublettenprüfung

Vom Projektverzeichnis aus ausführen:

```sh
node web/modules/custom/ddbgo_gin/tests/js/unique-field-submit.test.cjs
```

Die ausgegebene temporäre HTML-Datei im Browser öffnen. Am Ende steht `PASS`
oder `FAIL`. Die Testseite verwendet die installierten Drupal-Callbacks und den
Change-Handler von Unique Field AJAX, kontrolliert aber die Antwortzeiten lokal.
Es werden keine Formulardaten an den Server gesendet. Geprüft werden direkte
Speicherklicks, parallele Prüfungen, Austausch von Eingabefeldern und Buttons,
Mehrfachklicks, native Validierung, Fehler, Reset und unbeteiligte Formulare.

## Regressionstest für Formularhilfen

```sh
node web/modules/custom/ddbgo_gin/tests/js/form-help.test.cjs
```

Die ausgegebene HTML-Datei im Browser öffnen. Der Test verwendet die tatsächliche
Tooltip-Library und prüft die anfängliche Sichtbarkeit vor CSS und verzögertem
JavaScript, das Layout beim Attach sowie Hover, Fokus, Escape, Klick, Verlassen,
wiederholtes Attach, AJAX, geschlossene Details und die Position am Bildschirmrand. Er sendet
keine Formulare ab. Zusätzlich mit schmalem Browserfenster prüfen. Die technischen
Tests ersetzen keinen manuellen Test mit NVDA oder VoiceOver.

## Zuständigkeiten und Konfiguration

| Bereich | Zuständigkeit |
| --- | --- |
| `ddbgo_gin.module` | Drupal-Hooks, Theme-Auswahl, Suchfilter, Lesezeichen und Formularnormalisierung |
| `DdbgoWorkspaceNavigationBlock` | Zugriffsgeprüfte Menüstruktur, aktuelle Links und Cache-Metadaten |
| `RouteSubscriber` / `UserKeyAuthAccessCheck` | Titel der Anlegeformulare und Zugriffsprüfung für API-Schlüssel |
| `ddbgo_gin.libraries.yml` | Assets und Abhängigkeiten; Kommentare nennen die jeweilige Einbindestelle |
| `ddbgo_gin.services.yml` / `ddbgo_gin.permissions.yml` | Registrierung der Route-/Access-Dienste und der administrativen Berechtigung |
| `ddbgo_gin.install` | Statusanzeige mit beim Build ersetzten Versionsplatzhaltern |

Das Modul hat keine eigene installierbare Fachkonfiguration. Menüdefinitionen,
Blockplatzierungen, Gin-Einstellungen sowie Such- und Feldhilfen liegen in der
Projektkonfiguration unter `config/sync`. Diese Einstellungen werden durch die
Darstellungshooks verwendet, nicht beim Seitenaufruf umgeschrieben.

`ddbgo_gin_is_theme()` prüft das aktive Theme einschließlich seiner Basisthemes.
Die Abfrage wird bewusst nicht statisch zwischengespeichert: Theme-Wechsel während
des Renderns müssen sofort berücksichtigt werden. Form-API-Normalisierung und
Unique-Field-Schutz gelten dagegen auch außerhalb von Gin.

Bei Vereinfachungen müssen folgende Verträge erhalten bleiben:

- Die Filtergruppierung bewahrt `#parents` und `#name`, damit Views weiterhin
  dieselben GET-Parameter erhält. Tagify verwendet den vorhandenen Submit-Button.
- Beschreibungs-IDs und ARIA-Verknüpfungen stammen aus Twig. Hilfe-Buttons und
  Hilfetexte werden gemeinsam eingebunden; Details benötigt die getrennte Ausgabe.
- Persönliche Lesezeichen bleiben Flag-Lazy-Builder mit ihren Cache-Abhängigkeiten.
  Zugriffsrechte und Sichtbarkeit werden nicht durch Client-Code ersetzt.
- Gin-Abhängigkeiten liefern weiterhin CSS für Checkboxen und Formularwrapper.
  Scheinbar doppelte CSS-Selektoren mit höherer Spezifität überschreiben gezielt
  Regeln von Gin/Gin Frontend und sind nicht automatisch überflüssig.
- Die bestehende Trim-/NFC-Normalisierung läuft vor den Formularvalidatoren der vier
  Inhaltstypen. Sie gehört zum Speichern und ist unabhängig von der Suchindexierung.

## PHP-/Twig-Integrationstest

In der installierten DDBgo-Umgebung vom Projektverzeichnis aus:

```sh
vendor/bin/drush php:script web/modules/custom/ddbgo_gin/tests/php/module.test.php
```

Der Test prüft Theme-Wechsel, Bereichszuordnung, Attribute, Suchparameter und die
Beschriftungspositionen vor/hinter Eingabefeldern einschließlich unsichtbarer und
fehlender Beschriftungen. Er speichert weder Inhalte noch Konfiguration. Für die
Browsertests weiterhin die oben beschriebenen HTML-Testseiten verwenden.
## Suchfilter zurücksetzen und Cache

Zusammengesetzte Datumsfilter stehen im Gin Frontend ohne zusätzliche Umrandung
neben den anderen Filtern. Die Vergleichsauswahl trägt den Filtertitel, die
Datumsfelder heißen „Datum“ beziehungsweise „Von“ und „Bis“. Die gesamte Gruppe
ist auf 30rem angelegt, einfache Filter auf 14rem. Bei Platzmangel bricht die
Zeile um; auf mobilen Bildschirmen stehen die Felder in voller Breite untereinander.
Die Erkennung basiert auf dem Views-Datumsfilter (einschließlich Search API),
nicht auf einer bestimmten View oder einem Feldnamen. Vorhandene Datumsauswahl,
Operatoren, Sichtbarkeitsregeln und URL-Parameter bleiben erhalten.
PHP wählt dafür lediglich die Komponente anhand der Filter-Metadaten aus.
`templates/ddbgo-date-filter.html.twig` übernimmt Beschriftungen und Anordnung
der vorhandenen Widgets; der gemeinsame Fieldset-Wrapper bleibt erhalten.
Die anfängliche Sichtbarkeit der Datumsfelder wird anhand des aktuellen Operators
bereits serverseitig gesetzt. So erscheint vor dem Start von Drupals `#states`
kein zusätzliches Feld, das die Zeile kurzzeitig umbrechen lässt. Bei einem
Operatorwechsel übernimmt weiterhin Drupals vorhandene Sichtbarkeitssteuerung.

Prüfung der gerenderten Formulare und wiederverwendbaren Erkennung:

```sh
vendor/bin/drush php:script web/modules/custom/ddbgo_gin/tests/php/date-filters.test.php
```

Views kann die letzte Filterauswahl in der Sitzung speichern. Damit kann eine
Suchseite ohne URL-Parameter trotzdem einen Suchbegriff und Dropdown-Auswahlen
enthalten. Ein ausschließlich nach URL variierender Render-Cache liefert nach
„Zurücksetzen“ möglicherweise diese frühere Darstellung erneut aus.

Der Cache-Kontext `ddbgo_view_filters:VIEW_ID` berücksichtigt daher die gespeicherten
Filterwerte der jeweiligen View. Er wird nur Formularen mit aktivierter
Merkfunktion hinzugefügt und an die umgebende Ausgabe weitergereicht. Änderungen
und Reset innerhalb derselben Sitzung ergeben unterschiedliche Cache-Varianten.
Suchbegriffe erscheinen dabei nur gehasht im Cache-Schlüssel; die Suchergebnis-
und Index-Caches bleiben aktiv.

Der Reset bleibt ein normaler Submit mit den vorhandenen Core-/BEF-Callbacks.
Bei solchen Formularen entfällt BEFs `reset_ajax`-Library: Sie besucht lediglich
die URL ohne Parameter und überspringt damit das Löschen der Sitzungswerte.
`data-drupal-selector="edit-reset"` sorgt zusätzlich dafür, dass Core Views den
Reset von AJAX ausnimmt. Suchen und andere AJAX-Aktionen bleiben unverändert.

Regressionstest (keine gespeicherten Inhalte oder Konfiguration werden verändert):

```sh
vendor/bin/drush php:script web/modules/custom/ddbgo_gin/tests/php/exposed-reset.test.php
```

Der Test prüft wechselnde Sitzungswerte und die Reset-/Cache-Anbindung aller
sieben Suchformulare. Zusätzlich im Browser eine Suche mit Dropdown-Auswahl
anwenden, die URL ohne Parameter aufrufen, zurücksetzen und dieselbe URL erneut
laden. Die Felder müssen nach Reset auch bei gefülltem Cache leer beziehungsweise
auf ihrer konfigurierten Standardauswahl stehen.

## Verfügbare Bestandstags in der Suche

Die Bestandsliste verwendet für `field_bestandstags` einen nativen Filter von
`facets_exposed_filters`: UND-Verknüpfung, Mindesttrefferzahl 1 und Auflösung
der Begriffs-IDs in Namen. Facets ermittelt die angebotenen Tags aus der gesamten
Treffermenge unter Berücksichtigung der Volltextsuche, unabhängig von der
aktuellen Ergebnisseite. Die bisherigen URLs und Tagify-Mehrfachauswahl bleiben erhalten.

Das kleine BEF-Widget `ddbgo_bestand_tags` ergänzt das vorhandene Tagify-Widget.
Es hält ausgewählte Tags vor der Suchausführung und bei null Treffern im
Formular, damit Views sie weiterhin merken kann und sie einzeln entfernbar
bleiben. Außerdem bewahrt es Facets' Verarbeitung dynamischer Auswahlwerte.
Die Berechnung verfügbarer Tags und die Suchabfrage bleiben vollständig bei
Facets/Search API. Das Widget ist nur für diesen Filter vorgesehen; zusätzliche
JavaScript-Anpassungen oder Composer-Patches sind nicht erforderlich.

Die Aktivierung des mitgelieferten Untermoduls und die View werden über den
regulären Konfigurationsimport übernommen. Danach Drupal-Caches neu aufbauen,
damit Views den neuen Facettenfilter erkennt; eine veraltete Filterdefinition
kann das Tag-Feld verschwinden lassen. Eine Neuindexierung ist nicht nötig,
da die Begriffs-IDs bereits indexiert sind:

```sh
drush config:import
drush cache:rebuild
```

Wenn der laufende Webserver das Feld trotzdem nicht ausgibt, unter
`/admin/config/development/performance` **Alle Caches leeren** ausführen.
Lokal wurde beobachtet, dass die Datenbank und Drush das neue Untermodul bereits
kannten, während der Webserver noch eine alte Modulliste verwendete. Ein
erfolgreicher CLI-Test allein bestätigt deshalb nicht die ausgelieferte Seite;
die Bestandsliste zusätzlich als angemeldeter Benutzer per HTTP prüfen.

Regressionstest nach Aktivierung und Konfigurationsimport:

```sh
drush php:script web/modules/custom/ddbgo_gin/tests/php/bestand-facets.test.php
```

Der Test benötigt einen indexierten Bestand mit mindestens zwei Tags. Er
vergleicht die Ergebnisse mit dem bisherigen Taxonomie-Filter und prüft
verfügbare Tags, Mehrfachauswahl, Entfernen, Volltext, leere Trefferlisten,
gespeicherte Auswahl, Zurücksetzen, bestehende Links und Seitennavigation.
Zusätzlich rendert er die vollständige Bestandsliste mit aktiver Konfiguration
zweimal und prüft Tag-Feld, Position außerhalb der Filterklappe und Tagify-Library.
Er speichert keine Inhalte oder Konfiguration; Sitzungen sind nur im Speicher.

## Bestandstags in der Anzeige

`ddbgo_bestand_tags` stellt Begriffe als native Links mit Tag-Darstellung dar.
Ein Klick öffnet die Bestandssuche mit genau dieser Begriffs-ID und leerem
Suchtext, auch bei zuvor gespeicherten Filtern. Begriffs- und Suchzugriff sowie
Cache-Abhängigkeiten werden berücksichtigt. Die Feldbeschriftung verwendet
Drupals `inline`-Darstellung mit Doppelpunkt; die Bedienung benötigt kein JavaScript.
Tag-Links verwenden denselben Hover-Schatten und dieselbe leichte Anhebung wie
Tabs, ohne Unterstreichung. Tastaturfokus und reduzierte Bewegung werden berücksichtigt.
Die Tags stehen nebeneinander und brechen bei Platzmangel in die nächste Zeile um.

Prüfung mit zwei vorhandenen Bestandstags, ohne Inhaltsänderungen:

```sh
drush php:script web/modules/custom/ddbgo_gin/tests/php/bestand-tags.test.php
```

## Migration des Statusfelds

Der Status-Formatter nutzt `ddbgo-record-status-plain.html.twig` für benannte
Entity-Anzeigemodi wie `default`, `full` und `teaser`: eine farbig hinterlegte
Statusfläche in Inhaltsbreite mit Text, ohne Mouseover-Hinweis.
Die Feldspalten der nativen Views- und Search-API-Tabellen verwenden Drupals
Anzeigemodus `_custom` und erhalten `ddbgo-record-status.html.twig` mit
Mouseover-Hinweis und Hilfe-Cursor. Der Statusname bleibt für Screenreader lesbar;
beim Drucken und im erzwungenen Farbmodus bleibt der Text auch dort sichtbar.
Beide Templates teilen sich `ddbgo_gin.record-status.css`.

```sh
drush php:script web/modules/custom/ddbgo_gin/tests/php/record-status-display.test.php
```

Die Umstellung von Farbwerten auf eine Drupal-Liste erfordert Update 11002 vor
dem Konfigurationsimport. Ablauf, Prüfbefehle und Rückweg stehen in
[STATUS-MIGRATION.md](STATUS-MIGRATION.md). Die alten Farbwerte bleiben erhalten.
