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

Die Action [`.github/workflows/helm-publish.yml`](../../.github/workflows/helm-publish.yml)
veröffentlicht das Chart als OCI-Artefakt in der GitHub Container Registry:

```text
oci://ghcr.io/mbuechner/helm/ddbgo
```

Sie läuft automatisch, wenn sich `helm/ddbgo/**` auf `master` ändert, und kann
zusätzlich in GitHub unter **Actions → Publish Helm chart → Run workflow**
gestartet werden. `GITHUB_TOKEN` genügt; ein zusätzliches Repository-Secret ist
nicht erforderlich.

Eine bereits veröffentlichte Version wird niemals überschrieben. Vor jeder
neuen Veröffentlichung muss deshalb `version` in `Chart.yaml` erhöht werden,
beispielsweise von `0.1.0` auf `0.1.1`. Nach dem Merge nach `master` kann der
Workflow unter **Actions** kontrolliert werden. Das Paket erscheint anschließend
auf der GitHub-Profil- beziehungsweise Organisationsseite unter **Packages**.

GitHub legt neue Container-Pakete abhängig von den Account-Einstellungen
möglicherweise zunächst als privat an. Soll das Chart ohne Anmeldung abrufbar
sein, muss die Sichtbarkeit in den Package-Einstellungen einmalig auf **Public**
gestellt werden.

### Installation aus GHCR

Ein öffentliches Chart kann ohne vorheriges `helm repo add` installiert werden:

```sh
helm upgrade --install ddbgo \
  oci://ghcr.io/mbuechner/helm/ddbgo \
  --version 0.1.0 \
  --namespace ddbgo \
  --create-namespace \
  --atomic \
  --timeout 15m
```

Bei einem privaten Paket ist zuerst eine Anmeldung mit einem klassischen GitHub
Personal Access Token mit `read:packages` notwendig:

```sh
export CR_PAT='GITHUB_TOKEN'
printf '%s' "$CR_PAT" | helm registry login ghcr.io \
  --username GITHUB_BENUTZERNAME \
  --password-stdin
```

Für das Testsystem kann das im Paket enthaltene Werteprofil zunächst entpackt
und dann verwendet werden:

```sh
helm pull oci://ghcr.io/mbuechner/helm/ddbgo \
  --version 0.1.0 \
  --untar

helm upgrade --install ddbgo-t ./ddbgo \
  --namespace ddbgo-t \
  --create-namespace \
  --values ./ddbgo/values-test.yaml \
  --atomic \
  --timeout 15m
```

Metadaten lassen sich vorab prüfen:

```sh
helm show chart oci://ghcr.io/mbuechner/helm/ddbgo --version 0.1.0
helm show values oci://ghcr.io/mbuechner/helm/ddbgo --version 0.1.0
```

OCI-Repositories werden nicht mit `helm repo add` registriert. Der vollständige
`oci://`-Pfad und die gewünschte Version werden direkt an `helm install`,
`helm upgrade`, `helm pull` oder `helm show` übergeben.
