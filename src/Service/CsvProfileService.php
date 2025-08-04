<?php

namespace App\Service;

use App\Entity\CsvImportProfile;
use App\Entity\User;
use App\Repository\CsvImportProfileRepository;
use Doctrine\ORM\EntityManagerInterface;

readonly class CsvProfileService
{
    public function __construct(
        private EntityManagerInterface     $entityManager,
        private CsvImportProfileRepository $csvImportProfileRepository
    )
    {
    }

    public function updateProfile(CsvImportProfile $profile): CsvImportProfile
    {
        $this->entityManager->flush();

        return $profile;
    }

    public function deleteProfile(CsvImportProfile $profile): void
    {
        $this->entityManager->remove($profile);
        $this->entityManager->flush();
    }

    /**
     * @return CsvImportProfile[]
     */
    public function getUserProfiles(User $user): array
    {
        return $this->csvImportProfileRepository->findByUser($user);
    }

    public function getUserProfileById(User $user, string $id): ?CsvImportProfile
    {
        return $this->csvImportProfileRepository->findByUserAndId($user, $id);
    }

    public function createDefaultProfiles(User $user): array
    {
        $profiles = [];

        // Profil Crédit Agricole
        $caProfile = new CsvImportProfile();
        $caProfile->setName('Crédit Agricole');
        $caProfile->setDescription('Format standard Crédit Agricole avec colonnes date, libellé, débit, crédit');
        $caProfile->setDelimiter(';');
        $caProfile->setEncoding('ISO-8859-1');
        $caProfile->setDateFormat('d/m/Y');
        $caProfile->setAmountType('credit_debit');
        $caProfile->setHasHeader(true);
        $caProfile->setColumnMapping([
            'date' => 0,
            'label' => 1,
            'debit' => 2,
            'credit' => 3
        ]);

        $profiles[] = $this->createProfile($caProfile, $user);

        // Profil BNP Paribas
        $bnpProfile = new CsvImportProfile();
        $bnpProfile->setName('BNP Paribas');
        $bnpProfile->setDescription('Format standard BNP Paribas avec montant unique');
        $bnpProfile->setDelimiter(',');
        $bnpProfile->setEncoding('UTF-8');
        $bnpProfile->setDateFormat('d/m/Y');
        $bnpProfile->setAmountType('single');
        $bnpProfile->setHasHeader(true);
        $bnpProfile->setColumnMapping([
            'date' => 0,
            'label' => 1,
            'amount' => 2
        ]);

        $profiles[] = $this->createProfile($bnpProfile, $user);

        // Profil générique
        $genericProfile = new CsvImportProfile();
        $genericProfile->setName('Format générique');
        $genericProfile->setDescription('Format CSV générique : Date, Libellé, Montant');
        $genericProfile->setDelimiter(',');
        $genericProfile->setEncoding('UTF-8');
        $genericProfile->setDateFormat('Y-m-d');
        $genericProfile->setAmountType('single');
        $genericProfile->setHasHeader(true);
        $genericProfile->setColumnMapping([
            'date' => 0,
            'label' => 1,
            'amount' => 2
        ]);

        $profiles[] = $this->createProfile($genericProfile, $user);

        return $profiles;
    }

    public function createProfile(CsvImportProfile $profile, User $user): CsvImportProfile
    {
        $profile->setUser($user);

        $this->entityManager->persist($profile);
        $this->entityManager->flush();

        return $profile;
    }

    public function getAvailableDateFormats(): array
    {
        return [
            'd/m/Y' => 'dd/mm/yyyy (31/12/2025)',
            'Y-m-d' => 'yyyy-mm-dd (2025-12-31)',
            'd-m-Y' => 'dd-mm-yyyy (31-12-2025)',
            'm/d/Y' => 'mm/dd/yyyy (12/31/2025)',
            'd/m/y' => 'dd/mm/yy (31/12/25)',
            'Y/m/d' => 'yyyy/mm/dd (2025/12/31)'
        ];
    }

    public function getAvailableDelimiters(): array
    {
        return [
            ',' => 'Virgule (,)',
            ';' => 'Point-virgule (;)',
            "\t" => 'Tabulation',
            '|' => 'Pipe (|)'
        ];
    }

    public function getAvailableEncodings(): array
    {
        return [
            'UTF-8' => 'UTF-8',
            'ISO-8859-1' => 'ISO-8859-1 (Latin-1)',
            'Windows-1252' => 'Windows-1252'
        ];
    }

    public function getAmountTypes(): array
    {
        return [
            'single' => 'Montant unique (positif/négatif)',
            'credit_debit' => 'Colonnes séparées Crédit/Débit'
        ];
    }

    public function validateProfileMapping(array $mapping, string $amountType): array
    {
        $errors = [];

        // Required fields
        if (!isset($mapping['date']) || $mapping['date'] === '') {
            $errors[] = 'La colonne date est obligatoire';
        }

        if (!isset($mapping['label']) || $mapping['label'] === '') {
            $errors[] = 'La colonne libellé est obligatoire';
        }

        // Amount validation based on type
        if ($amountType === 'single') {
            if (!isset($mapping['amount']) || $mapping['amount'] === '') {
                $errors[] = 'La colonne montant est obligatoire pour le type "montant unique"';
            }
        } else {
            $hasCredit = isset($mapping['credit']) && $mapping['credit'] !== '';
            $hasDebit = isset($mapping['debit']) && $mapping['debit'] !== '';

            if (!$hasCredit && !$hasDebit) {
                $errors[] = 'Au moins une colonne crédit ou débit est obligatoire';
            }
        }

        // Check for duplicate column indices
        $indices = array_filter($mapping, static fn($value) => $value !== '');
        if (count($indices) !== count(array_unique($indices))) {
            $errors[] = 'Les colonnes ne peuvent pas utiliser le même index';
        }

        return $errors;
    }
}
