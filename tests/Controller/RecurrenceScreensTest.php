<?php

namespace App\Tests\Controller;

use App\Entity\CategorizationRule;
use App\Entity\Category;
use App\Entity\Recurrence;
use App\Entity\Transaction;
use App\Enum\CategorySource;
use App\Enum\Direction;
use App\Enum\TransactionType;
use App\Service\Normalization\LabelNormalizer;
use App\Service\UserManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class RecurrenceScreensTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $user = $container->get(UserManager::class)->createOrUpdateUser('test@example.com', 'password');
        $this->client->loginUser($user);
    }

    private function makeCategorizedPrlv(string $label, int $amountCents, string $date, Category $category, CategorizationRule $rule): Transaction
    {
        $operationDate = new \DateTimeImmutable($date);
        $normalized = static::getContainer()->get(LabelNormalizer::class)->normalize($label, $operationDate);

        $transaction = new Transaction($operationDate, $operationDate, $label, $amountCents, $normalized->type);
        $transaction->setTokens($normalized->tokens);
        $transaction->setCategory($category);
        $transaction->setCategorySource(CategorySource::Manual);
        $transaction->setMatchedRule($rule);
        $this->entityManager->persist($transaction);

        return $transaction;
    }

    public function testSuggestionOccurrencesAreListedOnIndex(): void
    {
        $logement = new Category('Logement');
        $rule = new CategorizationRule('EDF', $logement, Direction::Debit);
        $rule->setTokens(['EDF']);
        $this->entityManager->persist($logement);
        $this->entityManager->persist($rule);
        $this->makeCategorizedPrlv('PRLV EDF clients particuliers', -8400, '2026-05-21', $logement, $rule);
        $this->makeCategorizedPrlv('PRLV EDF clients particuliers', -8800, '2026-06-21', $logement, $rule);
        $this->entityManager->flush();

        $this->client->request('GET', '/recurrences');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('2 occurrences', $content);
        self::assertStringContainsString('21/05/2026', $content, 'Les occurrences de la proposition sont consultables');
        self::assertStringContainsString('21/06/2026', $content);
    }

    public function testShowPageListsAttachedTransactions(): void
    {
        $logement = new Category('Logement');
        $this->entityManager->persist($logement);

        $recurrence = new Recurrence('EDF', Direction::Debit, 21, -8600);
        $recurrence->setCategory($logement);
        $this->entityManager->persist($recurrence);

        $operationDate = new \DateTimeImmutable('2026-07-21');
        $transaction = new Transaction($operationDate, $operationDate, 'PRLV EDF clients particuliers', -8409, TransactionType::Prelevement);
        $transaction->setRecurrence($recurrence);
        $this->entityManager->persist($transaction);
        $this->entityManager->flush();

        $this->client->request('GET', '/recurrences/'.$recurrence->getId());

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('PRLV EDF clients particuliers', $content);
        self::assertStringContainsString('21/07/2026', $content);
    }

    public function testBackfillAttachAndExcludeFromShowPage(): void
    {
        $logement = new Category('Logement');
        $rule = new CategorizationRule('EDF', $logement, Direction::Debit);
        $rule->setTokens(['EDF']);
        $this->entityManager->persist($logement);
        $this->entityManager->persist($rule);

        $recurrence = new Recurrence('EDF', Direction::Debit, 21, -8600);
        $recurrence->setRule($rule);
        $this->entityManager->persist($recurrence);

        foreach (['2026-05-21' => -8400, '2026-06-21' => -8800] as $date => $amount) {
            $operationDate = new \DateTimeImmutable($date);
            $normalized = static::getContainer()->get(LabelNormalizer::class)
                ->normalize('PRLV EDF clients particuliers', $operationDate);
            $transaction = new Transaction($operationDate, $operationDate, 'PRLV EDF clients particuliers', $amount, $normalized->type);
            $transaction->setTokens($normalized->tokens);
            $this->entityManager->persist($transaction);
        }
        $this->entityManager->flush();

        // Les deux lignes de l'historique sont proposées.
        $crawler = $this->client->request('GET', '/recurrences/'.$recurrence->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('form[action*="/attach/"]'));

        // ✓ Rattacher la première.
        $this->client->submit($crawler->filter('form[action*="/attach/"]')->form());
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertCount(1, $crawler->filter('form[action*="/attach/"]'), 'Une candidate de moins');

        // ✗ Ignorer la seconde : plus jamais proposée.
        $this->client->submit($crawler->filter('form[action*="/exclude/"]')->form());
        $crawler = $this->client->followRedirect();
        self::assertCount(0, $crawler->filter('form[action*="/attach/"]'));

        // Détacher la rattachée : elle disparaît sans être reproposée.
        $this->client->submit($crawler->filter('form[action*="/detach/"]')->form());
        $crawler = $this->client->followRedirect();
        self::assertCount(0, $crawler->filter('form[action*="/detach/"]'));
        self::assertCount(0, $crawler->filter('form[action*="/attach/"]'));
    }

    public function testBackfillAttachAnswersWithATurboStream(): void
    {
        // Depuis la page, Turbo soumet le formulaire en demandant un stream :
        // la ligne change de tableau sans rechargement (le Cmd+F de
        // l'utilisateur survit).
        $logement = new Category('Logement');
        $rule = new CategorizationRule('EDF', $logement, Direction::Debit);
        $rule->setTokens(['EDF']);
        $this->entityManager->persist($logement);
        $this->entityManager->persist($rule);

        $recurrence = new Recurrence('EDF', Direction::Debit, 21, -8600);
        $recurrence->setRule($rule);
        $this->entityManager->persist($recurrence);

        $operationDate = new \DateTimeImmutable('2026-06-21');
        $normalized = static::getContainer()->get(LabelNormalizer::class)->normalize('PRLV EDF clients particuliers', $operationDate);
        $transaction = new Transaction($operationDate, $operationDate, 'PRLV EDF clients particuliers', -8800, $normalized->type);
        $transaction->setTokens($normalized->tokens);
        $this->entityManager->persist($transaction);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/recurrences/'.$recurrence->getId());
        $form = $crawler->filter('form[action*="/attach/"]')->form();
        $this->client->request($form->getMethod(), $form->getUri(), $form->getPhpValues(), [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/vnd.turbo-stream.html', (string) $this->client->getResponse()->headers->get('Content-Type'));
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('action="remove" target="backfill-row-'.$transaction->getId().'"', $content);
        self::assertStringContainsString('action="prepend" target="attached-list"', $content);
        self::assertStringContainsString('id="attached-row-'.$transaction->getId().'"', $content);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        self::assertSame((string) $recurrence->getId(), (string) $entityManager->find(Transaction::class, $transaction->getId())?->getRecurrence()?->getId());
    }

    public function testShowDoesNotShadowNewRoute(): void
    {
        $this->client->request('GET', '/recurrences/new');

        self::assertResponseIsSuccessful();
    }
}
