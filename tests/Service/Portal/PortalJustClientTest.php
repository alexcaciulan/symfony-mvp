<?php

namespace App\Tests\Service\Portal;

use App\Service\Portal\PortalJustClient;
use App\Service\Portal\PortalJustException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class PortalJustClientTest extends TestCase
{
    private PortalJustClient $client;

    protected function setUp(): void
    {
        $this->client = new PortalJustClient(new NullLogger());
    }

    private function createSoapStub(mixed $returnValue): \SoapClient
    {
        return new class($returnValue) extends \SoapClient {
            public function __construct(private mixed $returnValue)
            {
                // Skip parent constructor — no WSDL needed for stub
            }

            public function __doRequest(string $request, string $location, string $action, int $version, bool $oneWay = false): ?string
            {
                return '';
            }

            public function __soapCall(string $name, array $args, ?array $options = null, $inputHeaders = null, &$outputHeaders = null): mixed
            {
                return $this->returnValue;
            }

            public function __call(string $name, array $args): mixed
            {
                return $this->returnValue;
            }
        };
    }

    private function createSoapFaultStub(\SoapFault $fault): \SoapClient
    {
        return new class($fault) extends \SoapClient {
            public function __construct(private \SoapFault $fault)
            {
            }

            public function __doRequest(string $request, string $location, string $action, int $version, bool $oneWay = false): ?string
            {
                return '';
            }

            public function __soapCall(string $name, array $args, ?array $options = null, $inputHeaders = null, &$outputHeaders = null): mixed
            {
                throw $this->fault;
            }

            public function __call(string $name, array $args): mixed
            {
                throw $this->fault;
            }
        };
    }

    public function testSearchReturnsEmptyArrayWhenNoResults(): void
    {
        $stub = $this->createSoapStub((object) [
            'CautareDosare2Result' => null,
        ]);

        $this->client->setClient($stub);
        $result = $this->client->searchByCaseNumber('123/2026', 'JudecatoriaCLUJNAPOCA');

        $this->assertSame([], $result);
    }

    public function testSearchReturnsParsedSingleDosar(): void
    {
        $stub = $this->createSoapStub((object) [
            'CautareDosare2Result' => (object) [
                'Dosar' => (object) [
                    'numar' => '123/211/2026',
                    'institutie' => 'JudecatoriaCLUJNAPOCA',
                    'departament' => 'Civil',
                    'categorieCazNume' => 'Cerere cu valoare redusă',
                    'stadiuProcesualNume' => 'Fond',
                    'obiect' => 'Pretenții',
                    'dataModificare' => '2026-02-27',
                    'parti' => (object) [
                        'DosarParte' => [
                            (object) ['nume' => 'Ion Popescu', 'calitateParte' => 'Reclamant'],
                            (object) ['nume' => 'SC Test SRL', 'calitateParte' => 'Pârât'],
                        ],
                    ],
                    'sedinte' => (object) [
                        'DosarSedinta' => (object) [
                            'data' => '15.03.2026',
                            'complet' => 'C5',
                            'ora' => '09:00',
                            'solutie' => null,
                            'solutieSumar' => null,
                            'dataPronuntare' => null,
                            'documentSedinta' => null,
                            'dataDocument' => null,
                            'numarDocument' => null,
                        ],
                    ],
                    'caiAtac' => null,
                ],
            ],
        ]);

        $this->client->setClient($stub);
        $result = $this->client->searchByCaseNumber('123/211/2026', 'JudecatoriaCLUJNAPOCA');

        $this->assertCount(1, $result);

        $dosar = $result[0];
        $this->assertSame('123/211/2026', $dosar['numar']);
        $this->assertSame('JudecatoriaCLUJNAPOCA', $dosar['institutie']);
        $this->assertSame('Fond', $dosar['stadiuProcesual']);

        $this->assertCount(2, $dosar['parti']);
        $this->assertSame('Ion Popescu', $dosar['parti'][0]['nume']);
        $this->assertSame('Reclamant', $dosar['parti'][0]['calitateParte']);

        $this->assertCount(1, $dosar['sedinte']);
        $this->assertSame('15.03.2026', $dosar['sedinte'][0]['data']);
        $this->assertSame('C5', $dosar['sedinte'][0]['complet']);

        $this->assertSame([], $dosar['caiAtac']);
    }

    public function testSearchReturnsMultipleDosare(): void
    {
        $stub = $this->createSoapStub((object) [
            'CautareDosare2Result' => (object) [
                'Dosar' => [
                    (object) [
                        'numar' => '100/211/2026',
                        'institutie' => 'Test',
                        'departament' => null,
                        'categorieCazNume' => null,
                        'stadiuProcesualNume' => null,
                        'obiect' => null,
                        'dataModificare' => null,
                        'parti' => null,
                        'sedinte' => null,
                        'caiAtac' => null,
                    ],
                    (object) [
                        'numar' => '101/211/2026',
                        'institutie' => 'Test',
                        'departament' => null,
                        'categorieCazNume' => null,
                        'stadiuProcesualNume' => null,
                        'obiect' => null,
                        'dataModificare' => null,
                        'parti' => null,
                        'sedinte' => null,
                        'caiAtac' => null,
                    ],
                ],
            ],
        ]);

        $this->client->setClient($stub);
        $result = $this->client->searchByCaseNumber('100/211/2026', 'Test');

        $this->assertCount(2, $result);
        $this->assertSame('100/211/2026', $result[0]['numar']);
        $this->assertSame('101/211/2026', $result[1]['numar']);
    }

    public function testSearchThrowsPortalJustExceptionOnSoapFault(): void
    {
        $stub = $this->createSoapFaultStub(
            new \SoapFault('Server', 'Service unavailable'),
        );

        $this->client->setClient($stub);

        $this->expectException(PortalJustException::class);
        $this->expectExceptionMessageMatches('/SOAP fault/');

        $this->client->searchByCaseNumber('123/2026', 'Test');
    }

    public function testSearchHandlesEmptyDosarResult(): void
    {
        $stub = $this->createSoapStub((object) [
            'CautareDosare2Result' => (object) [
                'Dosar' => null,
            ],
        ]);

        $this->client->setClient($stub);
        $result = $this->client->searchByCaseNumber('999/2026', 'Test');

        $this->assertSame([], $result);
    }

    public function testSearchParsesSedintaWithSolutie(): void
    {
        $stub = $this->createSoapStub((object) [
            'CautareDosare2Result' => (object) [
                'Dosar' => (object) [
                    'numar' => '50/2026',
                    'institutie' => 'Test',
                    'departament' => null,
                    'categorieCazNume' => null,
                    'stadiuProcesualNume' => null,
                    'obiect' => null,
                    'dataModificare' => null,
                    'parti' => null,
                    'sedinte' => (object) [
                        'DosarSedinta' => (object) [
                            'data' => '10.02.2026',
                            'complet' => 'C1',
                            'ora' => '10:00',
                            'solutie' => 'Admite cererea. Obligă pârâtul la plata sumei.',
                            'solutieSumar' => 'Admite cererea',
                            'dataPronuntare' => '10.02.2026',
                            'documentSedinta' => 'hotarare',
                            'dataDocument' => '15.02.2026',
                            'numarDocument' => '123',
                        ],
                    ],
                    'caiAtac' => null,
                ],
            ],
        ]);

        $this->client->setClient($stub);
        $result = $this->client->searchByCaseNumber('50/2026', 'Test');

        $sedinta = $result[0]['sedinte'][0];
        $this->assertSame('Admite cererea. Obligă pârâtul la plata sumei.', $sedinta['solutie']);
        $this->assertSame('Admite cererea', $sedinta['solutieSumar']);
        $this->assertSame('10.02.2026', $sedinta['dataPronuntare']);
    }

    public function testSearchParsesCaleAtac(): void
    {
        $stub = $this->createSoapStub((object) [
            'CautareDosare2Result' => (object) [
                'Dosar' => (object) [
                    'numar' => '60/2026',
                    'institutie' => 'Test',
                    'departament' => null,
                    'categorieCazNume' => null,
                    'stadiuProcesualNume' => null,
                    'obiect' => null,
                    'dataModificare' => null,
                    'parti' => null,
                    'sedinte' => null,
                    'caiAtac' => (object) [
                        'DosarCaleAtac' => (object) [
                            'dataDeclarare' => '20.02.2026',
                            'parteDeclaratoare' => 'SC Test SRL',
                            'tipCaleAtac' => 'Apel',
                        ],
                    ],
                ],
            ],
        ]);

        $this->client->setClient($stub);
        $result = $this->client->searchByCaseNumber('60/2026', 'Test');

        $this->assertCount(1, $result[0]['caiAtac']);
        $this->assertSame('20.02.2026', $result[0]['caiAtac'][0]['dataDeclarare']);
        $this->assertSame('SC Test SRL', $result[0]['caiAtac'][0]['parteDeclaratoare']);
        $this->assertSame('Apel', $result[0]['caiAtac'][0]['tipCaleAtac']);
    }
}
