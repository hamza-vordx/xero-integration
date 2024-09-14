<?php
  ini_set('display_errors', 'On');
  require __DIR__ . '/vendor/autoload.php';
  require_once('storage.php');

$config = require __DIR__ . '/config.php';

  // Storage Class uses sessions for storing access token (demo only)
  // you'll need to extend to your Database for a scalable solution
  $storage = new StorageClass();

$xero_client_id = $config['xero']['client_id'];
$xero_client_secret = $config['xero']['client_secret'];
$xero_redirect_uri = $config['xero']['redirect_uri'];
$xero_tenant_id = $config['xero']['tenant_id'];
$xero_access_token = $config['xero']['access_token'];

// Storage Class uses sessions for storing access token (demo only)
// you'll need to extend to your Database for a scalable solution
$storage = new StorageClass();

$provider = new \League\OAuth2\Client\Provider\GenericProvider([
    'clientId'                => $xero_client_id,
    'clientSecret'            => $xero_client_secret,
    'redirectUri'             => $xero_redirect_uri,
    'urlAuthorize'            => 'https://login.xero.com/identity/connect/authorize',
    'urlAccessToken'          => 'https://identity.xero.com/connect/token',
    'urlResourceOwnerDetails' => 'https://api.xero.com/api.xro/2.0/Organisation'
]);

  // Scope defines the data your app has permission to access.
  // Learn more about scopes at https://developer.xero.com/documentation/oauth2/scopes
  $options = [
      'scope' => ['openid email profile offline_access assets projects accounting.settings accounting.transactions accounting.contacts accounting.journals.read accounting.reports.read accounting.attachments']
  ];

  // This returns the authorizeUrl with necessary parameters applied (e.g. state).
  $authorizationUrl = $provider->getAuthorizationUrl($options);

  var_dump($authorizationUrl);

  // Save the state generated for you and store it to the session.
  // For security, on callback we compare the saved state with the one returned to ensure they match.
  $_SESSION['oauth2state'] = $provider->getState();

  // Redirect the user to the authorization URL.
  header('Location: ' . $authorizationUrl);
  exit();
?>