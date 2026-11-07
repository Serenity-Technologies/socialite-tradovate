# Copier: Tradovate OAuth — `socialite-tradovate` package plan

**Status:** 2026-10-24. **Decisions resolved** (see §3): the provider ships as a Composer package,
the PHP app mints the token and the connector custodies it. Written to be executed by an
implementing agent with no further research.

This is the PHP half of the OAuth flow in
[`copier-ninjatrader-demo-follower-slice-plan.md`](copier-ninjatrader-demo-follower-slice-plan.md) §4
(it supersedes that section's *code exchange* only — see D3) and the credential step that
[`copier-ninjatrader-tradovate-integration-plan.md`](copier-ninjatrader-tradovate-integration-plan.md)
S3A assumes. It does **not** build a connector, a command queue, or a sign-in provider.

If a probe on Demo contradicts a fact stated here, update this file with the observation and the
date — do not improvise around it in code.

---

## 0. Is there a provider to copy? (answer first)

**No Laravel Socialite Tradovate provider exists to install.** Packagist and GitHub were searched
on 2026-10-24: no `socialiteproviders/*` entry for Tradovate, and no `league/oauth2-*` provider for
it in the results. This package is new code, modelled on the sibling in-house package.

What does exist, and what to use:

| Source | What it gives | Use it for |
| --- | --- | --- |
| [`tradovate/example-api-oauth`](https://github.com/tradovate/example-api-oauth) | The only first-party walkthrough: authorize URL shape, form-encoded exchange, `/auth/me` | The **protocol facts**. It is Node/Express, not Laravel; port the three steps, not the code. It is also old — its Demo host and `live-api-d.../auth/me` call must be re-probed (§8). |
| `serenity_technologies/socialite-ctrader` (installed; source at `vendor/serenity_technologies/socialite-ctrader/`) | The sibling package: `composer.json` shape, `src/Provider.php`, `src/CtraderExtendSocialite.php`, PHPUnit tests, README/CHANGELOG | The **package template**. The new repository should be indistinguishable from it in layout and release process. |
| `socialiteproviders/manager` (already a dependency) | `SocialiteWasCalled`, per-driver config retrieval | Registration. This repo registers `apple`, `ctrader` and `microsoft` this way (`app/Providers/AppServiceProvider.php` ~L126). |
| [`docs/analysis/tradovate/ninjatrader-api-research.md`](../analysis/tradovate/ninjatrader-api-research.md) §3.2, [`tradovate-api-technical.md`](../analysis/tradovate/tradovate-api-technical.md) §1.1 | Cited endpoints and the spec's `auth` segment | The authoritative in-repo statement of the flow. |

The two things the official sample gets right and that the provider must reproduce exactly:
`response_type=code` + `client_id` + `redirect_uri` on the authorize URL, and an
`application/x-www-form-urlencoded` POST with `grant_type`, `client_id`, `client_secret`, `code`,
`redirect_uri` to the token endpoint.

---

## 1. Deliverables, in two phases

**Phase 1 — the package repository `serenity_technologies/socialite-tradovate`.** A standalone
Laravel Socialite provider with its own tests, README, CHANGELOG and tag. This is the deliverable the
decision "I want a Laravel package" names; it has no dependency on this application.

**Phase 2 — this application consumes it.** `composer require`, the `services.tradovate*` blocks,
the link controller and custodian, the two flags, feature tests, and a two-line registration in the
existing `SocialiteWasCalled` listener. Nothing from Phase 2 goes into the package, and nothing from
the package depends on `app/`.

| In | Out (explicitly) |
| --- | --- |
| Package: provider, extend-listener, PHPUnit tests, README, tag `v0.1.0` | Any migration, `nt_accounts`, `nt_commands`, `nt_instruments` |
| App: config, link controller, custodian contract, flags, Pest tests | The Go connector, its `/link` implementation |
| Demo + live drivers, live refused by default | Account selection UI, `/account/list`, account rows |
| Token handoff to the connector over loopback | Copying, dispatch, symbol mapping, contract size |
| Probe checklist for the unverified hosts/fields (§8) | Sign-in / registration with a Tradovate identity |

**Not a sign-in provider.** A Tradovate authorization links a brokerage account to an
already-authenticated FXCompute user. It must never create a user, log a user in, or write
`tradovate_id`. That is why Phase 2 adds a controller instead of extending
`App\Http\Controllers\Auth\SocialiteController` — that controller's `$idColumn = "{$provider}_id"`,
find-by-email and create-user branches are for identity providers and are wrong here in a way that
would be a security bug, not a style choice.

---

## 2. What already exists (re-verified in the tree)

- `laravel/socialite ^5.27`, `socialiteproviders/manager ^4.9` — both installed.
- The app's Composer repositories already include the private GitLab group that serves
  `serenity_technologies/*` (`composer.json` `repositories`), so the new package needs no client-side
  repository entry once it is published there.
- `serenity_technologies/socialite-ctrader` `composer.json`: `type: library`, MIT, PHP
  `^8.1|^8.2|^8.3|^8.4`, `laravel/socialite ^5.0`, `socialiteproviders/manager ^4.0`, dev
  `mockery/mockery ^1.6` + `phpunit/phpunit ^10.0`, PSR-4 `SocialiteProviders\Ctrader\`. The new
  package mirrors this, with `orchestra/testbench` added because it needs Laravel's HTTP fake.
- `SocialiteProviders\Manager\SocialiteWasCalled::getConfig()` resolves `services.<driver name>` and
  merges the provider's `additionalConfigKeys()` — **config is per driver, not per class**, so one
  provider class serves `tradovate` and `tradovate-demo` with different hosts. Confirmed in
  `vendor/socialiteproviders/manager/src/SocialiteWasCalled.php`.
- A relative `redirect` is resolved to an absolute URL by `SocialiteProviders\Manager\Config`
  (`formatRedirectUri()` → `URL::to()`), then installed by `setConfig()` — so the app's usual relative
  value is correct, matching the other providers in `config/services.php`.
- `Laravel\Socialite\Two\AbstractProvider::getAccessTokenResponse()` already POSTs `FORM_PARAMS`
  built by `getTokenFields()`, and `getTokenFields()` already carries exactly Tradovate's five
  fields. `user()` maps `access_token`, `refresh_token`, `expires_in` onto the `User`. There is
  **no** documented refresh token, so `refreshToken` is `null`.
- `app/Contracts/TradeCopier/` exists (`ClosingDealSource`), so the custodian interface belongs there.
- Routes: the authenticated dashboard group is
  `Route::middleware(['auth','verified'])->prefix('/dashboard')`, and the copier sub-group is
  `Route::prefix('/copier')->name('dashboard.copier.')` (routes/web.php ~L198).
- Tests run on sqlite `:memory:` with `SESSION_DRIVER=array` (`phpunit.xml`).
- `lang/en/dashboard.php` + `lang/fr/dashboard.php` are parity-checked by `php artisan lang:verify`
  (and `TranslationKeyCoverageTest`), so every new key goes in **both** files.

---

## 3. Decisions (resolved 2026-10-24)

**D1 — The provider is a Composer package, not an app class.** Repository
`serenity_technologies/socialite-tradovate`, namespace `SocialiteProviders\Tradovate`, published to
the same private GitLab Composer group as `socialite-ctrader`, versioned `^0.1`. The app consumes it
in Phase 2. There is no `packages/` directory in this repository and none is added.

**D2 — One class, two drivers: `tradovate` (live) and `tradovate-demo`.** The authorize, token and
identity hosts differ, and the manager keys config by driver name. Two drivers also make the
environment explicit at the call site instead of a runtime branch, and let the app register the live
one without ever calling it.

**D3 — PHP mints the token; the connector custodies it. Decided, and it amends the slice plan's
§4.1/D2.** The app performs the authorization-code exchange with Socialite, so the OAuth
`client_secret` stays in Laravel config and never enters the Go process. The connector receives only
the short-lived access token over loopback and remains the sole custodian and reader/writer: Redis,
AES-256-GCM, `nt:token:{environment}:{account}`. The database still never stores the token.
The slice plan is updated to match this document; §9 records the rejected alternative.

**D4 — State is a cache entry, and the driver is stateless.** This keeps the slice plan's D3: the
controller generates 32 random bytes, `Cache::put("nt:oauth:{state}", ['user_id', 'environment'],
now()->addMinutes(config('copier.ninjatrader.link_ttl_minutes')))`, and passes the value through
`->with(['state' => $state])->stateless()->redirect()`. The callback `Cache::pull`s the key, requires
the authenticated user id and the environment to match, and only then calls `user()`. Single-use,
10-minute TTL, bound to the user — and it survives the plugin/popup case where the external browser
has a different session, which is why ctrader is stateless too. No PKCE (not documented).

**D5 — No refresh token, and this plan does not invent one.** The OAuth response documents
`access_token` + `expires_in` only. `renewAccessToken` is a separate endpoint and whether it accepts
an OAuth token is **unprobed**; that probe and any renewal loop belong to the connector slice. Here,
expiry is `now()->addSeconds($user->expiresIn)` handed to the custodian, and the UI's remedy is
re-link.

**D6 — A dedicated link controller in the app, never `SocialiteController`.** See §1.

**D7 — Token-handling rules, enforced in review and tests:** never in the database; never in the
session, a flash message, a redirect query, a job payload, or a log line; never echoed in an error
page; never included in an exception's message beyond the provider's own `error` code and an HTTP
status. The token exists in memory between `$driver->user()` and `$custodian->link()` and nowhere
else.

**D8 — Two switches, both closed by default.** `copier.ninjatrader.enabled` gates the app's routes
(404 when off). `copier.ninjatrader.allow_live` gates the live connect (403 when off). The live
driver is still registered so the refusal is ours, not a missing-provider exception.

**D9 — The package does not auto-register itself.** Like `socialite-ctrader`, it ships an
`TradovateExtendSocialite` listener class and a README that shows the `SocialiteWasCalled` entry; the
app adds two lines to the listener it already has. No service provider, no `extra.laravel.providers`
magic — one registration mechanism, visible in the app.

---

## 4. Phase 1 — the package

### 4.1 Layout

```
socialite-tradovate/
├── composer.json
├── phpunit.xml
├── README.md
├── CHANGELOG.md
├── LICENSE
├── .gitignore
├── src/
│   ├── Provider.php
│   ├── TradovateExtendSocialite.php
│   └── TradovateOAuthException.php
└── tests/
    └── ProviderTest.php
```

### 4.2 `composer.json`

```json
{
    "name": "serenity_technologies/socialite-tradovate",
    "description": "Tradovate Socialite Provider for Laravel Socialite.",
    "type": "library",
    "license": "MIT",
    "authors": [{ "name": "Kwadwo Kyeremeh", "email": "kyerematics@gmail.com" }],
    "require": {
        "php": "^8.2",
        "laravel/socialite": "^5.0",
        "socialiteproviders/manager": "^4.0"
    },
    "require-dev": {
        "orchestra/testbench": "^9.0|^10.0",
        "phpunit/phpunit": "^10.5|^11.0"
    },
    "autoload": {
        "psr-4": { "SocialiteProviders\\Tradovate\\": "src/" }
    },
    "autoload-dev": {
        "psr-4": { "SocialiteProviders\\Tradovate\\Tests\\": "tests/" }
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
```

`phpunit.xml` mirrors `socialite-ctrader`'s: bootstrap `vendor/autoload.php`, one `Unit` suite over
`./tests`. Testbench is the one deliberate addition to the sibling's shape — the provider calls
`Http`, and `Http::fake()` needs a Laravel application.

### 4.3 `src/Provider.php`

```php
namespace SocialiteProviders\Tradovate;

class Provider extends AbstractProvider implements ProviderInterface
{
    use ConfigTrait;

    public static function additionalConfigKeys(): array
    {
        return ['environment', 'auth_url', 'token_url', 'api_url', 'timeout'];
    }
}
```

**Endpoints.** Defaults come from the driver's `environment` key; the explicit URL keys exist so a
staging host can be pointed at without a release:

| | Live (`tradovate`) | Demo (`tradovate-demo`) |
| --- | --- | --- |
| authorize | `https://trader.tradovate.com/oauth` | `https://trader.devel.ninjatrader.dev/oauth` |
| token exchange | `https://live.tradovateapi.com/auth/oauthtoken` | `https://live-api-d.tradovate.com/auth/oauthtoken` |
| identity | `https://live.tradovateapi.com/v1/auth/me` | `https://demo.tradovateapi.com/v1/auth/me` |

The Demo token host is from the OAuth page. The spec also lists `POST /auth/oauthtoken` on the API
base host, so if `live-api-d.tradovate.com` fails, try
`https://demo.tradovateapi.com/v1/auth/oauthtoken` and record which answered in §8. `/auth/me` on the
API base is the primary; the sample's `https://live-api-d.tradovate.com/auth/me` (no `/v1`) is the
fallback.

**Methods (and nothing else):**

- `getAuthUrl($state)` — `buildAuthUrlFromBase($this->getConfig('auth_url', $default), $state)`.
- `getCodeFields($state = null)` — `parent::getCodeFields($state)` then `unset($fields['scope'])`.
  Tradovate publishes no scope for this grant; `AbstractProvider` would otherwise send `scope=`.
- `getTokenUrl()` — `getConfig('token_url', $default)`.
- `getAccessTokenResponse($code)` — **override to use Laravel's HTTP client**, not the Guzzle client
  the parent uses, so `Http::fake()` covers it and a timeout can be set:
  `Http::asForm()->acceptJson()->timeout($timeout)->post($this->getTokenUrl(), $this->getTokenFields($code))`.
  - `ConnectionException` → `TradovateOAuthException('token_exchange_unreachable', …)`.
  - non-2xx → `TradovateOAuthException('token_exchange_failed', 'HTTP '.$status)`.
  - a body with `error` → `TradovateOAuthException($body['error'], $body['error_description'] ?? '')`.
  - a body without a non-empty `access_token` → `TradovateOAuthException('token_missing', …)`.
  - otherwise return the decoded array. `getTokenFields()` from the parent is already correct
    (`grant_type`, `client_id`, `client_secret`, `code`, `redirect_uri`) — do not reimplement it.
- `getUserByToken($token)` — `Http::withToken($token)->acceptJson()->timeout(...)->get($api.'/auth/me')`;
  non-2xx → `TradovateOAuthException('identity_failed', 'HTTP '.$status)`; return the decoded array.
  Do not log the response body.
- `mapUserToObject(array $user)` — `id` from `userId` cast to string, `name` from
  `fullName ?? name`, `email` from `email` (may be absent → `null`), `nickname`/`avatar` null.
  Fields confirmed in the official sample; re-confirm in §8.
- `public function environment(): string` — `(string) $this->getConfig('environment')`.
- Do **not** add `refreshToken()` or a `renewAccessToken` call.

`TradovateOAuthException` is `RuntimeException` with
`__construct(public readonly string $errorCode, string $detail = '', ?Throwable $previous = null)`.
It lives in the package so both the package tests and the app's controller catch the same class.

### 4.4 `src/TradovateExtendSocialite.php`

```php
namespace SocialiteProviders\Tradovate;

class TradovateExtendSocialite
{
    public function handle(SocialiteWasCalled $event): void
    {
        $event->extendSocialite('tradovate', Provider::class);
        $event->extendSocialite('tradovate-demo', Provider::class);
    }
}
```

### 4.5 Package tests (`tests/ProviderTest.php`)

PHPUnit + Testbench, mirroring `socialite-ctrader`'s test style but with `Http::fake()` for the two
HTTP calls. Build the provider directly:

```php
$provider = new Provider(app('request'), 'client-id', 'client-secret', 'https://fxcompute.test/dashboard/copier/ninjatrader/callback');
$provider->setConfig(new Config('client-id', 'client-secret', 'https://fxcompute.test/dashboard/copier/ninjatrader/callback', [
    'environment' => 'demo',
    'timeout' => 5,
]));
```

Cases:

1. `builds the demo authorize url with response_type, client_id, redirect_uri and the supplied state, and no scope`
   (`stateless()->with(['state' => 'state-abc'])->redirect()`, assert query keys; no `scope`).
2. `builds the live authorize url for the live driver`.
3. `exchanges the code form-encoded against the documented token host` — `Http::assertSent` on the
   URL, the five fields, and a form `Content-Type` (call `getAccessTokenResponse('abc')` directly).
4. `throws the provider's error code instead of returning a body without a token` (two cases: `error`
   in body; 200 with no `access_token`).
5. `maps /auth/me onto a socialite user` — `userFromToken('nt-token-abc')`; `getId()` is the string
   `4242`, name and email map, `getRefreshToken()` is null.
6. `surfaces a non-2xx identity response as a typed failure`.
7. `the authorize url carries the absolute callback when the configured redirect is relative` — the
   `Config` object resolves it; assert the query equals the absolute URL byte-for-byte.

Run `vendor/bin/phpunit` in the package repository.

### 4.6 Documentation and release

The README must cover: install; the two `services.tradovate*` blocks; the two-line
`SocialiteWasCalled` registration; the demo/live driver names and their hosts; usage
(`Socialite::driver('tradovate-demo')->stateless()->with(['state' => $state])->redirect()` in the
connect step, `stateless()->user()` in the callback); and the fact that no refresh token is issued.
`CHANGELOG.md` starts at `0.1.0`.

1. `composer validate`, `vendor/bin/phpunit` green, README and CHANGELOG written.
2. Tag `v0.1.0`; push to the same private GitLab group that serves `socialite-ctrader`.
3. Record the package URL in the README so the app's `composer require` line is copy-pasteable.

Local development before the tag may use a path repository in the app:

```json
{ "type": "path", "url": "../socialite-tradovate", "options": { "symlink": true } }
```

`composer require serenity_technologies/socialite-tradovate:@dev` — and remove the path repository
once the tag exists, so the app is not built against a working copy.

---

## 5. Phase 2 — the app consumes the package

### 5.1 Files

| Path | Action | Contents |
| --- | --- | --- |
| `composer.json` | edit | `serenity_technologies/socialite-tradovate: ^0.1` |
| `config/services.php` | edit | `tradovate` + `tradovate-demo` blocks |
| `config/copier.php` | edit | `ninjatrader` block: `enabled`, `allow_live`, `connector_url`, `connector_secret`, `link_ttl_minutes` |
| `app/Providers/AppServiceProvider.php` | edit | Two `extendSocialite` lines; bind the custodian in `register()` |
| `app/Services/TradeCopier/NinjaTrader/TradovateAccessToken.php` | create | Readonly DTO handed to the custodian |
| `app/Services/TradeCopier/NinjaTrader/TradovateLinkResult.php` | create | Readonly result: `accounts`, `expiresAt`, `apiHosts` |
| `app/Services/TradeCopier/NinjaTrader/TradovateConnectorUnavailable.php` | create | `RuntimeException` |
| `app/Contracts/TradeCopier/TradovateTokenCustodian.php` | create | `link(TradovateAccessToken): TradovateLinkResult` |
| `app/Services/TradeCopier/NinjaTrader/ConnectorTradovateTokenCustodian.php` | create | POST to the connector's `/link` (§5.4) |
| `app/Services/TradeCopier/NinjaTrader/NullTradovateTokenCustodian.php` | create | Refuses; the default when no connector URL is configured |
| `app/Http/Controllers/Dashboard/CopierNinjaTraderLinkController.php` | create | `connect()` + `callback()` (§5.3) |
| `routes/web.php` | edit | Two GET routes inside the `dashboard.copier` group |
| `.env.example` | edit | `TRADOVATE_*`, `NT_CONNECTOR_*`, `COPIER_NINJATRADE_*` (commented, defaults off) |
| `lang/en/dashboard.php`, `lang/fr/dashboard.php` | edit | `dashboard.ninjatrader.*` messages |
| `tests/Feature/Copier/NinjaTrader/TradovateLinkTest.php` | create | §5.5 |

No migration, no model, no factory, no Wayfinder regeneration (the only link is a plain `route()`
href; no React change is in this slice).

### 5.2 Config

`config/services.php` — relative `redirect` is correct here (see §2):

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

`config/copier.php` (the keys the slice plan's D12 already names):

```php
'ninjatrader' => [
    'enabled' => (bool) env('COPIER_NINJATRADE_ENABLED', false),
    'allow_live' => (bool) env('COPIER_NINJATRADE_ALLOW_LIVE', false),
    'connector_url' => env('NT_CONNECTOR_URL'),
    'connector_secret' => env('NT_CONNECTOR_SECRET'),
    'link_ttl_minutes' => (int) env('COPIER_NINJATRADE_LINK_TTL_MINUTES', 10),
],
```

`.env.example` adds these commented, all off/empty: `TRADOVATE_CLIENT_ID`,
`TRADOVATE_CLIENT_SECRET`, `TRADOVATE_DEMO_CLIENT_ID`, `TRADOVATE_DEMO_CLIENT_SECRET`,
`TRADOVATE_REDIRECT_URI`, `TRADOVATE_TIMEOUT`, `COPIER_NINJATRADE_ENABLED`,
`COPIER_NINJATRADE_ALLOW_LIVE`, `COPIER_NINJATRADE_LINK_TTL_MINUTES`, `NT_CONNECTOR_URL`,
`NT_CONNECTOR_SECRET`.

Registration in the existing listener:

```php
$event->extendSocialite('tradovate', \SocialiteProviders\Tradovate\Provider::class);
$event->extendSocialite('tradovate-demo', \SocialiteProviders\Tradovate\Provider::class);
```

### 5.3 Controller and routes

Inside `Route::prefix('/copier')->name('dashboard.copier.')` (so the names below are already
prefixed):

```php
Route::get('/ninjatrader/{environment}/connect', [CopierNinjaTraderLinkController::class, 'connect'])
    ->whereIn('environment', ['demo', 'live'])
    ->name('ninjatrader.connect');

Route::get('/ninjatrader/callback', [CopierNinjaTraderLinkController::class, 'callback'])
    ->name('ninjatrader.callback');
```

One callback URL for both environments (Tradovate registration is per app; a second registered URI
is not documented as allowed). The environment travels in the cache entry, so two tabs can run
two flows without colliding.

**`connect(Request $request, string $environment)`**

1. `abort_unless(config('copier.ninjatrader.enabled'), 404);`
2. `abort_unless(in_array($environment, ['demo','live'], true), 404);`
3. `abort_if($environment === 'live' && ! config('copier.ninjatrader.allow_live'), 403);`
4. `$state = Str::random(32);` then
   `Cache::put("nt:oauth:{$state}", ['user_id' => $request->user()->getKey(), 'environment' => $environment], now()->addMinutes(config('copier.ninjatrader.link_ttl_minutes', 10)));`
5. `return Socialite::driver($environment === 'live' ? 'tradovate' : 'tradovate-demo')
     ->stateless()->with(['state' => $state])->redirect();`

**`callback(Request $request, TradovateTokenCustodian $custodian)`**

1. Flag guard (404).
2. `$entry = Cache::pull('nt:oauth:'.(string) $request->input('state'));` Refuse (a redirect with
   an error, not an exception) unless `is_array($entry)`, `$entry['user_id'] === $request->user()->getKey()`,
   and `in_array($entry['environment'], ['demo','live'], true)`. A missing entry is an expired or
   replayed state — the same answer for both, so an attacker learns nothing.
3. `$driver = $entry['environment'] === 'live' ? 'tradovate' : 'tradovate-demo';` then
   `try { $user = Socialite::driver($driver)->stateless()->user(); }` — there is no
   `InvalidStateException` to catch here, because the driver is stateless and the state was already
   checked in step 2.
   - `TradovateOAuthException` → `Log::warning('Tradovate link failed', ['error' => $e->errorCode, 'environment' => $entry['environment']])`
     then the generic failure message. Do not `report()` the raw exception if it could carry a body.
   - `ConnectionException` → same generic failure.
4. Build the DTO and `$result = $custodian->link(new TradovateAccessToken(...))`.
   - `TradovateConnectorUnavailable` → "connector offline" message; **no** success flash and no
     retry; the code is spent, the user clicks connect again.
5. Success → redirect to `dashboard.copier.index` with a status naming the account count. The
   response and the session must contain no token.

**`TradovateAccessToken`** — `environment`, `#[\SensitiveParameter] string $accessToken`,
`int $expiresIn`, `?string $identityId`, `?string $displayName`, `string $redirectUri`. The
`#[\SensitiveParameter]` attribute redacts it from stack traces; the readonly class implements no
serialization interface so it cannot be serialized by accident.

**`TradovateLinkResult`** — `array $accounts` (provider account ids, `list<string>`),
`?CarbonImmutable $expiresAt`, `array $apiHosts`.

### 5.4 Custodian

```php
namespace App\Contracts\TradeCopier;

interface TradovateTokenCustodian
{
    public function link(TradovateAccessToken $token): TradovateLinkResult;
}
```

`ConnectorTradovateTokenCustodian` — `fromConfig()` factory like the other `CopierEngine*Client`s;
POST `rtrim($url,'/').'/link'` with `X-NT-Link-Secret`, `acceptJson()`, 5 s timeout, JSON body
`{environment, access_token, expires_in, identity_id, display_name, redirect_uri}`.
`throw_unless($response->successful(), TradovateConnectorUnavailable::class)`. Never log the request
or response body; on failure log only the status.

Binding in `AppServiceProvider::register()`:

```php
$this->app->bind(TradovateTokenCustodian::class, function () {
    $url = config('copier.ninjatrader.connector_url');
    $secret = config('copier.ninjatrader.connector_secret');

    return is_string($url) && $url !== '' && is_string($secret) && $secret !== ''
        ? ConnectorTradovateTokenCustodian::fromConfig()
        : new NullTradovateTokenCustodian;
});
```

`NullTradovateTokenCustodian` throws `TradovateConnectorUnavailable` — with the connector absent,
the link is refused loudly. A silent success would report a connected account that does not exist,
which is the failure mode this codebase treats as worst.

### 5.5 App feature tests (Pest)

`Http::fake()` both hosts; bind a fake custodian with `$this->mock(...)` / `app()->instance(...)`.
Fixtures: token `['access_token' => 'nt-token-abc', 'expires_in' => 4800]`, identity
`['userId' => 4242, 'fullName' => 'Demo Trader', 'email' => 'demo@example.test']`.

1. `the link surface is hidden when the flag is off` (404 with `copier.ninjatrader.enabled=false`).
2. `a guest is sent to login when the flag is on` (302 to the login route — the `auth` middleware,
   not the flag, decides).
3. `a live link is refused while allow_live is false` (403, no authorize redirect).
4. `connect redirects to the demo oauth page with a cache-backed state` —
   `assertRedirectContains('trader.devel.ninjatrader.dev/oauth')`; parse `state` from the URL and
   assert `Cache::get("nt:oauth:{$state}")` carries the user id and `demo`.
5. `callback refuses an unknown or expired state` (call with a random state; assert the error
   redirect and `Http::assertNothingSent()`; a second case travels 11 minutes first).
6. `callback refuses a state minted for another user` — connect as A, call back as B; refused, no
   exchange, custodian not invoked.
7. `callback refuses a replayed state` — complete once, call again; second is refused, custodian
   invoked once.
8. `callback hands the token and expiry to the custodian` — assert the DTO's `accessToken`,
   `expiresIn`, `environment`, `identityId`; assert the success redirect.
9. `callback turns a token error into an error redirect and does not call the custodian`.
10. `callback reports the connector as offline rather than claiming success`
    (`NullTradovateTokenCustodian` or a fake that throws).
11. `the token never reaches the response, the session or the log` — `Log::spy()`, assert no log call
    and no `nt-token-abc` substring in `$response->getContent()` or `session()->all()`.
12. `the callback creates no user and touches no user row` — `assertDatabaseCount('users', 1)` with one
    acting user; `$user->refresh()` is unchanged.

Run: `php artisan test --compact --filter=Tradovate` then `vendor/bin/pint --dirty --format agent`.

---

## 6. Demo probe checklist (a human, real credentials, once)

Record the answer and the date in this section when it is run. Do not hard-code a value a probe has
not confirmed.

1. Authorize URL accepts the request **without** `scope` and with the registered redirect URI.
2. Which token host answered: `live-api-d.tradovate.com/auth/oauthtoken` or
   `demo.tradovateapi.com/v1/auth/oauthtoken`.
3. Which identity host answered: `demo.tradovateapi.com/v1/auth/me` or
   `live-api-d.tradovate.com/auth/me`, and which fields came back (`userId`, `fullName`, `email`,
   `emailVerified`, `isTrial`).
4. The real `expires_in` value, and whether the token response carries `apiHosts`.
5. Whether `GET /auth/renewaccesstoken` accepts an OAuth `access_token` (this decides the connector's
   renewal loop; a negative answer makes re-link the only renewal).
6. Whether the same `client_id` works for both environments or each is issued separately.

---

## 7. Rejected alternatives (recorded, not to be revisited)

**A. The connector exchanges the code.** Rejected 2026-10-24 because it requires the OAuth
`client_secret` in the Go process, i.e. a second copy of an application secret. If that trade is ever
reconsidered, the app would forward `{code, environment, redirect_uri}` and the connector would also
need `client_id`/`client_secret`; the provider's exchange and identity methods would become dead
code.

**B. An in-app provider class.** Rejected: the OAuth provider is reusable and the repository already
ships a sibling Socialite package; an app class would be a second, divergent copy of the same
protocol.

**C. A `tradovate_id` column and sign-in.** Rejected: authorizing a brokerage account is not an
identity, and the generic `SocialiteController` would create or log in accounts by email.

---

## 8. Acceptance

**Phase 1 (package)**

- [ ] `composer validate` and `vendor/bin/phpunit` green in the package repository.
- [ ] `Socialite::driver('tradovate')` and `Socialite::driver('tradovate-demo')` return
      `SocialiteProviders\Tradovate\Provider` with the right `environment()` once the listener is
      registered by a consumer.
- [ ] The Demo authorize URL is
      `https://trader.devel.ninjatrader.dev/oauth?client_id=…&redirect_uri=…&response_type=code&state=…`
      with no `scope`.
- [ ] The exchange is a form-encoded POST to the documented host with the five documented fields.
- [ ] `v0.1.0` tagged and pushed to the group the app already points at.

**Phase 2 (app)**

- [ ] With the flag off every link route is a 404; with `allow_live=false` the live connect is a 403.
- [ ] A completed callback calls the custodian exactly once with the token, the identity id and the
      expiry, and returns a success redirect that contains no token.
- [ ] No token in the database, session, logs, response body, or exception text (test 11).
- [ ] A state minted for another user, replayed, or older than the TTL is refused before any HTTP
      call.
- [ ] With no connector configured the link is refused, never reported as success.
- [ ] `php artisan test --compact --filter=Tradovate` green; `vendor/bin/pint --dirty --format agent`
      clean; `php artisan lang:verify` green if messages were added.

## 9. Do not

- Do not add a migration, a model, a factory, a `tradovate_id` column, or a `packages/` directory.
- Do not reuse `SocialiteController` or add `tradovate` to its `{provider}` route constraint.
- Do not store, cache, flash or log the token; do not put it in a redirect.
- Do not add PKCE, a `scope`, or a refresh-token flow.
- Do not retry the exchange or the custodian call inside the request.
- Do not register the driver as a sign-in option anywhere in the UI.
- Do not have the connector call the token endpoint.

---

## 10. References

- [OAuth integration (NinjaTrader docs)](https://docs.ninjatrader.com/api/oauth) — prerequisites, hosts, the "acts on behalf of other users" wording.
- [`tradovate/example-api-oauth`](https://github.com/tradovate/example-api-oauth) — the three-step walkthrough, `/auth/me` fields.
- [`docs/analysis/tradovate/ninjatrader-api-research.md`](../analysis/tradovate/ninjatrader-api-research.md) §3.2.
- [`docs/analysis/tradovate/tradovate-api-technical.md`](../analysis/tradovate/tradovate-api-technical.md) §1.1 (`auth` segment).
- [`docs/analysis/tradovate/tradovate-entitlements-legal.md`](../analysis/tradovate/tradovate-entitlements-legal.md) §1.4.
- [`docs/implementation/copier-ninjatrader-demo-follower-slice-plan.md`](copier-ninjatrader-demo-follower-slice-plan.md) §4.
