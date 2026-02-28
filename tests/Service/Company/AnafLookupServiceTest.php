<?php

namespace App\Tests\Service\Company;

use App\Service\Company\AnafLookupException;
use App\Service\Company\AnafLookupService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class AnafLookupServiceTest extends TestCase
{
    private function createService(MockResponse $response): AnafLookupService
    {
        $httpClient = new MockHttpClient($response);

        return new AnafLookupService($httpClient, new NullLogger());
    }

    public function testLookupReturnsCompanyData(): void
    {
        $anafResponse = [
            'found' => [
                [
                    'date_generale' => [
                        'cui' => 14399840,
                        'denumire' => 'ORANGE ROMANIA SA',
                        'adresa' => 'Jud. BUCUREȘTI, Mun. București, Sect. 1, B-dul Lascăr Catargiu, Nr.47-53',
                        'nrRegCom' => 'J40/10178/2002',
                        'telefon' => '0214014000',
                        'fax' => '0214014001',
                        'codPostal' => '010665',
                        'cod_CAEN' => '6120',
                        'stare_inregistrare' => 'ACTIV',
                    ],
                    'inregistrare_scop_Tva' => [
                        'scpTVA' => true,
                    ],
                    'stare_inactiv' => [
                        'statusInactivi' => false,
                    ],
                    'adresa_sediu_social' => [
                        'sdenumire_Strada' => 'B-dul Lascăr Catargiu',
                        'snumar_Strada' => '47-53',
                        'sdenumire_Localitate' => 'București',
                        'sdenumire_Judet' => 'BUCUREȘTI',
                        'scod_Postal' => '010665',
                        'sdetalii_Adresa' => 'Etaj 3',
                    ],
                ],
            ],
            'notFound' => [],
        ];

        $service = $this->createService(new MockResponse(json_encode($anafResponse)));

        $result = $service->lookupByCui('14399840');

        $this->assertSame('ORANGE ROMANIA SA', $result['companyName']);
        $this->assertSame('14399840', $result['cui']);
        $this->assertSame('J40/10178/2002', $result['nrRegCom']);
        $this->assertSame('B-dul Lascăr Catargiu', $result['street']);
        $this->assertSame('47-53', $result['streetNumber']);
        $this->assertSame('București', $result['city']);
        $this->assertSame('BUCUREȘTI', $result['county']);
        $this->assertSame('010665', $result['postalCode']);
        $this->assertSame('Etaj 3', $result['addressDetails']);
        $this->assertSame('0214014000', $result['phone']);
        $this->assertSame('0214014001', $result['fax']);
        $this->assertSame('6120', $result['codCAEN']);
        $this->assertSame('ACTIV', $result['stare']);
        $this->assertTrue($result['platitorTVA']);
    }

    public function testLookupStripsNonNumericCharsFromCui(): void
    {
        $anafResponse = [
            'found' => [
                [
                    'date_generale' => [
                        'cui' => 14399840,
                        'denumire' => 'TEST SRL',
                    ],
                    'adresa_sediu_social' => [],
                    'inregistrare_scop_Tva' => [],
                    'stare_inactiv' => [],
                ],
            ],
            'notFound' => [],
        ];

        $service = $this->createService(new MockResponse(json_encode($anafResponse)));

        $result = $service->lookupByCui('RO14399840');

        $this->assertSame('TEST SRL', $result['companyName']);
    }

    public function testLookupThrowsOnInvalidCui(): void
    {
        $service = $this->createService(new MockResponse(''));

        $this->expectException(AnafLookupException::class);
        $this->expectExceptionMessageMatches('/CUI invalid/');

        $service->lookupByCui('1');
    }

    public function testLookupThrowsOnEmptyCui(): void
    {
        $service = $this->createService(new MockResponse(''));

        $this->expectException(AnafLookupException::class);

        $service->lookupByCui('');
    }

    public function testLookupThrowsWhenNotFound(): void
    {
        $anafResponse = [
            'found' => [],
            'notFound' => [['cui' => 99999999]],
        ];

        $service = $this->createService(new MockResponse(json_encode($anafResponse)));

        $this->expectException(AnafLookupException::class);
        $this->expectExceptionMessageMatches('/nu a fost găsit/');

        $service->lookupByCui('99999999');
    }

    public function testLookupThrowsOnApiError(): void
    {
        $service = $this->createService(new MockResponse('', ['http_code' => 500]));

        $this->expectException(AnafLookupException::class);
        $this->expectExceptionMessageMatches('/nu este disponibil/');

        $service->lookupByCui('14399840');
    }

    public function testLookupDetectsInactiveCompany(): void
    {
        $anafResponse = [
            'found' => [
                [
                    'date_generale' => [
                        'cui' => 12345678,
                        'denumire' => 'FIRMA INACTIVA SRL',
                    ],
                    'adresa_sediu_social' => [],
                    'inregistrare_scop_Tva' => [],
                    'stare_inactiv' => [
                        'statusInactivi' => true,
                        'dataInactivare' => '2024-01-01',
                    ],
                ],
            ],
            'notFound' => [],
        ];

        $service = $this->createService(new MockResponse(json_encode($anafResponse)));

        $result = $service->lookupByCui('12345678');

        $this->assertSame('INACTIV', $result['stare']);
    }

    public function testLookupDetectsRadiatedCompany(): void
    {
        $anafResponse = [
            'found' => [
                [
                    'date_generale' => [
                        'cui' => 12345678,
                        'denumire' => 'FIRMA RADIATA SRL',
                    ],
                    'adresa_sediu_social' => [],
                    'inregistrare_scop_Tva' => [],
                    'stare_inactiv' => [
                        'statusInactivi' => true,
                        'dataRadiere' => '2024-06-01',
                    ],
                ],
            ],
            'notFound' => [],
        ];

        $service = $this->createService(new MockResponse(json_encode($anafResponse)));

        $result = $service->lookupByCui('12345678');

        $this->assertSame('RADIAT', $result['stare']);
    }

    public function testLookupHandlesNullAddressFields(): void
    {
        $anafResponse = [
            'found' => [
                [
                    'date_generale' => [
                        'cui' => 12345678,
                        'denumire' => 'FIRMA SIMPLA SRL',
                    ],
                    'adresa_sediu_social' => [
                        'sdenumire_Strada' => '',
                        'snumar_Strada' => null,
                        'sdenumire_Localitate' => 'Cluj-Napoca',
                        'sdenumire_Judet' => 'CLUJ',
                    ],
                    'inregistrare_scop_Tva' => [
                        'scpTVA' => false,
                    ],
                    'stare_inactiv' => [],
                ],
            ],
            'notFound' => [],
        ];

        $service = $this->createService(new MockResponse(json_encode($anafResponse)));

        $result = $service->lookupByCui('12345678');

        $this->assertNull($result['street']);
        $this->assertNull($result['streetNumber']);
        $this->assertSame('Cluj-Napoca', $result['city']);
        $this->assertSame('CLUJ', $result['county']);
        $this->assertFalse($result['platitorTVA']);
    }
}
