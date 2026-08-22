<?php

namespace App\Tests\Service\Review;

use App\Entity\CategorizationRule;
use App\Entity\Category;
use App\Entity\Transaction;
use App\Enum\Direction;
use App\Enum\SuggestionOutcome;
use App\Service\Normalization\LabelNormalizer;
use App\Service\Review\SuggestionPrecisionProvider;
use App\Service\Review\TransactionCategorizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class TransactionCategorizerTest extends KernelTestCase
{
    private TransactionCategorizer $categorizer;
    private SuggestionPrecisionProvider $precisionProvider;
    private EntityManagerInterface $entityManager;
    private LabelNormalizer $normalizer;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->categorizer = $container->get(TransactionCategorizer::class);
        $this->precisionProvider = $container->get(SuggestionPrecisionProvider::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->normalizer = $container->get(LabelNormalizer::class);
    }

    private function makeTransaction(string $label, int $amountCents = -1000): Transaction
    {
        $date = new \DateTimeImmutable('2026-07-20');
        $normalized = $this->normalizer->normalize($label, $date);

        $transaction = new Transaction($date, $date, $label, $amountCents, $normalized->type);
        $transaction->setTokens($normalized->tokens);
        $this->entityManager->persist($transaction);

        return $transaction;
    }

    private function makeSuggested(string $label, Category $suggested): Transaction
    {
        $rule = new CategorizationRule('R', $suggested, Direction::Debit);
        $rule->setTokens(['CHRONO']);
        $this->entityManager->persist($rule);

        $transaction = $this->makeTransaction($label);
        $transaction->setSuggestedCategory($suggested);
        $transaction->setMatchedRule($rule);
        $this->entityManager->flush();

        return $transaction;
    }

    public function testAcceptedSuggestionIsRecorded(): void
    {
        $courses = new Category('Courses');
        $this->entityManager->persist($courses);
        $transaction = $this->makeSuggested('CARTE 09/04 CHRONO 1010 BOULIAC', $courses);

        $this->categorizer->categorize($transaction, $courses);

        self::assertSame(SuggestionOutcome::Accepted, $transaction->getSuggestionOutcome());
        self::assertSame($courses, $transaction->getSuggestionAtReview(), 'La suggestion survit à son effacement');
        self::assertNull($transaction->getSuggestedCategory());
        self::assertNotNull($transaction->getReviewedAt());
    }

    public function testCorrectedSuggestionIsRecorded(): void
    {
        $courses = new Category('Courses');
        $animaux = new Category('Animaux');
        $this->entityManager->persist($courses);
        $this->entityManager->persist($animaux);
        $transaction = $this->makeSuggested('CARTE 09/04 CHRONOVET LILLE', $courses);

        $this->categorizer->categorize($transaction, $animaux);

        self::assertSame(SuggestionOutcome::Corrected, $transaction->getSuggestionOutcome());
        self::assertSame($courses, $transaction->getSuggestionAtReview());
        self::assertSame($animaux, $transaction->getCategory());
    }

    public function testCategorizationWithoutSuggestionIsRecordedAsNone(): void
    {
        $courses = new Category('Courses');
        $this->entityManager->persist($courses);
        $transaction = $this->makeTransaction('CARTE 09/04 LIDL CENON');
        $this->entityManager->flush();

        $this->categorizer->categorize($transaction, $courses);

        self::assertSame(SuggestionOutcome::None, $transaction->getSuggestionOutcome());
        self::assertNull($transaction->getSuggestionAtReview());
    }

    public function testResetToReviewForgetsTheOutcome(): void
    {
        $courses = new Category('Courses');
        $this->entityManager->persist($courses);
        $transaction = $this->makeSuggested('CARTE 09/04 CHRONO 1010 BOULIAC', $courses);
        $this->categorizer->categorize($transaction, $courses);

        $this->categorizer->resetToReview($transaction);

        self::assertNull($transaction->getSuggestionOutcome());
        self::assertNull($transaction->getReviewedAt());
    }

    public function testPrecisionSummaryAggregatesOutcomes(): void
    {
        $courses = new Category('Courses');
        $animaux = new Category('Animaux');
        $this->entityManager->persist($courses);
        $this->entityManager->persist($animaux);

        $this->categorizer->categorize($this->makeSuggested('CARTE 09/04 CHRONO 1010 BOULIAC', $courses), $courses);
        $this->categorizer->categorize($this->makeSuggested('CARTE 10/04 CHRONO 1006 LE HAILLAN', $courses), $courses);
        $this->categorizer->categorize($this->makeSuggested('CARTE 11/04 CHRONOVET LILLE', $courses), $animaux);
        $blind = $this->makeTransaction('CARTE 12/04 LIDL CENON');
        $this->entityManager->flush();
        $this->categorizer->categorize($blind, $courses);

        $summary = $this->precisionProvider->summary();

        self::assertSame(4, $summary['overall']->total());
        self::assertSame(2, $summary['overall']->accepted);
        self::assertSame(1, $summary['overall']->corrected);
        self::assertSame(1, $summary['overall']->none);
        self::assertEqualsWithDelta(0.75, $summary['overall']->coverageRate(), 0.001);
        self::assertEqualsWithDelta(2 / 3, $summary['overall']->precisionRate(), 0.001);
        self::assertCount(1, $summary['months']);
        self::assertSame((new \DateTimeImmutable())->format('Y-m'), $summary['months'][0]->month);
    }
}
