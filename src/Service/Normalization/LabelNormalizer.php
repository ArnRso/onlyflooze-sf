<?php

namespace App\Service\Normalization;

use App\Dto\NormalizedLabel;
use App\Enum\TransactionType;

/**
 * Dérive d'un libellé bancaire brut : le type d'opération (préfixe), les
 * tokens du marchand et, pour les achats carte, la date d'achat embarquée.
 *
 * Les tokens sont nettoyés (dates, références type PAYLI, montants embarqués)
 * et matchés ensuite sur mot entier uniquement — jamais en sous-chaîne.
 */
class LabelNormalizer
{
    public function normalize(string $label, ?\DateTimeImmutable $operationDate = null): NormalizedLabel
    {
        $label = trim((string) preg_replace('/\s+/', ' ', $label));

        if (preg_match('/^CARTE (\d{2}\/\d{2}) (.*)$/i', $label, $matches) === 1) {
            return new NormalizedLabel(
                TransactionType::Carte,
                $this->tokenize($matches[2]),
                $this->resolvePurchaseDate($matches[1], $operationDate),
            );
        }

        if (preg_match('/^ANN CARTE (.*)$/i', $label, $matches) === 1) {
            return new NormalizedLabel(TransactionType::AnnulationCarte, $this->tokenize($matches[1]));
        }

        if (preg_match('/^ECH PRET (.*)$/i', $label, $matches) === 1) {
            return new NormalizedLabel(TransactionType::EcheancePret, $this->tokenize($matches[1]));
        }

        if (preg_match('/^PRLV (.*)$/i', $label, $matches) === 1) {
            return new NormalizedLabel(TransactionType::Prelevement, $this->tokenize($matches[1]));
        }

        if (preg_match('/^VIR (?:INST )?(?:WERO )?(?:VERS |DE )?(.*)$/i', $label, $matches) === 1) {
            return new NormalizedLabel(TransactionType::Virement, $this->tokenize($matches[1]));
        }

        if (preg_match('/^INT DEB ?(.*)$/i', $label, $matches) === 1) {
            return new NormalizedLabel(TransactionType::InteretsDebiteurs, $this->tokenize($matches[1]));
        }

        if (preg_match('/^RET DAB ?(.*)$/i', $label, $matches) === 1) {
            return new NormalizedLabel(TransactionType::RetraitDab, $this->tokenize($matches[1]));
        }

        if (preg_match('/^F (.*)$/i', $label, $matches) === 1) {
            return new NormalizedLabel(TransactionType::Frais, $this->tokenize($matches[1]));
        }

        return new NormalizedLabel(TransactionType::Autre, $this->tokenize($label));
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $merchant): array
    {
        $merchant = mb_strtoupper($merchant, 'UTF-8');

        // Références de paiement (PAYLI2441535/), montants embarqués (21,60 EUR)
        // et dates (23/07, 01/26, 03/02/24) ne discriminent rien : on les retire.
        $merchant = (string) preg_replace('/\bPAYLI\d+\/?/', ' ', $merchant);
        $merchant = (string) preg_replace('/\b\d{1,6},\d{2} ?EUR\b/', ' ', $merchant);
        $merchant = (string) preg_replace('~\b\d{2}/\d{2}(?:/\d{2,4})?\b~', ' ', $merchant);
        $merchant = str_replace('*', ' ', $merchant);

        // Le tiret est un séparateur comme l'espace : "SFR-SOCIETE FRANCAISE"
        // et "SFR" doivent converger vers le même token discriminant.
        $tokens = [];
        foreach (preg_split('/[ -]+/', $merchant) ?: [] as $token) {
            $token = trim($token, " \t.,/()");
            if (mb_strlen($token) >= 2) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    private function resolvePurchaseDate(string $dayMonth, ?\DateTimeImmutable $operationDate): ?\DateTimeImmutable
    {
        if ($operationDate === null) {
            return null;
        }

        [$day, $month] = explode('/', $dayMonth);

        if (!checkdate((int) $month, (int) $day, (int) $operationDate->format('Y'))) {
            return null;
        }

        $purchaseDate = $operationDate->setDate((int) $operationDate->format('Y'), (int) $month, (int) $day);

        // La date d'achat précède toujours la date d'opération : un achat de
        // décembre comptabilisé en janvier appartient à l'année précédente.
        if ($purchaseDate > $operationDate) {
            $purchaseDate = $purchaseDate->modify('-1 year');
        }

        return $purchaseDate;
    }
}
