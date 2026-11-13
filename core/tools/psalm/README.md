# Psalm analysis

The analysis tools have their own Composer lock file so the application lock file
and the scanner dependencies can be updated independently. CI installs this lock
instead of updating a global Psalm installation on every run.

From the repository root, with PHP 8.5 and the application extensions installed:

```sh
composer install --working-dir=core --no-scripts
composer install --working-dir=core/tools/psalm --no-scripts
APP_ENV=dev APP_DEBUG=1 php core/bin/console cache:warmup
cd core
php -d memory_limit=-1 tools/psalm/vendor/bin/psalm --config=psalm.xml --taint-analysis
```

The configuration checks all application source at error level 1, including unused
code checks. The Symfony plugin reads the generated development container so it
can recognize service creation, routes, event subscribers and other dynamic
framework entry points. Warm the container again after service or route changes.
Doctrine uses the generic contracts of the installed ORM version; the PHPUnit
plugin supplies test framework contracts. There is no baseline or global issue
suppression.

For a SARIF report, add `--report=../results.sarif`. The workflow retains the
scanner exit code while publishing that report, and then always enforces its
result. A missing or nonzero scanner status fails the job. `scripts/normalize-psalm-sarif.php` removes only empty optional
`helpUri` properties emitted by plugin rules; it preserves every finding.

Update tools deliberately with `composer update --working-dir=core/tools/psalm`,
review the lock-file changes, and run the full analysis again.
