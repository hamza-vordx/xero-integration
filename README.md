# Xero & Stripe Invoice Reconciliation Script

This PHP script integrates Stripe and Xero to automatically reconcile Stripe payments with Xero invoices.

## Features
- Fetches payments from Stripe.
- Matches the payments with Xero invoices.
- Automatically updates Xero invoices based on payments received.

## Prerequisites

1. PHP 7.4 or higher
2. Composer (to manage dependencies)
3. Xero API Credentials
4. Stripe API Credentials

## Installation

1. **Clone the repository:**
   ```bash
   git clone https://github.com/your-username/your-repo.git
   cd your-repo

2. **Install dependencies using Composer:**
   ```bash
    composer install
   
3. **Create and configure the config file:**
   You need to set up a config.php file with your API credentials for Xero and Stripe. To do this, copy the example-config.php to config.php using the following command:
   ```bash
    cp example-config.php config.php

Once copied, open the config.php file in your preferred text editor and replace the placeholder values with your actual credentials.

   ```bash
       return [
          'xero' => [
              'client_id' => 'your-xero-client-id-here',
              'client_secret' => 'your-xero-client-secret-here',
              'redirect_uri' => 'your-xero-redirect-uri-here',
              'tenant_id' => 'your-xero-tenant-id-here',
              'access_token' => 'your-xero-access-token-here',
          ],
          'stripe' => [
              'secret_key' => 'your-stripe-secret-key-here',
          ],
   ];
