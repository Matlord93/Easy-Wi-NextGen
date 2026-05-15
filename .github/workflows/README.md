# CI runner selection

Workflows default to GitHub-hosted public runners via `ubuntu-latest`.

If the public runner pool is unavailable, set the repository variable `CI_RUNNER_LABELS`
to the JSON array for the private runner labels, for example:

```json
["self-hosted", "k8s-autoscaling-runner", "linux"]
```

Leaving the variable unset (or empty) keeps CI on the public runner pool.
