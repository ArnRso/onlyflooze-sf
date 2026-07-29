<?php

namespace App\Tests\Controller;

use App\Service\UserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ScreensTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $user = static::getContainer()->get(UserManager::class)
            ->createOrUpdateUser('test@example.com', 'password');
        $this->client->loginUser($user);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function providePages(): iterable
    {
        yield 'dashboard' => ['/'];
        yield 'dashboard mois précis' => ['/?month=2026-06'];
        yield 'file de révision' => ['/review'];
        yield 'transactions' => ['/transactions'];
        yield 'import' => ['/import'];
        yield 'catégories' => ['/categories'];
        yield 'nouvelle catégorie' => ['/categories/new'];
        yield 'règles' => ['/rules'];
        yield 'récurrences' => ['/recurrences'];
        yield 'nouvelle récurrence' => ['/recurrences/new'];
        yield 'tags' => ['/tags'];
        yield 'nouveau tag' => ['/tags/new'];
    }

    #[DataProvider('providePages')]
    public function testPageLoads(string $url): void
    {
        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
    }

    public function testHealthIsPublic(): void
    {
        $client = $this->client;
        $client->request('GET', '/logout');
        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/logout');
        $this->client->request('GET', '/');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }
}
