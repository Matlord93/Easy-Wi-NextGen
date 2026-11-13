# Managed Minecraft installations

The Linux agent installs Vanilla, Paper, Spigot, Bedrock and BungeeCord from the
panel's version catalog. Versions and builds are selected in the creation form
and customer settings. Stable releases are shown by default; previews can be
included explicitly. Selecting a catalog entry pins the artifact used by that
installation job, including the upstream checksum when available.

## Rollout

1. Build and deploy the updated Linux agent first. Wait for its heartbeat to
   advertise `minecraft_managed_install`. New managed installations on older
   agents fail with `MINECRAFT_AGENT_UPDATE_REQUIRED` rather than running a
   partially supported installation. Existing legacy runtime jobs remain usable.
2. Deploy the updated panel, clear/warm the Symfony production cache, and run the normal Doctrine migrations, including
   `Version20261022090000` (nullable catalog metadata) and
   `Version20261022140000` (game-specific port pools).
3. Open **Administration → Minecraft** and choose a version. Missing Minecraft
   templates and the bundled Vanilla, Paper and Bedrock catalog are prepared on
   first use. No JSON upload, manually maintained version entry or initial CLI
   import is required. Release builds include these seed files under
   `core/resources/minecraft/`; source checkouts use the canonical `minecraft/`
   directory automatically.
4. Keep the existing `app:run-schedules` scheduler running. Official providers are
   refreshed in a background worker, normally hourly. Opening a version selector
   also dispatches a refresh when due. Spigot and BungeeCord are discovered from
   their official providers. Open selectors receive new versions automatically;
   stable releases are selected by default, with previews available explicitly.
   Inspect `core/var/minecraft-catalog/sync.log` and application logs if a provider
   remains unavailable. Failed sources preserve their existing catalog. Failed
   initial checks retry after one minute; a successful catalog is checked hourly.

5. Create a test instance on each target node and verify console output,
   connectivity, allocated ports and a restart before enabling it for customers.

The new managed workflow is Linux-only. Windows retains its existing installation
path. Nothing automatically replaces existing worlds or upgrades running server
versions when the catalog refreshes. Server upgrades continue through the panel's
explicit update/version-lock and automation controls.

## Installation fails with START_EXECUTABLE_OUTSIDE_GAME_DIR

If the log shows successful artifact preparation followed by
`START_EXECUTABLE_OUTSIDE_GAME_DIR path=/var/cache/easywi-java/.../bin/java`,
the server download has completed. The failure is in start-executable validation:
older agents incorrectly require the managed Java runtime to be inside the game
directory. The runtime intentionally lives in the protected Java cache.

Deploy and restart the agent containing the managed-Java path validation fix,
then retry the installation. The validator permits only the selected Java major's
exact provisioned binary (including an administrator-configured
`EASYWI_JAVA_CACHE_DIR`), and still checks that it exists and is executable.
Other external executables remain rejected. Do not move the JDK into the customer
directory or disable the path check globally.

## Installation only prints the managed-install message

`steam_app_id_missing` and `uses_steamcmd=false` are expected for Minecraft.
The former `echo "Minecraft artifact installation managed by agent"` command
does not download anything: the Linux agent must prepare the selected artifact
before invoking the shell command. A full install log should contain
`Minecraft: preparing the required Java runtime` and download/cache progress.
If those are absent and the server files are missing, check the assigned job for
`minecraft_managed=true`, `minecraft_channel`, and `minecraft_download_url`, and
check that the running agent binary matches the deployed release. Build/deploy
and restart the current Linux agent, wait for a fresh capability heartbeat, then
queue a new installation. A stale capability heartbeat alone does not prove the
currently running binary supports managed installation.

The panel now checks for a nonempty, readable `server.jar` (or executable
`bedrock_server`) in the installation working directory. Missing artifacts fail
with `MINECRAFT_ARTIFACT_MISSING` instead of succeeding after an echo. This check
does not replace the agent's download, checksum, Java or selected-version checks.

## Catalog sources and limits

- Vanilla: Mojang's complete version manifest, server download metadata and Java
  requirements. Client-only versions cannot be offered as dedicated servers.
- Paper: Paper's Fill API, available versions and builds with SHA-256 checksums.
- Spigot: official version metadata and BuildTools. The agent compiles Spigot;
  no redistribution or manual upload of a precompiled Spigot JAR is required.
- BungeeCord: successful builds retained by the official Jenkins service. The
  proxy build identifies the artifact; it is not a Minecraft server version.
- Bedrock: official Linux stable and preview downloads. The official endpoint
  exposes current downloads, not a complete historical archive. Previously
  imported catalog rows are retained, but upstream may remove old artifacts.

Automatic refreshes preserve existing custom entries and deliberately disabled entries.
The catalog pages expose version selection and a read-only list; legacy manual
form URLs redirect without modifying records.
A provider failure retains its previous catalog and reports the error separately.
Availability depends on the artifacts still being provided upstream; the panel
cannot manufacture releases that an upstream no longer hosts.

## Java and build dependencies

Java installations are downloaded automatically from Eclipse Adoptium using
upstream SHA-256 checksums. The agent verifies the actual Java major version,
reuses a protected cache and selects the matching binary per instance. Current
coverage includes Java 8, 16, 17, 21 and 25; an unavailable platform/version causes
a clear installation failure. Bedrock does not use Java.

The agent validates downloaded JARs and class requirements before replacing the
installed JAR. Spigot's BuildTools JDK can differ from the server runtime JDK;
compilation uses a separate build user and private Maven workspace. Install Git,
`runuser`, standard Linux build prerequisites and the native dependencies required
by Bedrock on the node. The existing node/user provisioning prerequisites still
apply. Behind an outbound proxy, configure Java trust/proxy settings and Maven's
standard proxy settings on the node; verification must remain enabled.

Default root-owned caches/state:

- `/var/cache/easywi-java`: downloaded JDKs (`EASYWI_JAVA_CACHE_DIR` override).
- `/var/cache/easywi-minecraft-artifacts`: verified Spigot artifacts and private
  build workspace (`EASYWI_MINECRAFT_ARTIFACT_CACHE_DIR` override).
- Build account: `easywi-minecraft-build` (`EASYWI_MINECRAFT_BUILD_USER` override
  for an administrator-provisioned dedicated account).
- `/var/lib/easywi-minecraft`: protected per-instance startup manifests.

Allow HTTPS access to Mojang's metadata/artifact hosts, Paper Fill/artifact hosts,
`hub.spigotmc.org`, `www.spigotmc.org`, the Spigot repositories, Maven dependency
repositories, Adoptium and its artifact redirects (including GitHub), and the
Minecraft Bedrock download services. BuildTools and first server startup can fetch
additional official libraries. Bedrock online authentication also requires access
to Microsoft's Minecraft/Xbox services. Do not disable online authentication as a
production connectivity workaround.

## Ports and customer settings

The creation form requests the game port and optional Query/RCON reservations.
Bedrock reserves two UDP game ports plus TCP signaling on the game port. It
preserves the distribution’s transport: older RakNet uses the IPv4/IPv6 UDP
ports, while current NetherNet uses TCP signaling and the second reserved UDP
port for gameplay. Extra LAN listeners are disabled; BungeeCord requires a backend
`host:port` and supports an optional UDP Query port. RCON is offered for Java game
servers and receives a generated password. Reservations are persisted, checked
for conflicts and serialized per node during creation.

The agent maintains `server.properties` network keys, booked player limits and
BungeeCord's managed listener. It uses an explicit local bind address when supplied
and otherwise the wildcard bind, so a public NAT address is not mistaken for a
local interface. Query/RCON are enabled only when reserved. Customer changes to
other properties, existing RCON passwords, worlds and proxy backends are retained.
BungeeCord uses `config.yml`, not `server.properties` or the Minecraft EULA file.

The customer settings editor loads the actual configuration or pending edits.
Protected network keys cannot be changed through that editor; use panel port and
slot controls. Before every managed systemd start, including host reboot, a
preflight validates Java/JAR compatibility and reapplies authoritative network
settings. Malformed configuration fails explicitly instead of silently replacing
customer content. Additional Bungee listeners need their own port allocation and
are not supported by the single managed listener workflow.

NetherNet status uses its HTTP signaling endpoint. This endpoint does not expose
player counts or game versions, so those details remain unavailable rather than
being reported as zero. Historical RakNet releases retain their UDP status query.

## Validation performed in the cloud workspace

The integration suite exercises creation port reservations, selection/build
payloads, customer settings, Java requirements, configuration protection, checksums,
archive traversal and cache integrity. Live opt-in Go tests download official
artifacts and start/query real servers. Browser validation exercises all five
creation choices and preview filtering. Run live tests only in an isolated test
workspace with network access; they are skipped by default.

Privileged systemd deployment and real customer-node firewall/NAT behavior require
a staging-node check. The cloud workspace cannot validate those production details.

## Separate port ranges per game

Under **Administration → Server ports**, assign a pool to Minecraft, other game
servers, or a specific game/template key. Existing pools remain general pools
until their assignment is changed; the assignment can also be edited on each pool.
Minecraft includes Vanilla, Paper, Spigot, Bedrock and BungeeCord. Specific-game
pools take priority over the family pool, followed by general pools only when no
dedicated pool exists. An exhausted or disabled dedicated pool never falls back
to another game's/general range. Existing instances keep their assigned ports.

The pool API accepts `game_scope` on creation and through
`PATCH /api/admin/port-pools/{id}` (also `/api/v1/admin/port-pools/{id}`). Values are
`minecraft`, `other`, `game:<template-game-key>`, or `all` for a general pool.

Before assigning an automatic or manual block, the panel checks reservations
across all pools and individual allocations on the same node, then requests an
authenticated bind probe from that node. Both TCP and UDP are checked, on IPv4
and IPv6 where available. Occupied candidates are skipped; an occupied manual
range is rejected. An offline node, failed authentication, or incomplete/malformed
probe response prevents allocation. Pools must be enabled. Creating a pool only
configures a range; the live check happens when its ports are reserved/assigned.

Node-level database locks serialize allocation across overlapping pools and
instance creation. Failed creation rolls back its new block reservations. A bind
probe establishes availability at the time of checking; it cannot prevent an
unrelated external process from binding the port later. The existing service
startup checks still apply. Update the agent before deploying the panel changes.
