<?php

use FriendsOfTwig\Twigcs;

$finder = Twigcs\Finder\TemplateFinder::create()
    ->in(__DIR__ . '/templates');

return Twigcs\Config\Config::create()
    ->setFinder($finder)
    ->setRuleset(Twigcs\Ruleset\Official::class);
