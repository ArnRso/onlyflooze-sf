<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Enum\CategorySource;
use App\Repository\CategorizationRuleRepository;
use App\Repository\TransactionRepository;
use App\Service\Import\TransactionImporter;
use App\Service\Review\TransactionCategorizer;
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

    public function testCategorizeStreamOnlyTouchesTheRowsThatChanged(): void
    {
        $courses = new Category('Courses');
        $this->entityManager->persist($courses);
        $this->entityManager->flush();

        static::getContainer()->get(TransactionImporter::class)->import(implode("\n", [
            '"Date operation";"Date valeur";"Libelle";"Debit";"Credit"',
            '"21/04/2026";"21/04/2026";"CARTE 19/04 CHRONO 1010 BOULIAC";"74,02";""',
            '"18/04/2026";"18/04/2026";"CARTE 17/04 CHRONO 1010 BOULIAC";"49,66";""',
            '"19/04/2026";"19/04/2026";"CARTE 19/04 TBM-VENTE LIGNE BORDEAUX";"1,90";""',
        ]), 'export.csv');

        $byLabel = [];
        foreach ($this->transactionRepository->findAllToReview() as $transaction) {
            $byLabel[$transaction->getAmountCents()] = $transaction;
        }

        $crawler = $this->client->request('GET', '/review');
        $form = $crawler->filter('tr#review-row-'.$byLabel[-7402]->getId().' form')->form([
            'category' => (string) $courses->getId(),
            'nature' => 'expense',
        ]);
        $this->client->request($form->getMethod(), $form->getUri(), $form->getPhpValues(), [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('action="remove" target="review-row-'.$byLabel[-7402]->getId().'"', $content);
        self::assertStringContainsString('action="replace" target="review-row-'.$byLabel[-4966]->getId().'"', $content, 'Le jumeau reçoit sa suggestion');
        self::assertStringContainsString('Suggestion : Courses', $content);
        self::assertStringNotContainsString('review-row-'.$byLabel[-190]->getId(), $content, 'TBM n\'a pas bougé : pas re-rendu');
        self::assertStringNotContainsString('target="review-list"', $content, 'La liste entière n\'est pas re-rendue');
    }

    public function testLookupShowsWhatWasAlreadyDecidedForALabel(): void
    {
        $courses = new Category('Courses');
        $this->entityManager->persist($courses);
        $this->entityManager->flush();

        static::getContainer()->get(TransactionImporter::class)->import(implode("\n", [
            '"Date operation";"Date valeur";"Libelle";"Debit";"Credit"',
            '"09/04/2026";"09/04/2026";"CARTE 08/04 CHRONO 1010 BOULIAC";"50,00";""',
            '"21/04/2026";"21/04/2026";"CARTE 19/04 CHRONO 006 LE HAILLAN";"54,11";""',
        ]), 'export.csv');

        // Une CHRONO triée, l'autre encore dans la file.
        foreach ($this->transactionRepository->findAllToReview() as $transaction) {
            if (str_contains($transaction->getLabel(), '1010')) {
                static::getContainer()->get(TransactionCategorizer::class)->categorize($transaction, $courses);
            }
        }

        $this->client->request('GET', '/review/lookup?q=chrono');
        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-frame id="classified-lookup">', $content);
        self::assertStringContainsString('CHRONO 1010 BOULIAC', $content, 'La transaction triée est listée');
        self::assertStringNotContainsString('CHRONO 006', $content, 'Celle encore à trier ne l\'est pas');
        self::assertStringContainsString('Courses <strong>× 1</strong>', $content);
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
