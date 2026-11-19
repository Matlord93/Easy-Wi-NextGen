# Psalm

Psalm is installed separately from the application's dependencies. The lock file
records the tool and plugin versions used locally and in the security workflow.

From the repository root:

```sh
composer install --working-dir=tools/psalm --no-scripts
composer install --working-dir=core --no-scripts
cd core
APP_ENV=dev APP_DEBUG=1 php bin/console cache:warmup
composer psalm
composer psalm -- --taint-analysis
```

The configuration targets PHP 8.5 and analyzes all of `core/src` at level 1.
The Symfony plugin reads the generated development container to resolve services
and Twig extensions. Warm the container again after changing service definitions.
Doctrine's own generic declarations supply the repository types.

Psalm writes its cache below the ignored `core/var/psalm` directory. No error
baseline is configured. The security workflow uploads its SARIF report even
when Psalm reports findings and then fails the job on a nonzero result.

Run the Doctrine mapping regression test from the repository root:

```sh
PYTHONDONTWRITEBYTECODE=1 python3 tools/psalm/tests/test_doctrine_mapping.py -v
```

This is also the security workflow's entry point. It runs the locked Psalm
scanner four times to check cold single-worker and parallel scans, followed by
cached parallel scans. The test verifies that Doctrine entities are recognized
while an ordinary unused class still produces a finding. Invoking the file
directly avoids relying on test discovery patterns; a missing file or failed
test stops the workflow before analysis.
