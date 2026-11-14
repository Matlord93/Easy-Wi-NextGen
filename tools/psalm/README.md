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
