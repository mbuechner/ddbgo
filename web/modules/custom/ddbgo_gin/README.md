# Darstellung und Interaktion

Statisches HTML wird in Twig ausgegeben. PHP stellt die notwendigen Daten,
Berechtigungen und Cache-Abhängigkeiten bereit. JavaScript ist auf Interaktionen
und die unten beschriebenen Gin-Korrekturen begrenzt.

## Weihnachtslichter

Happy New Year entscheidet anhand seiner Konfiguration und des Zeitraums,
ob die Lichterkette erscheint. `page--gin-frontend.html.twig` übernimmt das
native Gin-Frontend-Template und ergänzt unmittelbar hinter dem Header einen
leeren `#garland`-Platzhalter. Das Modul verwendet dieses Element mit seiner
vorhandenen Bibliothek. Ein relativ positionierter Wrapper verankert die
Lichter unter dem Menü; sein eigener Stapelkontext hält aufgeklappte Menüs
über der Dekoration. Ohne aktive Bibliothek bleibt der Platzhalter unsichtbar
und beansprucht keinen Platz. Die festen Abstands- und Toolbar-Optionen von
Happy New Year bleiben ausgeschaltet. Zusätzliche JavaScript- oder
CSS-Dateien sind nicht erforderlich. Bei Gin-Frontend-Updates das kopierte
Template mit dem Original vergleichen.

Snowstorm wird aus dem aktuellen `master`-Stand von
`scottschiller/Snowstorm` unter `web/libraries/snowstorm` installiert.
`composer install` und `composer update` laden das Archiv über
`refresh-snowstorm` ohne Download-Cache erneut; mit `composer refresh-snowstorm`
lässt sich nur diese Bibliothek aktualisieren. `--no-scripts` überspringt diese
Aktualisierung. Happy New Year verwendet die lokale `snowstorm.js`, da die
minifizierte Upstream-Datei nicht alle aktuellen Änderungen enthält.

## Templates

- `form-element--ddbgo-gin`, `fieldset--ddbgo-gin`, `details--ddbgo-gin` und
  `datetime-wrapper--ddbgo-gin` übernehmen die Struktur der Gin-Formularwrapper.
  `ddbgo-help.html.twig` setzt Button und Tooltip gemeinsam zusammen. Details
  bindet beide Teile getrennt ein, damit sein Hilfetext außerhalb von Summary bleibt.
  `ddbgo-help-toggle.html.twig` liefert Button-Typ, zugänglichen
  Namen und die Zuordnung zur Beschreibung über `aria-describedby`. Die Beschreibung
  erhält `role="tooltip"` und folgt unmittelbar dem Button (bei Details
  direkt nach dem Summary). Bestehende Beschreibungs-IDs bleiben erhalten.
  Mehrfach-Dateifelder zeigen ihre Gruppenbeschreibung stattdessen direkt im
  aufgeklappten Inhalt; ihre Summary-Zeile enthält keinen Hilfebutton.
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

## Hilfe in Mehrfach-Dateifeldern

Das Gin-Details-Template platziert normalerweise einen Hilfebutton in `summary`.
Da `summary` selbst ein Bedienelement ist, entsteht damit eine Verschachtelung
interaktiver Elemente. Der axe-Befund `nested-interactive` wurde bei diesen
Dateigruppen bestätigt:

- Bestand: Fragebogen (`field_fragebogen`).
- Aggregator und KWE: Vertrag (`field_vertrag`).

`details--ddbgo-gin.html.twig` erkennt zentral den von Drupal Core gelieferten
Widget-Theme-Hook `file_widget_multiple`. Für solche Mehrfach-Dateifelder entfällt
der Hilfebutton in der Überschrift; die vollständige Gruppenbeschreibung wird
normal im aufgeklappten Inhalt ausgegeben. Gin merkt sich unter
`description_display_toggle` die ursprüngliche Darstellungsart, bevor es die
Beschreibung für den Tooltip versteckt. Das Template stellt diese Art wieder
her: reguläre Hilfe ist sichtbar, ausdrücklich unsichtbare Beschreibungen
behalten ihre Einstellung. Beim Einklappen wird die Beschreibung zusammen mit
den Eingaben verborgen und ist nach dem Öffnen wieder erreichbar.

Die Regel gilt in Gin und Gin Frontend auch beim Bearbeiten und bei
AJAX-Neuaufbau. Andere Details und die Hilfen der einzelnen Upload-Eingaben
behalten ihre bisherige Darstellung. Texte, Upload-Funktionen, Beschriftungen,
Fehlerzuordnungen und Aufklapp-Attribute werden nicht verändert. Die Korrektur
benötigt weder zusätzliche PHP-/JavaScript-Logik noch CSS, Modul, Patch oder
Konfigurationsimport. Nach dem Deployment `drush cr` ausführen.

Read-only Renderprüfung mit ungespeicherten Formularen:

```sh
drush php:script web/modules/custom/ddbgo_gin/tests/php/details-help.test.php
```

Die Prüfung berücksichtigt den tatsächlichen `node.add`-Routenkontext, damit
Gins Formularhilfe wie im Browser aktiviert wird. Im Browser zusätzlich die
drei Dateigruppen auf- und zuklappen, den Hilfetext und die Upload-Steuerung
mit der Tastatur prüfen sowie den axe-Test wiederholen. Diese interaktive
Prüfung steht aus; die Browsersteuerung ist in dieser Sitzung nicht verfügbar.

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

## Fachliche Breadcrumbs

[`Custom Breadcrumbs`](https://www.drupal.org/project/custom_breadcrumbs) wird
über Composer eingebunden und durch `core.extension.yml` aktiviert. Die
Hierarchie liegt in den 13 exportierten Regeln
`config/sync/custom_breadcrumbs.custom_breadcrumbs.ddbgo_*.yml`.
Unter **Struktur → Custom breadcrumbs** (`/admin/structure/custom-breadcrumbs`)
lassen sich diese Regeln auch über die Oberfläche bearbeiten. Die globalen
Einstellungen stehen unter `/admin/config/user-interface/custom-breadcrumbs`
und in `custom_breadcrumbs.settings.yml`.

Jeder fachliche Datensatz hat eine feste Listenansicht als Elternseite:

| Inhaltstyp | Elternseite |
| --- | --- |
| KWE | Liste der Kultur- und Wissenseinrichtungen (`/search/kwe`) |
| Aggregator | Aggregatorenübersicht (`/search/aggregator/uebersicht`) |
| Bestand | Bestandsliste (`/search/bestand`) |
| Person | Personenliste (`/search/person`) |

Beispiele:

- `Startseite / Liste der Kultur- und Wissenseinrichtungen / Beispielmuseum`
- `Startseite / Personenliste / Person hinzufügen`
- `Startseite / Bestandsliste / Bestandstitel / Bearbeiten`
- `Startseite / Bestandsliste / Bestandstitel / Löschen`
- `Startseite / Aggregatorenübersicht / Beispielaggregator`
- `Startseite / Aggregatorenübersicht / Beispielaggregator / Bearbeiten`
- `Startseite / Aggregatorenliste`
- `Startseite / Aggregatorenübersicht`
- `Startseite / Bestandsliste für Europeanalieferungen`
- `Startseite / Bestandsliste für Coding da Vinci`

Alle Listenansichten liegen gleichrangig direkt unter Startseite. Das gilt
auch für Aggregatorenliste und Aggregatorenübersicht sowie für Bestandsliste,
Europeanalieferungen und Coding da Vinci. Die Volltextsuche, Meine Lesezeichen,
Seitenübersicht und Informationsseiten des Inhaltstyps `page` liegen ebenfalls
direkt unter Startseite; auf der Startseite selbst wird keine Breadcrumb
ausgegeben. Der Klickweg verändert diese Einordnung nicht. Aggregatoren sind
immer der Aggregatorenübersicht zugeordnet, einschließlich Anlegen, Bearbeiten
und Löschen. Ein Bestand aus der Europeana-Liste bleibt unter der normalen
Bestandsliste; Personen werden unabhängig von ihren Verknüpfungen immer der
Personenliste zugeordnet.

Die aktuelle Seite ist unverlinkter Text. `current_page: false` ist bewusst
gesetzt: Jede Regel definiert ihren Abschluss ausdrücklich mit `<nolink>` und
`[node:title]` beziehungsweise `[current-page:title]`. Der zweite Token liefert
bei Views und Anlegeformularen den tatsächlichen Seitentitel. Es werden keine
Titel gekürzt (`trim_title: 0`). `site_wide: false` beschränkt das Modul auf die
konfigurierten Seiten; andere Verwaltungsseiten behalten ihre bisherige
Breadcrumb-Erzeugung. `admin_pages_disable: false` erlaubt die ausdrücklich
konfigurierten Anlege-, Bearbeitungs- und Löschrouten, die Drupal intern auch
bei Verwendung des Frontend-Themes als Verwaltungsrouten kennzeichnet.

Zwei kleine Integrationen im bestehenden Modul sind erforderlich:

- `ddbgo_gin_theme_registry_alter()` entfernt ausschließlich in Gin Frontend
  die Breadcrumb-Preprocessor von Gin und Gin Frontend. Gin würde sonst auf
  Datensatzseiten alle fachlichen Vorfahren entfernen. Core-Preprocessing,
  Gin-Templates und die Darstellung bleiben erhalten. Die Registry des
  Verwaltungs-Themes Gin wird nicht verändert.
- `ddbgo_gin_system_breadcrumb_alter()` ergänzt auf Bearbeitungs- und
  Löschseiten den Link zum Datensatz und die unverlinkte Aktion „Bearbeiten“
  beziehungsweise „Löschen“. Entity-Regeln des Moduls gelten für alle drei
  Routen gemeinsam und können diese Unterscheidung nicht selbst konfigurieren.
  Außerdem werden konfigurierte Links auf Zugriff geprüft; unerlaubte Ziele
  entfallen. Das neue Breadcrumb-Objekt übernimmt die bisherigen Cache-Metadaten
  und ergänzt die Zugriffsergebnisse und den Datensatz. Die Zuordnung zu den
  Listen bleibt vollständig in der Konfiguration.

`ddbgo_gin.frontend-layout.css` vereinheitlicht Gins ersten, sonst senkrechten
Trennstrich mit den übrigen `/`-Trennzeichen. Lange Texte können auch auf
kleinen Bildschirmen und bei Zoom vollständig umbrechen. Es gibt dafür weder
zusätzliches JavaScript noch Änderungen an Core oder Contrib-Dateien.

**Modulgrenze:** Custom Breadcrumbs 1.1.3 fügt bei Pfadregeln derzeit `NULL`
als Cache-Abhängigkeit hinzu. Drupal setzt dadurch deren `max-age` auf `0`.
Diese Breadcrumbs verhindern somit das Render-Caching der jeweiligen Seite.
Die Integration übernimmt diese Metadaten unverändert; sie erhöht die
Cache-Laufzeit nicht künstlich und benötigt keinen Patch. Bei einem Modulupdate
diesen Punkt erneut prüfen. Die tatsächlichen Zugriffsrechte werden bei jedem
Aufruf weiterhin berücksichtigt.

Bereitstellung auf weiteren Umgebungen:

```bash
composer install
drush cim -y
drush cr
```

Der nur lesende Integrationstest prüft die Listen und Spezialansichten,
Datensatzseiten, Anlegen/Bearbeiten/Löschen, Informations- und Startseite,
gerendertes Gin-Markup sowie Zugriff und Cache-Metadaten:

```bash
drush php:script web/modules/custom/ddbgo_gin/tests/php/breadcrumbs.test.php
```

Zusätzlich im Browser bei schmaler Ansicht und Zoom prüfen: alle Texte bleiben
lesbar, Vorfahren sind per Tab erreichbar, die aktuelle Seite erzeugt keinen
zusätzlichen Tab-Stopp, und alle Trennzeichen sehen gleich aus. Der CLI-Test
ersetzt diese visuelle und tastaturbezogene Prüfung nicht.

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

## Dauerhafte Lesezeichenmeldungen

Nach dem Setzen oder Entfernen eines Lesezeichens bleibt Flags vorhandene
Rückmeldung sichtbar. Bei der nächsten Aktion am selben Lesezeichen ersetzt
Flag den gesamten Linkbereich einschließlich der alten Meldung. Sie lässt sich
auch ausdrücklich über „×“ schließen. Es entsteht keine Sammlung alter
Rückmeldungen; ein Seitenwechsel beendet die Anzeige.

Die Library `bookmark_feedback` wird über `hook_library_info_alter()` an
`flag/flag.link_ajax` angehängt und erreicht damit auch Lesezeichen außerhalb
der Gin-Frontend-Detailansicht. Sie deaktiviert ausschließlich bei
`.flag-bookmark .js-flag-message` die Animation: Flag entfernt die Nachricht in
seinem `animationend`-Handler, nicht durch einen Timer. Ohne Animation wird
dieser Handler nicht ausgelöst. Die bisherigen vier Sekunden langen Animationen
und ihre Variante für reduzierte Bewegung sind aus der Frontend-CSS entfernt.

In der Seitenaktionsleiste steht die Meldung im normalen Layoutfluss unter dem
Lesezeichen. Sie überdeckt dadurch keinen nachfolgenden Inhalt, kann auf kleinen
Bildschirmen umbrechen und hat keine feste Höhe.

Die kleine JavaScript-Ergänzung dekoriert Flags `actionLinkFlash`-Kommando einmal
im Behavior-Attach, nachdem die Scripts geladen sind. Flag fügt die Nachricht
erst nach dem AJAX-Neuaufbau und dessen Behavior-Attach ein; deshalb genügt ein
gewöhnliches Attach auf der Nachricht nicht. Das Originalkommando mit seiner
Ansage, seinem Rückgabewert und seiner Fehlerbehandlung bleibt erhalten. Nur
Lesezeichenmeldungen erhalten eine native Schaltfläche `type="button"` mit dem
zugänglichen Namen „Lesezeichenmeldung schließen“. Das sichtbare × ist für
assistive Technik dekorativ. Der Button hat eine Zielgröße von mindestens
24 × 24 Pixeln sowie einen sichtbaren Tastaturfokus.

Tab erreicht den Button, Enter und Leertaste aktivieren ihn nativ. Escape
schließt die Meldung ebenfalls, wenn der Fokus innerhalb der Meldung liegt.
Liegt der Fokus beim Schließen darin, geht er zum zugehörigen Lesezeichen-Link
zurück; bei geändertem Zugriff ohne Link bleibt die Tab-Position am Wrapper.
Ein Fokus außerhalb der Nachricht wird nicht verschoben. Außenklicks,
Fokuswechsel und Zeitablauf schließen die Meldung nicht automatisch. Das
Schließen blendet nur die Rückmeldung aus und ändert nicht das Lesezeichen.
Grundlage: [W3C Button Pattern](https://www.w3.org/WAI/ARIA/apg/patterns/button/).

Flag kündigt den Meldungstext bereits über `Drupal.announce()` an. Das redundante
`aria-live` am Nachrichtenabsatz wird vor dem Einfügen der Schaltfläche entfernt,
damit deren Beschriftung keine zusätzliche Live-Ansage auslöst. Es werden keine
eigenen Meldungsansagen, Timer, globalen Klick-Listener oder DOM-Observer ergänzt.
Andere Flags erhalten keine Schließen-Schaltfläche. Ein Patch oder eine
Konfigurationsänderung ist nicht nötig.

```sh
drush cr
node --test web/modules/custom/ddbgo_gin/tests/js/bookmark-feedback.test.cjs
```

Die JavaScript-Regression verwendet Flags echtes Originalkommando mit einem
minimalen DOM-Harness. Sie prüft Ergänzung nach dem AJAX-Attach, Originalansage,
Schließen, Fokus, Escape und mehrfache Anbindung. Sie ersetzt keinen Browser-
oder Screenreader-Test.

Manuell auf einer Detailseite ein Lesezeichen setzen,
länger als vier Sekunden warten und es wieder entfernen: Es soll jeweils nur
die aktuelle Rückmeldung sichtbar sein. Zusätzlich per Tab, Enter/Leertaste
und Escape schließen, Fokus-Rückkehr, schmale Bildschirmbreite, reduzierte
Bewegung und Screenreader-Ansage prüfen. Auch bei Klicks auf andere Inhalte
muss die Meldung stehen bleiben. Die
Bestätigung beim Entfernen unter `/bookmarks` ist davon unabhängig.

## Leere verknüpfte Einträge

Das Inline-Paragraphs-Widget (`entity_reference_paragraphs`) zeigt bei leeren
Feldern keinen generischen Hinweis „Noch kein Seitenabschnitt hinzugefügt.“
mehr. Feldüberschrift, Pflichtfeldmarkierung, Hilfetext und Hinzufügen-Aktionen
bleiben erhalten. Ein gezielter Widget-Alter-Hook entfernt nur das Textelement
aus dem Render-Array, auch bei AJAX-Neuaufbau und künftig ergänzten Feldern
dieses Widget-Typs. Das Contrib-Widget erzeugt den Hinweis direkt in PHP und
bietet dafür weder eine eigene Vorlage noch eine Einstellung zum Ausblenden.

## Formularbeschriftungen ohne künstliche Überschriften

Die Korrektur aus DDBGO-71 für Personen-E-Mail gilt in Gin und davon abgeleiteten
Themes auch für die folgenden Feldgruppen. Ihre Beschriftungen werden als
`span` statt `h4` ausgegeben: Sie benennen Eingaben, keinen Dokumentabschnitt.
Dadurch erzeugen sie keinen Sprung von der Seitenüberschrift `h1` zu `h4`.

| Bereich | Beschriftungen / Felder |
| --- | --- |
| Person und KWE | E-Mail (`field_email`) |
| Aggregator, KWE und Bestand | Personen und Kontakt (`field_personen`, `field_kontakt`) |
| Bestand | DDB-Objekte und Europeana-Objekte (`field_ddb_objekte`, `field_europeana_objekte_content_`, `field_europeana_objekte_metadata`) |
| Europeana-Objektgruppen | Verschachtelte Objekte (`field_objekte` in den Paragraph-Typen `europeana_objekte_content_tier` und `europeana_objekte_metadata_tier`) |
| Bestand | Erster Ingest in die DDB (`field_erstingest`), Datum des Europeana-Lieferstatus (`field_datum_des_status_der_europ`) |
| KWE | Erster Ingest in Archivportal Europa bzw. Europeana (`field_erstingest_archivportal`, `field_erstingest_europeana`) |
| Kontakt-Einträge | Datum (`paragraph.kontakt.field_datum`) |

Für Mehrfachfelder erzeugt Drupal Core das `h4` fest in
`web/core/lib/Drupal/Core/Field/FieldPreprocess.php`; eine Widget-Einstellung
für den Tag existiert nicht. `hook_field_widget_complete_form_alter()` markiert
deshalb gezielt die oben genannten Mehrfachfelder anhand von Entitätstyp,
Bundle und Feldname. `hook_preprocess_field_multiple_value_form()` ersetzt
anschließend nur den noch vorhandenen Core-Tag `h4` dieser markierten
Tabellenlabels durch `span`. Die Markierung gilt auch beim Bearbeiten,
in eingebetteten Paragraphs und beim Neuaufbau durch AJAX. Erst beim Rendern
wird das Theme geprüft. Andere Mehrfachfelder, andere Themes und die
Konfiguration von Feldstandardwerten behalten ihr bisheriges Verhalten.
Leere Paragraphs-Felder verwenden bereits `strong` und bleiben unverändert.

Datumsbeschriftungen entstehen über einen anderen Renderweg. Der vorhandene
Gin-Override `datetime-wrapper--ddbgo-gin.html.twig` gibt seine Feldgruppentitel
ebenfalls als `span` aus. Das gilt einheitlich für Datums-/Zeitfeldgruppen in
Gin, einschließlich der oben genannten fünf Datumsfelder. Die tatsächlichen
Eingaben und deren separate Core-Labels werden nicht verändert oder neu benannt.

Die vorhandenen Klassen, Pflichtfeldmarkierungen, Hilfetexte, Tabellenkopfzellen
`th` und Hinzufügen-/Entfernen-Aktionen bleiben erhalten. Tabs und echte
Abschnittsüberschriften behalten ihre Semantik. Gin/Claro formatieren die
Label-Klassen unabhängig vom HTML-Tag; die lokale Hilfebutton-CSS-Regel
berücksichtigt diese Klasse ebenfalls. Es ist kein zusätzliches JavaScript,
Modul, Core-Patch oder Konfigurationsimport erforderlich.

Nach dem Deployment `drush cr` ausführen. Automatische Renderprüfung ohne
Speichern von Inhalten oder Absenden von Formularen:

```bash
drush php:script web/modules/custom/ddbgo_gin/tests/php/form-labels.test.php
```

Zur manuellen Prüfung die genannten Formulare anlegen und bearbeiten sowie
E-Mail-, Personen-, Kontakt- und Objektzeilen per AJAX hinzufügen/entfernen.
Beschriftungen, Hilfetexte und Layout müssen erhalten bleiben; HeadingsMap
darf diese Feldbeschriftungen nicht mehr als Überschriften aufführen. Dabei
auch die verschachtelten Objektgruppen und lange Datumslabels auf schmalen
Bildschirmen prüfen.

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

Untergeordnete Details innerhalb von Formular-Tabs, etwa DDB-Objekte sowie
Europeanas Content-/Metadata-Tier im Bestandsformular, verwenden kompaktere
Summary-Zeilen mit Gins normaler Schriftgröße (16 Pixel) und reduzierten
Abständen. Eingeklappt liegt ihre Mindesthöhe einschließlich Rahmen bei
Standarddichte bei 44 Pixeln, ungefähr auf Eingabefeldhöhe mit etwas zusätzlichem
Raum. Die Höhe wird aus den Input-Variablen abgeleitet; lange Titel können bei
schmalen Bildschirmen oder Zoom weiterhin umbrechen. Auch die Überschriften
innerer Mehrfachfelder verwenden hier 16 statt Gins üblicher 18 Pixel, damit
Beschriftung und aufgeklappter Inhalt ausgewogen wirken. Normale Eingabelabels
behalten ihre Gin-Schriftgröße.
Pfeil, Hover-Effekt, Tastaturfokus und native Aufklappfunktion bleiben erhalten.
Die Regel in `ddbgo_gin.section-spacing.css` greift ausschließlich bei direkt
verschachtelten Details in einer Formular-Tabfläche. Äußere Tab-/Accordion-Zeilen
und deren Inhaltsabstände bleiben erhalten. Der Scope nutzt das vom Server
gerenderte `data-horizontal-tabs-panes`, funktioniert also auch vor der
JavaScript-Initialisierung und nach AJAX-Neuaufbau. Kein zusätzlicher PHP-/Twig-
oder JavaScript-Code und kein Konfigurationsimport sind nötig; danach `drush cr`.

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

DDB-Objekte, Europeana-Objekte: Content-Tier und Europeana-Objekte: Metadata-Tier
stehen direkt im Anzeigeabschnitt Europeana / Archivportal. Der lokale
Label-Helper verwendet dieselben Titel wie die Feld- und Gruppenkonfiguration
auch in den drei Bestand-Formularwidgets.
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
verwendet dieselben Tab-Abstände wie die anderen Inhaltstypen.
Lange Texte sowie die Zeile mit Paragraph-Überschrift und Aktionen dürfen
auf schmalen Bildschirmen umbrechen. Das Template
`input--ddbgo-gin-paragraph-add.html.twig` gibt Hinzufügen-Aktionen als native
Submit-Buttons mit umbrechender Beschriftung aus; Name, Wert, ID und
AJAX-Attribute bleiben erhalten.

Die Library `section_spacing` wird über `theme_components` in Gin und Gin
Frontend geladen. Die CSS-Selektoren erfassen auch AJAX-neuaufgebaute
Node-Formulare. Suchfilter behalten ihre unabhängigen Abstände und Raster.

## Einheitliche Abschnittsnamen und Feldreihenfolge

Formular und Detailansicht verwenden für KWE, Aggregator und Bestand dieselben
Tab-Beschriftungen: `Verwaltung` statt `Informationen` und `Kontaktverlauf`
für die dokumentierten Kontakte mit Datum und Bemerkungen. Die zugehörigen
Kontakt-Feldlabels und die Abschnittsverweise in den Bemerkungs-Hilfetexten
sind angeglichen. Die Person-Seite zeigt in Formular und Detailansicht einen
einzelnen Reiter `Person`. Field Group verwendet dafür den horizontalen
Wrapper `group_person_tabs` mit der bestehenden Inhaltsgruppe `group_person`
als einzigem Tab. Unter 640 Pixeln erscheint dieser wie bei den anderen
Inhaltstypen als geöffnetes Accordion; ohne JavaScript ist der Inhalt ebenfalls
aufgeklappt zugänglich. Die Felder behalten ihre Reihenfolge und ihre Widgets
bzw. Formatter. Die bisherigen Tab-Abstände greifen ohne Person-Sonderregel.
`KWE / Aggregator` und `Europeana / Archivportal` werden ebenfalls in beiden
Ansichten gleich geschrieben.

Die Feldreihenfolge folgt der bisherigen Detailansicht. Bei KWE stehen die
Europeana- und Archivportal-Lieferangaben jeweils zusammen mit ihrem
Ingest-Datum und den Bemerkungen. Beim Bestand steht die Webseite am Anfang
der allgemeinen Angaben; im Formular geht der Titel voraus. In `Verwaltung`
folgt der erste DDB-Ingest auf Dashboard und Hauptticket, vor dem Fragebogen.
Im Europeana-/Archivportal-Abschnitt folgen DDB-Objekte auf die Europeana-Lieferung
über DDB; das Statusdatum steht bei Status/Ingestart, vor den Europeana-Objekten.
Formular-Details und direkt angezeigte Objektfelder haben dieselbe relative
Reihenfolge. Field-Group-Children und Rendergewichte bilden diese Ordnung ab.

Die Einstellungen liegen in den Form-/View-Displays und Feldkonfigurationen
unter `config/sync`. Bestehende Gruppen-IDs werden weiterhin für Tab-Verweise
und die Wiederherstellung beim Wechsel zwischen Ansicht und Bearbeitung
verwendet. Der lokale `ddbgo_gin_object_field_labels()`-Helper verwendet
`Europeana-Objekte: Content-Tier` bzw. `Europeana-Objekte: Metadata-Tier` passend
zu den Feld-, Details- und Paragraph-Beschriftungen. Auch bei AJAX-Neuaufbau
werden diese Titel übernommen.

Deployment: `drush config:import` und `drush cr`. Anschließend die bestehenden
`paragraph-display.test.php`- und `form-labels.test.php`-Renderprüfungen ausführen
und die Tab-Namen sowie die Feldreihenfolge beim Wechsel zur Bearbeitung prüfen.

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

### Reflow ohne gekürzte Texte

Die Anpassungen für kleine Bildschirme und Zoom verwenden ausschließlich CSS.
Beschriftungen, Optionen, Platzhalter und Schriftgrößen bleiben unverändert;
die Felder gewinnen bei Bedarf Höhe. Bestehende Widget-Funktionen und die
Formular-Konfiguration werden nicht ersetzt.

- `ddbgo_gin.form-controls.css` lässt Tagify-Chips in Nodeformularen mit langen
  Beschriftungen wachsen. Der Text darf umbrechen; das Entfernen-Steuerelement
  behält seinen Platz. Einzelne Vorschläge dürfen ebenfalls wachsen, während
  die gesamte Vorschlagsliste weiterhin ihre Scrollbegrenzung behält. Tagify
  hängt diese Liste an `body`; der CSS-Scope erfasst deshalb die Popups auf
  Seiten mit einem Tagify-Nodeformular. Reine Suchseiten bleiben unverändert.
- Select2-Mehrfachfelder zeigen ihren Platzhalter in einer internen Textarea.
  Deren feste Einzeilenhöhe wird nur im leeren Nodeformular-Feld und nur bei
  Unterstützung von `field-sizing: content` aufgehoben. Dadurch kann der
  vollständige Platzhalter umbrechen und die Höhe bestimmen. Nach Auswahl oder
  beim Tippen gilt wieder das bisherige Suchfeld-Layout. Ältere Browser behalten
  ihr bisheriges Verhalten. Die Unterstützung wird durch `@supports` geprüft;
  es gibt keine JavaScript-Nachrüstung. Siehe
  [MDN: field-sizing](https://developer.mozilla.org/en-US/docs/Web/CSS/Reference/Properties/field-sizing).
- `ddbgo_gin.section-spacing.css` lässt Upload- und Paragraphs-Aktionszeilen
  umbrechen und deren Inhalte schrumpfen. Bei Mehrfachfeldern wird der Inhalt
  der TableDrag-Zellen auf die verfügbare Breite begrenzt. Tabellenstruktur,
  Sortiergewichte, Tastaturbedienung und DOM-Reihenfolge bleiben erhalten.
- Die ältere Breitenregel für Details in `ddbgo_gin.frontend-layout.css` nimmt
  Datei-, Datums-/Zeitfelder, Sortiergewichte und kompakte Controls wie die
  gemeinsamen Formularregeln aus. Sie erzwingt für diese Elemente keine volle
  Zeilenbreite mehr.

**Grenzen:** Traditionelle native Dropdowns bleiben browsergesteuert.
CSS kann dort einen mehrzeiligen ausgewählten Text oder Umbruch in der
geöffneten Liste nicht browserübergreifend gewährleisten; siehe
[MDN: select](https://developer.mozilla.org/en-US/docs/Web/HTML/Reference/Elements/select).
Die langen Vertragsoptionen und Leertexte werden deshalb vollständig erhalten,
und die entsprechenden Befunde gelten durch diese CSS-Änderung nicht als
abschließend behoben. Auch enge E-Mail-/Paragraphs-Tabellen mit eingeblendeten
Sortiergewichten müssen visuell geprüft werden; sie erhalten weder eine
globale feste Spaltenverteilung noch ein pauschales Stapellayout.

Deployment: `drush cr`; ein Konfigurationsimport ist nicht nötig.
Manuell in KWE-, Aggregator- und Bestandsformularen bei 200 % und 400 % Zoom
sowie bei 320 CSS-Pixeln Breite prüfen: lange Bestandstags auswählen und die
Vorschlagsliste öffnen, leere Select2-Mehrfachfelder anzeigen, Datei-Uploads
mit und ohne vorhandene Datei sowie E-Mail-/Paragraphs-Zeilen mit sichtbarer
Zeilenreihenfolge prüfen. Texte müssen vollständig zugänglich bleiben,
Steuerelemente erreichbar sein und gewöhnliche Formularbereiche ohne
horizontalen Seitenüberlauf auskommen. Zusätzlich Hinzufügen/Entfernen per
AJAX und Tastaturfokus kontrollieren. Die visuelle Prüfung steht aus, da die
Browsersteuerung in dieser Sitzung nicht verfügbar ist.

### Zuordnung von Inline-Fehlermeldungen

`FormErrorAccessibility` verknüpft vorhandene Inline-Fehler in Gin und Gin
Frontend über `aria-describedby` mit dem jeweiligen Formularfeld. Die vier
bestehenden Form-Templates geben dafür eine vom eindeutigen Form-API-Element-ID
abgeleitete Fehler-ID aus. Hilfereferenzen bleiben erhalten; bei Datum/Uhrzeit
erhalten die einzelnen Eingaben den gemeinsamen Fehlerbezug, bei `details`
auch der fokussierbare `summary`. Der Fieldset-Preprocessor bewahrt zusätzliche
Referenzen, wenn Core seine Gruppenbeschreibung einsetzt.

Die Zuordnung erfolgt erst unmittelbar vor dem Rendern, weil Form API die
Fehler nach dem Formularaufbau zuweist. Der Callback ist unabhängig vom Theme
registriert und prüft Gin erst beim Rendern. Unterdrückte Fehler, fehlerfreie
Felder, andere Themes und ein deaktiviertes Core-Modul `inline_form_errors`
erhalten keine neuen Referenzen. Die Korrektur ändert
weder Validierungsregeln noch Browser-Validierungspopups und setzt keine
pauschalen Alert-Rollen auf Fehler einer vollständig neu geladenen Seite.

```sh
drush cr
drush php:script web/modules/custom/ddbgo_gin/tests/php/form-errors.test.php
```

Der Test rendert echte Node- und Paragraphs-Formulare mit ausschließlich im
Speicher eingefügten Fehlern sowie Gruppen und unterdrückte Meldungen. Er prüft
Ziel-IDs, Feldbezüge, erhaltene Hilfen und die Theme-Grenze, ohne Formulare
abzusenden oder Inhalte zu speichern. Die Ansage mit Screenreader manuell
prüfen, insbesondere nach AJAX-Neuaufbau.

### AJAX-Wartezustände und Dublettenhinweise

Die Library `form_status` wird nur an Anlege-/Bearbeitungsformulare von KWE,
Aggregator, Person und Bestand in Gin und Gin Frontend angehängt. Der Marker
`data-ddbgo-form-status` begrenzt die Beobachtung auf diese Formulare. Die
Komponente dekoriert einzelne Drupal-AJAX-Instanzen, keine Core-Prototypen,
und verwendet die vorhandene, zunächst leere Live-Region von `Drupal.announce()`.
Ansagen sind höflich (`polite`); weder der Fokus noch die Validierung ändern sich.

Bei länger als 500 Millisekunden dauernden Ladekreisen wird die auslösende
Feld-/Aktionsbeschriftung mit „Bitte warten“ angekündigt. Nach Verarbeitung der
AJAX-Kommandos und Drupals Fokusbehandlung folgt der angezeigte Dublettenhinweis
oder „Ladevorgang beendet“. Das behauptet weder eine erfolgreiche Speicherung
noch die Gültigkeit einer Eingabe. Eigene Meldungs-/Ansagekommandos der Antwort
ersetzen den allgemeinen Abschlusshinweis. Netzwerk-/Verifikationsfehler bleiben
bei Drupals vorhandener Fehlermeldung; Abbrüche räumen lediglich den Wartetimer auf.
Parallele Anfragen haben getrennte Zustände; entfernte Formulare und überholte
Antworten werden nicht angesagt.

`unique_field_ajax` erzeugt seinen Dublettenhinweis unmittelbar im Feld-Suffix.
Die Komponente gibt diesem Hinweis eine eindeutige ID und ergänzt sie in
`aria-describedby`, ohne vorhandene Hilfetexte zu entfernen. Entfällt die Warnung,
entfällt auch der von uns angelegte Bezug. Initiales Rendern und wiederholtes
Behavior-Attach verknüpfen nur die Texte und bleiben still. Hinweis und Feldname
werden für die Ansage escaped, da `Drupal.announce()` intern HTML einsetzt.
Die bestehende Submit-Guard `unique_field_submit` arbeitet unverändert weiter.

```sh
node web/modules/custom/ddbgo_gin/tests/js/form-status.test.cjs
```

Die ausführbaren Node-Checks prüfen Request-Timing, Wiederanbindung, Ersatzfelder,
parallele Anfragen, Fehler-/Abbruchpfade, erhaltene Hilfereferenzen und sichere
Meldungstexte. Mit NVDA oder VoiceOver zusätzlich Namens-/DDB-URI-Prüfung sowie
E-Mail-, Personen- und Kontakt-Hinzufügen prüfen: kurze Anfragen ohne unnötige
Warteansage, langsame Anfragen mit Beginn/Ende, Dublettenhinweise einmal pro
Antwort und weiterhin nutzbare Tab-Navigation. Native Browserpopups bleiben
unverändert und gehören in diesen manuellen Test.

### Fehler und Speicherbestätigung nach einem Seitenreload

`page_feedback` ergänzt die Rückmeldung bei vollständig neu geladenen Seiten
in Gin und Gin Frontend. Nach einer fehlgeschlagenen Formularübermittlung
erhält die vorhandene Fehlerübersicht einmal den Fokus. Ihr Inhalt wird über
`aria-describedby` mit der Übersicht verbunden; die vorhandenen Links zu den
fehlerhaften Feldern bleiben nutzbar. Bestehende IDs und Beschreibungen werden
erhalten. Ist bereits ein anderes Element fokussiert, bleibt der Fokus dort
und der Fehlertext wird stattdessen einmal dringlich (`assertive`) angesagt.
Fokus und Ansage werden nicht gleichzeitig ausgelöst.

Bei einer Fehlerantwort auf einen vollständigen POST ergänzt der
HTML-Preprocessor „Fehler“ am Anfang des Dokumenttitels. Der ursprüngliche
Seiten- und Websitetitel bleibt erhalten. Der Messenger wird dabei nur
gelesen, damit Core die Fehlerübersicht anschließend weiterhin ausgeben kann.
Der Preprocessor läuft nach der normalen Titelverarbeitung, auch nach Metatag,
damit dessen Titelersetzung den Fehlerhinweis nicht entfernt. Er setzt
ausdrücklich `max-age: 0` für diese Rückgabe.

Erfolgs- und Warnmeldungen werden einmal mit ihrem vorhandenen Inhalt über
Drupals zunächst leere Live-Region angesagt: Erfolg höflich (`polite`), Warnung
dringlich (`assertive`). Die Speicherbestätigung stammt weiterhin aus Drupal;
eine reine AJAX-Aktualisierung wird nicht als erfolgreiche Speicherung
ausgegeben. Schließen-Schaltfläche und dekorativer Meldungstitel gehören
nicht zum angesagten Text. Bei gleichzeitig vorhandenen Fehlern hat die
Fehlerübersicht Vorrang.

Der Status-Messages-Preprocessor markiert nur serverseitige Gin-Meldungen
mit `data-ddbgo-page-messages` und hängt dort die Library an. Dieser Hook läuft
in Cores sitzungsabhängigem Lazy-Placeholder auch bei einem Treffer im Dynamic
Page Cache. Erfolgsseiten nach einer Weiterleitung erhalten deshalb keinen
sitzungsabhängigen Titel im gecachten Seiten-HTML; ihre tatsächliche Meldung
wird beim initialen Behavior-Attach zugänglich gemacht. Weitere Attach-Aufrufe
und AJAX-Antworten erhalten keine zusätzliche Fokussteuerung oder Ansage.

Toastify ist über `toastify.settings: enable_for` für Verwaltungs- und
Frontend-Theme deaktiviert. Dadurch bleiben auch für Administrator*innen die
Gin-Meldungen sichtbar, bis sie ausdrücklich geschlossen werden. Die bisherigen
fünf Sekunden langen Toasts ersetzen die Fehlerübersicht nicht mehr.

Die Ergänzung kopiert keine Gin-Templates, ändert keine Validierungsregeln und
benötigt keinen neuen Patch oder ein weiteres Modul. Native Chrome-Popups für
Pflichtfelder, E-Mail- und URL-Prüfungen bleiben ein separater manueller Prüffall.
Ein bereits gefüllter Live-Container im initialen HTML allein gewährleistet
keine Ansage nach einem Reload; deshalb werden gezielter Fokus und Drupals
vorhandene Ansagefunktion verwendet. Grundlage:
[W3C: User Notifications](https://www.w3.org/WAI/tutorials/forms/notifications/).

```sh
drush cim -y
drush cr
drush php:script web/modules/custom/ddbgo_gin/tests/php/page-feedback.test.php
node --test web/modules/custom/ddbgo_gin/tests/js/page-feedback.test.cjs
```

Die PHP-Regression prüft echte Meldungs- und HTML-Renderings, Theme-/AJAX-Grenzen
und den nicht konsumierenden Messenger-Zugriff. Sie speichert keine Inhalte
und sendet keine Formulare ab. Die JavaScript-Regression prüft einmalige
Ansagen, Fehlerpriorität, Fokus, erhaltene Beschreibungen und sicheres Escaping.
Die tatsächliche Screenreader-Ansage muss manuell geprüft werden: eine
serverseitig ungültige Eingabe speichern, über die Fehlerübersicht das Feld
aufrufen, die Eingabe korrigieren und erfolgreich speichern. Dies auch als
Administrator*in sowie bei einer bereits gecachten Zielseite kontrollieren.

### Nulltreffermeldungen nach AJAX-Filterung

„Keine Ergebnisse gefunden“ ist bei einer Aktualisierung ohne Seitenwechsel eine
[Statusmitteilung nach WCAG 4.1.3](https://www.w3.org/WAI/WCAG22/Understanding/status-messages.html),
kein dringender Fehler. `EmptyViewsResultsSubscriber` ergänzt deshalb ein
höfliches (`polite`) `AnnounceCommand` bei Views-AJAX-Antworten mit tatsächlich
ausgeführter View und leerem Ergebnis. Die Korrektur gilt unabhängig von
View-ID, Theme und Display-Typ, also auch für Block-, Standard- und
Verwaltungsdisplays. Sie erfasst künftig auf AJAX umgestellte Views ebenfalls.
Vollständige Seitenaufrufe erhalten keine zusätzliche Ansage; die vorhandene
AJAX-Einstellung und die bisherige Fokusbehandlung bleiben unverändert.

Die Ansage übernimmt ausschließlich konfigurierte sichtbare Textmeldungen aus
den Standard-Empty-Handlern `Text` und `TextCustom`. Deren `render(TRUE)`-Ausgabe
wird mit Drupals `renderInIsolation()` gerendert; bei `Text` werden dabei die
Filter des konfigurierten Textformats angewendet. HTML-Tags werden entfernt,
HTML-Entities dekodiert und der fertige Text erneut escaped, weil
`Drupal.announce()` intern HTML einsetzt. Andere Empty-Plugins und fehlende oder
leere Textmeldungen erzeugen keine zusätzliche Ansage. Es wird kein Meldungstext
erfunden. Antworten mit eigenen `announce`- oder `message`-Kommandos behalten
ihre bestehende Ansage, damit keine Doppelmeldung entsteht.

Der Subscriber fügt den Ansagebefehl nach dem Austausch der View ein und
verwendet Drupals bereits vorhandene, zunächst leere Live-Region. Die
Abhängigkeiten `views/views.ajax` → `core/drupal.ajax` → `core/drupal.message`
→ `core/drupal.announce` laden diese schon auf der ursprünglichen Seite.
Ein bloßes `role="status"` am neu eingefügten Nulltreffertext wäre für die
dynamische Ansage nicht zuverlässig. Es werden weder der ganze Ergebnisbereich
als Live-Region markiert noch Fokus, Filter, Ergebnisse oder sichtbare Texte
verändert; eigenes JavaScript und ein Patch sind nicht nötig.

```sh
drush cr
drush php:script web/modules/custom/ddbgo_gin/tests/php/views-empty-status.test.php
```

Zusätzlich mit NVDA oder VoiceOver die AJAX-Listen
`/search/bestand/europeana` und `/search/bestand/cdv` sowie weitere neu aktivierte
AJAX-Displays filtern: Bei Nulltreffern soll die konfigurierte sichtbare
Textmeldung einmal höflich vorgelesen werden, auch bei einer erneuten Filterung
mit demselben Ergebnis. Die Ergänzung darf die bisherige Fokusbehandlung nicht
verändern. Treffer, vollständige Seitenaufrufe und Views ohne unterstützte
Nulltreffer-Textmeldung dürfen keine zusätzliche Ansage erzeugen.

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

## Zugängliche Namen, Beschreibungen, Pflichtstatus und Suchfeld-Semantik für Select2

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
- Der Pflichtstatus des Originalfelds (`required` oder `aria-required="true"`)
  wird als `aria-required="true"` an dieselben Fokusziele übertragen. Wird das
  Feld optional, wird das Attribut dort entfernt. Die Suchfelder erhalten kein
  natives `required`: Sonst würde zusätzlich zum gewählten Wert eine Suchanfrage
  verlangt. Auswahl, Validierung und sichtbare Sternchen bleiben unverändert.

Das behebt den fehlenden Pflichtstatus der Select2-Ersatzfelder bei „Person“ und
„Rolle“ in den Personen-Paragraphs von KWE, Aggregator und Bestand sowie bei
„Kultur- oder Wissenseinrichtung“ im Bestandsformular. Die Korrektur gilt zentral
auch für weitere erforderliche Select2-Felder und nachgeladene Paragraphs.
Optionale Felder werden nicht anhand von CSS-Klassen oder Sternchen zu
Pflichtfeldern erklärt.

Die nativen Auswahlfelder „Content-Tier“, „Metadata-Tier“ und „Medientyp“
(einschließlich der verschachtelten Objektgruppen) benötigen keine Ergänzung:
Ein read-only Render der tatsächlichen Bestands-Anlegeform mit ungespeicherten
Paragraphs bestätigt jeweils natives `required` und korrekt verknüpfte Labels.
Das Label darf neben dem Steuerelement stehen; eine Eltern-Kind-Beziehung zum
Sternchen ist nicht erforderlich. Entscheidend ist die programmatische
Zuordnung zum Feld. Siehe
[W3C: Pflichtfelder mit ARIA kennzeichnen](https://www.w3.org/WAI/WCAG22/Techniques/aria/ARIA2)
und [W3C: Formularvalidierung](https://www.w3.org/WAI/tutorials/forms/validation/).

Select2 4.1.0 erzeugt für die Inline-Suche der Mehrfachauswahl ein `textarea`
mit `role="searchbox"` und `type="search"`. Die Rolle löst den axe-Befund
`aria-allowed-role` aus; das `type`-Attribut ist für Textareas ebenfalls
ungültig. Ein Textarea besitzt bereits die native `textbox`-Semantik. Der bestehende Fix
entfernt beide Attribute ausschließlich beim bekannten Inline-Textarea mit
der Rolle `searchbox`. Die echte Dropdown-Suche der Einfachauswahl verwendet
ein `input type="search"` und bleibt unverändert. Anders deklarierte Adapter
werden nicht überschrieben. Siehe
[MDN: textarea](https://developer.mozilla.org/en-US/docs/Web/HTML/Reference/Elements/textarea).

Die Textarea bleibt als Element erhalten, einschließlich ihrer CSS-Regeln für
lange Platzhalter. Labels, Beschreibungen, `aria-autocomplete` und die von
Select2 beim Öffnen/Schließen gepflegten Attribute `aria-controls` und
`aria-activedescendant` bleiben erhalten. Eine künstliche Ersatzrolle oder
Änderung von `aria-multiline` wird nicht eingeführt. Die Korrektur gilt zentral
für die von der Drupal-Integration erzeugten Mehrfachauswahlen, also auch in
nachgeladenen Paragraphs und Suchfiltern.

Die Library wird in `hook_library_info_alter()` als Abhängigkeit von
`select2/select2` eingebunden und gilt damit für alle mit der Drupal-Integration
erzeugten Select2-Felder, unabhängig von Feldnamen und Theme. Eine Mikrotask
wartet auf die synchronen Drupal-Behaviors. Das Contrib-Ereignis `select2-init`
liegt vor der Initialisierung; `select2:open` wäre für das Benennen des Suchfelds
zu spät, weil Select2 zuvor den Fokus setzt. Die Suchfelder werden deshalb
bereits im geschlossenen Zustand vorbereitet. `once` markiert die erzeugte
Auswahl statt des Originalfelds, damit auch AJAX-Neuaufbau und erneute
Initialisierung erfasst werden. Der Pflichtstatus wird bei jedem Attach erneut
abgeglichen. Ein begrenzter `MutationObserver` beobachtet pro Originalselect
ausschließlich `required` und `aria-required`, damit spätere Änderungen durch
Drupal `#states` und Conditional Fields übernommen werden. Er liest jeweils die
aktuelle Select2-Instanz und arbeitet daher auch nach erneuter Initialisierung.
Bei Drupal-Detach mit `unload` wird er getrennt; ein späteres Attach richtet ihn
erneut ein. Es gibt keine Beobachtung des gesamten Formulars und keine Änderung
von Werten, Fokus oder Validierungsereignissen.

Der Fix korrigiert Textzuordnungen, Pflichtstatus und das ungültige
Inline-Textarea-Markup.
Tastaturverhalten, Auswahl und Neuanlage
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
AJAX-Austausch sowie Suche, Enter und Escape. Die zusätzlichen Strukturprüfungen
kontrollieren die native Textarea-Semantik und den Erhalt der Suchfeld-Bezüge
auch nach erneuter Initialisierung und AJAX-Austausch; echte Such-Inputs
behalten ihre Attribute. Pflichtstatus-Prüfungen umfassen Einzel- und
Mehrfachauswahl, optionale Felder, dynamische Attributänderungen ohne `change`
oder erneutes Attach, erneute Initialisierung, AJAX-Austausch sowie
Detach mit `unload` und anschließendes Attach.
Originalfelder behalten ihre Pflichtattribute; Suchfelder bekommen kein natives
`required`. Die Synchronisierung löst keine zusätzlichen `change`-Ereignisse aus.
Es werden keine Serveranfragen
oder Formularübermittlungen ausgeführt. Attributprüfungen allein belegen noch
keine korrekte Screenreader-Ansage: Im Accessibility-Tree zusätzlich Namen,
Wert und Beschreibung prüfen, anschließend mit NVDA oder VoiceOver auf den
Anlege-/Bearbeitungsformularen testen, einschließlich neu hinzugefügter
Personen-Paragraphen und Felder ohne Hilfetext.

Die Fixture wurde lokal im isolierten Edge erfolgreich ausgeführt. Dessen
Accessibility-Tree bestätigt Feldnamen, Werte und Beschreibungen für Einzel-
und Mehrfachauswahl, die Dropdown-Suche, explizite ARIA-Namen und das nachgeladene
Feld. Der manuelle Screenreadertest auf den tatsächlichen Formularen steht aus.
Für die neue Textarea-Korrektur und die Pflichtstatus-Übertragung wurde die
Fixture erweitert und ihre Syntax geprüft. Die zusätzlichen Browserprüfungen, der erneute axe-Test und die
Screenreader-Prüfung stehen aus, da die Browsersteuerung in dieser Sitzung
nicht verfügbar ist. Die frühere erfolgreiche Browserprüfung deckt diese
Erweiterung noch nicht ab.
Die Pflichtstatus-Logik und der Observer-Lebenszyklus wurden zusätzlich mit
einem temporären Node-VM-Adapter gegen das echte Produktionsskript geprüft:
97 erfolgreiche Prüfungen, einschließlich optionaler Zustände, aktueller
Fokusziele nach erneuter Initialisierung und Aufräumen bei `unload`.
Diese isolierte Vertragsprüfung ersetzt weder echtes Select2 im Browser noch
den Screenreadertest.

## Fokus beim Anzeigen der Zeilenreihenfolge

Core TableDrag und Gins Variante blenden mit „Zeilenreihenfolge anzeigen“ die
Reihenfolgefelder ein, lassen den Fokus jedoch am Schalter. Bei mehrwertigen
Feldern liegen weitere Eingaben vor dem ersten Reihenfolgefeld in der Tabfolge.
Die zentrale Ergänzung `ddbgo_gin.tabledrag-focus.js` setzt nach bewusster
Betätigung des Schalters den Fokus direkt auf das erste sichtbare, bedienbare
Reihenfolgefeld der zugehörigen Tabelle. Auswahl- und Zahlenfelder werden
unterstützt; deaktivierte, unsichtbare und schreibgeschützte Felder werden
übersprungen. Beim Ausblenden, in leeren Tabellen und ohne bedienbares Ziel
erhält der betätigte Schalter den Fokus.

Die Zuordnung erfolgt über die TableDrag-Instanz und deren `action: order`-
Einstellungen. Nur mit `hidden: true` konfigurierte Ziele werden berücksichtigt;
dauerhaft sichtbare Reihenfolgefelder und Eltern-/ID-Spalten sind keine Ziele.
Verschachtelte Tabellen werden ausgeschlossen. Die Zuordnung hängt nicht davon
ab, ob Gin einen Scroll-Wrapper zwischen Schalter und Tabelle einfügt.

Der zusätzliche Klick-Handler läuft nach dem vorhandenen Core-/Gin-Handler.
Er reagiert damit auch auf die native Aktivierung per Enter oder Leertaste.
Der tatsächliche Sichtbarkeitszustand entscheidet über das Fokusziel, nicht der
übersetzte Schaltertext. Das allgemeine Ereignis `columnschange` wird bewusst
nicht verwendet: Drupal löst es auch beim Laden, nach AJAX, beim Synchronisieren
gespeicherter Einstellungen und für mehrere Tabellen gleichzeitig aus. Diese
automatischen Vorgänge sollen keinen zusätzlichen Fokuswechsel auslösen.

Die Library ist eine Abhängigkeit von `core/drupal.tabledrag`, auch wenn Gin
dessen JavaScript ersetzt. Eine Mikrotask wartet auf die Initialisierung;
`once` am erzeugten Schalter verhindert doppelte Handler und berücksichtigt
neue Schalter nach AJAX-Austausch. Die Sortierfunktion, Formularwerte, Ziehgriffe
und die normale Tabfolge werden nicht verändert. Der zuvor festgestellte
Semantik-Befund der Ziehgriffe bleibt separat offen. Ein Modul, Composer-Patch
oder Konfigurationsimport ist nicht erforderlich; nach dem Deployment `drush cr`.

Dies ist eine Bedienungsverbesserung. [WCAG 2.4.3](https://www.w3.org/WAI/WCAG22/Understanding/focus-order.html)
fordert eine sinnvolle Fokusreihenfolge, keine pauschale Fokusverlagerung oder
Ein-Tab-Regel für jeden eingeblendeten Inhalt.

Regressionstest mit den installierten TableDrag-Implementierungen und Claro:

```sh
node web/modules/custom/ddbgo_gin/tests/js/tabledrag-focus.test.cjs gin
node web/modules/custom/ddbgo_gin/tests/js/tabledrag-focus.test.cjs core
```

Die ausgegebenen HTML-Dateien im Browser öffnen, zusätzlich mit `?saved=1` für
eine anfangs eingeblendete Reihenfolge. Die Fixture prüft Anzeigen/Ausblenden,
mehrere und verschachtelte Tabellen, deaktivierte Fieldsets, schreibgeschützte
und unsichtbare Felder, leere Tabellen, wiederholtes Attach, AJAX-Austausch,
gespeicherte Einstellungen und unveränderte Formularwerte. Es werden keine
Serveranfragen oder Formularübermittlungen ausgeführt. Die programmgesteuerten
Klicks ersetzen keine Prüfung der nativen Tastenaktivierung: zusätzlich Enter,
Leertaste und Tab im Browser sowie NVDA/VoiceOver auf den Personen-/Kontakt-
Paragraphen und im E-Mail-Mehrfachfeld des Personenformulars prüfen.

Lokal bestanden Core und Gin im isolierten Edge jeweils mit und ohne gespeicherte
Einstellung die Fixture sowie echte Enter-, Leertasten- und Mausaktivierung.
Fokusziel, zugänglicher Name und unveränderte Formularwerte wurden geprüft.
Der manuelle Screenreadertest auf den tatsächlichen Formularen steht aus.

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
  Die passenden Navigationslinks werden einmal erfasst und nur bei einem
  tatsächlichen Reiterwechsel aktualisiert. AJAX ergänzt Links aus dem neuen
  Fragment. Eingaben in Formularfeldern lösen keine Link- oder Tab-Durchläufe
  aus; Navigationsereignisse prüfen nur den gerade bedienten Link.
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
- `ddbgo_gin.select2-accessibility.js`: Überträgt Feldnamen, Hilfetext-Verweise
  und Pflichtstatus auf Select2s Fokusziele und korrigiert die Inline-Textarea-
  Semantik; siehe den Select2-Abschnitt oben.
- `ddbgo_gin.tabledrag-focus.js`: Fokussiert das erste bedienbare Reihenfolgefeld
  nach Betätigung des TableDrag-Schalters; siehe „Fokus beim Anzeigen der Zeilenreihenfolge“.
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

Die Tests führen die installierten Field-Group-Tab- und Validierungsfunktionen
mit einem kleinen DOM-/jQuery-Adapter aus, ohne Datenbank: Reiterübernahme,
URL-Parameter, fremde Links, Gruppenzuordnung,
verschachtelte Reiter, mobile Details, Tastaturbedienung, AJAX und Fehlervorrang.
Sie zählen außerdem Link-/Tab-Abfragen bei vielen Links und Eingabeereignissen;
dies ist keine Messung der tatsächlichen Browserlatenz.
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

## Tab-Reihenfolge bei Custom Formattern

Alle sieben projektbezogenen Custom Formatter haben ihre Kontext-Bearbeitung
in `config/sync/custom_formatters.formatter.*.yml` deaktiviert:
`third_party_settings.contextual.mode: '0'`. Die Einstellung ist ausdrücklich
gesetzt, da ein fehlender Wert die Kontext-Bearbeitung wieder aktiviert.

| Formatter | Beispiele für die Verwendung |
| --- | --- |
| `isil_link` | ISIL in KWE-Suche und KWE-Einzelansicht |
| `jira_link` | JIRA-Hauptticket bei Aggregator und Bestand; Europeana-Lieferung |
| `jira_link_bei_europeana` | JIRA-Ticket bei Europeana in Bestandsliste und Einzelansicht |
| `label_als_telefonnummer` | Telefonnummer in Personenliste und Einzelansicht |
| `label_als_link` | DDB-URI, Coding-da-Vinci-Link und Taxonomie-URIs |
| `komma_getrennte_aufzaehlung` | Datenformat, Lieferweg und Ausrichtungsangaben |
| `text_in_klammern` | Medientyp in der Listenansicht von DDB-Objekt-Paragraphs |

Bei aktiviertem Modus steht im HTML hinter der formatierten Ausgabe ein
zusätzlicher Bearbeitungsplatzhalter für die Formatter-Konfiguration. Drupal
erzeugt daraus eine mit Tab erreichbare Schaltfläche. In den betroffenen
Suchtabellen fehlt deren positionierender Feldwrapper; die Schaltfläche erscheint
oben rechts, obwohl sie in der Tab-Reihenfolge unmittelbar auf den Feldinhalt
folgt. Bestätigt wurde das bei ISIL-, Telefon- und JIRA-Links. In Einzelansichten
kann die Positionierung korrekt sein; die Verwaltungsabkürzung entfällt auch dort.
Bei `text_in_klammern` entfernt die Europeana-View bereits die entsprechenden
HTML-Tags; die Deaktivierung gilt auch für weitere Ausgaben dieses Formatters.

Die Änderung entfernt ausschließlich die Abkürzungen zu den
Formatter-Konfigurationen. Diese bleiben unter `/admin/structure/formatters`
erreichbar. Linktexte, Ziele, Telefonnummern und die bisherigen Formatierungen
kommen unverändert aus den vorhandenen Formatter-Templates. Die normale
Datensatz-Bearbeitung und andere Drupal-Kontextmenüs bleiben verfügbar.
Zusätzliche Hooks, JavaScript oder Patches sind dafür nicht nötig.

Deployment: Konfiguration importieren (`drush cim`), danach Caches neu aufbauen
(`drush cr`). Zur Kontrolle auf `/search/kwe`, `/search/person`, `/search/bestand`
und `/search/bestand/europeana` mit Tab über die jeweiligen Links gehen:
Die zusätzlichen Formatter-Schaltflächen dürfen nicht mehr in der Tabfolge
erscheinen. Die Linkausgabe und fehlende Formatter-Kontextplatzhalter lassen
sich per Renderprüfung kontrollieren; die tatsächliche Tab-Reihenfolge
zusätzlich im Browser prüfen.

## Barrierefreie Verknüpfung der aufklappbaren Suchfilter

Die Suchfilter verwenden ein natives `<details>` mit `<summary>` als
Aufklappschaltfläche. Das entspricht dem
[Disclosure-Muster der W3C](https://www.w3.org/WAI/ARIA/apg/patterns/disclosure/):
Eine zusätzliche Überschrift ist dafür nicht vorgeschrieben, `aria-controls`
ist optional. DDBgo ergänzt dieses Attribut zur eindeutigen Zuordnung des
Schalters zum eingeblendeten Inhalt.

`templates/details--ddbgo-gin.html.twig` vergibt ausschließlich bei der Klasse
`ddbgo-exposed-filters` eine mit `clean_unique_id` erzeugte ID am Inhaltswrapper
und setzt `aria-controls` am zugehörigen `<summary>` auf genau diese ID.
Die eindeutige Vergabe berücksichtigt mehrere Filterformulare und AJAX-Ausgaben.
Andere Details bleiben unverändert. Öffnen, Schließen und Tastaturbedienung
übernimmt weiterhin das native HTML-Element; die vorhandene Aktualisierung von
`aria-expanded` bleibt bei Drupal Core. Zusätzliches JavaScript, ein Modul oder
ein Patch sind nicht erforderlich.

Deployment: Caches neu aufbauen (`drush cr`). Zur Kontrolle im gerenderten HTML
prüfen, dass jedes Filter-`<summary>` mit `aria-controls` genau einen vorhandenen
Inhaltswrapper referenziert. Im Browser geöffneten und geschlossenen Zustand,
Enter- und Leertastenbedienung sowie `aria-expanded` prüfen. Auch bei mehreren
Filterformularen auf einer Seite und nach AJAX-Filterung müssen die IDs eindeutig
und die Verknüpfungen gültig bleiben.

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
