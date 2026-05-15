# Security Policy

## Unterstützte Versionen

Dieses Projekt befindet sich aktuell in der Version `1.0.0-alpha`. Sicherheitsupdates werden grundsätzlich für die jeweils aktuelle Entwicklungs- bzw. Release-Version bereitgestellt.

| Version | Sicherheitsupdates |
| ------- | ------------------ |
| `1.0.x` | Ja, solange diese Reihe aktiv gepflegt wird |
| `< 1.0` | Nein, außer nach ausdrücklicher Ankündigung |

## Sicherheitslücken melden

Bitte melde vermutete Sicherheitslücken **nicht** öffentlich über Issues, Diskussionen oder Pull Requests.

Nutze stattdessen einen privaten Meldeweg:

- GitHub: Erstelle nach Möglichkeit eine private Security Advisory im Repository.
- Alternativ: Kontaktiere die Maintainer über den im Projekt bzw. in der Organisation hinterlegten Sicherheitskontakt.

Wenn kein privater Meldeweg verfügbar ist, teile zunächst nur eine kurze Beschreibung des Problems mit und veröffentliche keine ausnutzbaren Details, bis ein sicherer Kommunikationskanal vereinbart wurde.

## Benötigte Informationen

Damit wir eine Meldung schnell prüfen können, füge bitte möglichst viele der folgenden Informationen hinzu:

- betroffene Version, Commit oder Deployment-Umgebung
- betroffene Komponente, zum Beispiel Core, Agent, Installer, API oder Webinterface
- technische Beschreibung der Schwachstelle
- reproduzierbare Schritte oder ein minimales Proof of Concept
- erwartete Auswirkung, zum Beispiel Rechteausweitung, Datenabfluss oder Remote Code Execution
- bekannte Voraussetzungen, etwa besondere Rollen, Konfigurationen oder Netzwerkzugriff
- Hinweise auf bereits erfolgte Veröffentlichung oder aktive Ausnutzung, falls bekannt

## Umgang mit Meldungen

Wir bemühen uns um folgenden Ablauf:

1. Eingang der Meldung bestätigen.
2. Reproduzierbarkeit und Auswirkung prüfen.
3. Korrektur planen und umsetzen.
4. Sicherheitsrelevante Änderungen testen.
5. Veröffentlichung, Changelog oder Advisory vorbereiten.

Die tatsächlichen Zeiten können je nach Schweregrad, Reproduzierbarkeit und Verfügbarkeit der Maintainer variieren.

## Koordinierte Offenlegung

Bitte gib uns angemessen Zeit, eine Schwachstelle zu untersuchen und zu beheben, bevor Details veröffentlicht werden. Wir bitten darum, Exploit-Code, konkrete Angriffspfade oder sensible Projektdaten nicht öffentlich zu teilen, solange keine abgestimmte Veröffentlichung erfolgt ist.

## Scope

Zum Security-Scope gehören insbesondere:

- Authentifizierung, Autorisierung und Session-Handling
- API-Endpunkte und Agent-Kommunikation
- Installer, Deployment- und Update-Skripte
- Umgang mit Zugangsdaten, Tokens, Secrets und Konfigurationsdateien
- Mandanten-, Rollen- und Rechteprüfungen
- Datei-, Prozess-, Netzwerk- und Dienstverwaltung durch Agents

Nicht als Sicherheitslücke gelten in der Regel:

- fehlende Sicherheitsheader ohne konkret nachweisbare Auswirkung
- theoretische Schwachstellen ohne realistischen Angriffspfad
- Social-Engineering-Angriffe ohne technische Projektschwachstelle
- Denial-of-Service durch extrem hohe Last ohne spezifischen Bug
- Probleme in nicht unterstützten oder stark veränderten Deployments

## Sichere Nutzung

Betreiber sollten Easy-Wi Next-Gen nur in gepflegten Umgebungen einsetzen, Updates zeitnah einspielen, Secrets regelmäßig rotieren und Admin-Zugänge mit starken Passwörtern sowie, sofern verfügbar, zusätzlicher Absicherung schützen.
