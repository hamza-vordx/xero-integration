<?php


require 'vendor/autoload.php';
require 'storage.php';
require 'config.php';

use XeroAPI\XeroPHP\OAuth2\Client\Provider\Xero as XeroProvider;
use GuzzleHttp\Client as GuzzleClient;

$config = require('config.php');
$storage = new StorageClass();

// Create Xero API provider instance
$xeroProvider = new XeroProvider([
    'clientId' => $config['xero']['client_id'],
    'clientSecret' => $config['xero']['client_secret'],
    'redirectUri' => $config['xero']['redirect_uri'],
    'scope' => ['openid', 'profile', 'email', 'accounting.transactions', 'accounting.contacts']
]);

// Refresh the access token if needed
$storage->refreshAccessToken($xeroProvider);

// Get the refreshed access token
$accessToken = $storage->getAccessToken();
if ($accessToken) {
    echo "Access token refreshed successfully: $accessToken\n";
} else {
    echo "Failed to refresh access token.\n";
}


