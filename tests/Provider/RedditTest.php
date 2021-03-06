<?php

namespace Rudolf\OAuth2\Client\Tests\Provider;

use InvalidArgumentException;
use League\OAuth2\Client\Token\AccessToken;
use PHPUnit\Framework\TestCase;
use Rudolf\OAuth2\Client\Provider\Reddit;

class RedditTest extends TestCase
{

    private function getBaseCredentials()
    {
        return [
            'userAgent' => 'phpunit:oauth2:test (by /u/oauth2)',
            'scopes'    => ['identity', 'read'],
        ];
    }

    /**
     * Please note that these credentials are for test purposes only
     * and don't belong to a proper application. Therefore it's okay
     * to specify them here out in the open, where it would obviously
     * be a very bad idea otherwise.
     */
    private function getCredentials($type = null)
    {
        if ($type === null) {
            $credentials = [
                'clientId'      => '_ID_',
                'clientSecret'  => '_SECRET_',
                'redirectUri'   => '_URI_',
            ];
        } else {
            $env = __DIR__ . "/env.json";

            if (is_file($env) && is_readable($env)) {
                $credentials = json_decode(file_get_contents($env), true);
                $credentials = $credentials[$type];
            } else {
                $this->markTestSkipped();
            }
        }

        return array_merge($this->getBaseCredentials(), $credentials);
    }

    private function createProvider($credentials)
    {
        return new Reddit($credentials);
    }

    private function assertValidAccessToken(AccessToken $token)
    {
        $this->assertObjectHasAttribute('accessToken', $token);
        $this->assertObjectHasAttribute('expires', $token);

        $this->assertRegExp("~\d{10,}~", "{$token->getExpires()}");
        $this->assertTrue( ! empty($token->getToken()));
    }

    public function authorizationUrlOptionsProvider()
    {
        return [
            [['duration' => 'permanent']],
            [['duration' => 'temporary']],
            [[]],
        ];
    }

    /**
     * @dataProvider authorizationUrlOptionsProvider
     */
    public function testGetAuthorizationUrl(array $options = [])
    {
        $credentials = $this->getCredentials();
        $provider = $this->createProvider($credentials);

        $url = $provider->getAuthorizationUrl($options);
        $urlParts = parse_url($url);

        $this->assertEquals('https', $urlParts['scheme']);
        $this->assertEquals('ssl.reddit.com', $urlParts['host']);
        $this->assertEquals('/api/v1/authorize', $urlParts['path']);

        parse_str($urlParts['query'], $queryStringParts);

        $this->assertEquals($credentials['clientId'], $queryStringParts['client_id']);
        $this->assertEquals($credentials['redirectUri'], $queryStringParts['redirect_uri']);
        $this->assertEquals('code', $queryStringParts['response_type']);
        $this->assertEquals('auto', $queryStringParts['approval_prompt']);
        $this->assertEquals('identity,read', $queryStringParts['scope']);

        if (isset($options['duration'])) {
            $this->assertEquals($queryStringParts['duration'], $options['duration']);
        } else {
            $this->assertFalse(isset($queryStringParts['duration']));
        }

        $this->assertRegExp('~[a-zA-Z0-9]{32}~', $queryStringParts['state']);
    }

    public function testGetHeaders()
    {
        $credentials = $this->getCredentials();
        $auth = base64_encode(
            "{$credentials['clientId']}:{$credentials['clientSecret']}");

        $expected = [
            "User-Agent"    => $credentials['userAgent'],
            "Authorization" => "Basic $auth"
        ];

        $provider = $this->createProvider($credentials);
        $this->assertEquals($expected, $provider->getHeaders());
    }

    public function testGetHeadersInvalidUserAgent()
    {
        $this->expectException(InvalidArgumentException::class);
        $credentials = $this->getCredentials();
        $credentials['userAgent'] = 'invalid';

        $invalidProvider = $this->createProvider($credentials);
        $invalidProvider->getHeaders();
    }

    public function testGetUserAgentFromServer()
    {
        $credentials = $this->getCredentials();
        $userAgent = $credentials['userAgent'];
        $_SERVER['HTTP_USER_AGENT'] = $userAgent;

        $credentials['userAgent'] = '';

        $provider = $this->createProvider($credentials);

        $this->assertNotFalse(!$provider->userAgent);
        $provider->getHeaders();
    }

    public function testGetHeadersWithToken()
    {
        $accessToken = md5(time());
        $token = new AccessToken([
            'access_token' => $accessToken,
            'expires'      => time() + 3600
        ]);

        $credentials = $this->getCredentials();
        $expected = [
            "User-Agent"    => $credentials['userAgent'],
            "Authorization" => "Bearer $accessToken"
        ];

        $provider = $this->createProvider($credentials);
        $this->assertEquals($expected, $provider->getHeaders($token));
    }

    public function testUserDetails()
    {
        $credentials = $this->getCredentials('password');
        $provider = $this->createProvider($credentials);
        $token = $provider->getAccessToken('password', [
            'username' => $credentials['username'],
            'password' => $credentials['password']
        ]);
        $userData = $provider->getResourceOwner($token);
    }

    public function testGetAccessTokenUsingClientCredentials()
    {
        $credentials = $this->getCredentials('client_credentials');
        $provider = $this->createProvider($credentials);
        $token = $provider->getAccessToken('client_credentials');
        $this->assertValidAccessToken($token);
    }

    public function testGetAccessTokenUsingUsernameAndPassword()
    {
        $credentials = $this->getCredentials('password');
        $provider = $this->createProvider($credentials);
        $token = $provider->getAccessToken('password', [
            'username' => $credentials['username'],
            'password' => $credentials['password']
        ]);

        $this->assertValidAccessToken($token);
    }

    public function testGetAccessTokenUsingImplicitFlow()
    {
        $credentials = $this->getCredentials('installed_client');
        $provider = $this->createProvider($credentials);
        $token = $provider->getAccessToken('installed_client', [
            'device_id' => uniqid('', true),
        ]);

        $this->assertValidAccessToken($token);
    }

    public function deviceIdProvider()
    {
        return [
            [[]],
            [["device_id" => ""]],                    // Equivalent to not provided
            [["device_id" => "abc"]],                 // Too short
            [["device_id" => md5("abc")]],            // Too long
            [["device_id"  => str_repeat("☕", 24)]],  // Has to be ASCII
        ];
    }

    /**
     * @dataProvider deviceIdProvider
     */
    public function testDeviceId($options = [])
    {
        $this->expectException(InvalidArgumentException::class);
        $credentials = $this->getCredentials('installed_client');
        $provider = $this->createProvider($credentials);
        $provider->getAccessToken('installed_client', $options);
    }
}
