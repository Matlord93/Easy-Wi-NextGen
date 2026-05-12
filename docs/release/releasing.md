# Releasing (Core)

## Tag-basiert
1. Version in `VERSION` aktualisieren.
2. Channel festlegen: `stable`, `beta` oder `dev`.
3. Tag erstellen und pushen:
   - Stable: `vX.Y.Z`
   - Beta: `vX.Y.Z-beta.N`
   - Dev: `vX.Y.Z-dev.N`
4. GitHub Release mit passendem Pre-release-Flag und Release-Notes-Marker anlegen:

```text
easywi-channel: stable
```

Für `beta` und `dev` muss GitHub **Pre-release** aktiviert sein. `alpha` wird nur noch als Legacy-Alias für `dev` akzeptiert.

## Lokal bauen
```bash
RELEASE_CHANNEL=stable VERSION=$(cat VERSION) ./scripts/build-core-release.sh dist/core-release
```

## Workflow-Artefakte
`release.yml` baut:
- `core-full-<ver>.zip` / `.tar.gz`
- `core-novendor-<ver>.zip` / `.tar.gz`
- `manifest.json`, `checksums.sha256`, `feed.json`, optional `manifest.sig`

## Feed
`feed.json` wird als Release-Asset erzeugt und vom Panel als Source konsumiert. Das strukturierte Format trennt die Zielversionen pro Channel:

```json
{
  "latest": {
    "stable": "1.2.0",
    "beta": "1.3.0-beta.1",
    "dev": "1.4.0-dev.3"
  },
  "releases": [
    {"version": "1.2.0", "channel": "stable", "artifacts": {}}
  ]
}
```
