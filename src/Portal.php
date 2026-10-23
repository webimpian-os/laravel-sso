<?php

namespace Webimpian\Sso;

use DomainException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;
use UnexpectedValueException;

class Portal
{
    public const LOGOUT_EVENT = 'http://schemas.openid.net/event/backchannel-logout';

    public static function issuer(): string
    {
        return rtrim((string) config('sso.issuer'), '/');
    }

    public static function redirectUri(): string
    {
        return config('sso.redirect_uri') ?: route('sso.callback');
    }

    public static function postLogoutUri(): string
    {
        return config('sso.post_logout_redirect_uri') ?: url('/');
    }

    public static function scopes(): string
    {
        $scopes = preg_split('/\s+/', trim((string) config('sso.scopes')), -1, PREG_SPLIT_NO_EMPTY);

        if (Sso::roleMap() !== [] || filled(config('sso.workos_id_column'))) {
            $scopes[] = 'staff';
        }

        return implode(' ', array_unique($scopes));
    }

    public function authorizeUrl(array $query): string
    {
        return $this->endpoint('authorization_endpoint').'?'.http_build_query($query);
    }

    public function endSessionUrl(): string
    {
        return $this->endpoint('end_session_endpoint');
    }

    public function signIn(string $code, string $verifier, string $nonce): array
    {
        try {
            $idToken = $this->http()->asForm()->post($this->endpoint('token_endpoint'), [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => static::redirectUri(),
                'client_id' => config('sso.client_id'),
                'client_secret' => config('sso.client_secret'),
                'code_verifier' => $verifier,
            ])->throw()->json('id_token');
        } catch (ConnectionException|RequestException $e) {
            throw $this->fault('The portal refused the sign-in code', $e);
        }

        if (! is_string($idToken)) {
            throw $this->fault('The portal sent no ID token');
        }

        $claims = $this->verify($idToken);

        if (! hash_equals($nonce, (string) ($claims['nonce'] ?? '')) || ! is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw $this->fault('The ID token is not for this sign-in');
        }

        return ['id_token' => $idToken, 'claims' => $claims];
    }

    public function logoutClaims(string $logoutToken): array
    {
        $claims = $this->verify($logoutToken);

        // A nonce marks an ID token; a revoke notice has no sid.
        if (! isset($claims['events'][self::LOGOUT_EVENT]) || isset($claims['nonce']) || (! is_string($claims['sid'] ?? null) && ! is_bool($claims['revoked'] ?? null))) {
            throw $this->fault('Not a back-channel logout token');
        }

        return $claims;
    }

    // Returns sub, email, name, workos_user_id, created, access and access_reason.
    public function addStaff(string $email, string $firstName, ?string $lastName = null): array
    {
        return $this->staffApi('POST', '/api/staff', array_filter(['email' => $email, 'first_name' => $firstName, 'last_name' => $lastName]), "The portal didn't add them");
    }

    // Returns sub, removed, access and access_reason.
    public function removeStaff(string $sub): array
    {
        return $this->staffApi('DELETE', '/api/staff/'.rawurlencode($sub), [], "The portal didn't remove them");
    }

    // Uncached, so a portal outage is seen before staff are sent there.
    public function reachable(): bool
    {
        try {
            return Http::connectTimeout(2)->timeout(3)->get(static::issuer().'/.well-known/openid-configuration')->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    private function verify(string $jwt): array
    {
        $keys = $this->keys();

        // A new kid means a rotated key; once a minute at most, so forged kids can't hammer the portal.
        if (! isset($keys[$this->kid($jwt)]) && Cache::add($this->cacheKey('jwks-refreshed'), true, 60)) {
            Cache::forget($this->cacheKey('jwks'));
            $keys = $this->keys();
        }

        $leeway = JWT::$leeway;
        JWT::$leeway = 60;

        try {
            $claims = json_decode(json_encode(JWT::decode($jwt, $keys)), true);
        } catch (UnexpectedValueException|DomainException|InvalidArgumentException $e) {
            throw $this->fault('The token did not verify', $e);
        } finally {
            JWT::$leeway = $leeway;
        }

        if (($claims['iss'] ?? null) !== static::issuer() || ! in_array(config('sso.client_id'), (array) ($claims['aud'] ?? []), true) || ! isset($claims['exp'])) {
            throw $this->fault('The token is from another issuer, for another app, or never expires');
        }

        return $claims;
    }

    private function kid(string $jwt): string
    {
        $header = json_decode(base64_decode(strtr(explode('.', $jwt)[0], '-_', '+/')), true);

        return is_array($header) && is_string($header['kid'] ?? null) ? $header['kid'] : '';
    }

    private function keys(): array
    {
        $jwks = Cache::remember($this->cacheKey('jwks'), 3600, fn () => $this->fetch($this->endpoint('jwks_uri')));

        try {
            return JWK::parseKeySet($jwks);
        } catch (UnexpectedValueException|DomainException|InvalidArgumentException $e) {
            throw $this->fault('The portal published keys that could not be read', $e);
        }
    }

    private function endpoint(string $name): string
    {
        $discovery = Cache::remember($this->cacheKey('discovery'), 86400, function () {
            $document = $this->fetch(static::issuer().'/.well-known/openid-configuration');

            // A wrong SSO_ISSUER fails here, not later as tokens that never verify.
            if (($document['issuer'] ?? null) !== static::issuer()) {
                throw $this->fault('The discovery document names another issuer');
            }

            return $document;
        });

        if (! is_string($discovery[$name] ?? null)) {
            throw $this->fault("The portal publishes no {$name}");
        }

        return $discovery[$name];
    }

    private function fetch(string $url): array
    {
        try {
            $json = $this->http()->get($url)->throw()->json();
        } catch (ConnectionException|RequestException $e) {
            throw $this->fault('The portal could not be reached', $e);
        }

        if (! is_array($json)) {
            throw $this->fault("The portal sent something other than JSON from {$url}");
        }

        return $json;
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->connectTimeout(5)->timeout(10);
    }

    private function cacheKey(string $name): string
    {
        return 'sso.'.$name.'.'.md5(static::issuer());
    }

    private function staffApi(string $method, string $path, array $data, string $failed): array
    {
        try {
            $response = $this->http()
                ->withBasicAuth((string) config('sso.client_id'), (string) config('sso.client_secret'))
                ->send($method, static::issuer().$path, $data === [] ? [] : ['json' => $data]);
        } catch (ConnectionException $e) {
            throw $this->fault('The portal could not be reached', $e);
        }

        if (! $response->successful()) {
            throw new SsoException($response->json('error_description') ?? $response->json('message') ?? "{$failed} (HTTP {$response->status()}).", $response->status());
        }

        return $response->json();
    }

    // Staff see one plain message; the reason goes to the log.
    private function fault(string $reason, ?Throwable $previous = null): SsoException
    {
        Log::warning('SSO: '.$reason, $previous ? ['error' => $previous->getMessage()] : []);

        return new SsoException("The portal couldn't sign you in. Try again in a moment.", 502, $previous);
    }
}
