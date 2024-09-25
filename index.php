<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/vendor/autoload.php';

// Create the Slim app
$app = AppFactory::create();

// Display errors (only in development mode)
$app->addErrorMiddleware(true, true, true);

// Define a route for the homepage that includes the HTML content from 'home.php'
$app->get('/', function (Request $request, Response $response) {
    ob_start();
    include __DIR__ . '/home.php';  // Include the HTML content
    $htmlContent = ob_get_clean();
    $response->getBody()->write($htmlContent);
    return $response;
});
$app->get('/index.php', function (Request $request, Response $response) {
    return $response
        ->withHeader('Location', '/')
        ->withStatus(302); // 302 indicates a temporary redirect
});
// Include authorization.php logic in a route
$app->get('/authorization', function (Request $request, Response $response) {
    // Start the session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Include the authorization logic
    ob_start(); // Start output buffering
    require __DIR__ . '/authorization.php'; // Include your authorization file
    $response->getBody()->write(ob_get_clean()); // Get the output and clear the buffer

    return $response; // Return the response
});

$app->get('/callback', function (Request $request, Response $response) {
    // Include the callback.php file here
    require __DIR__ . '/callback.php';

    return $response; // Adjust this as needed based on your callback logic
});
$app->get('/authorizedResource', function (Request $request, Response $response) {
    // Include your authorized resource logic
    ob_start();
    include __DIR__ . '/authorizedResource.php'; // Include the authorized resource file
    $htmlContent = ob_get_clean();
    $response->getBody()->write($htmlContent);
    return $response;
});

$app->post('/stripe-webhook', function (Request $request, Response $response) {
    // Process the webhook without affecting the response
    processStripeWebhook($request);

    // Return a 200 status indicating that the webhook was received
    return $response->withStatus(200);
});

// Define a function to process the webhook
function processStripeWebhook(Request $request) {
    // Include the existing webhook logic here
    include __DIR__ . '/stripe-webhook.php';
}



// Define another route (e.g., echo route)
$app->post('/echo', function (Request $request, Response $response) {
    $json = json_decode((string) $request->getBody(), true);
    $response->getBody()->write(json_encode([
        'message' => $json['message'] ?? 'No message provided',
    ]));
    return $response->withHeader('Content-Type', 'application/json');
});

// You can add more routes here as needed

// Run the Slim app
$app->run();
