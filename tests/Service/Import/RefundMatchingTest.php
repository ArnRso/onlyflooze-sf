<?php

namespace App\Tests\Service\Import;

use App\Entity\Category;
use App\Enum\CategorySource;
use App\Enum\TransactionNature;
use App\Repository\TransactionRepository;
use App\Service\Import\TransactionImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class RefundMatchingTest extends KernelTestCase
{
    private TransactionImporter $importer;
    private TransactionRepository $transactionRepository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->importer = $container->get(TransactionImporter::class);
        $this->transactionRepository = $container->get(TransactionRepository::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    public function testRefundIsPairedWithItsOriginPurchase(): void
    {
        // Cas réel : achat Apple 218 € le 07/03, annulé le 20/03. La
        // catégorie de l'achat est suggérée et la nature est
        // « remboursement » : la paire se neutralise dans le budget.
        $shopping = new Category('Shopping');
        $this->entityManager->persist($shopping);
        $this->entityManager->flush();

        $this->importer->import(implode("\n", [
            '"Date operation";"Date valeur";"Libelle";"Debit";"Credit"',
            '"07/03/2026";"07/03/2026";"CARTE 06/03 APPLE.COM/FR PARIS";"218,00";""',
        ]), 'export1.csv');

        $purchase = $this->transactionRepository->findAll()[0];
        $purchase->setCategory($shopping);
        $purchase->setCategorySource(CategorySource::Manual);
        $this->entityManager->flush();

        $this->importer->import(implode("\n", [
            '"Date operation";"Date valeur";"Libelle";"Debit";"Credit"',
            '"20/03/2026";"20/03/2026";"ANN CARTE APPLE.COM/FR PARIS";"";"218,00"',
        ]), 'export2.csv');

        $refund = $this->transactionRepository->findAllToReview()[0];

        self::assertSame(TransactionNature::Reimbursement, $refund->getNature(), 'Une annulation carte n\'est jamais un revenu');
        self::assertSame((string) $shopping->getId(), (string) $refund->getSuggestedCategory()?->getId(), 'La catégorie de l\'achat d\'origine est suggérée');
        self::assertNull($refund->getCategory(), 'Suggestion seulement, jamais appliquée');
    }

    public function testRefundWithoutMatchingOriginStaysUnsuggested(): void
    {
        $this->importer->import(implode("\n", [
            '"Date operation";"Date valeur";"Libelle";"Debit";"Credit"',
            '"20/03/2026";"20/03/2026";"ANN CARTE AMAZON PAYMENTS PAYLI2441535/";"";"44,44"',
        ]), 'export.csv');

        $refund = $this->transactionRepository->findAllToReview()[0];

        self::assertSame(TransactionNature::Reimbursement, $refund->getNature());
        self::assertNull($refund->getSuggestedCategory());
    }
}
