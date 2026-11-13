# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable release. Omnisign Stripe Identity: identity verification through Stripe's VerificationSessions - an identity document photographed live and a selfie matched against it, in Stripe's hosted flow or in the page; the session followed, its report read as the checks performed, cancelled, redacted; webhooks with their Stripe-Signature checked. Test mode with a test key.

### Changed

- Requires `omnisense/contracts: ^1.0`, `omnisense/omnisign: ^1.1`: stable versions, so `composer require omnisign/stripe-identity` installs in a project at the default minimum-stability. `extra.branch-alias` names 1.x (`1.x-dev`); the CI (`.github/workflows/ci.yml`, glitchr-studio/ci's shared workflow) and the release on a tag (`release.yml`).
