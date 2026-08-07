# DDBgo Helm-Chart für OpenShift

Dieses Chart installiert DDBgo mit Redis und MariaDB in einem OpenShift-
Namespace. Es verwendet eine OpenShift `Route`, nicht privilegierte Container,
`RuntimeDefault`-Seccomp, entfernte Linux-Capabilities und keine fest
eingetragene UID. Dadurch kann die OpenShift-SCC die projektspezifische UID
vergeben.

## Benennung

Die Namen werden aus dem Release-Namen gebildet:

| Ressource | Produktion | Test |
| --- | --- | --- |
| Release / Namespace | `ddbgo` | `ddbgo-t` |
| Drupal Deployment, Service, ConfigMap, Secret, PVC, ServiceAccount | `ddbgo-drupal` | `ddbgo-t-drupal` |
| Redis StatefulSet, Service, Secret, PVC, ServiceAccount | `ddbgo-redis` | `ddbgo-t-redis` |
| MariaDB StatefulSet, Service, Secret, PVC, ServiceAccount | `ddbgo-db` | `ddbgo-t-db` |
| Route (Name und Host) | `go.deutsche-digitale-bibliothek.de` | `go-t.deutsche-digitale-bibliothek.de` |

Drei getrennte ServiceAccounts statt eines gemeinsamen Accounts halten die
Workloads nach dem Least-Privilege-Prinzip voneinander getrennt. Es werden
keine Rollen oder RoleBindings benötigt.

## Installation

Voraussetzungen sind Helm 3 und ein angemeldeter OpenShift-Client.

Produktion:

```sh
helm upgrade --install ddbgo ./helm/ddbgo \
  --namespace ddbgo \
  --create-namespace \
  --atomic \
  --timeout 15m
```

Test:

```sh
helm upgrade --install ddbgo-t ./helm/ddbgo \
  --namespace ddbgo-t \
  --create-namespace \
  --values ./helm/ddbgo/values-test.yaml \
  --atomic \
  --timeout 15m
```

Vor einer Installation kann der serverseitige Dry-Run Namenskollisionen
erkennen:

```sh
helm upgrade --install ddbgo ./helm/ddbgo \
  --namespace ddbgo \
  --dry-run=server \
  --debug
```

Das Anwendungs-Image verwendet standardmäßig den vom Projekt veröffentlichten
Tag `tagged`. Für reproduzierbare Deployments sollte beim Installieren ein
konkreter Release-Tag oder vorzugsweise ein Digest gesetzt werden:

```sh
helm upgrade --install ddbgo ./helm/ddbgo \
  --namespace ddbgo \
  --set-string drupal.image.tag=v1.2.3
```

## Schutz bestehender Ressourcen

`protection.failOnExistingResource=true` bewirkt:

- Eine Erstinstallation bricht ab, wenn eine der zu erzeugenden Ressourcen
  bereits vorhanden ist. Das Chart übernimmt und überschreibt sie nicht.
- Ein Upgrade ändert nur Ressourcen, deren Helm-Metadaten exakt zu diesem
  Release und Namespace gehören.
- `helm.sh/resource-policy: keep` schützt alle erzeugten Ressourcen vor dem
  Löschen durch Uninstall, Rollback oder eine Entfernung aus dem Chart.

Ein normales Upgrade aktualisiert weiterhin die Ressourcen, die bereits zu
diesem Release gehören. Ohne diese Ausnahme wären Helm-Upgrades nicht möglich.
Durch `keep` bleibt nach `helm uninstall` bewusst eine laufende, verwaiste
Installation zurück. Eine spätere Entfernung ist deshalb ausschließlich eine
explizite Administratorentscheidung.

Bestehende Secrets und PVCs können referenziert werden. Dazu `create=false` und
den vorhandenen Namen setzen, zum Beispiel:

```yaml
drupal:
  secret:
    create: false
    existingSecret: ddbgo-drupal
  persistence:
    create: false
    existingClaim: ddbgo-drupal
```

Erwartete Schlüssel in vorhandenen Secrets:

| Secret | Schlüssel |
| --- | --- |
| Drupal | `hash-salt` |
| Redis | `password`, `users.acl` |
| MariaDB | `database`, `username`, `password`, `root-password` |

`users.acl` muss eine gültige Redis-ACL enthalten, deren Passwort mit
`password` übereinstimmt, beispielsweise
`user default on >GEHEIM ~* &* +@all`.

## Passwörter und Persistenz

Bleiben Passwortwerte leer, erzeugt das Chart sie bei der ersten Installation
zufällig. Die erzeugten Secrets sind unveränderlich und werden bei Upgrades
weiterverwendet. Für zentrale Secret-Verwaltung sollten stattdessen vorhandene
Secrets referenziert werden.

Drupal verwendet einen PVC für öffentliche und private Dateien. Ein
Init-Container legt darin getrennte Verzeichnisse an. Redis aktiviert AOF;
MariaDB und Redis verwenden jeweils einen eigenen PVC. Die Standardgrößen und
StorageClasses können in `values.yaml` angepasst werden.

## Versionslinien

- Drupal Core ist im Projekt-Lockfile auf `11.4.5` festgelegt.
- Redis verwendet `8.2.7`; die 8.2-Linie ist GA und bis 1. September 2030
  unterstützt.
- MariaDB verwendet `11.8.8-ubi9`; MariaDB 11.8 ist eine LTS-Linie.

Patch-Updates sollten automatisiert geprüft und nach einem Test als Änderung an
`values.yaml` übernommen werden. Bewegliche Tags wie `latest` oder `lts` sind
absichtlich nicht voreingestellt.

## Veröffentlichung über GitHub

Der vollständige operative Ablauf einschließlich Auswahl, Installation,
Abnahme und Rollback konkreter Test- und Produktionsversionen steht im
[`Helm-Release- und Deployment-Workflow`](../../docs/helm-release-workflow.md).

Die Action [`.github/workflows/helm-publish.yml`](../../.github/workflows/helm-publish.yml)
veröffentlicht das Chart als OCI-Artefakt unter:

```text
oci://ghcr.io/mbuechner/helm/ddbgo
```

Sie läuft bei Chart-Änderungen sowohl auf `test` als auch auf `master`. Beide
Branches verwenden dieselbe Basisversion aus `Chart.yaml`; die veröffentlichte
Version wird abhängig vom Branch gebildet:

| Branch | Kanal | Beispielversion | Zielrelease |
| --- | --- | --- | --- |
| `test` | Test-Prerelease | `0.1.0-test.123.1.shaabc1234` | `ddbgo-t` in `ddbgo-t` |
| `master` | Produktion | `0.1.0` | `ddbgo` in `ddbgo` |

Die Testversion enthält Workflow-Lauf, Versuch und Commit-ID. Sie ist dadurch
eindeutig und kann eine vorhandene Version nicht überschreiben. Beim Merge von
`test` nach `master` bleibt `Chart.yaml` unverändert; aus derselben Basisversion
wird auf `master` die stabile Produktionsversion.

Der typische Ablauf für die nächste Version ist:

1. Auf `test` die Basisversion in `Chart.yaml` auf beispielsweise `0.2.0`
   erhöhen.
2. Änderungen auf `test` veröffentlichen und mit Versionen wie
   `0.2.0-test.…` im Testsystem prüfen.
3. `test` ohne zusätzliche Versionsänderung nach `master` mergen.
4. Die Action veröffentlicht auf `master` genau `0.2.0` für Produktion.

Eine bereits veröffentlichte Version wird nicht überschrieben. Weitere
Änderungen nach einer Produktionsveröffentlichung erfordern deshalb die nächste
Basisversion. Die Action kann auch unter
**Actions → Publish Helm chart → Run workflow** gestartet werden; dabei muss als
Branch `test` oder `master` ausgewählt sein.

`GITHUB_TOKEN` genügt zum Veröffentlichen. Das Paket erscheint unter
**Packages**. GitHub legt neue Pakete abhängig von den Account-Einstellungen
möglicherweise zunächst als privat an. Für anonymen Abruf muss seine Sichtbarkeit
einmalig auf **Public** gestellt werden.

### GitHub Pages für den OpenShift Developer Catalog

Zusätzlich zu GHCR pflegt dieselbe Action zwei klassische HTTP-Helm-Repositories
auf dem Branch `gh-pages`:

| Namespace | Repository-URL | Inhalt |
| --- | --- | --- |
| `ddbgo-t` | `https://mbuechner.github.io/ddbgo/helm/test/` | Test-Prereleases aus `test` |
| `ddbgo` | `https://mbuechner.github.io/ddbgo/helm/stable/` | stabile Versionen aus `master` |

Jeder Kanal besitzt eine eigene `index.yaml`. Bereits vorhandene Chartarchive
werden vor dem Veröffentlichen inhaltlich verglichen und niemals überschrieben.
Die Action verwendet keinen Force-Push und wird über `concurrency` serialisiert,
damit Test- und Produktionspublikationen den Pages-Branch nicht gleichzeitig
ändern.

Einmalige GitHub-Konfiguration:

1. Den Workflow zunächst auf `test` ausführen, damit der Branch `gh-pages`
   angelegt wird.
2. Unter **Settings → Actions → General → Workflow permissions** die Option
   **Read and write permissions** erlauben, sofern eine Organisationsrichtlinie
   Schreibzugriff nicht bereits zulässt.
3. Unter **Settings → Pages** als Quelle **Deploy from a branch**, Branch
   `gh-pages` und Verzeichnis `/ (root)` auswählen.
4. Prüfen, ob
   `https://mbuechner.github.io/ddbgo/helm/test/index.yaml` erreichbar ist.

Repository für das Testprojekt:

```yaml
apiVersion: helm.openshift.io/v1beta1
kind: ProjectHelmChartRepository
metadata:
  name: ddbgo
  namespace: ddbgo-t
spec:
  name: DDBgo Test
  description: Testversionen des DDBgo Helm-Charts
  connectionConfig:
    url: https://mbuechner.github.io/ddbgo/helm/test/
```

Repository für Produktion:

```yaml
apiVersion: helm.openshift.io/v1beta1
kind: ProjectHelmChartRepository
metadata:
  name: ddbgo
  namespace: ddbgo
spec:
  name: DDBgo
  description: Stabile Versionen des DDBgo Helm-Charts
  connectionConfig:
    url: https://mbuechner.github.io/ddbgo/helm/stable/
```

Nach dem Anlegen erscheinen die Charts im jeweiligen OpenShift-Projekt im
Developer Catalog:

```sh
oc apply -f ddbgo-test-repository.yaml
oc apply -f ddbgo-production-repository.yaml

oc get projecthelmchartrepositories -n ddbgo-t
oc get projecthelmchartrepositories -n ddbgo
```

### Installation aus GHCR

Die genaue erzeugte Version steht in der Zusammenfassung des jeweiligen
Action-Laufs. Ein öffentliches Produktionschart wird so installiert:

```sh
helm upgrade --install ddbgo \
  oci://ghcr.io/mbuechner/helm/ddbgo \
  --version 0.1.0 \
  --namespace ddbgo \
  --create-namespace \
  --atomic \
  --timeout 15m
```

Für das Testsystem wird die konkrete Prerelease-Version zusammen mit dem
Testprofil verwendet:

```sh
helm upgrade --install ddbgo-t \
  oci://ghcr.io/mbuechner/helm/ddbgo \
  --version 0.1.0-test.123.1.shaabc1234 \
  --namespace ddbgo-t \
  --create-namespace \
  --values ./helm/ddbgo/values-test.yaml \
  --atomic \
  --timeout 15m
```

Das Testprofil setzt den Drupal-Container auf den beweglichen Tag `test` und
`imagePullPolicy: Always`. Die Chart-Version steht zusätzlich als Annotation im
Pod-Template, sodass jede neue Testchart-Version einen Drupal-Rollout auslöst.
Produktion verwendet weiterhin den freigegebenen Tag `tagged`, sofern kein
konkreter Tag oder Digest übergeben wird.

Bei einem privaten Paket ist zuerst eine Anmeldung mit einem klassischen GitHub
Personal Access Token mit `read:packages` notwendig:

```sh
export CR_PAT='GITHUB_TOKEN'
printf '%s' "$CR_PAT" | helm registry login ghcr.io \
  --username GITHUB_BENUTZERNAME \
  --password-stdin
```

Metadaten lassen sich vorab prüfen:

```sh
helm show chart oci://ghcr.io/mbuechner/helm/ddbgo --version 0.1.0
helm show values oci://ghcr.io/mbuechner/helm/ddbgo --version 0.1.0
```

OCI-Repositories werden nicht mit `helm repo add` registriert. Der vollständige
`oci://`-Pfad und die gewünschte Version werden direkt an `helm install`,
`helm upgrade`, `helm pull` oder `helm show` übergeben.

Die Publish-Action installiert nicht selbst auf einem OpenShift-Cluster, da
dafür bewusst noch keine Cluster-Zugangsdaten im Repository definiert sind. Eine
separate Deployment-Action kann darauf aufbauend über geschützte GitHub-
Environments für `test` und `production` ergänzt werden.
