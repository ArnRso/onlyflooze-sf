<?php

namespace App\Tests\Controller;

use App\Repository\TransactionRepository;
use App\Service\UserManager;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ImportUploadTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $user = static::getContainer()->get(UserManager::class)
            ->createOrUpdateUser('test@example.com', 'password');
        $this->client->loginUser($user);
    }

    public function testCsvUploadThroughForm(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, implode("\n", [
            '"Date operation";"Date valeur";"Libelle";"Debit";"Credit"',
            '"23/07/2026";"23/07/2026";"CARTE 22/07 CARREFOUR HYPER LORMONT";"83,43";""',
        ]));

        $crawler = $this->client->request('GET', '/import');
        $form = $crawler->filter('form[name="csv_upload"]')->form();
        $form['csv_upload[file]']->upload(new UploadedFile($path, 'export.csv', 'text/csv', test: true));

        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('1</strong> nouvelle', (string) $this->client->getResponse()->getContent());

        self::assertCount(1, static::getContainer()->get(TransactionRepository::class)->findAll());

        unlink($path);
    }
}
