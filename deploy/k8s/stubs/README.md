# Kubernetes stub layout

This directory reserves the future Kubernetes rollout structure.

Suggested next steps:
- `base/` for Deployments/Services (core, agent, db, queue)
- `overlays/dev` and `overlays/stage` for env-specific patches
- Secret integration via External Secrets / Sealed Secrets
