<?php

namespace App\Dto;

use App\Entity\CategorizationRule;
use App\Enum\RuleChangeKind;

final readonly class RuleChange
{
    /**
     * @param list<string> $before
     * @param list<string> $after
     */
    public function __construct(
        public CategorizationRule $rule,
        public RuleChangeKind $kind,
        public array $before,
        public array $after,
    ) {
    }
}
