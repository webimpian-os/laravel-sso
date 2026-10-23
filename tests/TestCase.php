<?php

namespace Webimpian\Sso\Tests;

use Firebase\JWT\JWT;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use Webimpian\Sso\Sso;
use Webimpian\Sso\SsoServiceProvider;

abstract class TestCase extends Orchestra
{
    protected const ISSUER = 'https://portal.test';

    private static array $keys = [];

    protected array $portalKeys = ['key-1'];

    protected ?string $nextToken = null;

    protected bool $portalDown = false;

    protected function getPackageProviders($app): array
    {
        return [SsoServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('sso.issuer', self::ISSUER);
        $app['config']->set('sso.client_id', 'app-client');
        $app['config']->set('sso.client_secret', 'app-secret');
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('workos_id')->nullable();
            $table->string('role')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        (require __DIR__.'/../database/migrations/2026_10_23_000000_add_sso_columns_to_users_table.php')->up();
    }

    protected function defineRoutes($router): void
    {
        $router->middleware(['web', 'auth'])->get('dashboard', fn () => 'dashboard');
        $router->middleware(['web', 'auth'])->post('notes', fn () => 'saved');
        $router->get('login', fn () => redirect()->route('sso.login'))->name('login');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->fakePortal();
    }

    protected function tearDown(): void
    {
        Sso::resolveUsersUsing(null);
        Sso::createUsersUsing(null);
        Sso::syncRolesUsing(null);

        parent::tearDown();
    }

    // Answers like the portal; the token endpoint hands out $this->nextToken.
    private function fakePortal(): void
    {
        Http::fake([
            'portal.test/.well-known/openid-configuration' => fn () => $this->portalDown ? Http::response('', 503) : Http::response([
                'issuer' => self::ISSUER,
                'authorization_endpoint' => self::ISSUER.'/oauth/authorize',
                'token_endpoint' => self::ISSUER.'/oauth/token',
                'jwks_uri' => self::ISSUER.'/oauth/jwks',
                'end_session_endpoint' => self::ISSUER.'/oauth/logout',
            ]),
            'portal.test/oauth/jwks' => fn () => Http::response(['keys' => array_map(fn (string $kid) => $this->jwk($kid), $this->portalKeys)]),
            'portal.test/oauth/token' => fn () => Http::response(['id_token' => $this->nextToken]),
        ]);
    }

    protected function signIn(array $claims = [], string $kid = 'key-1', ?callable $sign = null): TestResponse
    {
        $query = $this->portalQuery($this->get('auth/sso/login'));

        $this->nextToken = ($sign ?? fn (array $payload) => $this->sign($payload, $kid))([
            'iss' => self::ISSUER,
            'aud' => 'app-client',
            'sub' => 'staff-uuid-1',
            'sid' => 'session-1',
            'email' => 'aina@webimpian.com',
            'name' => 'Aina',
            'iat' => time(),
            'exp' => time() + 3600,
            'nonce' => $query['nonce'],
            ...$claims,
        ]);

        return $this->get('auth/sso/callback?'.http_build_query(['code' => 'code-1', 'state' => $query['state']]));
    }

    protected function logoutToken(array $claims = []): string
    {
        return $this->sign([
            'iss' => self::ISSUER,
            'aud' => 'app-client',
            'sub' => 'staff-uuid-1',
            'sid' => 'session-1',
            'iat' => time(),
            'exp' => time() + 120,
            'jti' => 'logout-1',
            'events' => ['http://schemas.openid.net/event/backchannel-logout' => new \stdClass],
            ...$claims,
        ]);
    }

    protected function portalQuery(TestResponse $response): array
    {
        $location = (string) $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $query;
    }

    protected function sign(array $payload, string $kid = 'key-1', ?string $signedWith = null): string
    {
        return JWT::encode($payload, self::key($signedWith ?? $kid)['private'], 'ES256', $kid);
    }

    protected function jwk(string $kid): array
    {
        return ['kty' => 'EC', 'crv' => 'P-256', 'alg' => 'ES256', 'use' => 'sig', 'kid' => $kid, ...self::key($kid)['public']];
    }

    private static function key(string $kid): array
    {
        if (! isset(self::$keys[$kid])) {
            // PHP 8.1 on OpenSSL 3 checks the bit count even for EC keys.
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'private_key_bits' => 384]);
            openssl_pkey_export($key, $private);
            $ec = openssl_pkey_get_details($key)['ec'];
            $encode = fn (string $bytes) => rtrim(strtr(base64_encode(str_pad($bytes, 32, "\0", STR_PAD_LEFT)), '+/', '-_'), '=');

            self::$keys[$kid] = ['private' => $private, 'public' => ['x' => $encode($ec['x']), 'y' => $encode($ec['y'])]];
        }

        return self::$keys[$kid];
    }
}
