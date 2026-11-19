# Minecraft live integration checks

Regular unit/regression tests run with:

```sh
go test ./... -count=1
```

Tests that download official Minecraft distributions or start a real game
server require the explicit `minecraft_integration` build tag. They are
excluded from regular builds instead of reporting skips or no-op passes.
Selecting the tag executes the checks; missing network, Linux, certificates,
Java/download dependencies or upstream availability causes a real failure.

```sh
go test -tags minecraft_integration ./cmd/agent \
  -run '^TestManagedMinecraftLive(Vanilla|JavaMajors|Distributions)$' \
  -count=1 -timeout=45m -v
```

This validates a real Vanilla server start, Java 8/16/17/21/25, and installation
of Paper, BungeeCord, Bedrock and Spigot. Each distribution subtest reports its
own failure, so one provider failure does not prevent later providers from
being checked. BungeeCord and BuildTools use the official SpigotMC GitHub
release metadata, exact release asset URLs and published SHA-256 digests;
third-party mirrors and replacement artifacts are not used.

To also start each installed distribution:

```sh
EASYWI_TEST_MINECRAFT_START_LIVE=1 \
  go test -tags minecraft_integration ./cmd/agent \
  -run '^TestManagedMinecraftLiveDistributions$' -count=1 -timeout=45m -v
```

Run in an isolated Linux node/container, with enough space and memory for
Java, game artifacts and temporary worlds. Spigot requires Git and a JDK and
runs its actual BuildTools build under a separate build user when necessary.
The regular file-ownership checks require root. The permission-denial check
drops privileges in a child process when its parent is root, so the ownership
and denial assertions can both execute in the same root matrix.

Optional caches:

- `EASYWI_JAVA_CACHE_DIR`: managed Java installation root.
- `EASYWI_MINECRAFT_ARTIFACT_CACHE_DIR`: verified Spigot artifact/build cache.
- `EASYWI_MINECRAFT_BUILD_USER`: existing or managed unprivileged build user.

External connections retain certificate verification. Behind a TLS-inspecting
proxy, install its trusted CA or configure `SSL_CERT_FILE`/`CURL_CA_BUNDLE` and
Git/Java trust stores correctly. Do not disable certificate verification to
make a test pass. Upstream HTTP errors are test failures and must be reported
separately from the regular suite, including when an upstream blocks access
before its artifact can be checked.
