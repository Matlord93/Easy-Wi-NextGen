# Teamspeak 6 (TS6) & Virtual Server Hosting (Skeleton)

This document captures the planned TS6 architecture, capability detection, and the placeholder job interfaces
that will be implemented in later EPICs.

## Scope

* TS6 core module placeholders in the Symfony application.
* Agent heartbeat capability reporting (`ts6_supported`).
* Virtual server hosting placeholders (TS reseller concept).

## Planned architecture (TS6)

* One **physical TS6 instance per node** (owned/managed by the platform).
* Multiple **virtual servers** attached to each physical TS6 instance.
* Core UI surfaces the module as **Planned / Experimental** while gated behind feature flags.

## Intended job interfaces (placeholder)

These job types are documented for the future agent implementation. They are **not** active yet.

### Physical TS6 instance jobs

* `ts6.instance.create`
* `ts6.instance.update`
* `ts6.instance.start`
* `ts6.instance.stop`
* `ts6.instance.restart`
* `ts6.instance.delete`
* `ts6.instance.backup`
* `ts6.instance.restore`

### Virtual server jobs

* `ts6.virtual.create`
* `ts6.virtual.update`
* `ts6.virtual.suspend`
* `ts6.virtual.resume`
* `ts6.virtual.delete`
* `ts6.virtual.snapshot`

## Manual test steps

1. Sign in as an admin user.
2. Navigate to **Admin → Modules** and enable:
   * **Teamspeak 6 (Experimental)**
   * **TS Virtual Servers (Experimental)** (optional, to show the virtual server card)
3. Navigate to **Admin → Services → TS6 (Experimental)**.
4. Verify the page shows:
   * **Planned/Experimental** badges.
   * A **Node capabilities** table with `ts6_supported` values.
   * The **Virtual server hosting** card appearing only when the feature flag is enabled.

