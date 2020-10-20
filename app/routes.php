<?php
declare(strict_types=1);

use App\Domain\Oauth2\OAuth2Settings;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Views\PhpRenderer;

return function (App $app) {
    session_start();

    $app->get('/', function (Request $request, Response $response) {
        $container = new PhpRenderer('../templates/');

        if (!isset($_SESSION['settings'])) {
            $_SESSION['settings'] = (new OAuth2Settings())->getSettings();
        }

        $response = $container->render($response, 'main.phtml', ["settings" => $_SESSION['settings']]);
        return $response;
    });

    //start_oidc
    $app->post('/', function (Request $request, Response $response) {
        $settings = populate($request);
        $wellKnownUrl = $settings['wellKnown'];
        $authorization_endpoint = get_location_from_metadata($wellKnownUrl, 'authorization_endpoint');

        $params = [
            "client_id" => $settings['clientId'],
            "response_type" => 'code',
            "scope" => $settings['scopes'],
            "redirect_uri" => $settings['callbackUrl'],
            "state" => $settings['state']
        ];
        $redirect_url = $authorization_endpoint . "?" . http_build_query($params);
        return $response
                ->withHeader('Location', $redirect_url)
                ->withStatus(302);
    });

    //process_callback
    $app->get('/callback', function (Request $request, Response $response) {
        $container = new PhpRenderer('../templates/');
        $settings = $_SESSION['settings'];
        $code = $request->getQueryParams()['code'];
        $payload = [
            'grant_type' => 'authorization_code',
            'redirect_uri' => $settings['callbackUrl'],
            'code' => $code,
            'scope' => $settings['scopes']
        ];

        $settings = callAuthenticationWith($settings, $payload);

        $_SESSION['settings'] = $settings;

        $redirect_url = needsOrganizationAccess($settings);
        if($redirect_url != null) {
            return $response
                ->withHeader('Location', $redirect_url)
                ->withStatus(302);
        }

        return $container->render($response, 'main.phtml',  ["settings" => $settings]);
    });

    //refresh_access_token
    $app->get('/refresh-access-token', function (Request $request, Response $response) {
        $container = new PhpRenderer('../templates/');
        $settings = $_SESSION['settings'];
        $payload = [
            'grant_type' => 'refresh_token',
            'redirect_uri' => $settings['callbackUrl'],
            'refresh_token' => $settings['refreshToken'],
            'scope' => $settings['scopes']
        ];
        $settings = callAuthenticationWith($settings, $payload);

        $_SESSION['settings'] = $settings;
        $response = $container->render($response, 'main.phtml', ["settings" => $settings]);
        return $response;
    });

    //call-api
    $app->post('/call-api', function (Request $request, Response $response) {
        $container = new PhpRenderer('../templates/');
        $settings = $_SESSION['settings'];

        $parsedBody = $request->getParsedBody();
        $url = filter_var($parsedBody['url'], FILTER_SANITIZE_STRING);
        $token = filter_var($parsedBody['token'], FILTER_SANITIZE_STRING);

        $body = apiGet($token, $url);
        $body = json_encode(json_decode($body), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $settings['accessToken'] = $token;
        $settings['apiResponse'] = $body;
        $_SESSION['settings'] = $settings;

        $response = $container->render($response, 'main.phtml', ["settings" => $settings]);
        return $response;
    });

    function apiGet($token, $resourceUrl)
    {
        $client = new Client();
        $res = $client->request('GET', $resourceUrl, ['verify' => false,
            'headers' => [
                'authorization' => 'Bearer ' . $token,
                'Accept' => 'application/vnd.deere.axiom.v3+json'
            ]
        ]);
        return (string)$res->getBody();
    }

    function callAuthenticationWith($settings, array $payload)
    {
        $token_endpoint = get_location_from_metadata($settings['wellKnown'], 'token_endpoint');

        $client = new Client();
        $basic_auth_header = base64_encode($settings['clientId'] . ':' . $settings['clientSecret']);
        $res = $client->request('POST', $token_endpoint, ['verify' => false,
            'headers' => [
                'authorization' => 'Basic ' . $basic_auth_header,
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded'
            ],
            'form_params' => $payload
        ]);
        $body = (string)$res->getBody();
        $json = json_decode($body);
        $settings['accessToken'] = $json->access_token;
        $settings['refreshToken'] = $json->refresh_token;
        $settings['accessTokenDetails'] = getAccessTokenDetails($json->access_token);
        $now = new DateTime();
        $settings['exp'] = $now->modify("+" . $json->expires_in / (1000 * 60 * 60) . " hour")->format('Y-m-d H:i:s');
        return $settings;
    }

    function getAccessTokenDetails($jwt_access_token)
    {
        $separator = '.';
        list($header, $payload, $signature) = explode($separator, $jwt_access_token);
        return json_encode(json_decode(base64_decode($payload)), JSON_PRETTY_PRINT);
    }

    /**
     * @param string|null $wellKnownUrl
     * @param string|null $element
     * @return string|null
     */
    function get_location_from_metadata(?string $wellKnownUrl, string $element): string
    {
        $client = new Client();
        $res = $client->request('GET', $wellKnownUrl, ['verify' => false]);
        $body = (string)$res->getBody();
        $json = json_decode($body);
        return $json->$element;
    }

    /**
     * Check to see if the 'connections' rel is present for any organization.
     * If the rel is present it means the oauth application has not completed it's
     * access to an organization and must redirect the user to the uri provided
     * in the link.
     *
     * @return A redirect uri if 'connections' rel is present or <code>null</code>
     * if no redirect is required to finish the setup.
     */
    function needsOrganizationAccess($settings)
    {
        $token = $settings['accessToken'];
        $orgsUrl = $settings['apiUrl'] . '/organizations';

        $response = json_decode(apiGet($token, $orgsUrl), $assoc=true);

        $orgs = $response['values'];

        foreach ($orgs as $org) {
            $links = $org['links'];
            foreach($links as $link) {
                if($link['rel'] === 'connections'){
                    $orgConnectedRedirect = http_build_query(array(
                        'redirect_uri' => $settings['orgConnectionCompletedUrl']
                        )
                    );
                    return $link['uri'] . '?' . $orgConnectedRedirect;
                }
            }

        }

        return null;
    }

    /**
     * @param Request $request
     * @return array|null
     */
    function populate(Request $request)
    {
        $parsedBody = $request->getParsedBody();

        $settings = $_SESSION['settings'];
        $settings['clientId'] = filter_var($parsedBody['clientId'], FILTER_SANITIZE_STRING);
        $settings['clientSecret'] = filter_var($parsedBody['clientSecret'], FILTER_SANITIZE_STRING);
        $settings['wellKnown'] = filter_var($parsedBody['wellKnown'], FILTER_SANITIZE_STRING);
        $settings['callbackUrl'] = filter_var($parsedBody['callbackUrl'], FILTER_SANITIZE_STRING);
        $settings['scopes'] = filter_var($parsedBody['scopes'], FILTER_SANITIZE_STRING);
        $settings['state'] = filter_var($parsedBody['state'], FILTER_SANITIZE_STRING);
        $_SESSION['settings'] = $settings;
        return $settings;
    }
};
