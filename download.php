<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . "/abdm_session_manager.php");

// === Encrypt using ABDM Public Key ===
function encryptWithPublicKey($plainText, $publicKeyPath = 'abdm_public.pem') {
    $inputFile = tempnam(sys_get_temp_dir(), 'input_plain');
    $outputFile = tempnam(sys_get_temp_dir(), 'input_enc');

    file_put_contents($inputFile, $plainText);

    $cmd = "openssl pkeyutl -encrypt -in " . escapeshellarg($inputFile) .
           " -out " . escapeshellarg($outputFile) .
           " -pubin -inkey " . escapeshellarg($publicKeyPath) .
           " -keyform PEM -pkeyopt rsa_padding_mode:oaep -pkeyopt rsa_oaep_md:sha1 -pkeyopt rsa_mgf1_md:sha1";

    shell_exec($cmd);

    if (!file_exists($outputFile) || filesize($outputFile) === 0) {
        unlink($inputFile); unlink($outputFile);
        throw new Exception("Encryption failed");
    }

    $encrypted = file_get_contents($outputFile);
    unlink($inputFile); unlink($outputFile);
    return base64_encode($encrypted);
}

// === UUIDv4 Generator ===
function generateUuidV4() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
}

// === Get Current ISO Timestamp ===
function getISOTimestamp() {
    return gmdate("Y-m-d\\TH:i:s.000\\Z");
}

// === Step 1: Send OTP Request ===
function requestOtp($aadhaarEncrypted, $accessToken) {
    $url = "https://abhasbx.abdm.gov.in/abha/api/v3/enrollment/request/otp";
    $requestId = generateUuidV4();
    $timestamp = getISOTimestamp();

    $headers = [
        "Content-Type: application/json",
        "REQUEST-ID: $requestId",
        "TIMESTAMP: $timestamp",
        "Authorization: $accessToken"
    ];

    $body = [
        "txnId" => "",
        "scope" => ["abha-enrol"],
        "loginHint" => "aadhaar",
        "loginId" => $aadhaarEncrypted,
        "otpSystem" => "aadhaar"
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => $headers
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception("OTP request failed with HTTP $httpCode. Response: $response");
    }

    $data = json_decode($response, true);
    if (empty($data['txnId'])) {
        throw new Exception("txnId not found in OTP response.");
    }

    return $data['txnId'];
}

// === Step 2: Verify OTP & Enrol ABHA ===
function verifyOtp($txnId, $otpEncrypted, $accessToken, $mobile) {
    $url = "https://abhasbx.abdm.gov.in/abha/api/v3/enrollment/enrol/byAadhaar";
    $requestId = generateUuidV4();
    $timestamp = getISOTimestamp();

    $headers = [
        "Content-Type: application/json",
        "REQUEST-ID: $requestId",
        "TIMESTAMP: $timestamp",
        "Authorization: $accessToken"
    ];

    $body = [
        "authData" => [
            "authMethods" => ["otp"],
            "otp" => [
                "txnId" => $txnId,
                "otpValue" => $otpEncrypted,
                "mobile" => $mobile
            ]
        ],
        "consent" => [
            "code" => "abha-enrollment",
            "version" => "1.4"
        ]
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => $headers
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        "httpCode" => $httpCode,
        "body" => json_decode($response, true),
        "raw" => $response
    ];
}

// === Step 3: Download ABHA Card PDF ===
function downloadAbhaCard($authToken, $xToken, $requestId, $timestamp) {
    $url = "https://abhasbx.abdm.gov.in/abha/api/v3/profile/account/abha-card";

    $headers = [
        "X-Token: Bearer $xToken",
        "REQUEST-ID: $requestId",
        "TIMESTAMP: $timestamp",
        "Authorization: Bearer $authToken"
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        file_put_contents("abha-card.pdf", $response);
        echo "✅ ABHA Card downloaded as 'abha-card.pdf'\n";
    } else {
        echo "❌ Failed to download ABHA card. HTTP $httpCode\n";
        echo "Response: $response\n";
    }
}

// === MAIN EXECUTION ===
try {
    $aadhaar = readline("Enter Aadhaar number: ");
    $encryptedAadhaar = encryptWithPublicKey($aadhaar);

    $session = new abdm_session_manager();
    $accessToken = $session->getAccessToken();

    echo "📨 Sending OTP request...\n";
    $txnId = requestOtp($encryptedAadhaar, $accessToken);
    echo "✅ OTP sent. txnId: $txnId\n";

    $otp = readline("Enter OTP received: ");
    $encryptedOtp = encryptWithPublicKey($otp);
    $mobile = "9650063029"; // ✅ Replace with correct linked mobile if needed

    echo "🔐 Verifying OTP and enrolling...\n";
    $result = verifyOtp($txnId, $encryptedOtp, $accessToken, $mobile);

    echo "✅ Verification Status: HTTP " . $result['httpCode'] . "\n";

    $enrollmentToken = $result['body']['tokens']['token'];
    $authorizationToken = $session->getAccessToken();
    $requestId = generateUuidV4();
    $timestamp = getISOTimestamp();

    echo "⬇️  Downloading ABHA card...\n";
    downloadAbhaCard($authorizationToken, $enrollmentToken, $requestId, $timestamp);

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
