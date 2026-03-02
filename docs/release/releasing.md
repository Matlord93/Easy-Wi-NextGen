# Releasing (Core)

## Tag-basiert
1. Version in `VERSION` aktualisieren.
2. Tag erstellen: `git tag vX.Y.Z && git push origin vX.Y.Z`.
3. Workflow `release.yml` baut:
   - `core-full-<ver>.zip` / `.tar.gz`
   - `core-novendor-<ver>.zip` / `.tar.gz`
   - `manifest.json`, `checksums.sha256`, `feed.json`, optional `manifest.sig`

## Lokal bauen
```bash
VERSION=$(cat VERSION) ./scripts/build-core-release.sh dist/core-release
```

## Feed
`feed.json` wird als Release-Asset erzeugt und vom Panel als Source konsumiert.
