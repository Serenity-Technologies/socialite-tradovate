<?php

namespace SocialiteProviders\Tradovate\Tests;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Orchestra\Testbench\TestCase;
use SocialiteProviders\Manager\Config;
use SocialiteProviders\Manager\ServiceProvider as SocialiteProvidersServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Tradovate\Provider;
use SocialiteProviders\Tradovate\TradovateExtendSocialite;
use SocialiteProviders\Tradovate\TradovateOAuthException;

class ProviderTest extends TestCase
{
    private const REDIRECT = 'https://fxcompute.test/dashboard/copier/ninjatrader/callback';

    private const TOKEN_URL = 'https://live-api-d.tradovate.com/auth/oauthtoken';

    private const IDENTITY_URL = 'https://demo.tradovateapi.com/v1/auth/me';

    protected function getPackageProviders($app)
    {
        return [SocialiteProvidersServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app)
    {
        /** @var ConfigRepository $config */
        $config = $app->make('config');

        $config->set('app.url', 'https://fxcompute.test');

        $config->set('services.tradovate', [
            'client_id' => 'live-client-id',
            'client_secret' => 'live-client-secret',
            'redirect' => '/dashboard/copier/ninjatrader/callback',
            'environment' => 'live',
            'timeout' => 5,
        ]);

        $config->set('services.tradovate-demo', [
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'redirect' => '/dashboard/copier/ninjatrader/callback',
            'environment' => 'demo',
            'timeout' => 5,
        ]);
    }

    public function test_builds_the_demo_authorize_url_with_response_type_client_id_redirect_uri_and_state_and_no_scope(): void
    {
        $url = $this->provider()->stateless()->with(['state' => 'state-abc'])->redirect()->getTargetUrl();

        $this->assertStringStartsWith('https://trader.devel.ninjatrader.dev/oauth?', $url);

        $query = $this->queryFrom($url);

        $this->assertSame('code', $query['response_type']);
        $this->assertSame('client-id', $query['client_id']);
        $this->assertSame(self::REDIRECT, $query['redirect_uri']);
        $this->assertSame('state-abc', $query['state']);
        $this->assertArrayNotHasKey('scope', $query);
    }

    public function test_builds_the_live_authorize_url_for_the_live_driver(): void
    {
        $url = $this->provider(['environment' => 'live'])->stateless()->with(['state' => 'state-abc'])->redirect()->getTargetUrl();

        $this->assertStringStartsWith('https://trader.tradovate.com/oauth?', $url);
        $this->assertSame('live', $this->provider(['environment' => 'live'])->environment());
    }

    public function test_exchanges_the_code_form_encoded_against_the_documented_token_host(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'nt-token-abc', 'expires_in' => 4800]),
        ]);

        $body = $this->provider()->getAccessTokenResponse('abc');

        $this->assertSame('nt-token-abc', $body['access_token']);

        Http::assertSent(function (ClientRequest $request) {
            return $request->url() === self::TOKEN_URL
                && $request->method() === 'POST'
                && $request->isForm()
                && $request->data() === [
                    'grant_type' => 'authorization_code',
                    'client_id' => 'client-id',
                    'client_secret' => 'client-secret',
                    'code' => 'abc',
                    'redirect_uri' => self::REDIRECT,
                ];
        });
    }

    public function test_throws_the_providers_error_code_from_the_token_body(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'The authorization code is invalid or expired.',
            ]),
        ]);

        try {
            $this->provider()->getAccessTokenResponse('abc');
            $this->fail('The provider accepted a token response carrying an error.');
        } catch (TradovateOAuthException $e) {
            $this->assertSame('invalid_grant', $e->errorCode);
        }
    }

    public function test_throws_token_missing_instead_of_returning_a_body_without_a_token(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['expires_in' => 4800]),
        ]);

        try {
            $this->provider()->getAccessTokenResponse('abc');
            $this->fail('The provider accepted a token response with no access token.');
        } catch (TradovateOAuthException $e) {
            $this->assertSame('token_missing', $e->errorCode);
            $this->assertStringNotContainsString('nt-token', $e->getMessage());
        }
    }

    public function test_a_non_2xx_token_response_is_typed(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['message' => 'Internal error'], 500),
        ]);

        try {
            $this->provider()->getAccessTokenResponse('abc');
            $this->fail('The provider accepted a failed token response.');
        } catch (TradovateOAuthException $e) {
            $this->assertSame('token_exchange_failed', $e->errorCode);
            $this->assertStringContainsString('500', $e->getMessage());
        }
    }

    public function test_a_token_endpoint_connection_failure_is_typed(): void
    {
        Http::fake([
            self::TOKEN_URL => fn () => throw new ConnectionException('Connection refused'),
        ]);

        try {
            $this->provider()->getAccessTokenResponse('abc');
            $this->fail('The provider accepted an unreachable token endpoint.');
        } catch (TradovateOAuthException $e) {
            $this->assertSame('token_exchange_unreachable', $e->errorCode);
        }
    }

    public function test_a_malformed_token_json_body_is_typed(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response('{"access_token":', 200, ['Content-Type' => 'application/json']),
        ]);

        try {
            $this->provider()->getAccessTokenResponse('abc');
            $this->fail('The provider accepted a malformed token body.');
        } catch (TradovateOAuthException $e) {
            $this->assertSame('token_missing', $e->errorCode);
        }
    }

    public function test_an_identity_endpoint_connection_failure_is_typed(): void
    {
        Http::fake([
            self::IDENTITY_URL => fn () => throw new ConnectionException('Connection refused'),
        ]);

        try {
            $this->provider()->userFromToken('nt-token-abc');
            $this->fail('The provider accepted an unreachable identity endpoint.');
        } catch (TradovateOAuthException $e) {
            $this->assertSame('identity_failed', $e->errorCode);
            $this->assertStringNotContainsString('nt-token-abc', $e->getMessage());
        }
    }

    public function test_a_malformed_identity_json_body_is_typed(): void
    {
        Http::fake([
            self::IDENTITY_URL => Http::response('<html>gateway</html>', 200, ['Content-Type' => 'text/html']),
        ]);

        try {
            $this->provider()->userFromToken('nt-token-abc');
            $this->fail('The provider accepted a malformed identity body.');
        } catch (TradovateOAuthException $e) {
            $this->assertSame('identity_failed', $e->errorCode);
        }
    }

    public function test_maps_auth_me_onto_a_socialite_user(): void
    {
        Http::fake([
            self::IDENTITY_URL => Http::response([
                'userId' => 4242,
                'fullName' => 'Demo Trader',
                'email' => 'demo@example.test',
            ]),
        ]);

        $user = $this->provider()->userFromToken('nt-token-abc');

        $this->assertSame('4242', $user->getId());
        $this->assertSame('Demo Trader', $user->getName());
        $this->assertSame('demo@example.test', $user->getEmail());
        $this->assertNull($user->refreshToken);

        Http::assertSent(fn (ClientRequest $request) => $request->url() === self::IDENTITY_URL
            && $request->hasHeader('Authorization', 'Bearer nt-token-abc'));
    }

    public function test_surfaces_a_non_2xx_identity_response_as_a_typed_failure(): void
    {
        Http::fake([
            self::IDENTITY_URL => Http::response(['errorText' => 'Unauthorized'], 401),
        ]);

        try {
            $this->provider()->userFromToken('nt-token-abc');
            $this->fail('The provider accepted a failed identity response.');
        } catch (TradovateOAuthException $e) {
            $this->assertSame('identity_failed', $e->errorCode);
            $this->assertStringContainsString('401', $e->getMessage());
            $this->assertStringNotContainsString('nt-token-abc', $e->getMessage());
        }
    }

    public function test_the_authorize_url_carries_the_absolute_callback_when_the_configured_redirect_is_relative(): void
    {
        /** @var UrlGenerator $urlGenerator */
        $urlGenerator = $this->application()->make('url');
        $urlGenerator->forceRootUrl('https://fxcompute.test');
        $urlGenerator->forceScheme('https');

        $provider = $this->provider([], '/dashboard/copier/ninjatrader/callback');

        $url = $provider->stateless()->with(['state' => 'state-abc'])->redirect()->getTargetUrl();

        $this->assertSame(
            'https://fxcompute.test/dashboard/copier/ninjatrader/callback',
            $this->queryFrom($url)['redirect_uri'],
        );
    }

    public function test_user_runs_the_exchange_then_reads_the_identity(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'nt-token-abc', 'expires_in' => 4800]),
            self::IDENTITY_URL => Http::response([
                'userId' => 4242,
                'fullName' => 'Demo Trader',
                'email' => 'demo@example.test',
            ]),
        ]);

        $this->request()->merge(['code' => 'abc']);

        $user = $this->provider()->stateless()->user();

        $this->assertSame('4242', $user->getId());
        $this->assertSame('nt-token-abc', $user->token);
        $this->assertSame(4800, $user->expiresIn);
        $this->assertNull($user->refreshToken);
    }

    public function test_the_listener_registers_both_drivers_with_their_configured_environment(): void
    {
        /** @var SocialiteWasCalled $event */
        $event = $this->application()->make(SocialiteWasCalled::class);

        (new TradovateExtendSocialite)->handle($event);

        $demo = Socialite::driver('tradovate-demo');
        $live = Socialite::driver('tradovate');

        $this->assertInstanceOf(Provider::class, $demo);
        $this->assertInstanceOf(Provider::class, $live);
        $this->assertSame('demo', $demo->environment());
        $this->assertSame('live', $live->environment());
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function provider(array $config = [], ?string $redirect = null): Provider
    {
        $redirect ??= self::REDIRECT;

        $provider = new Provider($this->request(), 'client-id', 'client-secret', $redirect);
        $provider->setConfig(new Config('client-id', 'client-secret', $redirect, array_merge([
            'environment' => 'demo',
            'timeout' => 5,
        ], $config)));

        return $provider;
    }

    /**
     * @return array<string, mixed>
     */
    private function queryFrom(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        /** @var array<string, mixed> $query */
        return $query;
    }

    private function application(): Application
    {
        $app = $this->app;

        if (! $app instanceof Application) {
            $this->fail('The test application is not booted.');
        }

        return $app;
    }

    private function request(): Request
    {
        /** @var Request $request */
        $request = $this->application()['request'];

        return $request;
    }
}
