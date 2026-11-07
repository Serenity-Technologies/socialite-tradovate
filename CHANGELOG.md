# Changelog

All notable changes to `serenity_technologies/socialite-tradovate` are documented here. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses
[semantic versioning](https://semver.org/).

## [0.1.0] - 2026-10-24

### Added

- `SocialiteProviders\Tradovate\Provider`, a Laravel Socialite OAuth 2 provider for the Tradovate /
  NinjaTrader Trade API.
- Two drivers from one class: `tradovate` (live) and `tradovate-demo`, with per-environment default
  hosts and `auth_url` / `token_url` / `api_url` / `timeout` overrides.
- `getCodeFields()` omits `scope`, which Tradovate does not publish for this grant.
- `getAccessTokenResponse()` posts the documented five fields form-encoded through Laravel's HTTP
  client, so it can be faked and timed out.
- `/auth/me` identity mapping: `userId` → string id, `fullName`/`name`, nullable `email`.
- `TradovateOAuthException` with a stable `errorCode`: `token_exchange_unreachable`,
  `token_exchange_failed`, `token_missing`, `identity_failed`, or Tradovate's own `error` value.
- `TradovateExtendSocialite`, the `SocialiteWasCalled` listener that registers both drivers.
- Testbench/PHPUnit suite covering the authorize URL for both environments, the form-encoded
  exchange, both token-failure shapes, identity mapping, a non-2xx identity, relative-callback
  resolution and driver registration.

### Notes

- No refresh token: the OAuth response documents `access_token` and `expires_in` only.
- No PKCE and no scope.
- Not a sign-in provider; it links a brokerage account to an authenticated user.
- The Demo token and identity hosts are the published ones but remain unprobed against real
  credentials; see the README for the overrides.
