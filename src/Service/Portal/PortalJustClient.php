<?php

declare(strict_types=1);

namespace App\Service\Portal;

use Psr\Log\LoggerInterface;

class PortalJustClient
{
    // The official courts portal only serves this endpoint over HTTP (HTTPS is
    // not offered). Party names are redacted from logs; no PII is sent in query
    // strings, but responses are not transport-encrypted.
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
        return $this->search([
            'numarDosar' => $caseNumber,
            'obiectDosar' => '',
            'numeParte' => '',
            'institutie' => $institutionCode,
        ]);
    }

    /**
     * Search by party name within an institution, optionally bounded by a date
     * window. Used by auto-discovery before the ECRIS number is known: `numeParte`
     * does a partial match, `institutie` narrows to one court.
     *
     * @return array<int, array<string, mixed>> Same shape as {@see searchByCaseNumber()}
     *
     * @throws PortalJustException
     */
    public function searchByParty(
        string $partyName,
        string $institutionCode,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
    ): array {
        $params = [
            'numarDosar' => '',
            'obiectDosar' => '',
            'numeParte' => $partyName,
            'institutie' => $institutionCode,
        ];

        // dataStart/dataStop are xsd:dateTime on CautareDosare2; omit when absent.
        if ($from !== null) {
            $params['dataStart'] = $from->format('Y-m-d\TH:i:s');
        }
        if ($to !== null) {
            $params['dataStop'] = $to->format('Y-m-d\TH:i:s');
        }

        return $this->search($params);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws PortalJustException
     */
    private function search(array $params): array
    {
        try {
            $response = $this->getClient()->CautareDosare2($params);

            return $this->parseResponse($response);
        } catch (\SoapFault $e) {
            // Redact numeParte: it may carry a natural-person name (GDPR art. 5(1)(f)).
            $logParams = $params;
            if (isset($logParams['numeParte']) && $logParams['numeParte'] !== '') {
                $logParams['numeParte'] = '[redacted]';
            }

            $this->logger->error('Portal SOAP fault: {message}', [
                'message' => $e->getMessage(),
                'params' => $logParams,
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
