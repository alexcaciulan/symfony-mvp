<?php

declare(strict_types=1);

namespace App\Service\Company;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AnafLookupService
{
    private const API_URL = 'https://webservicesp.anaf.ro/api/PlatitorTvaRest/v9/tva';

    /** Romanian postal codes have six digits; Bucharest ones start with a zero. */
    private const POSTAL_CODE_LENGTH = 6;

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
     *     flatAddress: ?string,
     *     fiscalAddress: array{street: ?string, streetNumber: ?string, city: ?string, county: ?string, postalCode: ?string, addressDetails: ?string},
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
            throw new AnafLookupException('exception.anaf.cui_invalid');
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
            throw new AnafLookupException('exception.anaf.unavailable', 0, $e);
        }

        if (empty($data['found'])) {
            throw new AnafLookupException('exception.anaf.not_found');
        }

        return $this->parseResponse($data['found'][0]);
    }

    private function parseResponse(array $item): array
    {
        $general = $item['date_generale'] ?? [];
        $address = $item['adresa_sediu_social'] ?? [];
        $fiscal = $item['adresa_domiciliu_fiscal'] ?? [];
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
            'postalCode' => $this->canonicalPostalCode(
                $address['scod_Postal'] ?? null,
                $general['codPostal'] ?? null,
            ),
            'addressDetails' => $this->clean($address['sdetalii_Adresa'] ?? null),
            // The only field carrying block / staircase / floor / apartment. It
            // mirrors the FISCAL DOMICILE, not the registered office, so callers
            // must check the two structured blocks agree before trusting it.
            'flatAddress' => $this->clean($general['adresa'] ?? null),
            'fiscalAddress' => [
                'street' => $this->clean($fiscal['ddenumire_Strada'] ?? null),
                'streetNumber' => $this->clean($fiscal['dnumar_Strada'] ?? null),
                'city' => $this->clean($fiscal['ddenumire_Localitate'] ?? null),
                'county' => $this->clean($fiscal['ddenumire_Judet'] ?? null),
                'postalCode' => $this->canonicalPostalCode($fiscal['dcod_Postal'] ?? null, null),
                'addressDetails' => $this->clean($fiscal['ddetalii_Adresa'] ?? null),
            ],
            'phone' => $this->clean($general['telefon'] ?? null),
            'fax' => $this->clean($general['fax'] ?? null),
            'codCAEN' => $this->clean($general['cod_CAEN'] ?? null),
            'stare' => $stare,
            'platitorTVA' => !empty($tva['scpTVA']),
        ];
    }

    /**
     * ANAF stores postal codes with the leading zero already stripped, so every
     * Bucharest company comes back five digits short of a valid code ("61202"
     * instead of "061202"). Provincial codes start with 1..9 and arrive intact.
     *
     * Anything that is not five or six digits is discarded rather than padded:
     * an invented code on the envelope is worse than no code at all.
     */
    private function canonicalPostalCode(mixed $primary, mixed $fallback): ?string
    {
        foreach ([$primary, $fallback] as $candidate) {
            $digits = preg_replace('/\D/', '', (string) (is_scalar($candidate) ? $candidate : ''));

            if ($digits === '' || $digits === null) {
                continue;
            }

            if (strlen($digits) === self::POSTAL_CODE_LENGTH) {
                return $digits;
            }

            if (strlen($digits) === self::POSTAL_CODE_LENGTH - 1) {
                return '0' . $digits;
            }

            $this->logger->warning('ANAF returned an unusable postal code: {value}', ['value' => $digits]);
        }

        return null;
    }

    private function clean(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $collapsed = preg_replace('/\s+/u', ' ', trim((string) $value));

        return $collapsed === '' || $collapsed === null ? null : $collapsed;
    }
}
