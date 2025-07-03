<?php
// abdm_session_manager.php

class abdm_session_manager {
    private $accessToken = null;
    private $accessTokenExpiry = 0;
    private $config;

    public function __construct($config = []) {
        $credsConfig = require(__DIR__ . '/config.php');
        $v3Endpoints = require(__DIR__ . '/abdm_v3_config.php');
        $this->config = array_merge([
            'clientId' => '',
            'clientSecret' => '',
            'environment' => 'sbx',
            'gatewayBaseUrl' => 'https://dev.abdm.gov.in',
            'createSessionPath' => '/api/hiecm/gateway/v3/sessions',
            'useProxySettings' => false,
            'proxyHost' => '',
            'proxyPort' => 0,
            'connectionTimeout' => 3000,
            'responseTimeout' => 10,
            'callbackUrl' => '',
            'hipId' => '',
            'tokenCacheFile' => __DIR__ . '/abdm_token_cache.json' 
        ], $v3Endpoints, $credsConfig, $config);
    }

    private function loadCachedToken() {
        if (file_exists($this->config['tokenCacheFile'])) {
            $data = json_decode(file_get_contents($this->config['tokenCacheFile']), true);
            if (isset($data['accessToken'], $data['expiresAt']) && $data['expiresAt'] > time()) {
                $this->accessToken = $data['accessToken'];
                $this->accessTokenExpiry = $data['expiresAt'];
            }
        }
    }

    private function saveTokenToCache($accessToken, $expiresIn) {
        $this->accessToken = "Bearer " . $accessToken;
        $this->accessTokenExpiry = time() + $expiresIn - 60;
        $data = [
            'accessToken' => $this->accessToken,
            'expiresAt' => $this->accessTokenExpiry
        ];
        file_put_contents($this->config['tokenCacheFile'], json_encode($data));
    }

    private function getCurrentTimestamp() {
        $dt = new DateTime('now', new DateTimeZone("UTC"));
        return $dt->format("Y-m-d\TH:i:s.v\Z");
    }

    private function fetchAccessToken() {
        if (!$this->accessToken || time() >= $this->accessTokenExpiry) {
            $this->startSession();
        }
        return $this->accessToken;
    }

    public function startSession() {
        $response = $this->getSessionResponse([
            "clientId" => $this->config['clientId'],
            "clientSecret" => $this->config['clientSecret'],
            "grantType" => "client_credentials"
        ]);

        if (isset($response['accessToken'], $response['expiresIn'])) {
            $this->saveTokenToCache($response['accessToken'], $response['expiresIn']);
        } else {
            throw new Exception("Access token could not be fetched");
        }
    }

    public function getGatewayRequestHeaders() {
        return [
            'Content-Type: application/json',
            'X-CM-ID' => $this->config['environment'],
            'Authorization' => $this->fetchAccessToken()
        ];
    }

    private function getSessionResponse($data) {
        $url = rtrim($this->config['gatewayBaseUrl'], '/') . $this->config['createSessionPath'];
        $headers = $this->getDefaultSessionHeaders();
        return $this->makePostRequest($url, $data, $headers);
    }

    private function getDefaultSessionHeaders() {
        return [
            "Content-Type: application/json",
            "X-CM-ID: " . $this->config['environment'],
            "TIMESTAMP: " . $this->getCurrentTimestamp(),
            "REQUEST-ID: " . uniqid()
        ];
    }

    private function makePostRequest($url, $data, $headers) {
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT_MS, $this->config['connectionTimeout']);
        curl_setopt($curl, CURLOPT_TIMEOUT, $this->config['responseTimeout']);

        if ($this->config['useProxySettings']) {
            curl_setopt($curl, CURLOPT_PROXY, $this->config['proxyHost']);
            curl_setopt($curl, CURLOPT_PROXYPORT, $this->config['proxyPort']);
        }

        $response = curl_exec($curl);
        if (curl_errno($curl)) {
            error_log("cURL Error: " . curl_error($curl));
            curl_close($curl);
            return null;
        }

        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($response, true);
        } else {
            error_log("HTTP error ($httpCode): $response");
            echo "<pre>HTTP Error ($httpCode): $response</pre>";
            return null;
        }
    }

    public function getAccessToken() {
        return $this->fetchAccessToken();
    }

    public function generateLinkToken($patientDetails) {
        $url = rtrim($this->config['gatewayBaseUrl'], '/') . $this->config['generateLinkTokenPath'];
        $headers = [
            'Content-Type: application/json',
            'X-HIP-ID' => $this->config['hipId'],
            'X-CM-ID' => $this->config['environment'],
            'Authorization' => $this->fetchAccessToken(),
            'REQUEST-ID' => $this->generateRequestId(),
            'TIMESTAMP' => $this->getCurrentTimestamp()
        ];

        $body = [
            'abhaNumber' => $patientDetails['abhaNumber'],
            'abhaAddress' => $patientDetails['abhaAddress'],
            'name' => $patientDetails['name'],
            'gender' => $patientDetails['gender'],
            'yearOfBirth' => (int)$patientDetails['yearOfBirth']
        ];

        $response = $this->makePostRequest($url, $body, $headers);
        if (!$response || !isset($response['linkToken'])) {
            throw new Exception("No response or failed to generate link token.");
        }
        return $response['linkToken'];
    }

    private function generateRequestId() {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
