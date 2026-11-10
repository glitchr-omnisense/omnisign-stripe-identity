# omnisign/stripe-identity

Stripe Identity for [omnisense/omnisign](https://github.com/glitchr-omnisense/omnisign) 1.1 and
later: the `identity-check` contract (`IdentityCheckInterface`) through Stripe's API on
`https://api.stripe.com/v1` (`Api::URL`). Every call carries `Authorization: Bearer <secret key>` and
`Stripe-Version: 2026-10-28.endive` (`Api::VERSION`, the `api_version` option); bodies are
form-encoded in Stripe's bracket notation (`options[document][allowed_types][]=passport`), answers
are JSON.

## Installation

```sh
composer require omnisign/stripe-identity
```

PHP 8.2 or later, `omnisense/omnisign` ^1.1 and `symfony/http-client`.

## The sources

Stripe's documentation, read on 2026-11-10: the API reference for
`identity/verification_sessions` (create, retrieve, cancel, redact, list, the object) and
`identity/verification_reports` (the object), the guides "Verification sessions", "Access
verification results", "Webhooks" (manual signature verification), "Use cases" (permitted and
banned uses), the changelog (the API version), Stripe's support page on identity data retention, and
the French pricing page.

## Credentials

- A **secret key**: `sk_test_...` for test mode, `sk_live_...` in production; or a restricted key
  (`rk_...`) with "Identity verification results" - and "Recent sensitive verification results"
  when the birth date is asked for (`birth_date: true`; verifications of the last 48 hours).
- For webhooks, an **endpoint** in Stripe's Dashboard listening to `identity.verification_session.*`
  (`redacted` only when subscribed to explicitly), and its secret `whsec_...` (`webhook_secret`).

Keys go by reference, never in the code: `%env(STRIPE_IDENTITY_SECRET_KEY)%` in Symfony, the secrets
vault for production.

## Options

| Option | Default | |
|---|---|---|
| `secret_key` | required | the bearer key |
| `webhook_secret` | | the endpoint's `whsec_...`; without it, `notifyIdentity()` is not supported |
| `api_version` | `2026-10-28.endive` | `Stripe-Version`; empty: the account's default version |
| `tolerance` | `300` | seconds a webhook's timestamp may be old (0: no check - not advised) |
| `person` | `false` | `true`: the result names the person (`verified_outputs`' first and last name) |
| `birth_date` | `false` | `true`: and their birth date (`verified_outputs.dob`; a restricted key, see above) |
| `url` | | another API root |

## From the contract to Stripe

| | |
|---|---|
| `startVerification()` | `POST /identity/verification_sessions`: `type=document`, `options[document][allowed_types][]` (the `documents`), `options[document][require_live_capture]`, `options[document][require_matching_selfie]`, `return_url`, `client_reference_id` (the `key`), `provided_details[email]`, `metadata` |
| `fetchVerification()` | `GET /identity/verification_sessions/{id}`: a fresh `url` and `client_secret` while it requires input |
| `verificationResult()` | `GET /identity/verification_sessions/{id}?expand[]=last_verification_report` (+ `expand[]=verified_outputs`, `expand[]=verified_outputs.dob` with `person`, `birth_date`) |
| `cancelVerification()` | `POST .../cancel` - only while it requires input; for good |
| `redactVerification()` | `POST .../redact` - the session, its reports, events, logs and files erased at Stripe; allowed when it requires input (it is then canceled) or is verified, not while processing |
| `notifyIdentity()` | `Stripe-Signature: t=...,v1=...`: the HMAC-SHA-256 of `"<t>.<raw body>"` under the endpoint's secret, any `v1` (several while a secret rolls; `v0` ignored), the timestamp within `tolerance` |

| Stripe `status` | `VerificationStatus` |
|---|---|
| `requires_input` | `PENDING` - with `error` (`last_error.code`, `.reason`) after a failed attempt: the person may try again |
| `processing` | `PROCESSING` |
| `verified` | `VERIFIED` |
| `canceled` | `CANCELED` |

| Report section | `Check` |
|---|---|
| `document` | `DOCUMENT` (`verified` passed, `unverified` failed; `error.code`: `document_expired`, `document_type_not_supported`, `document_unverified_other`) |
| `selfie` | `SELFIE` (`selfie_face_mismatch`, `selfie_manipulated`, `selfie_document_missing_photo`, `selfie_unverified_other`) |
| `id_number`, `email`, `phone` | `ID_NUMBER`, `EMAIL`, `PHONE` when present |

Of the report the result keeps the document's `type` (`passport`, `id_card`, `driving_license`) and
`issuing_country`; nothing else - no number, expiry, address, nor the images' file ids. Stripe has
no separate liveness check in its report: `capabilities()->liveness` is `false`; the live capture
of the document is `require_live_capture`.

| Event | `IdentityEvent` |
|---|---|
| `identity.verification_session.created`, `.processing`, `.verified`, `.requires_input`, `.canceled`, `.redacted` | `CREATED`, `PROCESSING`, `VERIFIED`, `REQUIRES_INPUT`, `CANCELED`, `REDACTED` |

`IdentityNotification`: the session's id, its `client_reference_id` (the application's key), the
event's id (Stripe may deliver twice, in any order: deduplicate on it).

## Capabilities

`contracts: [identity-check]`, no signature levels, in the page (Stripe.js with the
`client_secret`), third party; documents passport, ID card, driving licence; selfie yes.
`DocumentType::RESIDENCE_PERMIT` and `OTHER` are refused before any call (`InvalidConfigException`).

`capabilities()->terms` (`StripeIdentityGatewayFactory::terms()`):

- **Price**: EUR 1.25 per verified document and selfie, the first 50 free (French page, 2026-11-10);
  Identity is in public beta in the EU.
- **Banned uses**: reselling the verification or its data; HIPAA health data; FCRA purposes (credit,
  employment, housing, insurance); government or law enforcement; users under 16; users linked to
  sanctioned countries or parties; prohibited or restricted businesses.
- **Retention at Stripe**: biometric identifiers up to a year, images and extracted data typically
  seven years; Stripe does not delete on the site's behalf - redact.

## Personal data: what a site must not keep

- the session's `url` and `client_secret` (single use, expiring; never stored, never logged);
- the document's and the selfie's images - the package never reads them, not even their file ids;
- the document's number, expiry, the person's address, and the birth date beyond the check that
  needed it;
- personal data in `metadata`: identifiers only.

A site keeps the reference, the status, the checks and their outcome, the document's type and
country, the date; and calls `redactVerification()` once the purpose is served.

## Errors

| Answer | Exception |
|---|---|
| 401, 403 | `InvalidKeyException` (Stripe's `error.message`) |
| other 4xx | `ProviderException`, `providerCode` Stripe's `error.code` (`parameter_invalid_empty`...) |
| 429, 5xx, no answer | `UnreachableException` |
| a webhook whose signature or age does not hold | `InvalidNotificationException` |

## Example in Symfony

```yaml
omnisign:
    gateways:
        kyc:
            factory: stripe-identity
            options:
                secret_key: '%env(STRIPE_IDENTITY_SECRET_KEY)%'
                webhook_secret: '%env(default::STRIPE_IDENTITY_WEBHOOK_SECRET)%'
```

```php
public function __construct(private readonly IdentityCheckInterface $kyc) {}
```

## Verified, and not

- Verified: every call above against a `MockHttpClient` that records the requests (method, URL,
  body, headers) and serves answers written from Stripe's reference (`Tests/Fixtures`); the
  webhook's signature scheme with Stripe's documented construction; PHPStan at level max.
- Not verified: no Stripe account was used, no call left the machine. The names of the hosted
  flow's test cases, and whether `client_secret` and `url` come back null inside events, were not
  confirmed.
