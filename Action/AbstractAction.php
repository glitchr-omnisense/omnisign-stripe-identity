<?php

namespace Omnisign\StripeIdentity\Action;

use Omnisign\Action\ActionInterface;
use Omnisign\Action\ApiAwareInterface;
use Omnisign\Action\ApiAwareTrait;
use Omnisign\Model\Verification;
use Omnisign\Model\VerificationStatus;
use Omnisign\StripeIdentity\Api;

/** An action on Stripe's Identity API. */
abstract class AbstractAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    /** A VerificationSession's status, as the family's; requires_input with a last_error stays PENDING, the error said. */
    public const STATUSES = [
        'requires_input' => VerificationStatus::PENDING,
        'processing' => VerificationStatus::PROCESSING,
        'verified' => VerificationStatus::VERIFIED,
        'canceled' => VerificationStatus::CANCELED,
    ];

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    protected static function path(Verification $verification, string $then = ''): string
    {
        return '/identity/verification_sessions/'.rawurlencode($verification->reference()).$then;
    }

    /**
     * The verification as a VerificationSession answered it: its id, status,
     * last error, the hosted flow's url and client_secret (fresh while it
     * requires input, null after).
     *
     * @param array<string, mixed> $session
     */
    protected static function read(Verification $verification, array $session): Verification
    {
        $error = self::map($session, 'last_error');

        return $verification->with(
            reference: self::string($session, 'id') ?? $verification->reference,
            status: self::STATUSES[self::string($session, 'status') ?? ''] ?? VerificationStatus::UNKNOWN,
            url: self::string($session, 'url'),
            clientSecret: self::string($session, 'client_secret'),
            error: $error ? ['code' => self::string($error, 'code'), 'reason' => self::string($error, 'reason')] : null,
            createdAt: null !== ($created = self::int($session, 'created')) ? new \DateTimeImmutable('@'.$created) : null,
            live: true === ($session['livemode'] ?? null),
        );
    }

    /** @param array<string, mixed> $data */
    protected static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return \is_string($value) && '' !== $value ? $value : (\is_int($value) ? (string) $value : null);
    }

    /** @param array<string, mixed> $data */
    protected static function int(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return \is_int($value) ? $value : (\is_string($value) && ctype_digit($value) ? (int) $value : null);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>|null
     */
    protected static function map(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;
        if (!\is_array($value)) {
            return null;
        }
        $map = [];
        foreach ($value as $k => $v) {
            $map[(string) $k] = $v;
        }

        return $map;
    }
}
