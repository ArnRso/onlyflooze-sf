<?php

use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withPhpSets()
    // Un service readonly ne peut plus être rendu lazy (le proxy ne peut pas étendre une classe readonly).
    // Sur les entités, la promotion déplace le mapping Doctrine dans le constructeur et lui applique
    // les types du @param (non-empty-string…), qui ne valent pas pour la colonne.
    ->withSkip([
        ReadOnlyClassRector::class,
        ClassPropertyAssignToConstructorPromotionRector::class => [__DIR__.'/src/Entity'],
    ])
    ->withComposerBased(twig: true, doctrine: true, phpunit: true, symfony: true)
    ->withImportNames(importShortClasses: false, removeUnusedImports: true)
;
