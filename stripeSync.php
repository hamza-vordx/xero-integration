<?php

require 'vendor/autoload.php';

// Load the StorageClass
require_once('storage.php');

// Initialize StorageClass
$storage = new StorageClass();
$config = require('config.php');

// Fetch Xero credentials
$xero_client_id = $config['xero']['client_id'];
$xero_client_secret = $config['xero']['client_secret'];
$xero_redirect_uri = $config['xero']['redirect_uri'];

// Fetch Xero tenant ID from storage
$xero_tenant_id = $storage->getXeroTenantId();

// Fetch Stripe credentials
$stripe_secret_key = $config['stripe']['secret_key'];

// Set up Stripe API
\Stripe\Stripe::setApiKey($stripe_secret_key);

// Token refreshing logic
if ($storage->getHasExpired()) {
    $provider = new \League\OAuth2\Client\Provider\GenericProvider([
        'clientId'                => $xero_client_id,
        'clientSecret'            => $xero_client_secret,
        'redirectUri'             => $xero_redirect_uri,
        'urlAuthorize'            => 'https://login.xero.com/identity/connect/authorize',
        'urlAccessToken'          => 'https://identity.xero.com/connect/token',
        'urlResourceOwnerDetails' => 'https://api.xero.com/api.xro/2.0/Organisation'
    ]);

    try {
        $newAccessToken = $provider->getAccessToken('refresh_token', [
            'refresh_token' => $storage->getRefreshToken()
        ]);

        // Save the new token, expiration time, and refresh token
        $storage->setToken(
            $newAccessToken->getToken(),
            $newAccessToken->getExpires(),
            $xero_tenant_id,
            $newAccessToken->getRefreshToken(),
            $newAccessToken->getValues()["id_token"]
        );

        echo "Access token refreshed successfully.\n";
    } catch (\League\OAuth2\Client\Provider\Exception\IdentityProviderException $e) {
        echo 'Failed to refresh access token: ' . $e->getMessage() . "\n";
        exit;
    }
}

// Initialize Xero API configuration with the current token
$xeroConfig = XeroAPI\XeroPHP\Configuration::getDefaultConfiguration()->setAccessToken(
    (string)$storage->getAccessToken()
);

// Create Xero Accounting API instance
$accountingApi = new XeroAPI\XeroPHP\Api\AccountingApi(
    new GuzzleHttp\Client(),
    $xeroConfig
);




function fetchAccounts($accountingApi, $xeroTenantId) {
    try {
        // Fetch accounts from Xero
        $accounts = $accountingApi->getAccounts($xeroTenantId);

        // Initialize an array to store the accounts
        $accountsArray = [];

        // Process each account and add it to the array
        foreach ($accounts->getAccounts() as $account) {
            $accountsArray[$account->getName()] = [
                'account_id' => $account->getAccountID(),
                'account_code' => $account->getCode()
            ];
        }

        return $accountsArray;
    } catch (XeroAPI\XeroPHP\ApiException $e) {
        echo 'Exception when calling AccountingApi->getAccounts: ', $e->getMessage(), PHP_EOL;
        return [];
    }
}

// Fetch accounts and add them to your data
$accounts = fetchAccounts($accountingApi, $xero_tenant_id);


function fetchTrackingCategories($accountingApi, $xeroTenantId) {
    try {
        $trackingCategories = $accountingApi->getTrackingCategories($xeroTenantId);
        $categoriesAndOptions = [];

        foreach ($trackingCategories->getTrackingCategories() as $category) {
            $categoryData = [
                'category_id' => $category->getTrackingCategoryID(),
                'category_name' => $category->getName(),
                'options' => []
            ];

            foreach ($category->getOptions() as $option) {
                $categoryData['options'][] = [
                    'option_id' => $option->getTrackingOptionID(),
                    'option_name' => $option->getName()
                ];
            }

            $categoriesAndOptions[] = $categoryData;
        }

        return $categoriesAndOptions;
    } catch (XeroAPI\XeroPHP\ApiException $e) {
        echo 'Exception when calling AccountingApi->getTrackingCategories: ', $e->getMessage(), PHP_EOL;
        return [];
    }
}

// Fetch and display tracking categories
$categories = fetchTrackingCategories($accountingApi, $xero_tenant_id);

// Initialize a mapping array
$categoryMapping = [];

// Populate the mapping with both category and option names
foreach ($categories as $category) {
    foreach ($category['options'] as $option) {
        $categoryMapping[$category['category_name']][$option['option_name']] = [
            'category_id' => $category['category_id'],
            'option_id' => $option['option_id']
        ];
    }
}


function findBestMatch($text, $mapping) {
    $bestMatchKey = null;
    $bestMatchScore = PHP_INT_MAX;

    foreach ($mapping as $key => $value) {
        // Calculate the Levenshtein distance between text and mapping key
        $distance = levenshtein(strtolower($text), strtolower($key));
        if ($distance < $bestMatchScore) {
            $bestMatchScore = $distance;
            $bestMatchKey = $key;
        }
    }

    return $bestMatchKey;
}



try {
    // Step 1: Fetch Stripe Transactions
    $payoutId = 'po_1PuOgdDxoXu8eGE9qwFkWbLY'; // Use your specific payout ID
    $transactions = [];
    $startingAfter = null;

    do {
        // Fetch balance transactions for the specified payout ID
        $response = \Stripe\BalanceTransaction::all([
            'payout' => $payoutId,
            'limit' => 100, // Increase the limit to fetch more transactions in one request
            'starting_after' => $startingAfter, // For pagination
        ]);

        // Merge the new data into the transactions array
        $transactions = array_merge($transactions, $response->data);

        // Check if there are more transactions to fetch
        if ($response->has_more) {
            $startingAfter = end($response->data)->id;
        } else {
            $startingAfter = null; // No more data to fetch
        }
    } while ($startingAfter);

    // Step 2: Process Transactions and Metadata
    $invoiceItems = [];

    foreach ($transactions as $transaction) {
        if ($transaction->type !== 'charge') {
            continue; // Skip non-charge transactions
        }



        try {
            // Retrieve the charge details from Stripe
            $charge = \Stripe\Charge::retrieve($transaction->source);


            $description = $transaction->description;

            // Extract destination and type from description
            // Assuming descriptions follow a format like "Paid for [Destination] [Date] [Type]"
            $descriptionLower = strtolower($description);

            // Split the description into parts
            $descriptionParts = explode(' ', $descriptionLower);

            // Adjust slicing based on your exact format
            $destinationPart = implode(' ', array_slice($descriptionParts, 2, -3));
            $typePart = end($descriptionParts);

            // Find the best matching key for Destination and Type
            $mappedDestinationKey = findBestMatch($destinationPart, $categoryMapping['Destination']);
            $mappedTypeKey = findBestMatch($typePart, $categoryMapping['Type']);


            // Check if a valid match was found for destination
            // Log for mapped destination
            if ($mappedDestinationKey && isset($categoryMapping['Destination'][$mappedDestinationKey])) {
                $mappedDestination = $categoryMapping['Destination'][$mappedDestinationKey];
                echo "Mapped Destination: Key = '$mappedDestinationKey', Category ID = " . $mappedDestination['category_id'] . ", Option ID = " . $mappedDestination['option_id'] . "\n";
            } else {
                echo "Warning: Could not find a valid destination match for: '$destinationPart'. Key attempted: '$mappedDestinationKey'\n";
                $mappedDestination = ['category_id' => null, 'option_id' => null]; // Fallback value
            }

// Log for mapped type
            if ($mappedTypeKey && isset($categoryMapping['Type'][$mappedTypeKey])) {
                $mappedType = $categoryMapping['Type'][$mappedTypeKey];
                echo "Mapped Type: Key = '$mappedTypeKey', Category ID = " . $mappedType['category_id'] . ", Option ID = " . $mappedType['option_id'] . "\n";
            } else {
                echo "Warning: Could not find a valid type match for: '$typePart'. Key attempted: '$mappedTypeKey'\n";
                $mappedType = ['category_id' => null, 'option_id' => null]; // Fallback value
            }

// Extract and log category and option IDs
            $destinationCategoryID = $mappedDestination['category_id'] ?? 'null';
            $destinationOptionID = $mappedDestination['option_id'] ?? 'null';
            $typeCategoryID = $mappedType['category_id'] ?? 'null';
            $typeOptionID = $mappedType['option_id'] ?? 'null';

//            echo "Extracted Destination IDs: Category ID = $destinationCategoryID, Option ID = $destinationOptionID\n";
//            echo "Extracted Type IDs: Category ID = $typeCategoryID, Option ID = $typeOptionID\n";


            if ($charge && isset($charge->metadata)) {
                // Convert metadata object to array
                $metadataArray = $charge->metadata->toArray();
//                var_dump($metadataArray);

                // Determine if there is only one metadata item or multiple
                $isSingleMetadata = count($metadataArray) === 1;
                $amount = $transaction->amount / 100;
                // Loop through metadata values
                foreach ($metadataArray as $metaKey => $metaValue) {
                    // Initialize variable to store account code
                    $accountCode = null;

                    // Define regex patterns to find account codes
                    $patterns = [
                        '/\((\d{4})\)/',        // Matches codes within parentheses, e.g., (4001)
                        '/\b(\d{4})\b/',        // Matches standalone four-digit codes
                        '/\b(\d{4,5})\b/',      // Matches standalone four or five-digit codes
                    ];

                    // Try each pattern to find an account code
                    foreach ($patterns as $pattern) {
                        if (preg_match($pattern, $metaValue, $matches)) {
                            $accountCode = trim($matches[1]); // Extract and trim account code
                            break; // Exit loop once a match is found
                        }
                    }

                    // If an account code is found, search for it in the accounts array
                    if ($accountCode) {
                        $accountFound = false;


                        $amount = $transaction->amount / 100; // Default to transaction amount

                        // If there are multiple metadata items, extract price from metadata
                        if (!$isSingleMetadata) {
                            $metaParts = explode(' ', $metaKey);

                            if (count($metaParts) === 3) {
                                $index = $metaParts[0]; // Extract index
                                $price = $metaParts[2];
                                $amount = $price;
                            } else {
                                // Fallback in case metadata key is not as expected
                                $amount = $transaction->amount / 100;

                            }
                        }

                        // Search for the account in the accounts array
                        foreach ($accounts as $accountName => $accountDetails) {
                            if (trim($accountDetails['account_code']) === $accountCode) {
                                // Collect invoice items
                                $invoiceItems[] = [
                                    'description' => $metaValue,
                                    'amount' => $amount, // Use determined amount
                                    'currency' => strtoupper($transaction->currency),
                                    'account' => $accountDetails['account_code'],
                                    'typeCategoryID' => $typeCategoryID,
                                    'typeOptionID' => $typeOptionID,
                                    'destinationCategoryID' => $destinationCategoryID,
                                    'destinationOptionID' => $destinationOptionID,
                                ];
                                $accountFound = true;
                                break; // Exit loop once account is found
                            }
                        }

                        if (!$accountFound) {
                            echo "Warning: Metadata value not found in accounts array: $metaValue\n";
                        }
                    } else {
                        echo "Warning: No account code found in metadata value: $metaValue\n";
                    }
                }
            } else {
                echo "Charge metadata not available or not in expected format.\n";
            }


        } catch (\Exception $e) {
            echo "Error retrieving charge details: " . $e->getMessage();
        }
    }





// Step 3: Create Invoice in Xero
    if (count($invoiceItems) > 0) {

//        var_dump(count($invoiceItems));
        try {
            // Create Invoice object
            $invoice = new XeroAPI\XeroPHP\Models\Accounting\Invoice();
            $invoice->setType('ACCREC');  // Accounts Receivable (ACCREC)
            $invoice->setContact(new XeroAPI\XeroPHP\Models\Accounting\Contact(['contact_id' => 'c4806c10-11a4-424c-a497-a6d6cc936947'])); // Set appropriate contact

            // Set current date and due date
            $invoice->setDate(new DateTime());
            $invoice->setDueDate((new DateTime())->modify('+30 days')); // Due date in 30 days

            // Prepare line items for the invoice
            $lineItems = [];
            foreach ($invoiceItems as $item) {

                $lineItem = new XeroAPI\XeroPHP\Models\Accounting\LineItem();
                $lineItem->setDescription($item['description']);
                $lineItem->setQuantity(1);  // Assuming quantity is 1
                $lineItem->setUnitAmount($item['amount']);  // Set the amount from the Stripe transaction
                $lineItem->setAccountCode($item['account']); // Account code from Xero accounts

                // Set tracking for "Type" and "Destination"
                $trackingCategory = new XeroAPI\XeroPHP\Models\Accounting\LineItemTracking();
                $trackingCategory->setTrackingCategoryId($item['typeCategoryID']);
                $trackingCategory->setTrackingOptionId($item['typeOptionID']); // Set the correct option ID for the type

                $trackingProject = new XeroAPI\XeroPHP\Models\Accounting\LineItemTracking();
                $trackingProject->setTrackingCategoryId($item['destinationCategoryID']);
                $trackingProject->setTrackingOptionId($item['destinationOptionID']); // Set the correct option ID for the destination

                $lineItem->setTracking([$trackingCategory, $trackingProject]);
                $lineItems[] = $lineItem;
            }

            // Attach line items to the invoice
            $invoice->setLineItems($lineItems);
            $invoice->setCurrencyCode(strtoupper($transaction->currency));

            $invoice->setStatus('DRAFT');  // Set to DRAFT, change to 'AUTHORISED' if you want it immediately sent

            // Debugging
            var_dump($invoice);



             $createdInvoice = $accountingApi->createInvoices($xero_tenant_id, new XeroAPI\XeroPHP\Models\Accounting\Invoices(['invoices' => [$invoice]]));
             echo "Invoice created successfully! Invoice ID: " . $createdInvoice->getInvoices()[0]->getInvoiceId() . "\n";

        } catch (XeroAPI\XeroPHP\ApiException $e) {
            echo "Exception when creating Xero invoice: ", $e->getMessage(), PHP_EOL;
        }
    } else {
        echo "No invoice items found to create an invoice.\n";
    }



} catch (\Exception $e) {
    echo 'Error: ' . $e->getMessage();
}

?>









