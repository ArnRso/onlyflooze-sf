<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Enum\CategorySource;
use App\Repository\CategorizationRuleRepository;
use App\Repository\TransactionRepository;
use App\Service\Import\TransactionImporter;
use App\Service\UserManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ReviewFlowTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private TransactionRepository $transactionRepository;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->transactionRepository = $container->get(TransactionRepository::class);

        $user = $container->get(UserManager::class)->createOrUpdateUser('test@example.com', 'password');
        $this->client->loginUser($user);
    }

    public function testFullReviewFlow(): void
    {
        $courses = new Category('Courses');
        $this->entityManager->persist($courses);
        $this->entityManager->flush();

        static::getContainer()->get(TransactionImporter::class)->import(implode("\n", [
            '"Date operation";"Date valeur";"Libelle";"Debit";"Credit"',
            '"23/07/2026";"23/07/2026";"CARTE 22/07 CARREFOUR HYPER LORMONT";"83,43";""',
        ]), 'export.csv');

        // La transaction apparaît dans la file de révision.
        $crawler = $this->client->request('GET', '/review');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('CARREFOUR', (string) $this->client->getResponse()->getContent());

        // Validation via le formulaire de la ligne.
        $form = $crawler->filter('tr[id^="review-row-"] form')->form([
            'category' => (string) $courses->getId(),
            'nature' => 'expense',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/review');

        // La transaction est catégorisée, marquée manuelle.
        $transaction = $this->transactionRepository->findAll()[0];
        self::assertSame((string) $courses->getId(), (string) $transaction->getCategory()?->getId());
        self::assertSame(CategorySource::Manual, $transaction->getCategorySource());
        self::assertSame(0, $this->transactionRepository->countToReview());

        // Une règle a été apprise.
        $rules = static::getContainer()->get(CategorizationRuleRepository::class)->findAll();
        self::assertCount(1, $rules);
        self::assertContains('CARREFOUR', $rules[0]->getTokens());
    }

    public function testReapplyButton(): void
    {
        $courses = new Category('Courses');
        $this->entityManager->persist($courses);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/review');
        $form = $crawler->filter('form[action$="/review/reapply"]')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/review');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }
}
