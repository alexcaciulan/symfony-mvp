<?php

declare(strict_types=1);

namespace App\Tests\Service\Billing\Netopia;

use App\Entity\Invoice;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\InvoiceType;
use App\Service\Billing\Netopia\NetopiaApiClient;
use App\Service\Billing\Netopia\NetopiaException;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

final class NetopiaApiClientTest extends TestCase
{
    private string $privateKey;
    private string $publicKey;

    protected function setUp(): void
    {
        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($res, 'Could not generate RSA test keypair');

        openssl_pkey_export($res, $privateKey);
        $this->privateKey = (string) $privateKey;
        $details = openssl_pkey_get_details($res);
        self::assertIsArray($details);
        $this->publicKey = $details['key'];
    }

    private function client(MockHttpClient $http, ?string $publicKey = null): NetopiaApiClient
    {
        return new NetopiaApiClient(
            httpClient: $http,
            netopiaPosSignature: 'POS-1',
            netopiaApiKey: 'key-1',
            netopiaPublicKey: $publicKey ?? $this->publicKey,
            netopiaIsLive: false,
            netopiaNotifyUrl: 'https://app.test/webhook/netopia',
            netopiaReturnUrl: 'https://app.test/subscription/return',
        );
    }

    private function invoice(): Invoice
    {
        $user = (new User())->setEmail('lawyer@test.com');

        return (new Invoice())
            ->setUser($user)
            ->setType(InvoiceType::SUBSCRIPTION)
            ->setAmount('99.00');
    }

    public function testStartPaymentReturnsRedirectAndNtpId(): void
    {
        $http = new MockHttpClient([
            new MockResponse((string) json_encode([
                'payment' => ['ntpID' => 'NTP-123', 'status' => 15, 'paymentURL' => 'https://sandbox.netopia/pay/abc'],
            ])),
        ]);

        $result = $this->client($http)->startPayment($this->invoice(), $this->invoice()->getUser(), 'INV-1-abc');

        self::assertSame('https://sandbox.netopia/pay/abc', $result->redirectUrl);
        self::assertSame('NTP-123', $result->ntpID);
        self::assertSame(15, $result->status);
    }

    public function testStartPaymentThrowsWhenNoRedirectUrl(): void
    {
        $http = new MockHttpClient([new MockResponse((string) json_encode(['payment' => ['ntpID' => 'X', 'status' => 15]]))]);

        $this->expectException(NetopiaException::class);
        $this->client($http)->startPayment($this->invoice(), $this->invoice()->getUser(), 'INV-1-abc');
    }

    public function testChargeTokenAcceptedOnPaidStatus(): void
    {
        $http = new MockHttpClient([new MockResponse((string) json_encode(['payment' => ['ntpID' => 'NTP-9', 'status' => 3]]))]);
        $subscription = (new Subscription())->setUser((new User())->setEmail('lawyer@test.com'))->setRecurringToken('tok-abc');

        $result = $this->client($http)->chargeToken($subscription, $this->invoice(), 'INV-2-def');

        self::assertTrue($result->accepted);
        self::assertSame(3, $result->status);
        self::assertSame('NTP-9', $result->ntpID);
    }

    public function testChargeTokenDeclinedOnNonPaidStatus(): void
    {
        $http = new MockHttpClient([new MockResponse((string) json_encode(['payment' => ['ntpID' => 'NTP-9', 'status' => 12], 'error' => ['message' => 'insufficient funds']]))]);
        $subscription = (new Subscription())->setUser((new User())->setEmail('lawyer@test.com'))->setRecurringToken('tok-abc');

        $result = $this->client($http)->chargeToken($subscription, $this->invoice(), 'INV-2-def');

        self::assertFalse($result->accepted);
        self::assertSame('insufficient funds', $result->errorMessage);
    }

    public function testChargeTokenThrowsWithoutToken(): void
    {
        $http = new MockHttpClient([]);

        $this->expectException(NetopiaException::class);
        $this->client($http)->chargeToken(new Subscription(), $this->invoice(), 'INV-2-def');
    }

    public function testFetchStatusReturnsStatusInt(): void
    {
        $http = new MockHttpClient([new MockResponse((string) json_encode(['payment' => ['status' => 5]]))]);

        self::assertSame(5, $this->client($http)->fetchStatus('NTP-1'));
    }

    /**
     * Builds an IPN exactly as Netopia v2 sends it: the notification is the JSON
     * body; the `Verification-token` header is a JWT (RS512) whose `sub` is
     * base64(sha512(body)), signed by the platform key, with iss/aud claims.
     *
     * @param array<string, mixed> $body
     */
    private function signedIpn(array $body, ?string $iss = 'NETOPIA Payments', ?string $aud = 'POS-1', ?int $iat = null): Request
    {
        $json = (string) json_encode($body);
        $sub = base64_encode((string) hash('sha512', $json, true));

        $jwt = JWT::encode([
            'iss' => $iss,
            'aud' => [$aud],
            'sub' => $sub,
            'iat' => $iat ?? time(),
        ], $this->privateKey, 'RS512');

        $request = Request::create('/webhook/netopia', 'POST', content: $json);
        $request->headers->set('Verification-token', $jwt);

        return $request;
    }

    private static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function ipnWithRawToken(string $token): Request
    {
        $request = Request::create('/webhook/netopia', 'POST', content: '{"order":{"orderID":"INV-1-a"}}');
        $request->headers->set('Verification-token', $token);

        return $request;
    }

    /** @return array<string, mixed> */
    private function ipnBody(int $status = 3): array
    {
        return [
            'order' => ['orderID' => 'INV-42-xyz'],
            'payment' => [
                'ntpID' => 'NTP-42',
                'status' => $status,
                'token' => 'save-tok',
                'binding' => ['expireMonth' => 12, 'expireYear' => 30],
                'instrument' => ['panMasked' => '4111 **** 1111'],
            ],
        ];
    }

    public function testVerifyIpnAcceptsValidSignatureAndReadsBody(): void
    {
        $result = $this->client(new MockHttpClient([]))->verifyIpn($this->signedIpn($this->ipnBody()));

        self::assertSame('INV-42-xyz', $result->orderId);
        self::assertSame('NTP-42', $result->ntpID);
        self::assertTrue($result->paid);
        self::assertSame('save-tok', $result->token);
        self::assertSame('12/30', $result->tokenExpiresAt);
        self::assertSame('4111 **** 1111', $result->cardMask);
    }

    public function testVerifyIpnReturnsNoTokenWhenBindingEmpty(): void
    {
        // Real sandbox shape when the card was not tokenized: empty token + binding.
        $body = [
            'order' => ['orderID' => 'INV-42-xyz'],
            'payment' => ['ntpID' => 'NTP-42', 'status' => 3, 'token' => '', 'binding' => ['expireMonth' => 0, 'expireYear' => 0]],
        ];

        $result = $this->client(new MockHttpClient([]))->verifyIpn($this->signedIpn($body));

        self::assertTrue($result->paid);
        self::assertNull($result->token);
        self::assertNull($result->tokenExpiresAt);
    }

    public function testVerifyIpnTreatsConfirmedStatusAsPaid(): void
    {
        $result = $this->client(new MockHttpClient([]))->verifyIpn($this->signedIpn($this->ipnBody(status: 5)));

        self::assertTrue($result->paid); // status 5 = confirmed
    }

    public function testVerifyIpnRejectsTamperedBody(): void
    {
        // Sign for one body, then swap the body: the sub hash no longer matches.
        $request = $this->signedIpn($this->ipnBody());
        $tampered = Request::create('/webhook/netopia', 'POST', content: '{"order":{"orderID":"INV-999-hacked"},"payment":{"status":3}}');
        $tampered->headers->set('Verification-token', $request->headers->get('Verification-token'));

        $this->expectException(NetopiaException::class);
        $this->client(new MockHttpClient([]))->verifyIpn($tampered);
    }

    public function testVerifyIpnRejectsWrongIssuer(): void
    {
        $this->expectException(NetopiaException::class);
        $this->client(new MockHttpClient([]))->verifyIpn($this->signedIpn($this->ipnBody(), iss: 'Attacker'));
    }

    public function testVerifyIpnRejectsWrongAudience(): void
    {
        $this->expectException(NetopiaException::class);
        $this->client(new MockHttpClient([]))->verifyIpn($this->signedIpn($this->ipnBody(), aud: 'OTHER-POS'));
    }

    public function testVerifyIpnRejectsTamperedSignature(): void
    {
        $request = $this->signedIpn($this->ipnBody());
        $request->headers->set('Verification-token', $request->headers->get('Verification-token') . 'x');

        $this->expectException(NetopiaException::class);
        $this->client(new MockHttpClient([]))->verifyIpn($request);
    }

    public function testVerifyIpnRejectsSignatureFromWrongKey(): void
    {
        $otherRes = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $otherPublic = openssl_pkey_get_details($otherRes)['key'];

        $this->expectException(NetopiaException::class);
        $this->client(new MockHttpClient([]), $otherPublic)->verifyIpn($this->signedIpn($this->ipnBody()));
    }

    public function testVerifyIpnRejectsStaleIat(): void
    {
        // Replay protection: an IPN older than the accepted window is rejected.
        $this->expectException(NetopiaException::class);
        $this->client(new MockHttpClient([]))->verifyIpn($this->signedIpn($this->ipnBody(), iat: time() - 7200));
    }

    public function testVerifyIpnRejectsAlgNone(): void
    {
        // alg:none downgrade must be refused by the allowlist before any key use.
        $token = self::b64url('{"alg":"none","typ":"JWT"}') . '.' . self::b64url('{"iss":"NETOPIA Payments"}') . '.';

        $this->expectException(NetopiaException::class);
        $this->client(new MockHttpClient([]))->verifyIpn($this->ipnWithRawToken($token));
    }

    public function testVerifyIpnRejectsHs256Downgrade(): void
    {
        // Classic alg-confusion: sign HS256 using the RSA public key PEM as the
        // HMAC secret. The RS*-only allowlist rejects it before verification.
        $header = self::b64url('{"alg":"HS256","typ":"JWT"}');
        $payload = self::b64url('{"iss":"NETOPIA Payments","aud":["POS-1"]}');
        $sig = self::b64url(hash_hmac('sha256', $header . '.' . $payload, $this->publicKey, true));

        $this->expectException(NetopiaException::class);
        $this->client(new MockHttpClient([]))->verifyIpn($this->ipnWithRawToken("$header.$payload.$sig"));
    }

    public function testVerifyIpnAcceptsAudienceNotAtIndexZero(): void
    {
        $json = (string) json_encode($this->ipnBody());
        $sub = base64_encode((string) hash('sha512', $json, true));
        $jwt = JWT::encode(['iss' => 'NETOPIA Payments', 'aud' => ['OTHER', 'POS-1'], 'sub' => $sub, 'iat' => time()], $this->privateKey, 'RS512');
        $request = Request::create('/webhook/netopia', 'POST', content: $json);
        $request->headers->set('Verification-token', $jwt);

        $result = $this->client(new MockHttpClient([]))->verifyIpn($request);

        self::assertSame('INV-42-xyz', $result->orderId);
    }

    public function testVerifyIpnRejectsMissingToken(): void
    {
        $request = Request::create('/webhook/netopia', 'POST', content: '{"order":{"orderID":"INV-1-a"}}');

        $this->expectException(NetopiaException::class);
        $this->client(new MockHttpClient([]))->verifyIpn($request);
    }

    public function testIsPaidStatus(): void
    {
        $client = $this->client(new MockHttpClient([]));

        self::assertTrue($client->isPaidStatus(3));
        self::assertTrue($client->isPaidStatus(5));
        self::assertFalse($client->isPaidStatus(15));
        self::assertFalse($client->isPaidStatus(0));
    }
}
