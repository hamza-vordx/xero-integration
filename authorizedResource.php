<?php
ini_set('display_errors', 'On');
require __DIR__ . '/vendor/autoload.php';
require_once('storage.php');

$config = require('config.php');
use XeroAPI\XeroPHP\AccountingObjectSerializer;

// Storage Class uses file-based storage instead of sessions
$storage = new StorageClass();
$xeroTenantId = (string)$storage->getXeroTenantId();



// Token refreshing logic
if ($storage->getHasExpired()) {
    $provider = new \League\OAuth2\Client\Provider\GenericProvider([
        'clientId'                => $config['xero']['client_id'],
        'clientSecret'            => $config['xero']['client_secret'],
        'redirectUri'             => $config['xero']['redirect_uri'],
        'urlAuthorize'            => 'https://login.xero.com/identity/connect/authorize',
        'urlAccessToken'          => 'https://identity.xero.com/connect/token',
        'urlResourceOwnerDetails' => 'https://api.xero.com/api.xro/2.0/Organisation'
    ]);

    // Retrieve refresh token
    $refreshToken = $storage->getRefreshToken();
    if (empty($refreshToken)) {
        echo "No refresh token available.";
        exit;
    }

    try {
        $newAccessToken = $provider->getAccessToken('refresh_token', [
            'refresh_token' => $refreshToken
        ]);

        // Save the new token, expiration time, and refresh token
        $storage->setToken(
            $newAccessToken->getToken(),
            $newAccessToken->getExpires(),
            $xeroTenantId,
            $newAccessToken->getRefreshToken(),
            $newAccessToken->getValues()["id_token"]
        );

        echo "Access token refreshed successfully.\n";
    } catch (\League\OAuth2\Client\Provider\Exception\IdentityProviderException $e) {
        error_log('Failed to refresh access token: ' . $e->getMessage());
        exit;
    } catch (Exception $e) {
        error_log('General error: ' . $e->getMessage());
        exit;
    }
}

$tokenData = $storage->getToken(); // Get the token data
$accessToken = $tokenData['token'] ?? null; // Extract the token, default to null if not set


$config = XeroAPI\XeroPHP\Configuration::getDefaultConfiguration()->setAccessToken($accessToken);
$apiInstance = new XeroAPI\XeroPHP\Api\AccountingApi(
    new GuzzleHttp\Client(),
    $config
);

$message = "no API calls";
if (isset($_GET['action'])) {
    if ($_GET["action"] == 1) {
        // Get Organisation details
        $apiResponse = $apiInstance->getOrganisations($xeroTenantId);
        $message = 'Organisation Name: ' . $apiResponse->getOrganisations()[0]->getName();
    } else if ($_GET["action"] == 2) {
        // Create Contact
        try {
            $person = new XeroAPI\XeroPHP\Models\Accounting\ContactPerson;
            $person->setFirstName("John")
                ->setLastName("Smith")
                ->setEmailAddress("john.smith@24locks.com")
                ->setIncludeInEmails(true);

            $arr_persons = [];
            array_push($arr_persons, $person);

            $contact = new XeroAPI\XeroPHP\Models\Accounting\Contact;
            $contact->setName('FooBar')
                ->setFirstName("Foo")
                ->setLastName("Bar")
                ->setEmailAddress("ben.bowden@24locks.com")
                ->setContactPersons($arr_persons);

            $arr_contacts = [];
            array_push($arr_contacts, $contact);
            $contacts = new XeroAPI\XeroPHP\Models\Accounting\Contacts;
            $contacts->setContacts($arr_contacts);

            $apiResponse = $apiInstance->createContacts($xeroTenantId, $contacts);
            $message = 'New Contact Name: ' . $apiResponse->getContacts()[0]->getName();
        } catch (\XeroAPI\XeroPHP\ApiException $e) {
            $error = AccountingObjectSerializer::deserialize(
                $e->getResponseBody(),
                '\XeroAPI\XeroPHP\Models\Accounting\Error',
                []
            );
            $message = "ApiException - " . $error->getElements()[0]["validation_errors"][0]["message"];
        }

    } else if ($_GET["action"] == 3) {
        // Get Invoices with Filters
        $if_modified_since = new \DateTime("2019-01-02T19:20:30+01:00");
        $where = null;
        $statuses = array("DRAFT", "SUBMITTED");
        $page = 1;

        try {
            $apiResponse = $apiInstance->getInvoices($xeroTenantId, $if_modified_since, $where, null, null, null, null, $statuses, $page);
            if (count($apiResponse->getInvoices()) > 0) {
                $message = 'Total invoices found: ' . count($apiResponse->getInvoices());
            } else {
                $message = "No invoices found matching filter criteria";
            }
        } catch (Exception $e) {
            echo 'Exception when calling AccountingApi->getInvoices: ', $e->getMessage(), PHP_EOL;
        }
    } else if ($_GET["action"] == 4) {
        // Create Multiple Contacts
        try {
            $contact = new XeroAPI\XeroPHP\Models\Accounting\Contact;
            $contact->setName('George Jetson')
                ->setFirstName("George")
                ->setLastName("Jetson")
                ->setEmailAddress("george.jetson@aol.com");

            $arr_contacts = [];
            array_push($arr_contacts, $contact);
            array_push($arr_contacts, $contact);
            $contacts = new XeroAPI\XeroPHP\Models\Accounting\Contacts;
            $contacts->setContacts($arr_contacts);

            $apiResponse = $apiInstance->createContacts($xeroTenantId, $contacts, false);
            $message = 'First contact created: ' . $apiResponse->getContacts()[0]->getName();

            if ($apiResponse->getContacts()[1]->getHasValidationErrors()) {
                $message .= '<br> Second contact validation error: ' . $apiResponse->getContacts()[1]->getValidationErrors()[0]["message"];
            }

        } catch (\XeroAPI\XeroPHP\ApiException $e) {
            $error = AccountingObjectSerializer::deserialize(
                $e->getResponseBody(),
                '\XeroAPI\XeroPHP\Models\Accounting\Error',
                []
            );
            $message = "ApiException - " . $error->getElements()[0]["validation_errors"][0]["message"];
        }
    } else if ($_GET["action"] == 5) {
        // Get Contacts with Filters
        $if_modified_since = new \DateTime("2019-01-02T19:20:30+01:00");
        $page = 1;

        try {
            $apiResponse = $apiInstance->getContacts($xeroTenantId, $if_modified_since, null, null, null, $page, null);
            if (count($apiResponse->getContacts()) > 0) {
                $message = 'Total contacts found: ' . count($apiResponse->getContacts());
            } else {
                $message = "No contacts found matching filter criteria";
            }
        } catch (Exception $e) {
            echo 'Exception when calling AccountingApi->getContacts: ', $e->getMessage(), PHP_EOL;
        }
    } else if ($_GET["action"] == 6) {
        // Get JWT Claims
        $jwt = new XeroAPI\XeroPHP\JWTClaims();
        $jwt->setTokenId((string)$storage->getIdToken());
        $jwt->setTokenAccess((string)$storage->getAccessToken());
        $jwt->decode();

        echo("sub: " . $jwt->getSub() . "<br>");
        echo("sid: " . $jwt->getSid() . "<br>");
        echo("iss: " . $jwt->getIss() . "<br>");
        echo("exp: " . $jwt->getExp() . "<br>");
        echo("given name: " . $jwt->getGivenName() . "<br>");
        echo("family name: " . $jwt->getFamilyName() . "<br>");
        echo("email: " . $jwt->getEmail() . "<br>");
        echo("user id: " . $jwt->getXeroUserId() . "<br>");
        echo("username: " . $jwt->getPreferredUsername() . "<br>");
        echo("session id: " . $jwt->getGlobalSessionId() . "<br>");
        echo("authentication_event_id: " . $jwt->getAuthenticationEventId() . "<br>");
    }
}
?>
<html>
<body>
<ul>
    <li><a href="authorizedResource.php?action=1">Get Organisation Name</a></li>
    <li><a href="authorizedResource.php?action=2">Create one Contact</a></li>
    <li><a href="authorizedResource.php?action=3">Get Invoice with Filters</a></li>
    <li><a href="authorizedResource.php?action=4">Create multiple contacts and summarizeErrors</a></li>
    <li><a href="authorizedResource.php?action=5">Get Contact with Filters</a></li>
    <li><a href="authorizedResource.php?action=6">Get JWT Claims</a></li>
</ul>
<div>
    <?php
    echo($message);
    ?>
</div>
</body>
</html>
