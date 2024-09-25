<?php

ini_set('display_errors', 'On');
require __DIR__ . '/vendor/autoload.php';
require_once('storage.php');

$config = require __DIR__ . '/config.php';

// Initialize Storage Class with the file for storing tokens
$storage = new StorageClass();

// Set up Xero OAuth parameters
$xero_client_id = $config['xero']['client_id'];
$xero_client_secret = $config['xero']['client_secret'];
$xero_redirect_uri = $config['xero']['redirect_uri'];

// Create the OAuth provider
$provider = new \League\OAuth2\Client\Provider\GenericProvider([
    'clientId'                => $xero_client_id,
    'clientSecret'            => $xero_client_secret,
    'redirectUri'             => $xero_redirect_uri,
    'urlAuthorize'            => 'https://login.xero.com/identity/connect/authorize',
    'urlAccessToken'          => 'https://identity.xero.com/connect/token',
    'urlResourceOwnerDetails' => 'https://api.xero.com/api.xro/2.0/Organisation'
]);

// Check for an authorization code
if (!isset($_GET['code'])) {
    echo "Something went wrong, no authorization code found";
    exit("Something went wrong, no authorization code found");

// Check the state to mitigate CSRF attacks
} elseif (empty($_GET['state'])) {
    echo "Invalid State";
    exit('Invalid state');
} else {
    try {
        // Try to get an access token using the authorization code grant.
        $accessToken = $provider->getAccessToken('authorization_code', [
            'code' => $_GET['code']
        ]);

        // Set up Xero configuration with the access token
        $config = XeroAPI\XeroPHP\Configuration::getDefaultConfiguration()->setAccessToken((string)$accessToken->getToken());
        $identityInstance = new XeroAPI\XeroPHP\Api\IdentityApi(
            new GuzzleHttp\Client(),
            $config
        );

        // Get connections (tenants)
        $result = $identityInstance->getConnections();

        // Save tokens, expiration, tenant ID
        $storage->setToken(
            $accessToken->getToken(),
            $accessToken->getExpires(),
            $result[0]->getTenantId(),
            $accessToken->getRefreshToken(),
            $accessToken->getValues()["id_token"]
        );

        // Redirect to the authorized resource page
        header('Location: ' . './authorizedResource.php');
        exit();

    } catch (\League\OAuth2\Client\Provider\Exception\IdentityProviderException $e) {
        var_dump($e->getMessage());
        echo "Callback failed";
        exit();
    }
}
