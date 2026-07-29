<?php

namespace App\Enum;

enum TransactionType: string
{
    case Carte = 'carte';
    case Prelevement = 'prelevement';
    case Virement = 'virement';
    case EcheancePret = 'echeance_pret';
    case Frais = 'frais';
    case AnnulationCarte = 'annulation_carte';
    case InteretsDebiteurs = 'interets_debiteurs';
    case RetraitDab = 'retrait_dab';
    case Autre = 'autre';

    public function isRecurrenceCandidate(): bool
    {
        return match ($this) {
            self::Prelevement, self::EcheancePret, self::Frais => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Carte => 'Carte',
            self::Prelevement => 'Prélèvement',
            self::Virement => 'Virement',
            self::EcheancePret => 'Échéance de prêt',
            self::Frais => 'Frais bancaires',
            self::AnnulationCarte => 'Annulation carte',
            self::InteretsDebiteurs => 'Intérêts débiteurs',
            self::RetraitDab => 'Retrait DAB',
            self::Autre => 'Autre',
        };
    }
}
