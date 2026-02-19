<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class HomeControllerTest extends WebTestCase
{
    public function testHomepageReturns200(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
    }

    public function testHomepageContainsExpectedContent(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $this->assertSelectorTextContains('h1', 'Recuperare creanțe');
    }

    public function testSwitchLocaleRedirectsBack(): void
    {
        $client = static::createClient();
        $client->request('GET', '/switch-locale/en', [], [], ['HTTP_REFERER' => '/']);

        $this->assertResponseRedirects('/');
    }

    public function testSwitchLocaleWithoutRefererRedirectsToHome(): void
    {
        $client = static::createClient();
        $client->request('GET', '/switch-locale/en');

        $this->assertResponseRedirects('/');
    }
}
