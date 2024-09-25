<?php

class StorageClass
{
    private $filePath;

    public function __construct($filePath = 'tokens.json')
    {
        $this->filePath = $filePath;

        // Ensure the file exists
        if (!file_exists($this->filePath)) {
            file_put_contents($this->filePath, json_encode([]));
        }
    }

    public function getToken()
    {
        $tokens = $this->readTokens();

        // If it doesn't exist or is expired, return null
        if (empty($tokens) || ($tokens['expires'] !== null && $tokens['expires'] <= time())) {
            return null;
        }

        return $tokens;
    }

    public function setToken($token, $expires = null, $tenantId, $refreshToken, $idToken)
    {
        $tokens = [
            'token' => $token,
            'expires' => $expires,
            'tenant_id' => $tenantId,
            'refresh_token' => $refreshToken,
            'id_token' => $idToken,
        ];

        file_put_contents($this->filePath, json_encode($tokens));
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
        $content = file_get_contents($this->filePath);
        return json_decode($content, true);
    }
}
