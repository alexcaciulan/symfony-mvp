<?php

namespace App\Service\Company;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AnafLookupService
{
    private const API_URL = 'https://webservicesp.anaf.ro/api/PlatitorTvaRest/v9/tva';

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {}

    /**
     * Lookup company data by CUI from ANAF API.
     *
     * @return array{
     *     companyName: string,
     *     cui: string,
     *     nrRegCom: ?string,
     *     street: ?string,
     *     streetNumber: ?string,
     *     city: ?string,
     *     county: ?string,
     *     postalCode: ?string,
     *     addressDetails: ?string,
     *     phone: ?string,
     *     fax: ?string,
     *     codCAEN: ?string,
     *     stare: string,
     *     platitorTVA: bool,
     * }
     *
     * @throws AnafLookupException
     */
    public function lookupByCui(string $cui): array
    {
        $cui = preg_replace('/[^0-9]/', '', $cui);

        if ($cui === '' || strlen($cui) < 2 || strlen($cui) > 10) {
            throw new AnafLookupException('CUI invalid: trebuie să conțină între 2 și 10 cifre.');
        }

        try {
            $response = $this->httpClient->request('POST', self::API_URL, [
                'json' => [
                    ['cui' => (int) $cui, 'data' => date('Y-m-d')],
                ],
                'timeout' => 10,
            ]);

            // ANAF returns HTTP 404 with valid JSON when CUI not found
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->error('ANAF API error for CUI {cui}: {error}', [
                'cui' => $cui,
                'error' => $e->getMessage(),
            ]);
            throw new AnafLookupException('Serviciul ANAF nu este disponibil. Încercați mai târziu.', 0, $e);
        }

        if (empty($data['found'])) {
            throw new AnafLookupException('CUI-ul nu a fost găsit în baza de date ANAF.');
        }

        return $this->parseResponse($data['found'][0]);
    }

    private function parseResponse(array $item): array
    {
        $general = $item['date_generale'] ?? [];
        $address = $item['adresa_sediu_social'] ?? [];
        $tva = $item['inregistrare_scop_Tva'] ?? [];
        $stareInactiv = $item['stare_inactiv'] ?? [];

        $stare = 'ACTIV';
        if (!empty($stareInactiv['statusInactivi'])) {
            $stare = !empty($stareInactiv['dataRadiere']) ? 'RADIAT' : 'INACTIV';
        }

        return [
            'companyName' => $this->clean($general['denumire'] ?? ''),
            'cui' => (string) ($general['cui'] ?? ''),
            'nrRegCom' => $this->clean($general['nrRegCom'] ?? null),
            'street' => $this->clean($address['sdenumire_Strada'] ?? null),
            'streetNumber' => $this->clean($address['snumar_Strada'] ?? null),
            'city' => $this->clean($address['sdenumire_Localitate'] ?? null),
            'county' => $this->clean($address['sdenumire_Judet'] ?? null),
            'postalCode' => $this->clean($address['scod_Postal'] ?? null),
            'addressDetails' => $this->clean($address['sdetalii_Adresa'] ?? null),
            'phone' => $this->clean($general['telefon'] ?? null),
            'fax' => $this->clean($general['fax'] ?? null),
            'codCAEN' => $this->clean($general['cod_CAEN'] ?? null),
            'stare' => $stare,
            'platitorTVA' => !empty($tva['scpTVA']),
        ];
    }

    private function clean(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
