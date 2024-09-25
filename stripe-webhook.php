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
$stripe_webhook_secret = $config['stripe']['webhook_secret'];

$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];


$event = null;

try {
    // Verify the webhook signature
    $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $stripe_webhook_secret);
} catch(\UnexpectedValueException $e) {
    // Invalid payload
    http_response_code(400);
    exit('Invalid payload');
} catch(\Stripe\Exception\SignatureVerificationException $e) {
    // Invalid signature
    http_response_code(400);
    exit('Invalid signature');
}

// Handle the event
switch ($event->type) {
    case 'payout.paid':
        $payout = $event->data->object;
        global $payoutId;
       $payoutId =  processPayoutPaid($payout);
       $payoutDate =  processPayout($payout);
        break;

    case 'payout.failed':
        $payout = $event->data->object;
        processPayoutFailed($payout);
        break;

    // Handle other event types if necessary
    default:
        echo 'Received unknown event type ' . $event->type;
}

// Return a 200 response to acknowledge receipt of the event
http_response_code(200);

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
        if (strtolower($text) === 'ba' && strtolower($key) === 'bespoke') {
            return $key;
        }

        $distance = levenshtein(strtolower($text), strtolower($key));
        if ($distance < $bestMatchScore) {
            $bestMatchScore = $distance;
            $bestMatchKey = $key;
        }
    }

    return $bestMatchKey;
}

function processPayout($payout){

    return $payout->arrival_date;
}
function processPayoutPaid($payout){

    return $payout->id;
}

// Convert Unix timestamp to a readable date format
$payoutDateFormatted = date('Y-m-d', $payoutDate); // Format as 'YYYY-MM-DD'


    try {
    // Step 1: Fetch Stripe Transactions
//    $payoutId = 'po_1Q20deDxoXu8eGE9lCo2PVGp'; // Use your specific payout ID
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
    $totalFees = 0;

    foreach ($transactions as $transaction) {

        if ($transaction->type === 'stripe_fee') {
            $totalFees += $transaction->amount / 100;
        }

        if ($transaction->type === 'charge') {
            $totalFees -= $transaction->fee / 100;
            try {
                $charge = \Stripe\Charge::retrieve($transaction->source);

                $customer = \Stripe\Customer::retrieve($charge->customer);
                $email = $customer->email ?? 'N/A';

                $description = $transaction->description;

                // Process metadata and description for discrepancies
                $descriptionLower = strtolower($description);
                $descriptionParts = explode(' ', $descriptionLower);

                $destinationPart = implode(' ', array_slice($descriptionParts, 2, -3));
                $typePart = end($descriptionParts);

                $mappedDestinationKey = findBestMatch($destinationPart, $categoryMapping['Destination']);
                $mappedTypeKey = findBestMatch($typePart, $categoryMapping['Type']);

                $mappedDestination = $mappedDestinationKey && isset($categoryMapping['Destination'][$mappedDestinationKey])
                    ? $categoryMapping['Destination'][$mappedDestinationKey]
                    : ['category_id' => null, 'option_id' => null];

                $mappedType = $mappedTypeKey && isset($categoryMapping['Type'][$mappedTypeKey])
                    ? $categoryMapping['Type'][$mappedTypeKey]
                    : ['category_id' => null, 'option_id' => null];

                $destinationCategoryID = $mappedDestination['category_id'] ?? 'null';
                $destinationOptionID = $mappedDestination['option_id'] ?? 'null';
                $typeCategoryID = $mappedType['category_id'] ?? 'null';
                $typeOptionID = $mappedType['option_id'] ?? 'null';

                if ($charge && isset($charge->metadata)) {
                    $metadataArray = $charge->metadata->toArray();
                    $metadataTotalAmount = 0;
                    $metadataEntries = [];
                    $discrepancyFound = false;

                    foreach ($metadataArray as $metaKey => $metaValue) {
                        $accountCode = null;
                        $patterns = [
                            '/\((\d{4})\)/', // Extract account code from the metadata
                            '/\b(\d{4})\b/',
                            '/\b(\d{4,5})\b/',
                        ];

                        // Look for account code in the metadata value
                        foreach ($patterns as $pattern) {
                            if (preg_match($pattern, $metaValue, $matches)) {
                                $accountCode = trim($matches[1]);
                                break;
                            }
                        }

                        if ($accountCode) {
                            $metaParts = explode(' ', $metaKey);
                               $metaParts = explode(' ', $metaKey);
                                if (count($metaParts) === 3) {
                                    $price = $metaParts[2];
                                    $metaAmount = $price; }

                            $metadataTotalAmount += $metaAmount; // Add to total for valid entries

                            // Check if the metadata contains "Discount"
                            if (strpos($metaValue, 'Discount') !== false) {
                                // Extract discount amount
                                if (preg_match('/Discount code.*?(\d+(?:\.\d+)?)/', $metaValue, $discountMatches)) {
                                    $discountAmount = (float)$discountMatches[1];

                                    // Line 1: Add the full amount (without the discount)
                                    $metadataEntries[] = [
                                        'description' => $metaValue . ' for ' . $email , // Full description
                                        'amount' => $metaAmount + $discountAmount , // Full amount before discount
                                        'currency' => strtoupper($transaction->currency),
                                        'account' => $accountCode,
                                        'typeCategoryID' => $typeCategoryID,
                                        'typeOptionID' => $typeOptionID,
                                        'destinationCategoryID' => $destinationCategoryID,
                                        'destinationOptionID' => $destinationOptionID,
                                    ];

                                    // Line 2: Add the discount
                                    $discountAccountCode = (strpos($metaValue, 'deferral') !== false) ? '5020' : '5008';
                                    $metadataEntries[] = [
                                        'description' => 'Discount for ' . $metaKey .' for ' .$email,
                                        'amount' => -$discountAmount, // Negative for discount
                                        'currency' => strtoupper($transaction->currency),
                                        'account' => $discountAccountCode,
                                        'typeCategoryID' => $typeCategoryID,
                                        'typeOptionID' => $typeOptionID,
                                        'destinationCategoryID' => $destinationCategoryID,
                                        'destinationOptionID' => $destinationOptionID,
                                    ];
                                } else {
                                    echo "Warning: No valid discount amount found in metadata value: $metaValue\n";
                                }
                            } else {
                                // No discount, regular flow
                                $metadataEntries[] = [
                                    'description' => $metaValue . ' for ' . $email , // Full description
                                    'amount' => $metaAmount,
                                    'currency' => strtoupper($transaction->currency),
                                    'account' => $accountCode,
                                    'typeCategoryID' => $typeCategoryID,
                                    'typeOptionID' => $typeOptionID,
                                    'destinationCategoryID' => $destinationCategoryID,
                                    'destinationOptionID' => $destinationOptionID,
                                ];
                            }
                        } else {
                            echo "Warning: No account code found in metadata value: $metaValue\n";
                        }
                    }

                    // Check for discrepancies
                    if (abs($metadataTotalAmount - ($transaction->amount / 100)) > 0.01) {
                        $discrepancyFound = true;
                    }

                    if ($discrepancyFound) {

                        $invoiceItems[] = [
                            'description' => "Discrepancy in metadata for transaction ID: {$transaction->id}. Email: {$email}",
                            'amount' => $transaction->amount / 100,
                            'currency' => strtoupper($transaction->currency),
                            'account' => null,
                            'typeCategoryID' => null,
                            'typeOptionID' => null,
                            'destinationCategoryID' => null,
                            'destinationOptionID' => null,
                        ];

                        echo "Discrepancy detected for transaction {$transaction->id}. Added to invoice with default values.\n";
                    } else {
                        // Add valid metadata entries to invoice items only if no discrepancy was found
                        foreach ($metadataEntries as $entry) {
                            $invoiceItems[] = $entry;
                        }
                    }
                } else {
                    echo "Charge metadata not available or not in expected format.\n";
                }




            } catch (\Exception $e) {
                echo "Error retrieving charge details: " . $e->getMessage();
            }
        }

        if ($transaction->type === 'refund') {
            try {
                $refund = \Stripe\Refund::retrieve($transaction->source);
                $charge = \Stripe\Charge::retrieve($refund->charge);

                $customer = \Stripe\Customer::retrieve($charge->customer);
                $email = $customer->email ?? 'N/A';

                // Process metadata and description for discrepancies
                $description = $charge->description;
                $descriptionLower = strtolower($description);
                $descriptionParts = explode(' ', $descriptionLower);

                $destinationPart = implode(' ', array_slice($descriptionParts, 2, -3));
                $typePart = end($descriptionParts);

                $mappedDestinationKey = findBestMatch($destinationPart, $categoryMapping['Destination']);
                $mappedTypeKey = findBestMatch($typePart, $categoryMapping['Type']);

                $mappedDestination = $mappedDestinationKey && isset($categoryMapping['Destination'][$mappedDestinationKey])
                    ? $categoryMapping['Destination'][$mappedDestinationKey]
                    : ['category_id' => null, 'option_id' => null];

                $mappedType = $mappedTypeKey && isset($categoryMapping['Type'][$mappedTypeKey])
                    ? $categoryMapping['Type'][$mappedTypeKey]
                    : ['category_id' => null, 'option_id' => null];

                $destinationCategoryID = $mappedDestination['category_id'] ?? 'null';
                $destinationOptionID = $mappedDestination['option_id'] ?? 'null';
                $typeCategoryID = $mappedType['category_id'] ?? 'null';
                $typeOptionID = $mappedType['option_id'] ?? 'null';

                if ($refund && isset($charge->metadata)) {
                    $metadataArray = $charge->metadata->toArray();
                    $metadataEntries = [];
                    $metadataTotalAmount = 0;
                    $discrepancyFound = false;

                    foreach ($metadataArray as $metaKey => $metaValue) {
                        $accountCode = null;
                        $patterns = [
                            '/\((\d{4})\)/',
                            '/\b(\d{4})\b/',
                            '/\b(\d{4,5})\b/',
                        ];

                        foreach ($patterns as $pattern) {
                            if (preg_match($pattern, $metaValue, $matches)) {
                                $accountCode = trim($matches[1]);
                                break;
                            }
                        }

                        if ($accountCode) {
                            $metaParts = explode(' ', $metaKey);
                            $metadataAmount = (count($metaParts) >= 3) ? (float)$metaParts[2] : $refund->amount / 100;
                            $metadataTotalAmount += $metadataAmount;



                            // Check if the metadata contains "Discount"
                            if (strpos($metaValue, 'Discount') !== false) {
                                // Extract discount amount
                                if (preg_match('/Discount code.*?(\d+(?:\.\d+)?)/', $metaValue, $discountMatches)) {
                                    $discountAmount = (float)$discountMatches[1];

                                    // Line 1: Add the full amount (without the discount)
                                    $metadataEntries[] = [
                                        'description' => $metaValue . 'for ' . $email , // Full description
                                        'amount' => $metadataAmount + $discountAmount, // Full amount before discount
                                        'currency' => strtoupper($transaction->currency),
                                        'account' => $accountCode,
                                        'typeCategoryID' => $typeCategoryID,
                                        'typeOptionID' => $typeOptionID,
                                        'destinationCategoryID' => $destinationCategoryID,
                                        'destinationOptionID' => $destinationOptionID,
                                    ];

                                    // Line 2: Add the discount
                                    $discountAccountCode = (strpos($metaValue, 'deferral') !== false) ? '5020' : '5008';
                                    $metadataEntries[] = [
                                        'description' => 'Discount for ' . $metaKey. ' for ' . $email,
                                        'amount' => -$discountAmount, // Negative for discount
                                        'currency' => strtoupper($transaction->currency),
                                        'account' => $discountAccountCode,
                                        'typeCategoryID' => $typeCategoryID,
                                        'typeOptionID' => $typeOptionID,
                                        'destinationCategoryID' => $destinationCategoryID,
                                        'destinationOptionID' => $destinationOptionID,
                                    ];
                                } else {
                                    echo "Warning: No valid discount amount found in metadata value: $metaValue\n";
                                }
                            } else {
                                // No discount, regular flow
                                $metadataEntries[] = [
                                    'description' => $metaValue . ' for ' . $email , // Full description
                                    'amount' => -$metadataAmount,
                                    'currency' => strtoupper($transaction->currency),
                                    'account' => $accountCode,
                                    'typeCategoryID' => $typeCategoryID,
                                    'typeOptionID' => $typeOptionID,
                                    'destinationCategoryID' => $destinationCategoryID,
                                    'destinationOptionID' => $destinationOptionID,
                                ];
                            }
                        } else {
                            echo "Warning: No account code found in metadata value: $metaValue\n";
                        }
                    }
                  $refundAmount =   $refund->amount / 100;
                    // Check for discrepancies in refund amounts
                    echo " metadata  total value: $metadataTotalAmount\n";
                    echo " refund  total value:$refundAmount \n";
                    if ($metadataTotalAmount != $refundAmount ) {
                        $discrepancyFound = true;
                    }
                    if ($discrepancyFound) {


                        $invoiceItems[] = [
                            'description' => "Refund Discrepancy in metadata for transaction ID: {$transaction->id}. Email: {$email}",
                            'amount' => -$refund->amount / 100,
                            'currency' => strtoupper($transaction->currency),
                            'account' => null,
                            'typeCategoryID' => null,
                            'typeOptionID' => null,
                            'destinationCategoryID' => null,
                            'destinationOptionID' => null,
                        ];

                        echo "Discrepancy detected for refund transaction {$transaction->id}. Added to invoice with default values.\n";
                    } else {
                        // Add valid metadata entries to invoice items only if no discrepancy was found
                        foreach ($metadataEntries as $entry) {
                            $invoiceItems[] = $entry;
                        }
                    }
                } else {
                    echo "Refund metadata not available or not in expected format.\n";
                }

            } catch (\Exception $e) {
                echo "Error retrieving refund details: " . $e->getMessage();
            }
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
            $invoice->setDueDate((new DateTime())->modify('+7 days')); // Due date in 30 days
            $invoice->setReference('Stripe Payout: ' . $payoutDateFormatted);
            // Prepare line items for the invoice
            $lineItems = [];
            foreach ($invoiceItems as $item) {

                $lineItem = new XeroAPI\XeroPHP\Models\Accounting\LineItem();
                $lineItem->setDescription($item['description']);
                $lineItem->setQuantity(1);  // Assuming quantity is 1
                $lineItem->setUnitAmount($item['amount']);  // Set the amount from the Stripe transaction
                $lineItem->setAccountCode($item['account']); // Account code from Xero accounts
                $lineItem->setTaxType('NONE');
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

            // Add Stripe fees as a line item if there are any fees
            if ($totalFees) {
                // Create a new line item for Stripe fees
                $feeLineItem = new XeroAPI\XeroPHP\Models\Accounting\LineItem();
                $feeLineItem->setDescription('Stripe Fee');  // Set description as "Stripe Fee"
                $feeLineItem->setQuantity(1);  // Quantity of 1
                $feeLineItem->setUnitAmount($totalFees);  // Set the fee as a negative value
                $feeLineItem->setAccountCode('5010');  // Use the account code for Merchant Charges
                $feeLineItem->setTaxType('NONE');  // No VAT for fees
                $feeLineItem->setLineAmount($totalFees);  // Total fee as negative

                // Add this fee line item to the line items array
                $lineItems[] = $feeLineItem;

            }


            // Attach line items to the invoice
            $invoice->setLineItems($lineItems);
            $invoice->setCurrencyCode(strtoupper($transaction->currency));

            $invoice->setStatus('DRAFT');  // Set to DRAFT, change to 'AUTHORISED' if you want it immediately sent

            // Debugging
//            var_dump($invoice);


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









