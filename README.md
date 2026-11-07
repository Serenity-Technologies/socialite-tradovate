# Tradovate Socialite Provider

[Laravel Socialite](https://laravel.com/docs/socialite) provider for **Tradovate** — the NinjaTrader
Trade API — using the OAuth 2 authorization-code grant.

Two drivers ship from one class:

| Driver | Environment | Authorize | Token exchange | Identity |
| --- | --- | --- | --- | --- |
| `tradovate` | live | `https://trader.tradovate.com/oauth` | `https://live.tradovateapi.com/auth/oauthtoken` | `https://live.tradovateapi.com/v1/auth/me` |
| `tradovate-demo` | demo | `https://trader.devel.ninjatrader.dev/oauth` | `https://live-api-d.tradovate.com/auth/oauthtoken` | `https://demo.tradovateapi.com/v1/auth/me` |

The hosts are the ones published on 2026-10-24. The Demo token and identity hosts have **not** been
probed against real credentials; override them with `token_url` / `api_url` if the Demo answers on
`https://demo.tradovateapi.com/v1/auth/oauthtoken`.

**This is not a sign-in provider.** Authorizing a Tradovate account links a brokerage account to an
already-authenticated user. Do not create users, log users in, or store a `tradovate_id` from it.

## Requirements

- PHP 8.2+
- `laravel/socialite` ^5.0 and `socialiteproviders/manager` ^4.0 (installed as dependencies)

## Install

```bash
composer require serenity_technologies/socialite-tradovate:dev-main
```

The package is not on Packagist yet. Add the GitHub repository to the application's
`composer.json` so Composer can resolve it:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/Serenity-Technologies/socialite-tradovate.git"
        }
    ]
}
```

Switch to `:^0.1` once `v0.1.0` is tagged.

While developing against a working copy, use a path repository and remove it once `v0.1.0` is
tagged:

```json
{
    "repositories": [{ "type": "path", "url": "../socialite-tradovate", "options": { "symlink": true } }]
}
```

## Configuration

Add both drivers to `config/services.php`. A relative `redirect` is correct: the manager resolves it
to an absolute URL with `URL::to()`. Both environments share one registered callback URI, because
Tradovate documents app-level registration and the environment is carried by your own state entry.

```php
'tradovate' => [
    'client_id' => env('TRADOVATE_CLIENT_ID'),
    'client_secret' => env('TRADOVATE_CLIENT_SECRET'),
    'redirect' => env('TRADOVATE_REDIRECT_URI', '/dashboard/copier/ninjatrader/callback'),
    'environment' => 'live',
    'timeout' => (int) env('TRADOVATE_TIMEOUT', 10),
],

'tradovate-demo' => [
    'client_id' => env('TRADOVATE_DEMO_CLIENT_ID', env('TRADOVATE_CLIENT_ID')),
    'client_secret' => env('TRADOVATE_DEMO_CLIENT_SECRET', env('TRADOVATE_CLIENT_SECRET')),
    'redirect' => env('TRADOVATE_REDIRECT_URI', '/dashboard/copier/ninjatrader/callback'),
    'environment' => 'demo',
    'timeout' => (int) env('TRADOVATE_TIMEOUT', 10),
],
```

Optional per-driver overrides (useful for a staging or re-probed host, no release needed):

| Key | Meaning | Default |
| --- | --- | --- |
| `environment` | `live` or `demo`; selects the default hosts | `live` |
| `auth_url` | authorize page | per environment |
| `token_url` | token exchange | per environment |
| `api_url` | API base for `/auth/me` | per environment |
| `timeout` | HTTP timeout in seconds | `10` |

## Register the drivers

The package does not auto-register itself. Add the listener to the application's existing
`SocialiteWasCalled` listener:

```php
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Tradovate\TradovateExtendSocialite;

protected $listen = [
    SocialiteWasCalled::class => [
        // ...
        TradovateExtendSocialite::class,
    ],
];
```

Or, where the app listens inline:

```php
$event->extendSocialite('tradovate', \SocialiteProviders\Tradovate\Provider::class);
$event->extendSocialite('tradovate-demo', \SocialiteProviders\Tradovate\Provider::class);
```

## Usage

Connect (redirect the user to Tradovate's own authorization screen):

```php
use Laravel\Socialite\Facades\Socialite;

$state = Str::random(32);

Cache::put("nt:oauth:{$state}", [
    'user_id' => $request->user()->getKey(),
    'environment' => 'demo',
], now()->addMinutes(10));

return Socialite::driver('tradovate-demo')
    ->stateless()
    ->with(['state' => $state])
    ->redirect();
```

Callback:

```php
$entry = Cache::pull('nt:oauth:'.(string) $request->input('state'));

// Refuse unless the entry is present, unexpired, and belongs to this user.
// Then exchange the code and read the identity:

$user = Socialite::driver('tradovate-demo')->stateless()->user();

$user->token;      // access token — hand it to your own custodian, never store it in the database
$user->expiresIn;  // seconds
$user->getId();    // Tradovate userId, as a string
$user->getName();
$user->getEmail(); // may be null
```

The driver is stateless, so **the caller owns the state**: mint it, bind it to the authenticated
user and the environment, store it server-side, and compare it on the callback before calling
`user()`. A missing, expired, replayed or cross-user state must be refused before any HTTP call.

## What the provider does and does not do

- Sends `response_type=code`, `client_id`, `redirect_uri` and your `state`. It deliberately removes
  `scope`, which Tradovate does not publish for this grant.
- Exchanges the code with an `application/x-www-form-urlencoded` POST carrying exactly `grant_type`,
  `client_id`, `client_secret`, `code` and `redirect_uri`.
- Reads `/auth/me` with the bearer token and maps `userId` → `id` (string), `fullName`/`name`,
  `email` (nullable), with `nickname` and `avatar` null.
- Issues **no refresh token** — Tradovate documents `access_token` + `expires_in` only. Expiry means
  the user re-links; renewal is a connector-side concern, not this provider's.
- Does not create users, log users in, or persist anything.

## Errors

Failures throw `SocialiteProviders\Tradovate\TradovateOAuthException`, whose `errorCode` is either
Tradovate's own `error` value or one of:

| Code | Meaning |
| --- | --- |
| `token_exchange_unreachable` | the token host could not be reached |
| `token_exchange_failed` | the token host answered non-2xx |
| `token_missing` | the body carried no non-empty `access_token` |
| `identity_failed` | `/auth/me` answered non-2xx, unreachable, or malformed |

Exception messages carry an error code and an HTTP status only — never a token, a request body or a
response body. Do not log the response either.

## Testing

```bash
composer install
vendor/bin/phpunit
```

The suite uses Orchestra Testbench and `Http::fake()`; it covers the authorize URL for both
environments, the form-encoded exchange, both token-failure shapes, identity mapping, a non-2xx
identity, relative-callback resolution and driver registration.

## License

MIT. See [LICENSE](LICENSE).
