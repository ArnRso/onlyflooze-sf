<?php

namespace App\Dto;

use App\Entity\CategorizationRule;
use App\Enum\RuleChangeKind;

final readonly class RuleChange
{
    /**
     * @param list<string> $before       tokens avant
     * @param list<string> $after        tokens après (ceux de la nouvelle règle pour une séparation)
     * @param list<string> $fingerprints empreintes déplacées ou retirées
     */
    public function __construct(
        public CategorizationRule $rule,
        public RuleChangeKind $kind,
        public array $before,
        public array $after,
        public array $fingerprints = [],
    ) {
    }
}
