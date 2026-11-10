<?php

namespace Omnisign\StripeIdentity;

use Omnisign\Config;
use Omnisign\GatewayFactory;
use Omnisign\Model\Capabilities;
use Omnisign\Model\DocumentType;
use Omnisign\Model\Terms;
use Omnisign\StripeIdentity\Action\CancelAction;
use Omnisign\StripeIdentity\Action\FetchAction;
use Omnisign\StripeIdentity\Action\NotifyAction;
use Omnisign\StripeIdentity\Action\RedactAction;
use Omnisign\StripeIdentity\Action\ResultAction;
use Omnisign\StripeIdentity\Action\StartAction;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Stripe Identity: an identity document photographed live and a selfie
 * matched against it, in Stripe's hosted flow or its script in the page
 * (VerificationSessions). Identity checks only: it signs nothing.
 *
 *   options:
 *     secret_key: '%env(STRIPE_IDENTITY_SECRET_KEY)%'      # required; sk_test_... for test mode (nothing is really checked)
 *     webhook_secret: '%env(default::STRIPE_IDENTITY_WEBHOOK_SECRET)%'   # the endpoint's whsec_...: notifyIdentity() checks Stripe-Signature with it
 *     api_version: '2026-10-28.endive'                     # Stripe-Version; null: the account's default
 *     tolerance: 300                                       # seconds a webhook's timestamp may be old
 *     person: false                                        # true: the result names the person (verified_outputs' first and last name)
 *     birth_date: false                                    # true: and their birth date (a restricted key with the sensitive results' permission)
 *     url: ~                                               # another API root
 */
final class StripeIdentityGatewayFactory extends GatewayFactory
{
    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    public static function terms(): Terms
    {
        return new Terms(
            retention: null,
            partner: false,
            commercial: true,
            price: 'EUR 1.25 per verified document and selfie, the first 50 free (stripe.com/en-fr/identity, read 2026-11-10); public beta in the EU',
            mustNotKeep: [
                'the session\'s url and client_secret (single use, expiring; never stored nor logged)',
                'the document\'s and the selfie\'s images (their file ids, FileLinks)',
                'the document\'s number, expiry, address and the birth date, beyond the check that needed them',
                'personal data in metadata: identifiers only',
            ],
            rules: [
                'Banned uses: reselling the verification or its data; HIPAA health data; FCRA purposes (credit, employment, housing, insurance); government or law enforcement; users under 16; users linked to sanctioned countries or parties; prohibited or restricted businesses',
                'Stripe keeps biometric identifiers up to one year, images and extracted data typically seven years, and does not delete on the site\'s behalf: call redactVerification() once the purpose is served',
                'Some countries (Germany among them) forbid keeping identity images: redact',
            ],
            source: 'https://docs.stripe.com/identity/use-cases',
        );
    }

    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnisign.factory_name' => 'stripe-identity',
            'omnisign.factory_title' => 'Stripe Identity',
            'omnisign.required_options' => ['secret_key'],
            'webhook_secret' => null,
            'api_version' => Api::VERSION,
            'tolerance' => 300,
            'person' => false,
            'birth_date' => false,
            'url' => null,
            'omnisign.capabilities' => new Capabilities(
                levels: [],
                embedded: true,
                ordered: false,
                thirdParty: true,
                documents: false,
                contracts: [Capabilities::IDENTITY_CHECK],
                identityDocuments: [DocumentType::PASSPORT, DocumentType::ID_CARD, DocumentType::DRIVING_LICENSE],
                selfie: true,
                liveness: false,
                terms: self::terms(),
            ),
            'omnisign.api' => fn (Config $c) => new Api(
                $this->http ?? HttpClient::create(),
                self::text($c['secret_key']),
                rtrim(\is_string($url = $c->get('url')) && '' !== $url ? $url : Api::URL, '/'),
                \is_string($version = $c->get('api_version')) && '' !== $version ? $version : null,
                \is_string($secret = $c->get('webhook_secret')) && '' !== $secret ? $secret : null,
                is_numeric($tolerance = $c->get('tolerance')) ? (int) $tolerance : 300,
            ),
            'omnisign.action.identity_start' => new StartAction(),
            'omnisign.action.identity_fetch' => new FetchAction(),
            'omnisign.action.identity_result' => static fn (Config $c) => new ResultAction($c->bool('person'), $c->bool('birth_date')),
            'omnisign.action.identity_cancel' => new CancelAction(),
            'omnisign.action.identity_redact' => new RedactAction(),
            'omnisign.action.identity_notify' => static fn (Config $c) => $c->get('webhook_secret') ? new NotifyAction() : null,
        ]);
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
