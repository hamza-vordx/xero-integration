<?php

require 'vendor/autoload.php';

// Load config file
$config = require('config.php');

use Stripe\Stripe;
use XeroAPI\XeroPHP\Configuration;
use XeroAPI\XeroPHP\Api\AccountingApi;
use XeroAPI\XeroPHP\ApiException;
use XeroAPI\XeroPHP\Models\Accounting\Invoice;
use XeroAPI\XeroPHP\Models\Accounting\Payment;

// Xero API credentials
$xero_client_id = $config['xero']['client_id'];
$xero_client_secret = $config['xero']['client_secret'];
$xero_redirect_uri = $config['xero']['redirect_uri'];
$xero_tenant_id = $config['xero']['tenant_id'];
$xero_access_token = $config['xero']['access_token'];

// Stripe API credentials
$stripe_secret_key = $config['stripe']['secret_key'];

// Set up Stripe API
Stripe::setApiKey($stripe_secret_key);

// Initialize Xero API configuration
$xeroConfig = Configuration::getDefaultConfiguration()->setAccessToken($xero_access_token);

// Create Xero Accounting API instance
$accountingApi = new AccountingApi(
    new GuzzleHttp\Client(),
    $xeroConfig
);

try {
    // Fetch all Stripe payments
    $stripePayments = \Stripe\PaymentIntent::all([
        'limit' => 100, // Adjust the limit as necessary
    ]);

    foreach ($stripePayments->data as $payment) {
        // Extract Stripe payment details

        $stripePaymentId = $payment->id;
        $amountPaid = $payment->amount_received / 100; // Stripe amount is in cents
        $currency = $payment->currency;
        $stripeCustomerEmail = $payment->charges->data[0]->billing_details->email;

        // Search for corresponding Xero invoice by customer email
        $invoices = $accountingApi->getInvoices($xero_tenant_id, [
            'where' => 'Contact.EmailAddress=="' . $stripeCustomerEmail . '"',
            'statuses' => 'AUTHORISED',
        ]);

        foreach ($invoices->getInvoices() as $invoice) {
            $xeroInvoiceId = $invoice->getInvoiceId();
            $invoiceAmountDue = $invoice->getAmountDue();

            // Check if the invoice amount matches the Stripe payment
            if ($amountPaid >= $invoiceAmountDue) {
                // Create a Xero payment object
                $payment = new Payment();
                $payment->setInvoice(new Invoice(['invoice_id' => $xeroInvoiceId]));
                $payment->setAmount($invoiceAmountDue);
                $payment->setDate(new DateTime());
                $payment->setCurrencyRate(1.0); // Assuming same currency for both Stripe and Xero

                // Send payment to Xero
                $accountingApi->createPayment($xero_tenant_id, $payment);

                echo "Successfully reconciled Stripe payment ID $stripePaymentId with Xero Invoice ID $xeroInvoiceId\n";
            }
        }
    }

} catch (ApiException $e) {
    echo 'Exception when calling AccountingApi->getInvoices: ', $e->getMessage(), PHP_EOL;
}

