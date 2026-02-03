<?php

return array_merge(
    [
        Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
        Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
        Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle::class => ['all' => true],
        Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true],
        Symfony\Bundle\MonologBundle\MonologBundle::class => ['all' => true],
        Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    ],
    class_exists(League\FlysystemBundle\FlysystemBundle::class)
        ? [League\FlysystemBundle\FlysystemBundle::class => ['all' => true]]
        : [],
);
