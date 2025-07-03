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
        throw new Exception("🔐 Encryption failed. Make sure the public key is valid.");
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
        throw new Exception("❌ OTP request failed with HTTP $httpCode\nResponse: $response");
    }

    $data = json_decode($response, true);
    if (empty($data['txnId'])) {
        throw new Exception("❌ txnId not found in OTP response.");
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

// === Step 3: Download ABHA Card PDF with retry ===
// === Step 3: Download ABHA Card (PNG/PDF) ===
function downloadAbhaCard($authToken, $xToken) {
    $url = "https://abhasbx.abdm.gov.in/abha/api/v3/profile/account/abha-card";
    $requestId = generateUuidV4();
    $timestamp = getISOTimestamp();

    $headers = [
        "X-Token: Bearer $xToken",
        "REQUEST-ID: $requestId",
        "TIMESTAMP: $timestamp",
        "Authorization: Bearer $authToken"
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADER => true // so we can read both header + body
    ]);

    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headersRaw = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    curl_close($ch);

    if (!in_array($httpCode, [200, 202])) {
        throw new Exception("❌ Failed to download ABHA card. HTTP $httpCode\n$response");
    }

    // Try to get content-type from headers
    preg_match('/content-type:\s*([\w\/\-]+)\s*/i', $headersRaw, $matches);
    $contentType = strtolower(trim($matches[1] ?? ''));

    // Fallback: Check magic bytes
    if (str_starts_with($body, "\x89PNG")) {
        $ext = "png";
    } elseif (str_starts_with($body, "%PDF")) {
        $ext = "pdf";
    } else {
        // fallback based on content-type
        $ext = match ($contentType) {
            'image/png' => 'png',
            'application/pdf' => 'pdf',
            default => 'bin'
        };
    }

    // Save file
    $filename = "abha_card.$ext";
    file_put_contents($filename, $body);

    echo "✅ ABHA Card saved as $filename\n";
}


// === MAIN EXECUTION ===
try {
    $aadhaar = readline("📥 Enter Aadhaar number (12 digits): ");
    if (!preg_match('/^\d{12}$/', $aadhaar)) {
        throw new Exception("⚠️ Invalid Aadhaar number. Must be exactly 12 digits.");
    }

    $encryptedAadhaar = encryptWithPublicKey($aadhaar);

    $session = new abdm_session_manager();
    $accessToken = $session->getAccessToken();

    echo "📨 Sending OTP request...\n";
    $txnId = requestOtp($encryptedAadhaar, $accessToken);
    echo "✅ OTP sent successfully. txnId: $txnId\n";

    $otp = readline("📥 Enter OTP received: ");
    if (!preg_match('/^\d{6}$/', $otp)) {
        throw new Exception("⚠️ Invalid OTP. Must be 6 digits.");
    }

    $encryptedOtp = encryptWithPublicKey($otp);

    $mobile = readline("📱 Enter linked mobile number: ");
    if (!preg_match('/^[6-9]\d{9}$/', $mobile)) {
        throw new Exception("⚠️ Invalid mobile number.");
    }

    echo "🔐 Verifying OTP and enrolling...\n";
    $result = verifyOtp($txnId, $encryptedOtp, $accessToken, $mobile);

    echo "✅ Verification Status: HTTP " . $result['httpCode'] . "\n";

    if ($result['httpCode'] !== 200 || empty($result['body']['tokens']['token'])) {
        throw new Exception("❌ Enrollment failed or token not received.\nResponse:\n" . $result['raw']);
    }

    $enrollmentToken = $result['body']['tokens']['token'];
    $authorizationToken = $session->getAccessToken();
    $requestId = generateUuidV4();
    $timestamp = getISOTimestamp();

    echo "⬇️  Downloading ABHA card...\n";
    downloadAbhaCard($authorizationToken, $enrollmentToken, $requestId, $timestamp);

} catch (Exception $e) {
    echo "\n🚨 Error: " . $e->getMessage() . "\n";
}
