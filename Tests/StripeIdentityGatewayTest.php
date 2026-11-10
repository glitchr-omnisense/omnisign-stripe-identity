<?php

namespace Omnisign\StripeIdentity\Tests;

use Omnisign\Exception\InvalidConfigException;
use Omnisign\Exception\InvalidKeyException;
use Omnisign\Exception\InvalidNotificationException;
use Omnisign\Exception\NotSupportedException;
use Omnisign\Exception\ProviderException;
use Omnisign\Gateway;
use Omnisign\Model\Capabilities;
use Omnisign\Model\CheckKind;
use Omnisign\Model\DocumentType;
use Omnisign\Model\Envelope;
use Omnisign\Model\IdentityEvent;
use Omnisign\Model\Verification;
use Omnisign\Model\VerificationStatus;
use Omnisign\Request\Create;
use Omnisign\Request\Identity\Notify;
use Omnisign\Request\Identity\Start;
use Omnisign\StripeIdentity\StripeIdentityGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Answers written from Stripe's API reference for Identity
 * (docs.stripe.com/api/identity/verification_sessions, verification_reports,
 * webhooks), read on 2026-11-10: no account was used, no call left.
 */
final class StripeIdentityGatewayTest extends TestCase
{
    private const ID = 'vs_1NuN4zLkdIwHu7ixleE6HvkI';
    private const BASE = 'https://api.stripe.com/v1/identity/verification_sessions';

    /** @var list<array{string, string, array<array-key, mixed>}> */
    private array $calls = [];

    /**
     * @param list<string>         $answers fixture[:status]
     * @param array<string, mixed> $options
     */
    private function gateway(array $answers, array $options = []): Gateway
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$answers): MockResponse {
            $this->calls[] = [$method, $url, $options];
            $answer = array_shift($answers) ?? throw new \LogicException('No answer left for '.$method.' '.$url);
            [$file, $status] = explode(':', $answer.':200');

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$file.'.json'), ['http_code' => (int) $status, 'response_headers' => ['content-type' => 'application/json']]);
        });
        $gateway = (new StripeIdentityGatewayFactory($http))->create($options + ['secret_key' => 'sk_test_51abcwxyz', 'webhook_secret' => 'whsec_test_secret']);
        self::assertInstanceOf(Gateway::class, $gateway);

        return $gateway;
    }

    /** @return array<string, string> the call's headers, by lower-case name */
    private function headers(int $call): array
    {
        $headers = [];
        $raw = $this->calls[$call][2]['headers'] ?? [];
        foreach (\is_array($raw) ? $raw : [] as $line) {
            [$name, $value] = explode(': ', \is_string($line) ? $line : '', 2);
            $headers[strtolower($name)] = $value;
        }

        return $headers;
    }

    public function testASessionIsStartedWithTheDocumentsTheSelfieAndTheKey(): void
    {
        $gateway = $this->gateway(['session-created']);
        $started = $gateway->startVerification(new Verification(
            [DocumentType::PASSPORT, DocumentType::ID_CARD],
            email: 'camille@erable.example',
            returnUrl: 'https://solstay.example/bookings/42/identity',
            key: 'booking-42',
            metadata: ['booking' => 'B-42'],
        ));

        self::assertSame(['POST', self::BASE], [$this->calls[0][0], $this->calls[0][1]]);
        self::assertSame(
            'type=document&options%5Bdocument%5D%5Ballowed_types%5D%5B%5D=passport&options%5Bdocument%5D%5Ballowed_types%5D%5B%5D=id_card&options%5Bdocument%5D%5Brequire_live_capture%5D=true&options%5Bdocument%5D%5Brequire_matching_selfie%5D=true&return_url=https%3A%2F%2Fsolstay.example%2Fbookings%2F42%2Fidentity&client_reference_id=booking-42&provided_details%5Bemail%5D=camille%40erable.example&metadata%5Bbooking%5D=B-42',
            $this->calls[0][2]['body'],
        );
        $headers = $this->headers(0);
        self::assertSame(['Bearer sk_test_51abcwxyz', '2026-10-28.endive', 'application/x-www-form-urlencoded'], [$headers['authorization'], $headers['stripe-version'], $headers['content-type']]);

        self::assertSame([self::ID, VerificationStatus::PENDING, false], [$started->reference, $started->status, $started->live]);
        self::assertSame('https://verify.stripe.com/start/test_YWNjdF8xTnVONHo', $started->url);
        self::assertSame('vs_1NuN4zLkdIwHu7ixleE6HvkI_secret_test_1x2y3z', $started->clientSecret);
        self::assertSame('booking-42', $started->key);
    }

    public function testADocumentStripeDoesNotReadIsRefusedBeforeAnyCall(): void
    {
        $gateway = $this->gateway([]);
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Stripe Identity reads no "residence_permit" document');
        try {
            $gateway->startVerification(new Verification([DocumentType::RESIDENCE_PERMIT]));
        } finally {
            self::assertSame([], $this->calls);
        }
    }

    public function testAFailedAttemptWaitsForAnotherWithItsReasonAndAFreshAddress(): void
    {
        $fetched = $this->gateway(['session-requires-input'])->fetchVerification(new Verification(reference: self::ID, url: 'https://old', clientSecret: 'old'));

        self::assertSame(['GET', self::BASE.'/'.self::ID], [$this->calls[0][0], $this->calls[0][1]]);
        self::assertSame(VerificationStatus::PENDING, $fetched->status);
        self::assertFalse($fetched->status->isFinal());
        self::assertSame(['code' => 'document_expired', 'reason' => 'The document is expired.'], $fetched->error);
        self::assertSame('https://verify.stripe.com/start/test_cmV0cnk', $fetched->url);
    }

    public function testTheResultIsTheChecksPerformedAndNothingElseOfTheDocument(): void
    {
        $result = $this->gateway(['session-verified-report'])->verificationResult(new Verification(reference: self::ID));

        self::assertSame(['GET', self::BASE.'/'.self::ID.'?expand%5B%5D=last_verification_report'], [$this->calls[0][0], $this->calls[0][1]]);
        self::assertTrue($result->verified());
        self::assertSame([[CheckKind::DOCUMENT, true], [CheckKind::SELFIE, true]], array_map(static fn ($c) => [$c->kind, $c->passed], $result->checks));
        self::assertSame([DocumentType::ID_CARD, 'FR', 'vr_1MwBlH2eZvKYlo2C91hOpFMf', false], [$result->documentType, $result->issuingCountry, $result->report, $result->live]);
        self::assertNull($result->person, 'the person is not asked for: not carried');
        self::assertSame('2023-09-25T22:23:20+00:00', $result->at?->format(\DATE_ATOM));
    }

    public function testThePersonIsCarriedOnlyWhenAskedFor(): void
    {
        $result = $this->gateway(['session-verified-report'], ['person' => true, 'birth_date' => true])->verificationResult(new Verification(reference: self::ID));

        self::assertSame(self::BASE.'/'.self::ID.'?expand%5B%5D=last_verification_report&expand%5B%5D=verified_outputs&expand%5B%5D=verified_outputs.dob', $this->calls[0][1]);
        self::assertSame(['Camille', 'Erable', '1994-07-14'], [$result->person?->firstName, $result->person?->lastName, $result->person?->birthDate?->format('Y-m-d')]);
    }

    public function testAFailedCheckSaysWhichAndWhy(): void
    {
        $result = $this->gateway(['session-selfie-mismatch'])->verificationResult(new Verification(reference: self::ID));

        self::assertFalse($result->verified());
        self::assertSame(VerificationStatus::PENDING, $result->status);
        self::assertTrue($result->check(CheckKind::DOCUMENT)?->passed);
        self::assertSame([CheckKind::SELFIE, 'selfie_face_mismatch'], [$result->failures()[0]->kind, $result->failures()[0]->code]);
        self::assertSame([DocumentType::PASSPORT, 'DE'], [$result->documentType, $result->issuingCountry]);
    }

    public function testCancelAndRedact(): void
    {
        $gateway = $this->gateway(['session-canceled', 'session-redacted']);
        $canceled = $gateway->cancelVerification(new Verification(reference: self::ID));
        $redacted = $gateway->redactVerification(new Verification(reference: self::ID));

        self::assertSame(['POST', self::BASE.'/'.self::ID.'/cancel', ''], [$this->calls[0][0], $this->calls[0][1], $this->calls[0][2]['body']]);
        self::assertSame(['POST', self::BASE.'/'.self::ID.'/redact'], [$this->calls[1][0], $this->calls[1][1]]);
        self::assertSame([VerificationStatus::CANCELED, VerificationStatus::VERIFIED], [$canceled->status, $redacted->status]);
        self::assertTrue($canceled->status->isFinal());
    }

    public function testErrors(): void
    {
        try {
            $this->gateway(['error-401:401'])->fetchVerification(new Verification(reference: self::ID));
            self::fail();
        } catch (InvalidKeyException $e) {
            self::assertStringContainsString('Invalid API Key provided', $e->getMessage());
        }
        try {
            $this->gateway(['error-400:400'])->startVerification(new Verification());
            self::fail();
        } catch (ProviderException $e) {
            self::assertSame('parameter_invalid_empty', $e->providerCode);
        }
        $this->expectException(\LogicException::class);
        $this->gateway([])->fetchVerification(new Verification());
    }

    public function testAWebhookIsCheckedAgainstItsSignatureAndItsAge(): void
    {
        $body = (string) file_get_contents(__DIR__.'/Fixtures/event-verified.json');
        $t = (string) time();
        $signature = hash_hmac('sha256', $t.'.'.$body, 'whsec_test_secret');
        $gateway = $this->gateway([]);

        $notification = $gateway->notifyIdentity($body, ['stripe-signature' => "t=$t,v1=deadbeef,v1=$signature,v0=6ffbb5"]);
        self::assertSame([IdentityEvent::VERIFIED, 'identity.verification_session.verified', self::ID, 'booking-42', 'evt_1NG8Du2eZvKYlo2CUI79vXWy'], [$notification->event, $notification->type, $notification->reference, $notification->key, $notification->id]);

        foreach (['t='.$t.',v1=deadbeef', 'v1='.$signature, 't='.(time() - 600).',v1='.hash_hmac('sha256', (time() - 600).'.'.$body, 'whsec_test_secret')] as $header) {
            try {
                $gateway->notifyIdentity($body, ['Stripe-Signature' => $header]);
                self::fail($header);
            } catch (InvalidNotificationException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertFalse($this->gateway([], ['webhook_secret' => null])->supports(Notify::class), 'no secret, no webhooks');
    }

    public function testItVerifiesIdentitiesAndSignsNothing(): void
    {
        $gateway = $this->gateway([]);
        $capabilities = $gateway->capabilities();

        self::assertSame([[], [Capabilities::IDENTITY_CHECK], true], [$capabilities->levels, $capabilities->contracts, $capabilities->selfie]);
        self::assertTrue($capabilities->keeps(Capabilities::IDENTITY_CHECK));
        self::assertFalse($capabilities->keeps(Capabilities::SIGNATURE));
        self::assertSame('https://docs.stripe.com/identity/use-cases', $capabilities->terms?->source);
        self::assertSame(['passport', 'id_card', 'driving_license'], $capabilities->toArray()['identityDocuments']);
        self::assertTrue($gateway->supports(Start::class));
        self::assertFalse($gateway->supports(Create::class));
        $this->expectException(NotSupportedException::class);
        $gateway->create(new Envelope('Bail'));
    }

    public function testTheSecretKeyIsNeeded(): void
    {
        $this->expectException(InvalidConfigException::class);
        (new StripeIdentityGatewayFactory(new MockHttpClient()))->create([]);
    }
}
