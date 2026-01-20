<?php

declare(strict_types=1);

$bundles = [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle::class => ['all' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true],
    Symfony\Bundle\MonologBundle\MonologBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
];

if (class_exists(Symfony\Bundle\MailerBundle\MailerBundle::class)) {
    $bundles[Symfony\Bundle\MailerBundle\MailerBundle::class] = ['all' => true];
}

return $bundles;
