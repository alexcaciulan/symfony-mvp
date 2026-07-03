<?php

declare(strict_types=1);

namespace App\Service\Billing\Netopia;

use App\DTO\Billing\Netopia\NetopiaChargeResult;
use App\DTO\Billing\Netopia\NetopiaIpnResult;
use App\DTO\Billing\Netopia\NetopiaStartResult;
use App\Entity\Invoice;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\UserType;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin custom client over the Netopia Payments API v2 (JSON + API token). Sole
 * point of contact with the Netopia wire format; the rest of the app speaks the
 * neutral DTOs above and {@see App\Service\Billing\PaymentGatewayInterface}.
 *
 * WHY custom over the official `netopia/payment2` SDK: that SDK pins
 * `firebase/php-jwt:^6.0`, whose entire 6.x line is covered by CVE-2025-45769
 * ("weak encryption", fixed only in 7.0.0 which `^6.0` excludes), so composer's
 * security audit blocks it. This client keeps the request/response mapping here
 * and verifies the IPN JWT with openssl directly (no runtime JWT dependency),
 * testable with MockHttpClient exactly like {@see App\Service\Llm\AnthropicApiClient}.
 *
 * WIRE FORMAT: the exact v2 request/response envelope and the IPN signing
 * scheme are not fully public. Everything version-specific is confined to the
 * private mapping helpers below and marked `@wire`; confirm each against a live
 * sandbox response before go-live. The public method contracts and all
 * downstream code do not depend on these details.
 *
 * GDPR: only the opaque recurring `token`, its expiry and the masked PAN are
 * ever read/stored. The full card number never reaches this application (PCI
 * scope stays with Netopia's hosted page).
 */
// Not final: test doubles in NetopiaPaymentGatewayTest / PaymentReconciliationServiceTest.
class NetopiaApiClient
{
    private const SANDBOX_BASE_URL = 'https://secure.sandbox.netopia-payments.com';
    private const LIVE_BASE_URL = 'https://secure.mobilpay.ro';

    /** @wire v2 endpoints. */
    private const PATH_START = '/payment/card/start';
    private const PATH_STATUS = '/operation/status';

    private const REQUEST_TIMEOUT = 30;

    /** Response body cap before JSON decode (defensive; real responses are <10 KB). */
    private const MAX_RESPONSE_BYTES = 1_048_576;

    /**
     * @wire Netopia payment status codes. 3/5 = money captured; 15 = awaiting 3DS;
     * everything else is either in-flight or a failure. Confirm the full set on
     * the sandbox spec.
     */
    private const STATUS_PAID = 3;
    private const STATUS_CONFIRMED = 5;

    /** Header Netopia v2 uses to carry the signed IPN token (fallback: raw body). */
    private const IPN_TOKEN_HEADER = 'Verification-token';

    /** Max accepted age of a signed IPN, and clock-skew tolerance for future iat. */
    private const IPN_MAX_AGE_SECONDS = 3600;
    private const IPN_FUTURE_SKEW_SECONDS = 300;

    /**
     * Netopia's platform public key (2048-bit) that signs the v2 IPN JWTs. Per
     * Netopia support this is a FIXED key, identical for Sandbox and Live, so it
     * ships as the default and needs no configuration. It is a public key (not a
     * secret). `NETOPIA_PUBLIC_KEY` can still override it if Netopia ever rotates.
     * NOTE: this is the IPN-signing key, NOT the per-POS 1024-bit certificate from
     * the dashboard (that one is for the legacy v1 encryption flow).
     */
    private const PLATFORM_PUBLIC_KEY = <<<'PEM'
        -----BEGIN PUBLIC KEY-----
        MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAy6pUDAFLVul4y499gz1P
        gGSvTSc82U3/ih3e5FDUs/F0Jvfzc4cew8TrBDrw7Y+AYZS37D2i+Xi5nYpzQpu7
        ryS4W+qvgAA1SEjiU1Sk2a4+A1HeH+vfZo0gDrIYTh2NSAQnDSDxk5T475ukSSwX
        L9tYwO6CpdAv3BtpMT5YhyS3ipgPEnGIQKXjh8GMgLSmRFbgoCTRWlCvu7XOg94N
        fS8l4it2qrEldU8VEdfPDfFLlxl3lUoLEmCncCjmF1wRVtk4cNu+WtWQ4mBgxpt0
        tX2aJkqp4PV3o5kI4bqHq/MS7HVJ7yxtj/p8kawlVYipGsQj3ypgltQ3bnYV/LRq
        8QIDAQAB
        -----END PUBLIC KEY-----
        PEM;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $netopiaPosSignature,
        private readonly string $netopiaApiKey,
        private readonly string $netopiaPublicKey,
        private readonly bool $netopiaIsLive,
        private readonly string $netopiaNotifyUrl,
        private readonly string $netopiaReturnUrl,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Starts an on-session hosted-page payment for an invoice. Returns where to
     * redirect the browser (Netopia's 3DS page). The paid/failed outcome arrives
     * later via IPN, never from this call.
     */
    public function startPayment(Invoice $invoice, User $user, string $orderId): NetopiaStartResult
    {
        $this->assertConfigured();

        $body = $this->buildStartRequest($invoice, $user, $orderId, token: null);
        $decoded = $this->request(self::PATH_START, $body);

        $redirectUrl = $this->digString($decoded, ['payment', 'paymentURL'])
            ?? $this->digString($decoded, ['customerAction', 'url'])
            ?? $this->digString($decoded, ['payment', 'redirectUrl']);

        if (null === $redirectUrl) {
            $this->logger->error('netopia.start.no_redirect_url', ['orderId' => $orderId]);
            throw new NetopiaException('Netopia start response has no redirect URL');
        }

        return new NetopiaStartResult(
            redirectUrl: $redirectUrl,
            ntpID: $this->digString($decoded, ['payment', 'ntpID']),
            status: $this->digInt($decoded, ['payment', 'status']),
        );
    }

    /**
     * Charges a previously saved recurring token off-session (no user, no 3DS
     * redirect) for a subscription renewal. `accepted` reflects whether the
     * gateway took the charge; the definitive result still comes via IPN.
     */
    public function chargeToken(Subscription $subscription, Invoice $invoice, string $orderId): NetopiaChargeResult
    {
        $this->assertConfigured();

        $token = $subscription->getRecurringToken();
        if (null === $token || '' === $token) {
            throw new NetopiaException('Subscription has no recurring token to charge');
        }

        $body = $this->buildStartRequest($invoice, $subscription->getUser(), $orderId, token: $token);
        $decoded = $this->request(self::PATH_START, $body);

        $status = $this->digInt($decoded, ['payment', 'status']);

        return new NetopiaChargeResult(
            // Off-session charges resolve synchronously (no 3DS): a paid status is
            // success, anything else is a decline the caller duns on.
            accepted: $this->isPaidStatus($status),
            status: $status,
            ntpID: $this->digString($decoded, ['payment', 'ntpID']),
            errorMessage: $this->digString($decoded, ['error', 'message']) ?? $this->digString($decoded, ['message']),
        );
    }

    /**
     * Fetches the current status code of a transaction by its Netopia id
     * (`ntpID`), for reconciling invoices whose IPN was lost. Returns the raw
     * Netopia status integer.
     */
    public function fetchStatus(string $ntpID): int
    {
        $this->assertConfigured();

        $decoded = $this->request(self::PATH_STATUS, [
            'posSignature' => $this->netopiaPosSignature,
            'ntpID' => $ntpID,
        ]);

        return $this->digInt($decoded, ['payment', 'status']) ?: $this->digInt($decoded, ['status']);
    }

    /**
     * Verifies and decodes an IPN request. Throws {@see NetopiaException} if the
     * signature does not validate against the configured POS public key (fail
     * closed). Returns the normalized, trusted result only on success.
     */
    public function verifyIpn(Request $request): NetopiaIpnResult
    {
        // Netopia v2 carries the signed JWT in the `Verification-token` header;
        // the notification DATA is the JSON request body. The JWT `sub` binds the
        // two: it is base64(sha512(rawBody)). (Confirmed against Netopia's IPN SDK.)
        $rawBody = $request->getContent();
        $token = $request->headers->get(self::IPN_TOKEN_HEADER);
        if (null === $token || '' === trim($token)) {
            throw new NetopiaException('IPN request carries no verification token');
        }

        $claims = $this->decodeIpnJwt(trim($token));

        // Issuer + audience (this POS) must match.
        if (($claims['iss'] ?? null) !== 'NETOPIA Payments') {
            throw new NetopiaException('IPN issuer mismatch');
        }
        $aud = $claims['aud'] ?? null;
        $audValues = is_array($aud) ? $aud : [$aud];
        if (!in_array($this->netopiaPosSignature, $audValues, true)) {
            throw new NetopiaException('IPN audience mismatch');
        }

        // Freshness: reject stale/replayed IPNs (a captured, still-signed token
        // replayed later could otherwise overwrite an already-rotated recurring
        // token). Fail-open only when `iat` is absent/unparseable (the signature
        // is still required); the window is generous for clock skew + worker lag.
        $this->assertFresh($claims['iat'] ?? null);

        // Integrity: the signed `sub` must equal base64(sha512(body)); otherwise the
        // (unsigned) body was tampered with in transit.
        $expectedHash = base64_encode((string) hash('sha512', $rawBody, true));
        if (!hash_equals($expectedHash, (string) ($claims['sub'] ?? ''))) {
            throw new NetopiaException('IPN payload hash mismatch (tainted body)');
        }

        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            throw new NetopiaException('IPN body is not valid JSON');
        }

        $orderId = $this->digString($data, ['order', 'orderID']) ?? $this->digString($data, ['orderID']);
        if (null === $orderId) {
            throw new NetopiaException('IPN payload has no orderID');
        }
        $status = $this->digInt($data, ['payment', 'status']);

        return new NetopiaIpnResult(
            orderId: $orderId,
            status: $status,
            ntpID: $this->digString($data, ['payment', 'ntpID']),
            paid: $this->isPaidStatus($status),
            token: $this->digString($data, ['payment', 'token']) ?? $this->digString($data, ['payment', 'binding', 'token']),
            tokenExpiresAt: $this->bindingExpiry($data),
            // Spec field is `panMasked` (camelCase) under payment.instrument; keep
            // snake_case fallbacks defensively.
            cardMask: $this->digString($data, ['payment', 'instrument', 'panMasked'])
                ?? $this->digString($data, ['payment', 'binding', 'panMasked'])
                ?? $this->digString($data, ['payment', 'instrument', 'pan_masked']),
        );
    }

    public function isPaidStatus(int $status): bool
    {
        return self::STATUS_PAID === $status || self::STATUS_CONFIRMED === $status;
    }

    // ---------- request building (@wire) ----------

    /**
     * @wire Builds the v2 start/recurrent request envelope. When `$token` is set,
     * this is an off-session recurring charge on a saved instrument (no card data,
     * no 3DS). Confirm field names/nesting against the sandbox spec.
     *
     * @return array<string, mixed>
     */
    private function buildStartRequest(Invoice $invoice, User $user, string $orderId, ?string $token): array
    {
        $order = [
            'ntpID' => '',
            'posSignature' => $this->netopiaPosSignature,
            'dateTime' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'description' => $invoice->getType()->value . ' #' . $invoice->getId(),
            'orderID' => $orderId,
            // Netopia v2 requires amount as a JSON number (float64), not a string.
            // Rounded to 2 decimals; invoice amounts are small 2-decimal values so
            // the float carries them exactly (no sub-ban drift in practice).
            'amount' => round((float) $invoice->getAmount(), 2),
            'currency' => 'RON',
            'billing' => $this->buildBilling($user),
        ];

        $payment = [
            'options' => ['installments' => 0, 'bonus' => 0],
        ];
        if (null !== $token) {
            // Off-session recurring charge on the saved token. Netopia requires
            // the MIT (Merchant-Initiated Transaction) SCA-exemption indicator on
            // `order`, plus the payer's billing data (already sent). The response
            // rotates the token, captured again by the IPN handler.
            $payment['instrument'] = ['token' => $token];
            $order['scaExemptionInd'] = 'MIT';
        }

        return [
            'config' => [
                'notifyUrl' => $this->netopiaNotifyUrl,
                'redirectUrl' => $this->netopiaReturnUrl,
                'language' => 'ro',
            ],
            'payment' => $payment,
            'order' => $order,
        ];
    }

    /**
     * @wire Netopia billing block. Uses the company identity for lawyers/companies
     * (PJ/AVOCAT), the natural-person name otherwise.
     *
     * @return array<string, mixed>
     */
    private function buildBilling(User $user): array
    {
        $isCompany = in_array($user->getType(), [UserType::PJ, UserType::AVOCAT], true);
        $addressParts = array_filter([
            $user->getStreet(),
            $user->getStreetNumber(),
            $user->getBlock() ? 'bl. ' . $user->getBlock() : null,
            $user->getApartment() ? 'ap. ' . $user->getApartment() : null,
        ]);

        return [
            'email' => (string) $user->getEmail(),
            'phone' => (string) $user->getPhone(),
            'firstName' => $isCompany ? (string) $user->getCompanyName() : (string) $user->getFirstName(),
            'lastName' => $isCompany ? '' : (string) $user->getLastName(),
            'city' => (string) $user->getCity(),
            'country' => 642, // ISO 3166-1 numeric for Romania (@wire: confirm code system)
            'state' => (string) $user->getCounty(),
            'postalCode' => (string) $user->getPostalCode(),
            'details' => implode(' ', $addressParts),
        ];
    }

    // ---------- transport ----------

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function request(string $path, array $body): array
    {
        try {
            $response = $this->httpClient->request('POST', $this->baseUrl() . $path, [
                'headers' => [
                    'Authorization' => $this->netopiaApiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $body,
                'timeout' => self::REQUEST_TIMEOUT,
            ]);
            $statusCode = $response->getStatusCode();
            $rawBody = $response->getContent(throw: false);
        } catch (TransportException $e) {
            $this->logger->error('netopia.transport_error', ['exceptionClass' => $e::class, 'code' => $e->getCode()]);
            throw new NetopiaException('Netopia transport error', retryable: true, previous: $e);
        } catch (HttpExceptionInterface $e) {
            $this->logger->error('netopia.http_exception', ['exceptionClass' => $e::class, 'code' => $e->getCode()]);
            throw new NetopiaException('Netopia HTTP exception', retryable: true, previous: $e);
        }

        if ($statusCode >= 400) {
            // Netopia error bodies are diagnostic (missing/invalid field), not
            // sensitive; log them truncated for ops triage. Same approach as
            // AnthropicApiClient.
            $this->logger->error('netopia.http_error', [
                'status' => $statusCode,
                'path' => $path,
                'body' => mb_substr($rawBody, 0, 800),
            ]);
            throw new NetopiaException(sprintf('Netopia API returned HTTP %d', $statusCode), retryable: $statusCode >= 500);
        }

        if (strlen($rawBody) > self::MAX_RESPONSE_BYTES) {
            throw new NetopiaException('Netopia response too large; refusing to decode');
        }

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            throw new NetopiaException('Netopia response was not valid JSON');
        }

        return $decoded;
    }

    private function baseUrl(): string
    {
        return $this->netopiaIsLive ? self::LIVE_BASE_URL : self::SANDBOX_BASE_URL;
    }

    private function assertConfigured(): void
    {
        if ('' === trim($this->netopiaPosSignature) || '' === trim($this->netopiaApiKey)) {
            throw new NetopiaException('Netopia is not configured (POS signature / API key missing)');
        }
    }

    // ---------- JWT / parsing ----------

    /**
     * Verifies the RS* JWT signature directly with openssl and returns the decoded
     * payload. openssl is used instead of firebase/php-jwt because the latter
     * rejects RSA keys under 2048 bits (Netopia's sandbox cert was 1024-bit);
     * openssl_verify has no such policy and works for any key size.
     *
     * Alg-confusion is prevented by a strict allowlist: only RS256/384/512 are
     * accepted (mapped to their SHA variant), and the RSA public key is used, so
     * an attacker cannot downgrade to HMAC/none.
     *
     * @return array<string, mixed>
     */
    private function decodeIpnJwt(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (3 !== count($parts)) {
            throw new NetopiaException('IPN token is not a well-formed JWT');
        }
        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $header = json_decode((string) $this->base64UrlDecode($encodedHeader), true);
        $alg = is_array($header) ? ($header['alg'] ?? null) : null;
        $opensslAlg = match ($alg) {
            'RS256' => OPENSSL_ALGO_SHA256,
            'RS384' => OPENSSL_ALGO_SHA384,
            'RS512' => OPENSSL_ALGO_SHA512,
            default => null,
        };
        if (null === $opensslAlg) {
            throw new NetopiaException(sprintf('Unsupported IPN JWT algorithm "%s"', is_string($alg) ? $alg : 'none'));
        }

        $publicKey = openssl_pkey_get_public($this->publicKey());
        if (false === $publicKey) {
            throw new NetopiaException('Could not load Netopia public key');
        }

        $signature = $this->base64UrlDecode($encodedSignature);
        $verified = openssl_verify($encodedHeader . '.' . $encodedPayload, $signature, $publicKey, $opensslAlg);
        if (1 !== $verified) {
            $this->logger->error('netopia.ipn.signature_invalid', ['alg' => $alg]);
            throw new NetopiaException('IPN signature verification failed', retryable: false);
        }

        $payload = json_decode((string) $this->base64UrlDecode($encodedPayload), true);

        return is_array($payload) ? $payload : [];
    }

    /**
     * Card-expiry "MM/YY" from the IPN binding, or null when absent/empty. An
     * empty binding reports expireMonth=0, which is not a real expiry.
     *
     * @param array<string, mixed> $data
     */
    private function bindingExpiry(array $data): ?string
    {
        $month = $this->digInt($data, ['payment', 'binding', 'expireMonth']);
        $year = $this->digInt($data, ['payment', 'binding', 'expireYear']);
        if ($month < 1 || $month > 12 || $year < 1) {
            return null;
        }

        return sprintf('%02d/%02d', $month, $year % 100);
    }

    /**
     * Rejects IPNs whose `iat` is outside the accepted window. `iat` is accepted
     * in seconds or milliseconds. Absent/unparseable iat is tolerated (fail-open
     * on freshness only; the signature check is never skipped).
     */
    private function assertFresh(mixed $iat): void
    {
        if (!is_numeric($iat)) {
            return;
        }
        $iat = (int) $iat;
        if ($iat > 1_000_000_000_000) {
            $iat = intdiv($iat, 1000); // milliseconds → seconds
        }

        $age = time() - $iat;
        if ($age > self::IPN_MAX_AGE_SECONDS) {
            throw new NetopiaException('IPN is too old (possible replay)');
        }
        if ($age < -self::IPN_FUTURE_SKEW_SECONDS) {
            throw new NetopiaException('IPN timestamp is in the future');
        }
    }

    /** The configured IPN public key, falling back to the fixed platform key. */
    private function publicKey(): string
    {
        return '' !== trim($this->netopiaPublicKey) ? $this->netopiaPublicKey : self::PLATFORM_PUBLIC_KEY;
    }

    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if (0 !== $remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $path
     */
    private function digString(array $data, array $path): ?string
    {
        $value = $this->dig($data, $path);

        return (is_string($value) && '' !== $value) || is_int($value) || is_float($value) ? (string) $value : null;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $path
     */
    private function digInt(array $data, array $path): int
    {
        $value = $this->dig($data, $path);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $path
     */
    private function dig(array $data, array $path): mixed
    {
        $cursor = $data;
        foreach ($path as $key) {
            if (!is_array($cursor) || !array_key_exists($key, $cursor)) {
                return null;
            }
            $cursor = $cursor[$key];
        }

        return $cursor;
    }
}
