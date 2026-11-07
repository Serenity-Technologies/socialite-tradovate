<?php

namespace SocialiteProviders\Tradovate;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SocialiteProviders\Manager\OAuth2\AbstractProvider;
use SocialiteProviders\Manager\OAuth2\User;

/**
 * Tradovate (NinjaTrader Trade API) OAuth 2 authorization-code provider.
 *
 * One class serves two drivers, `tradovate` (live) and `tradovate-demo`, because
 * the manager keys configuration by driver name and the hosts differ per
 * environment. There is no documented refresh token and no documented scope:
 * `getCodeFields()` removes `scope`, and no `refreshToken()` path is offered.
 *
 * The token endpoint is called with Laravel's HTTP client (not the parent's
 * Guzzle client) so it can be faked in tests and given a timeout.
 */
class Provider extends AbstractProvider
{
    /**
     * The hosts Tradovate documents for each environment.
     *
     * @var array<string, array<string, string>>
     */
    protected const DEFAULTS = [
        'live' => [
            'auth_url' => 'https://trader.tradovate.com/oauth',
            'token_url' => 'https://live.tradovateapi.com/auth/oauthtoken',
            'api_url' => 'https://live.tradovateapi.com/v1',
        ],
        'demo' => [
            'auth_url' => 'https://trader.devel.ninjatrader.dev/oauth',
            'token_url' => 'https://live-api-d.tradovate.com/auth/oauthtoken',
            'api_url' => 'https://demo.tradovateapi.com/v1',
        ],
    ];

    protected const DEFAULT_ENVIRONMENT = 'live';

    protected const DEFAULT_TIMEOUT = 10;

    /**
     * The config keys this provider reads beyond the manager's defaults.
     *
     * @return array<int, string>
     */
    public static function additionalConfigKeys()
    {
        return ['environment', 'auth_url', 'token_url', 'api_url', 'timeout'];
    }

    protected function getAuthUrl($state)
    {
        return $this->buildAuthUrlFromBase($this->endpoint('auth_url'), $state);
    }

    /**
     * Tradovate publishes no scope for this grant, so `scope` must not be sent.
     *
     * @param  string|null  $state
     * @return array<string, mixed>
     */
    protected function getCodeFields($state = null)
    {
        /** @var array<string, mixed> $fields */
        $fields = parent::getCodeFields($state);

        unset($fields['scope']);

        return $fields;
    }

    protected function getTokenUrl()
    {
        return $this->endpoint('token_url');
    }

    /**
     * Exchange the authorization code, form-encoded, against the token host.
     *
     * The five fields come from the parent's `getTokenFields()`: `grant_type`,
     * `client_id`, `client_secret`, `code`, `redirect_uri`.
     *
     * @param  string  $code
     * @return array<string, mixed>
     *
     * @throws TradovateOAuthException
     */
    public function getAccessTokenResponse($code)
    {
        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout($this->timeout())
                ->post($this->getTokenUrl(), $this->getTokenFields($code));
        } catch (ConnectionException $e) {
            throw new TradovateOAuthException('token_exchange_unreachable', 'The token endpoint could not be reached.', $e);
        }

        if (! $response->successful()) {
            throw new TradovateOAuthException('token_exchange_failed', 'HTTP '.$response->status());
        }

        /** @var array<string, mixed>|null $body */
        $body = $response->json();

        if (! is_array($body)) {
            throw new TradovateOAuthException('token_missing', 'The token response was not a JSON object.');
        }

        $error = $body['error'] ?? null;

        if (is_string($error) && $error !== '') {
            $description = $body['error_description'] ?? null;

            throw new TradovateOAuthException($error, is_string($description) ? $description : '');
        }

        $accessToken = $body['access_token'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            throw new TradovateOAuthException('token_missing', 'The token response did not contain an access token.');
        }

        return $body;
    }

    /**
     * Read the authorized identity from `/auth/me`.
     *
     * @param  string  $token
     * @return array<string, mixed>
     *
     * @throws TradovateOAuthException
     */
    protected function getUserByToken($token)
    {
        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout($this->timeout())
                ->get($this->endpoint('api_url').'/auth/me');
        } catch (ConnectionException $e) {
            throw new TradovateOAuthException('identity_failed', 'unreachable', $e);
        }

        if (! $response->successful()) {
            throw new TradovateOAuthException('identity_failed', 'HTTP '.$response->status());
        }

        /** @var array<string, mixed>|null $body */
        $body = $response->json();

        if (! is_array($body)) {
            throw new TradovateOAuthException('identity_failed', 'The identity response was not a JSON object.');
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $user
     */
    protected function mapUserToObject(array $user)
    {
        $id = $user['userId'] ?? null;
        $id = is_int($id) || is_string($id) ? (string) $id : null;

        return (new User)->setRaw($user)->map([
            'id' => $id,
            'nickname' => null,
            'name' => $user['fullName'] ?? $user['name'] ?? null,
            'email' => $user['email'] ?? null,
            'avatar' => null,
        ]);
    }

    /**
     * The environment this driver is configured for: `live` or `demo`.
     */
    public function environment(): string
    {
        $environment = strtolower(trim($this->stringConfig('environment') ?? ''));

        return array_key_exists($environment, self::DEFAULTS) ? $environment : self::DEFAULT_ENVIRONMENT;
    }

    /**
     * An explicitly configured URL wins; otherwise the environment's documented host.
     */
    protected function endpoint(string $key): string
    {
        return $this->stringConfig($key) ?? self::DEFAULTS[$this->environment()][$key];
    }

    protected function timeout(): int
    {
        $configured = $this->getConfig('timeout', self::DEFAULT_TIMEOUT);
        $timeout = is_numeric($configured) ? (int) $configured : 0;

        return $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT;
    }

    /**
     * Read a config value that must be a non-empty string.
     */
    protected function stringConfig(string $key): ?string
    {
        $value = $this->getConfig($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
