<?php
declare(strict_types=1);

namespace App\Domain\Oauth2;

use JsonSerializable;

class OAuth2Settings implements JsonSerializable
{
    private $settings;

    public function __construct()
    {
        $SERVER_URL="http://localhost:9090";
        $this->settings = [
            "apiUrl"=> "https://sandboxapi.deere.com/platform",
            "clientId"=> "",
            "clientSecret"=> "",
            "wellKnown"=> "https://signin.johndeere.com/oauth2/aus78tnlaysMraFhC1t7/.well-known/oauth-authorization-server",
            "callbackUrl"=> $SERVER_URL . "/callback",
            "orgConnectionCompletedUrl" => $SERVER_URL,
            "scopes"=> "ag1 ag2 ag3 eq1 eq2 org1 org2 files offline_access",
            "state"=> uniqid(),
            "idToken"=> "",
            "accessToken"=> "",
            "refreshToken"=> "",
            "apiResponse"=> "",
            "accessTokenDetails"=> "",
            "exp"=> ""
        ];
    }

    public function getSettings(): ?array
    {
        return $this->settings;
    }

    public function setSettings(?array $settings)
    {
        return $this->settings = $settings;
    }

    public function jsonSerialize()
    {
        return [
            'settings' => $this->settings
        ];
    }
}
