# omnisign/stripe-identity

**Stripe Identity** for [omnisense/omnisign](https://github.com/glitchr-omnisense/omnisign): identity
verification (the family's `identity-check` contract) through Stripe's VerificationSessions - an
identity document photographed live and a selfie matched against it, in Stripe's hosted flow or in
the application's page; the checks performed; the personal data erased at Stripe once done. It
signs nothing.

```php
use Omnisign\Model\DocumentType;
use Omnisign\Model\Verification;
use Omnisign\StripeIdentity\StripeIdentityGatewayFactory;

$kyc = (new StripeIdentityGatewayFactory($httpClient))->create(['secret_key' => getenv('STRIPE_IDENTITY_SECRET_KEY')]);

$verification = $kyc->startVerification(new Verification([DocumentType::ID_CARD, DocumentType::PASSPORT], key: 'booking-42', returnUrl: $back));
// send the person to $verification->url (never stored); keep $verification->reference
$result = $kyc->verificationResult($kyc->fetchVerification($verification));   // VERIFIED, the document and selfie checks
$kyc->redactVerification($verification);                                       // once the purpose is served
```

Written from Stripe's API reference and guides for Identity, read on 2026-11-10 (API version
`2026-10-28.endive`). **Not verified in real: no account.** A `sk_test_` key is Stripe's test mode:
everything runs, nothing is really checked.

[Documentation](docs/index.md): the calls, the options, the checks, the webhooks, the personal
data a site must not keep, Stripe's terms.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Stripe. It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts written as test fixtures. Its MIT licence covers
this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with Stripe's terms and
Identity's permitted uses ([docs.stripe.com/identity/use-cases](https://docs.stripe.com/identity/use-cases)),
for the personal data it handles (GDPR: a lawful basis, a privacy notice, redaction once done), and
for any use made of what it returns. The authors publish it free of charge, make no commercial use
of it, and accept no liability for how it is used (see [LICENSE](LICENSE)).

License: MIT.
