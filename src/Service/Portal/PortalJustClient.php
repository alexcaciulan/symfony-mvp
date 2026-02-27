<?php

namespace App\Service\Portal;

use Psr\Log\LoggerInterface;

class PortalJustClient
{
    private const WSDL_URL = 'http://portalquery.just.ro/query.asmx?WSDL';

    private ?\SoapClient $client = null;

    public function __construct(
        private LoggerInterface $logger,
    ) {}

    /**
     * Search for a case by number and institution code.
     *
     * @return array<int, array{
     *     numar: ?string,
     *     institutie: ?string,
     *     departament: ?string,
     *     categorieCaz: ?string,
     *     stadiuProcesual: ?string,
     *     obiect: ?string,
     *     dataModificare: ?string,
     *     parti: array<int, array{nume: ?string, calitateParte: ?string}>,
     *     sedinte: array<int, array{
     *         data: ?string, complet: ?string, ora: ?string,
     *         solutie: ?string, solutieSumar: ?string, dataPronuntare: ?string,
     *         documentSedinta: ?string, dataDocument: ?string, numarDocument: ?string
     *     }>,
     *     caiAtac: array<int, array{
     *         dataDeclarare: ?string, parteDeclaratoare: ?string, tipCaleAtac: ?string
     *     }>
     * }>
     *
     * @throws PortalJustException
     */
    public function searchByCaseNumber(string $caseNumber, string $institutionCode): array
    {
        try {
            $client = $this->getClient();

            $response = $client->CautareDosare2([
                'numarDosar' => $caseNumber,
                'obiectDosar' => '',
                'numeParte' => '',
                'institutie' => $institutionCode,
            ]);

            return $this->parseResponse($response);
        } catch (\SoapFault $e) {
            $this->logger->error('Portal SOAP fault: {message}', [
                'message' => $e->getMessage(),
                'caseNumber' => $caseNumber,
                'institutionCode' => $institutionCode,
            ]);

            throw new PortalJustException('SOAP fault: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @internal Visible for testing — allows injecting a mock SoapClient.
     */
    public function setClient(\SoapClient $client): void
    {
        $this->client = $client;
    }

    private function getClient(): \SoapClient
    {
        if ($this->client === null) {
            $this->client = new \SoapClient(self::WSDL_URL, [
                'trace' => true,
                'exceptions' => true,
                'connection_timeout' => 10,
                'default_socket_timeout' => 30,
                'cache_wsdl' => \WSDL_CACHE_BOTH,
            ]);
        }

        return $this->client;
    }

    private function parseResponse(mixed $response): array
    {
        $result = $response->CautareDosare2Result ?? null;
        if ($result === null) {
            return [];
        }

        $dosare = $result->Dosar ?? null;
        if ($dosare === null) {
            return [];
        }

        // Normalize single result to array
        if (!is_array($dosare)) {
            $dosare = [$dosare];
        }

        $parsed = [];
        foreach ($dosare as $dosar) {
            $parsed[] = $this->parseDosar($dosar);
        }

        return $parsed;
    }

    private function parseDosar(object $dosar): array
    {
        return [
            'numar' => $this->prop($dosar, 'numar'),
            'institutie' => $this->prop($dosar, 'institutie'),
            'departament' => $this->prop($dosar, 'departament'),
            'categorieCaz' => $this->prop($dosar, 'categorieCazNume'),
            'stadiuProcesual' => $this->prop($dosar, 'stadiuProcesualNume'),
            'obiect' => $this->prop($dosar, 'obiect'),
            'dataModificare' => $this->prop($dosar, 'dataModificare'),
            'parti' => $this->parseParti($dosar),
            'sedinte' => $this->parseSedinte($dosar),
            'caiAtac' => $this->parseCaiAtac($dosar),
        ];
    }

    private function parseParti(object $dosar): array
    {
        $partiContainer = $dosar->parti ?? null;
        if ($partiContainer === null) {
            return [];
        }

        $items = $partiContainer->DosarParte ?? [];
        if (!is_array($items)) {
            $items = [$items];
        }

        $result = [];
        foreach ($items as $parte) {
            $result[] = [
                'nume' => $this->prop($parte, 'nume'),
                'calitateParte' => $this->prop($parte, 'calitateParte'),
            ];
        }

        return $result;
    }

    private function parseSedinte(object $dosar): array
    {
        $sedinteContainer = $dosar->sedinte ?? null;
        if ($sedinteContainer === null) {
            return [];
        }

        $items = $sedinteContainer->DosarSedinta ?? [];
        if (!is_array($items)) {
            $items = [$items];
        }

        $result = [];
        foreach ($items as $sedinta) {
            $result[] = [
                'data' => $this->prop($sedinta, 'data'),
                'complet' => $this->prop($sedinta, 'complet'),
                'ora' => $this->prop($sedinta, 'ora'),
                'solutie' => $this->prop($sedinta, 'solutie'),
                'solutieSumar' => $this->prop($sedinta, 'solutieSumar'),
                'dataPronuntare' => $this->prop($sedinta, 'dataPronuntare'),
                'documentSedinta' => $this->prop($sedinta, 'documentSedinta'),
                'dataDocument' => $this->prop($sedinta, 'dataDocument'),
                'numarDocument' => $this->prop($sedinta, 'numarDocument'),
            ];
        }

        return $result;
    }

    private function parseCaiAtac(object $dosar): array
    {
        $caiAtacContainer = $dosar->caiAtac ?? null;
        if ($caiAtacContainer === null) {
            return [];
        }

        $items = $caiAtacContainer->DosarCaleAtac ?? [];
        if (!is_array($items)) {
            $items = [$items];
        }

        $result = [];
        foreach ($items as $caleAtac) {
            $result[] = [
                'dataDeclarare' => $this->prop($caleAtac, 'dataDeclarare'),
                'parteDeclaratoare' => $this->prop($caleAtac, 'parteDeclaratoare'),
                'tipCaleAtac' => $this->prop($caleAtac, 'tipCaleAtac'),
            ];
        }

        return $result;
    }

    private function prop(object $obj, string $property): ?string
    {
        $value = $obj->{$property} ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
