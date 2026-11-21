<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

// Modules using the default translation domain share the portal catalogue.
return Yaml::parseFile(__DIR__.'/portal.en.yaml');
