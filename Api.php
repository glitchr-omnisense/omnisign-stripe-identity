<?php

namespace Omnisign\StripeIdentity;

use Omnisense\Contracts\Policy\Backoff;
use Omnisign\Exception\InvalidKeyException;
use Omnisign\Exception\InvalidNotificationException;
use Omnisign\Exception\ProviderException;
use Omnisign\Http\Answer;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Stripe's API for Identity: a secret key as the bearer, the API version
 * pinned by Stripe-Version, form-encoded bodies in Stripe's bracket
 * notation (options[document][allowed_types][]=passport), JSON answers.
 */
final class Api
{
    public const PROVIDER = 'stripe-identity';
    public const URL = 'https://api.stripe.com/v1';
    /** The API version the package was written against (docs.stripe.com/changelog, 2026-11-10). */
    public const VERSION = '2026-10-28.endive';

    /**
     * @param int           $tolerance seconds a webhook's timestamp may be old (Stripe's libraries: 300)
     * @param \Closure|null $clock     the time, for the tests: fn (): int
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        #[\SensitiveParameter] private readonly string $secretKey,
        public readonly string $url = self::URL,
        public readonly ?string $version = self::VERSION,
        #[\SensitiveParameter] private readonly ?string $webhookSecret = null,
        private readonly int $tolerance = 300,
        private readonly ?\Closure $clock = null,
        private readonly ?Backoff $backoff = null,
    ) {
    }

    /** Whether the key is a test key: nothing is really checked (Stripe's test mode). */
    public function isTest(): bool
    {
        return str_starts_with($this->secretKey, 'sk_test_') || str_starts_with($this->secretKey, 'rk_test_');
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->call('GET', $path.($query ? '?'.self::encode($query) : ''), []);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function post(string $path, array $params = []): array
    {
        return $this->call('POST', $path, ['headers' => ['Content-Type' => 'application/x-www-form-urlencoded'], 'body' => self::encode($params)]);
    }

    /**
     * A webhook's call, its Stripe-Signature checked: t=<timestamp>,v1=<hex>
     * (several v1 while a secret rolls; v0 and other schemes ignored), the
     * HMAC-SHA-256 of "<t>.<raw body>" under the endpoint's secret, the
     * timestamp no older than the tolerance.
     *
     * @return array<string, mixed> the event
     */
    public function verify(string $body, ?string $header): array
    {
        if (null === $this->webhookSecret) {
            throw new InvalidNotificationException(self::PROVIDER, 'No webhook secret is configured.');
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', (string) $header) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ('t' === $name) {
                $timestamp = $value;
            } elseif ('v1' === $name) {
                $signatures[] = $value;
            }
        }
        if (null === $timestamp || !ctype_digit($timestamp) || !$signatures) {
            throw new InvalidNotificationException(self::PROVIDER, 'No Stripe-Signature with a timestamp and a v1 signature.');
        }
        $expected = hash_hmac('sha256', $timestamp.'.'.$body, $this->webhookSecret);
        $held = false;
        foreach ($signatures as $signature) {
            $held = hash_equals($expected, $signature) || $held;
        }
        if (!$held) {
            throw new InvalidNotificationException(self::PROVIDER, 'The signature does not hold.');
        }
        $now = $this->clock && \is_int($t = ($this->clock)()) ? $t : time();
        if ($this->tolerance > 0 && abs($now - (int) $timestamp) > $this->tolerance) {
            throw new InvalidNotificationException(self::PROVIDER, \sprintf('The signature is %d seconds old, above the tolerance of %d.', abs($now - (int) $timestamp), $this->tolerance));
        }
        $event = json_decode($body, true);

        return \is_array($event) ? self::strings($event) : throw new InvalidNotificationException(self::PROVIDER, 'The callback is not JSON.');
    }

    /**
     * Stripe's form encoding: nested keys in brackets, lists as key[]=,
     * booleans as true/false, nulls left out.
     *
     * @param array<array-key, mixed> $params
     */
    public static function encode(array $params, string $prefix = ''): string
    {
        $pairs = [];
        foreach ($params as $key => $value) {
            $name = '' === $prefix ? (string) $key : $prefix.(\is_int($key) ? '[]' : '['.$key.']');
            if (null === $value) {
                continue;
            }
            if (\is_array($value)) {
                if ('' !== $encoded = self::encode($value, $name)) {
                    $pairs[] = $encoded;
                }
                continue;
            }
            $scalar = \is_bool($value) ? ($value ? 'true' : 'false') : (\is_scalar($value) ? (string) $value : '');
            $pairs[] = rawurlencode($name).'='.rawurlencode($scalar);
        }

        return implode('&', $pairs);
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function strings(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, array $options): array
    {
        $headers = \is_array($options['headers'] ?? null) ? $options['headers'] : [];
        $options['headers'] = ['Authorization' => 'Bearer '.$this->secretKey] + ($this->version ? ['Stripe-Version' => $this->version] : []) + $headers;
        $answer = Answer::send($this->http, self::PROVIDER, $method, $this->url.$path, $options, $this->backoff);
        if (\in_array($answer->status, [401, 403], true)) {
            throw new InvalidKeyException(self::PROVIDER, \sprintf('The secret key was refused (HTTP %d): %s', $answer->status, self::error($answer)), (string) $answer->status);
        }
        if ($answer->status >= 400) {
            $error = json_decode($answer->body, true);
            $code = \is_array($error) && \is_array($error['error'] ?? null) && \is_string($error['error']['code'] ?? null) ? $error['error']['code'] : (string) $answer->status;

            throw new ProviderException(self::PROVIDER, \sprintf('%s %s: HTTP %d, %s', $method, strtok($path, '?'), $answer->status, self::error($answer)), $code);
        }

        return self::strings($answer->json());
    }

    private static function error(Answer $answer): string
    {
        $error = json_decode($answer->body, true);
        if (!\is_array($error) || !\is_array($error['error'] ?? null)) {
            return mb_substr(trim($answer->body), 0, 200) ?: 'no detail';
        }
        $message = $error['error']['message'] ?? $error['error']['type'] ?? 'an error';

        return \is_string($message) ? $message : 'an error';
    }
}
