<?php

namespace App\Tests\Controller;

use App\Service\UserManager;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class RuleConsolidationTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $user = static::getContainer()->get(UserManager::class)->createOrUpdateUser('test@example.com', 'password');
        $this->client->loginUser($user);
    }

    public function testRulesPageShowsPrecisionAndGenericTokens(): void
    {
        $this->client->request('GET', '/rules');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Précision des suggestions', $content);
        self::assertStringContainsString('Tokens jugés génériques', $content);
        self::assertStringContainsString('Consolider les règles', $content);
    }

    public function testConsolidateButton(): void
    {
        $crawler = $this->client->request('GET', '/rules');
        $form = $crawler->filter('form[action$="/rules/consolidate"]')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/rules');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Consolidation :', (string) $this->client->getResponse()->getContent());
    }
}
