<?php

use Google\Cloud\Storage\StorageClient;

class StorageClass
{
    private $bucketName;
    private $storage;

    // Set a default bucket name in the constructor
    public function __construct($bucketName = 'stripe-xero-integration-436615.appspot.com')
    {
        $this->bucketName = $bucketName;
        // Initialize the Google Cloud Storage client
        $this->storage = new StorageClient();
    }

    public function getToken()
    {
        // Logic to read token from Google Cloud Storage
        $bucket = $this->storage->bucket($this->bucketName);
        $object = $bucket->object('tokens.json');

        if ($object->exists()) {
            $content = $object->downloadAsString();
            $tokens = json_decode($content, true);

            // Check if token is expired
            if (!empty($tokens) && ($tokens['expires'] !== null && $tokens['expires'] > time())) {
                return $tokens;
            }
        }

        return null; // No valid token found
    }

    public function setToken($token, $expires, $tenantId, $refreshToken, $idToken)
    {
        $tokens = [
            'token' => $token,
            'expires' => $expires,
            'tenant_id' => $tenantId,
            'refresh_token' => $refreshToken,
            'id_token' => $idToken,
        ];

        // Save the token to Google Cloud Storage
        $bucket = $this->storage->bucket($this->bucketName);
        $bucket->upload(json_encode($tokens), [
            'name' => 'tokens.json',
        ]);
    }

    public function getAccessToken()
    {
        $tokens = $this->readTokens();
        return $tokens['token'] ?? null;
    }

    public function getRefreshToken()
    {
        $tokens = $this->readTokens();
        return $tokens['refresh_token'] ?? null;
    }

    public function getExpires()
    {
        $tokens = $this->readTokens();
        return $tokens['expires'] ?? null;
    }

    public function getXeroTenantId()
    {
        $tokens = $this->readTokens();
        return $tokens['tenant_id'] ?? null;
    }

    public function getIdToken()
    {
        $tokens = $this->readTokens();
        return $tokens['id_token'] ?? null;
    }

    public function getHasExpired()
    {
        $tokens = $this->readTokens();
        return empty($tokens) || (time() > ($tokens['expires'] ?? 0));
    }

    private function readTokens()
    {
        // Logic to read tokens from Google Cloud Storage
        $bucket = $this->storage->bucket($this->bucketName);
        $object = $bucket->object('tokens.json');

        if ($object->exists()) {
            $content = $object->downloadAsString();
            return json_decode($content, true);
        }

        return []; // Return an empty array if the tokens file doesn't exist
    }
}
