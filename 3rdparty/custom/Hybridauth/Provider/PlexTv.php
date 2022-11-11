<?php

namespace Hybridauth\Provider;

use Hybridauth\Adapter\OAuth2;
use Hybridauth\Data\Collection;
use Hybridauth\Exception\UnexpectedApiResponseException;
use Hybridauth\User\Profile;

class PlexTv extends OAuth2
{
    protected $apiBaseUrl = 'https://plex.tv/api/v2';
    protected $apiDocumentation = 'https://forums.plex.tv/t/authenticating-with-plex/609370';

    private $product = 'OAuth';

    protected function configure()
    {
        if ($product = $this->config->filter('keys')->get('id')) {
            $this->product = $product;
        }
        $this->setCallback($this->config->get('callback'));
    }

    protected function initialize()
    {
        $this->apiRequestParameters = [
            'X-Plex-Client-Identifier' => $this->getStoredData('client_id') ?: '',
        ];

        $this->apiRequestHeaders = [
            'Accept' => 'application/json',
            'X-Plex-Product' => $this->product,
            'X-Plex-Token' =>  $this->getStoredData('access_token') ?: '',
        ];
    }

    protected function getAuthorizeUrl($parameters = [])
    {
        $clientId = 'HA-' . str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890');
        $this->storeData('client_id', $clientId);
        $this->storeData('authorization_state', $clientId);
        $pin = $this->apiRequest('pins', 'POST', [
            'strong' => 'true',
            'X-Plex-Client-Identifier' => $clientId,
        ]);
        $this->storeData('pin_id', $pin->id);

        return 'https://app.plex.tv/auth#?'.http_build_query([
            'clientID' => $clientId,
            'code' => $pin->code,
            'forwardUrl' => $this->callback.'?'.http_build_query(['code' => $pin->code, 'state' => $clientId]),
            'context' => ['device' => ['product' => $this->product]],
        ]);
    }

    protected function exchangeCodeForAccessToken($code)
    {
        $pin = $this->apiRequest('pins/'.$this->getStoredData('pin_id'));
        $this->deleteStoredData('pin_id');
        $pin->access_token = $pin->authToken;

        return json_encode($pin);
    }

    public function getUserProfile()
    {
        $data = new Collection($this->apiRequest('user'));

        if (!$data->exists('id')) {
            throw new UnexpectedApiResponseException('Provider API returned an unexpected response.');
        }

        $userProfile = new Profile();

        $userProfile->identifier = $data->get('id');
        $userProfile->displayName = $data->get('friendlyName');
        $userProfile->photoURL = $data->get('thumb');
        $userProfile->email = $data->get('email');

        return $userProfile;
    }
}
