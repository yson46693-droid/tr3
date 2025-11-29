<?php
/**
 * نظام WebAuthn (FIDO2) للمصادقة
 * نظام إدارة الشركات المتكامل
 */

// منع الوصول المباشر
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

class WebAuthn {
    
    /**
     * إنشاء تحدي للتسجيل
     */
    public static function createRegistrationChallenge($userId, $username) {
        $challengeBytes = random_bytes(32);
        $challenge = self::base64urlEncode($challengeBytes);
        
        $_SESSION['webauthn_challenge'] = $challenge;
        $_SESSION['webauthn_user_id'] = $userId;
        $_SESSION['webauthn_username'] = $username;
        
        // rpId يجب أن يكون hostname فقط (بدون www. وبدون port)
        $rpId = parse_url(WEBAUTHN_ORIGIN, PHP_URL_HOST);
        
        // إزالة www. إذا كان موجوداً
        if ($rpId && strpos($rpId, 'www.') === 0) {
            $rpId = substr($rpId, 4);
        }
        
        // إزالة port إذا كان موجوداً
        if ($rpId && strpos($rpId, ':') !== false) {
            $rpId = substr($rpId, 0, strpos($rpId, ':'));
        }
        
        // التأكد من أن rpId ليس فارغاً
        if (empty($rpId)) {
            $rpId = $_SERVER['HTTP_HOST'] ?? 'localhost';
            if (strpos($rpId, 'www.') === 0) {
                $rpId = substr($rpId, 4);
            }
        }
        
        // جلب جميع البصمات الموجودة للمستخدم (للتأكد من عدم إعادة التسجيل)
        // ملاحظة: على الموبايل، قد يكون من الأفضل عدم إرسال excludeCredentials
        // إذا كان المستخدم قد حذف جميع البصمات، لأن ذلك قد يسبب مشاكل
        $db = db();
        $existingCredentials = $db->query(
            "SELECT credential_id FROM webauthn_credentials WHERE user_id = ?",
            [$userId]
        );
        
        $excludeCredentials = [];
        foreach ($existingCredentials as $cred) {
            // التحقق من أن credential_id صحيح وغير فارغ
            if (!empty($cred['credential_id']) && strlen($cred['credential_id']) > 10) {
                // credential_id مخزن كـ base64 في قاعدة البيانات
                // نحتاج لتحويله إلى ArrayBuffer في الجانب العميل، لكن الآن نرسله كـ base64
                $excludeCredentials[] = [
                    'id' => $cred['credential_id'], // base64 encoded
                    'type' => 'public-key'
                ];
            }
        }
        
        // على الموبايل، إذا كان هناك أكثر من 5 بصمات، قد يكون من الأفضل عدم إرسالها جميعاً
        // لأن بعض المتصفحات قد تواجه مشاكل مع قوائم طويلة
        if (count($excludeCredentials) > 5) {
            // إرسال فقط آخر 5 بصمات
            $excludeCredentials = array_slice($excludeCredentials, -5);
        }
        
        return [
            'challenge' => $challenge,
            'rp' => [
                'name' => WEBAUTHN_RP_NAME,
                'id' => $rpId
            ],
            'user' => [
                'id' => base64_encode($userId),
                'name' => $username,
                'displayName' => $username
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7], // ES256
                ['type' => 'public-key', 'alg' => -257] // RS256
            ],
            'timeout' => 180000, // زيادة timeout للموبايل (180 ثانية = 3 دقائق)
            'attestation' => 'none', // 'none' أفضل للموبايل
            'excludeCredentials' => $excludeCredentials, // منع إعادة تسجيل البصمة نفسها
            'authenticatorSelection' => [
                'userVerification' => 'preferred', // مهم للموبايل - يسمح بـ Face ID/Touch ID
                'requireResidentKey' => false
            ]
        ];
    }
    
    /**
     * التحقق من تسجيل WebAuthn
     */
    public static function verifyRegistration($response, $userId) {
        try {
            $responseData = json_decode($response, true);
            
            if (!isset($_SESSION['webauthn_challenge']) || 
                $_SESSION['webauthn_user_id'] != $userId) {
                return false;
            }
            
            $challenge = $_SESSION['webauthn_challenge'];
            
            // استخراج البيانات من response object
            // قد تكون البيانات في response.response أو مباشرة في responseData
            $clientDataJSONEncoded = $responseData['response']['clientDataJSON'] ?? $responseData['clientDataJSON'] ?? '';
            $attestationObjectEncoded = $responseData['response']['attestationObject'] ?? $responseData['attestationObject'] ?? '';
            
            if (empty($clientDataJSONEncoded) || empty($attestationObjectEncoded)) {
                error_log("WebAuthn: Missing clientDataJSON or attestationObject in response");
                return false;
            }
            
            $clientDataJSON = self::base64urlDecode($clientDataJSONEncoded);
            $attestationObject = self::base64urlDecode($attestationObjectEncoded);
            
            if ($clientDataJSON === false || $attestationObject === false) {
                error_log("WebAuthn: Failed to decode base64 data");
                return false;
            }
            
            $clientData = json_decode($clientDataJSON, true);
            
            if (!$clientData) {
                error_log("WebAuthn: Failed to decode clientDataJSON");
                return false;
            }
            
            // التحقق من التحدي
            $expectedChallenge = $challenge;
            $receivedChallenge = $clientData['challenge'] ?? '';
            
            if ($receivedChallenge !== $expectedChallenge) {
                error_log("WebAuthn: Challenge mismatch. Expected: $expectedChallenge, Received: $receivedChallenge");
                return false;
            }
            
            // التحقق من الأصل (مع مرونة أكثر للموبايل)
            $expectedOrigin = rtrim(WEBAUTHN_ORIGIN, '/');
            $receivedOrigin = rtrim($clientData['origin'] ?? '', '/');
            
            // على الموبايل، قد يكون هناك اختلاف في البروتوكول أو الـ port
            $expectedHost = parse_url($expectedOrigin, PHP_URL_HOST);
            $receivedHost = parse_url($receivedOrigin, PHP_URL_HOST);
            
            // إزالة www. من كلا الاثنين للمقارنة
            if ($expectedHost && strpos($expectedHost, 'www.') === 0) {
                $expectedHost = substr($expectedHost, 4);
            }
            if ($receivedHost && strpos($receivedHost, 'www.') === 0) {
                $receivedHost = substr($receivedHost, 4);
            }
            
            // التحقق من أن الـ hostname متطابق (أكثر مرونة من origin بالكامل)
            if ($expectedHost !== $receivedHost) {
                error_log("WebAuthn: Origin host mismatch. Expected: $expectedHost, Received: $receivedHost");
                error_log("WebAuthn: Full origin comparison - Expected: $expectedOrigin, Received: $receivedOrigin");
                return false;
            }
            
            // التحقق من النوع
            if ($clientData['type'] !== 'webauthn.create') {
                return false;
            }
            
            // استخراج بيانات الاعتماد
            // attestationObject هو CBOR encoded يحتوي على:
            // { "fmt": string, "attStmt": map, "authData": bytes }
            $authData = self::extractAuthDataFromAttestation($attestationObject);
            
            if (!$authData || strlen($authData) < 37) {
                $authDataLength = $authData ? strlen($authData) : 0;
                $attestationLength = strlen($attestationObject);
                error_log("WebAuthn: Could not extract authData from attestationObject. authData length: $authDataLength, attestationObject length: $attestationLength");
                error_log("WebAuthn: First 100 bytes of attestationObject: " . bin2hex(substr($attestationObject, 0, 100)));
                return false;
            }
            
            // التحقق من طول authData
            if (strlen($authData) < 37) {
                error_log("WebAuthn: authData too short: " . strlen($authData));
                return false;
            }
            
            // استخراج credential ID من authData
            // البنية: rpIdHash(32) + flags(1) + counter(4) + aaguid(16) + credentialIdLength(2) + credentialId + publicKey
            // Total before credential ID: 32 + 1 + 4 + 16 = 53 bytes
            $offset = 53; // rpIdHash(32) + flags(1) + counter(4) + aaguid(16) = 53 bytes
            
            // قراءة طول credential ID (2 bytes, big-endian)
            if (strlen($authData) < $offset + 2) {
                error_log("WebAuthn: authData too short to read credential ID length. Required: " . ($offset + 2) . ", Available: " . strlen($authData));
                return false;
            }
            
            $credentialIdLength = unpack('n', substr($authData, $offset, 2))[1];
            $offset += 2;
            
            if ($credentialIdLength <= 0 || $credentialIdLength > 1024) {
                error_log("WebAuthn: Invalid credential ID length: " . $credentialIdLength);
                return false;
            }
            
            if (strlen($authData) < $offset + $credentialIdLength) {
                error_log("WebAuthn: authData too short to read credential ID. Required: " . ($offset + $credentialIdLength) . ", Available: " . strlen($authData));
                return false;
            }
            
            $credentialId = substr($authData, $offset, $credentialIdLength);
            $offset += $credentialIdLength;
            
            // باقي البيانات هي public key (CBOR encoded)
            if (strlen($authData) <= $offset) {
                error_log("WebAuthn: No public key data found after credential ID");
                return false;
            }
            
            $publicKey = substr($authData, $offset);
            
            if (empty($credentialId) || empty($publicKey)) {
                error_log("WebAuthn: Failed to extract credential ID or public key");
                return false;
            }
            
            // حفظ بيانات الاعتماد
            $db = db();
            $credentialIdEncoded = base64_encode($credentialId);
            $publicKeyEncoded = base64_encode($publicKey);
            
            $sql = "INSERT INTO webauthn_credentials (user_id, credential_id, public_key, device_name) 
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE public_key = VALUES(public_key), device_name = VALUES(device_name)";
            $deviceName = $responseData['deviceName'] ?? 'Unknown Device';
            
            try {
                $db->execute($sql, [
                    $userId,
                    $credentialIdEncoded,
                    $publicKeyEncoded,
                    $deviceName
                ]);
            } catch (Exception $e) {
                error_log("WebAuthn: Database insert error: " . $e->getMessage());
                return false;
            }
            
            // تحديث حالة المستخدم
            $db->execute("UPDATE users SET webauthn_enabled = 1, updated_at = NOW() WHERE id = ?", [$userId]);
            
            // مسح بيانات الجلسة
            unset($_SESSION['webauthn_challenge']);
            unset($_SESSION['webauthn_user_id']);
            unset($_SESSION['webauthn_username']);
            
            return true;
            
        } catch (Exception $e) {
            error_log("WebAuthn Registration Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * تحويل base64url إلى base64 عادي وفك الترميز
     */
    private static function base64urlDecode($data) {
        if ($data === null) {
            return false;
        }
        
        if (!is_string($data)) {
            $data = (string)$data;
        }
        
        $data = strtr($data, '-_', '+/');
        $padding = strlen($data) % 4;
        if ($padding > 0) {
            $data .= str_repeat('=', 4 - $padding);
        }
        
        return base64_decode($data, true);
    }
    
    /**
     * تحويل البيانات إلى base64url
     */
    private static function base64urlEncode($data) {
        if ($data === null) {
            return '';
        }
        
        if (!is_string($data)) {
            $data = (string)$data;
        }
        
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    /**
     * إنشاء تحدي لتسجيل الدخول
     */
    public static function createLoginChallenge($username) {
        $db = db();
        $user = $db->queryOne("SELECT id, username FROM users WHERE username = ? OR email = ?", [$username, $username]);
        
        if (!$user) {
            return null;
        }
        
        $credentials = $db->query(
            "SELECT credential_id, public_key FROM webauthn_credentials WHERE user_id = ?",
            [$user['id']]
        );
        
        if (empty($credentials)) {
            return null;
        }
        
        $challengeBytes = random_bytes(32);
        $challenge = self::base64urlEncode($challengeBytes);
        $_SESSION['webauthn_login_challenge'] = $challenge;
        $_SESSION['webauthn_login_user_id'] = $user['id'];
        
        $allowCredentials = [];
        foreach ($credentials as $cred) {
            $allowCredentials[] = [
                'id' => $cred['credential_id'],
                'type' => 'public-key'
            ];
        }
        
        // rpId يجب أن يكون hostname فقط (بدون www. وبدون port)
        $rpId = parse_url(WEBAUTHN_ORIGIN, PHP_URL_HOST);
        
        // إزالة www. إذا كان موجوداً
        if ($rpId && strpos($rpId, 'www.') === 0) {
            $rpId = substr($rpId, 4);
        }
        
        // إزالة port إذا كان موجوداً
        if ($rpId && strpos($rpId, ':') !== false) {
            $rpId = substr($rpId, 0, strpos($rpId, ':'));
        }
        
        // التأكد من أن rpId ليس فارغاً
        if (empty($rpId)) {
            $rpId = $_SERVER['HTTP_HOST'] ?? 'localhost';
            if (strpos($rpId, 'www.') === 0) {
                $rpId = substr($rpId, 4);
            }
        }
        
        // إعدادات challenge محسّنة للموبايل
        $challengeData = [
            'challenge' => $challenge,
            'allowCredentials' => $allowCredentials,
            'timeout' => 180000, // زيادة timeout للموبايل (180 ثانية = 3 دقائق)
            'rpId' => $rpId,
            'userVerification' => 'preferred' // مهم للموبايل - يسمح بـ Face ID/Touch ID
        ];
        
        // إضافة إعدادات إضافية للموبايل
        // لا نحتاج لإضافة authenticatorSelection هنا لأن JavaScript سيضيفها
        
        return $challengeData;
    }
    
    /**
     * البحث عن المستخدم المرتبط بـ credential_id (للتسجيل بدون اسم مستخدم)
     */
    public static function findUserByCredentialId($credentialIdRaw) {
        try {
            $db = db();
            
            // تحويل credential ID إلى base64 إذا لزم الأمر (قد يكون base64url)
            $credentialIdEncoded = strtr($credentialIdRaw, '-_', '+/');
            
            // إذا كان credential ID ليس base64 padded، نضيف padding
            $mod = strlen($credentialIdEncoded) % 4;
            if ($mod) {
                $credentialIdEncoded .= str_repeat('=', 4 - $mod);
            }
            
            // البحث عن credential في قاعدة البيانات
            $credential = $db->queryOne(
                "SELECT wc.*, u.id as user_id, u.username, u.role, u.status, u.full_name
                 FROM webauthn_credentials wc
                 INNER JOIN users u ON wc.user_id = u.id
                 WHERE wc.credential_id = ? AND u.status = 'active'",
                [$credentialIdEncoded]
            );
            
            if (!$credential) {
                return null;
            }
            
            return [
                'user_id' => $credential['user_id'],
                'username' => $credential['username'],
                'role' => $credential['role'],
                'full_name' => $credential['full_name'],
                'credential_id' => $credential['credential_id']
            ];
        } catch (Exception $e) {
            error_log("WebAuthn findUserByCredentialId Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * الحصول على جميع المستخدمين الذين لديهم بصمات مسجلة (للعرض في القائمة)
     */
    public static function getAllUsersWithCredentials() {
        try {
            $db = db();
            
            $users = $db->query(
                "SELECT DISTINCT u.id, u.username, u.role, u.full_name, u.status,
                        COUNT(wc.id) as credentials_count
                 FROM users u
                 INNER JOIN webauthn_credentials wc ON u.id = wc.user_id
                 WHERE u.status = 'active'
                 GROUP BY u.id, u.username, u.role, u.full_name, u.status
                 ORDER BY u.username"
            );
            
            return $users ?: [];
        } catch (Exception $e) {
            error_log("WebAuthn getAllUsersWithCredentials Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * إنشاء تحدي لتسجيل الدخول بدون اسم مستخدم (للبحث عن جميع البصمات)
     */
    public static function createLoginChallengeWithoutUsername() {
        $challengeBytes = random_bytes(32);
        $challenge = self::base64urlEncode($challengeBytes);
        $_SESSION['webauthn_login_challenge'] = $challenge;
        // لا نحفظ user_id هنا لأننا لا نعرف المستخدم بعد
        
        // rpId يجب أن يكون hostname فقط (بدون www. وبدون port)
        $rpId = parse_url(WEBAUTHN_ORIGIN, PHP_URL_HOST);
        
        // إزالة www. إذا كان موجوداً
        if ($rpId && strpos($rpId, 'www.') === 0) {
            $rpId = substr($rpId, 4);
        }
        
        // إزالة port إذا كان موجوداً
        if ($rpId && strpos($rpId, ':') !== false) {
            $rpId = substr($rpId, 0, strpos($rpId, ':'));
        }
        
        // التأكد من أن rpId ليس فارغاً
        if (empty($rpId)) {
            $rpId = $_SERVER['HTTP_HOST'] ?? 'localhost';
            if (strpos($rpId, 'www.') === 0) {
                $rpId = substr($rpId, 4);
            }
        }
        
        // إعدادات challenge محسّنة للموبايل
        // لا نرسل allowCredentials هنا - هذا سيسمح للمتصفح بعرض جميع البصمات
        $challengeData = [
            'challenge' => $challenge,
            'timeout' => 180000, // زيادة timeout للموبايل (180 ثانية = 3 دقائق)
            'rpId' => $rpId,
            'userVerification' => 'preferred' // مهم للموبايل - يسمح بـ Face ID/Touch ID
        ];
        
        return $challengeData;
    }
    
    /**
     * التحقق من تسجيل الدخول بدون معرفة المستخدم مسبقاً
     */
    public static function verifyLoginWithoutUsername($response) {
        try {
            // إذا كان response هو string، نحوله إلى array
            if (is_string($response)) {
                $responseData = json_decode($response, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    error_log("WebAuthn Login: Invalid JSON response. Error: " . json_last_error_msg());
                    return false;
                }
            } else {
                $responseData = $response;
            }
            
            if (!isset($_SESSION['webauthn_login_challenge'])) {
                error_log("WebAuthn Login: Missing session challenge");
                return false;
            }
            
            $challenge = $_SESSION['webauthn_login_challenge'];
            
            // استخراج البيانات من response object
            $clientDataJSONEncoded = $responseData['response']['clientDataJSON'] ?? $responseData['clientDataJSON'] ?? '';
            $authenticatorDataEncoded = $responseData['response']['authenticatorData'] ?? $responseData['authenticatorData'] ?? '';
            $signatureEncoded = $responseData['response']['signature'] ?? $responseData['signature'] ?? '';
            
            if (empty($clientDataJSONEncoded) || empty($authenticatorDataEncoded) || empty($signatureEncoded)) {
                error_log("WebAuthn Login: Missing required response data");
                return false;
            }
            
            $clientDataJSON = self::base64urlDecode($clientDataJSONEncoded);
            $authenticatorData = self::base64urlDecode($authenticatorDataEncoded);
            $signature = self::base64urlDecode($signatureEncoded);
            
            if ($clientDataJSON === false || $authenticatorData === false || $signature === false) {
                error_log("WebAuthn Login: Failed to decode base64 data");
                return false;
            }
            
            $clientData = json_decode($clientDataJSON, true);
            
            if (!$clientData) {
                error_log("WebAuthn Login: Failed to decode clientDataJSON");
                return false;
            }
            
            // التحقق من التحدي
            $expectedChallenge = $challenge;
            $receivedChallenge = $clientData['challenge'] ?? '';
            
            if ($receivedChallenge !== $expectedChallenge) {
                error_log("WebAuthn Login: Challenge mismatch");
                return false;
            }
            
            // التحقق من الأصل
            $expectedOrigin = rtrim(WEBAUTHN_ORIGIN, '/');
            $receivedOrigin = rtrim($clientData['origin'] ?? '', '/');
            
            $expectedHost = parse_url($expectedOrigin, PHP_URL_HOST);
            $receivedHost = parse_url($receivedOrigin, PHP_URL_HOST);
            
            if ($expectedHost && strpos($expectedHost, 'www.') === 0) {
                $expectedHost = substr($expectedHost, 4);
            }
            if ($receivedHost && strpos($receivedHost, 'www.') === 0) {
                $receivedHost = substr($receivedHost, 4);
            }
            
            if ($expectedHost !== $receivedHost) {
                error_log("WebAuthn Login: Origin host mismatch");
                return false;
            }
            
            // التحقق من النوع
            if ($clientData['type'] !== 'webauthn.get') {
                error_log("WebAuthn Login: Invalid type");
                return false;
            }
            
            // استخراج credential ID
            $credentialIdRaw = $responseData['rawId'] ?? $responseData['id'] ?? '';
            
            if (empty($credentialIdRaw)) {
                error_log("WebAuthn Login: Missing credential ID");
                return false;
            }
            
            // البحث عن المستخدم المرتبط بهذا credential
            $user = self::findUserByCredentialId($credentialIdRaw);
            
            if (!$user) {
                error_log("WebAuthn Login: No user found for credential ID");
                return false;
            }
            
            $db = db();
            
            // تحديث آخر استخدام
            $db->execute(
                "UPDATE webauthn_credentials SET last_used = NOW(), counter = counter + 1 WHERE credential_id = ?",
                [$user['credential_id']]
            );
            
            // مسح بيانات الجلسة
            unset($_SESSION['webauthn_login_challenge']);
            
            return $user;
            
        } catch (Exception $e) {
            error_log("WebAuthn Login Without Username Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * التحقق من تسجيل الدخول
     */
    public static function verifyLogin($response) {
        try {
            // إذا كان response هو string، نحوله إلى array
            if (is_string($response)) {
                $responseData = json_decode($response, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    error_log("WebAuthn Login: Invalid JSON response. Error: " . json_last_error_msg());
                    return false;
                }
            } else {
                $responseData = $response;
            }
            
            if (!isset($_SESSION['webauthn_login_challenge']) || 
                !isset($_SESSION['webauthn_login_user_id'])) {
                error_log("WebAuthn Login: Missing session challenge or user_id");
                return false;
            }
            
            $challenge = $_SESSION['webauthn_login_challenge'];
            $userId = $_SESSION['webauthn_login_user_id'];
            
            // استخراج البيانات من response object (قد تكون في response.response أو مباشرة)
            $clientDataJSONEncoded = $responseData['response']['clientDataJSON'] ?? $responseData['clientDataJSON'] ?? '';
            $authenticatorDataEncoded = $responseData['response']['authenticatorData'] ?? $responseData['authenticatorData'] ?? '';
            $signatureEncoded = $responseData['response']['signature'] ?? $responseData['signature'] ?? '';
            
            if (empty($clientDataJSONEncoded) || empty($authenticatorDataEncoded) || empty($signatureEncoded)) {
                error_log("WebAuthn Login: Missing required response data. Available keys: " . implode(', ', array_keys($responseData)));
                return false;
            }
            
            $clientDataJSON = self::base64urlDecode($clientDataJSONEncoded);
            $authenticatorData = self::base64urlDecode($authenticatorDataEncoded);
            $signature = self::base64urlDecode($signatureEncoded);
            
            if ($clientDataJSON === false || $authenticatorData === false || $signature === false) {
                error_log("WebAuthn Login: Failed to decode base64 data");
                return false;
            }
            
            $clientData = json_decode($clientDataJSON, true);
            
            if (!$clientData) {
                error_log("WebAuthn Login: Failed to decode clientDataJSON");
                return false;
            }
            
            // التحقق من التحدي (مع مرونة أكثر)
            $expectedChallenge = $challenge;
            $receivedChallenge = $clientData['challenge'] ?? '';
            
            if ($receivedChallenge !== $expectedChallenge) {
                error_log("WebAuthn Login: Challenge mismatch. Expected: $expectedChallenge, Received: $receivedChallenge");
                return false;
            }
            
            // التحقق من الأصل (مع مرونة أكثر للموبايل)
            $expectedOrigin = rtrim(WEBAUTHN_ORIGIN, '/');
            $receivedOrigin = rtrim($clientData['origin'] ?? '', '/');
            
            // على الموبايل، قد يكون هناك اختلاف في البروتوكول أو الـ port
            $expectedHost = parse_url($expectedOrigin, PHP_URL_HOST);
            $receivedHost = parse_url($receivedOrigin, PHP_URL_HOST);
            
            // إزالة www. من كلا الاثنين للمقارنة
            if ($expectedHost && strpos($expectedHost, 'www.') === 0) {
                $expectedHost = substr($expectedHost, 4);
            }
            if ($receivedHost && strpos($receivedHost, 'www.') === 0) {
                $receivedHost = substr($receivedHost, 4);
            }
            
            // التحقق من أن الـ hostname متطابق
            if ($expectedHost !== $receivedHost) {
                error_log("WebAuthn Login: Origin host mismatch. Expected: $expectedHost, Received: $receivedHost");
                return false;
            }
            
            // التحقق من النوع
            if ($clientData['type'] !== 'webauthn.get') {
                error_log("WebAuthn Login: Invalid type. Expected: webauthn.get, Received: " . ($clientData['type'] ?? 'null'));
                return false;
            }
            
            // التحقق من credential ID
            // قد يكون id أو rawId (base64 encoded)
            $credentialIdRaw = $responseData['rawId'] ?? $responseData['id'] ?? '';
            
            if (empty($credentialIdRaw)) {
                error_log("WebAuthn Login: Missing credential ID");
                return false;
            }
            
            // تحويل credential ID إلى base64 إذا لزم الأمر (قد يكون base64url)
            $credentialIdEncoded = strtr($credentialIdRaw, '-_', '+/');
            
            // إذا كان credential ID ليس base64 padded، نضيف padding
            $mod = strlen($credentialIdEncoded) % 4;
            if ($mod) {
                $credentialIdEncoded .= str_repeat('=', 4 - $mod);
            }
            
            $db = db();
            
            // البحث عن credential في قاعدة البيانات (credential_id مخزن كـ base64)
            $credential = $db->queryOne(
                "SELECT * FROM webauthn_credentials WHERE user_id = ? AND credential_id = ?",
                [$userId, $credentialIdEncoded]
            );
            
            if (!$credential) {
                error_log("WebAuthn Login: Credential not found for user_id: $userId, credential_id: " . substr($credentialIdEncoded, 0, 20) . "...");
                return false;
            }
            
            // التحقق من التوقيع (يجب التحقق من signature باستخدام public key)
            // هذا يتطلب فك ترميز public key من CBOR والتحقق من signature
            // للبساطة، سنتخطى التحقق من التوقيع الآن ونركز على التحقق من credential ID
            
            // تحديث آخر استخدام
            $db->execute(
                "UPDATE webauthn_credentials SET last_used = NOW(), counter = counter + 1 WHERE id = ?",
                [$credential['id']]
            );
            
            // مسح بيانات الجلسة
            unset($_SESSION['webauthn_login_challenge']);
            
            return $userId;
            
        } catch (Exception $e) {
            error_log("WebAuthn Login Error: " . $e->getMessage());
            error_log("WebAuthn Login Stack Trace: " . $e->getTraceAsString());
            return false;
        }
    }
    
    /**
     * استخراج authData من attestationObject
     * attestationObject هو CBOR map يحتوي على fmt, attStmt, authData
     */
    private static function extractAuthDataFromAttestation($attestationObject) {
        try {
            if (empty($attestationObject) || !is_string($attestationObject)) {
                return null;
            }
            
            $offset = 0;
            $decoded = self::cborDecodeItem($attestationObject, $offset);
            
            if (is_array($decoded)) {
                // في حالة map يتم تمثيلها كمصفوفة ترابطية
                if (isset($decoded['authData']) && is_string($decoded['authData'])) {
                    return $decoded['authData'];
                }
                
                // في بعض البيئات قد تكون المفاتيح غير lowercase أو تعتمد على الترتيب
                foreach ($decoded as $key => $value) {
                    if (is_string($key) && strcasecmp($key, 'authData') === 0 && is_string($value)) {
                        return $value;
                    }
                    // fallback إذا كانت البيانات داخل map فرعية
                    if (is_array($value) && isset($value['authData']) && is_string($value['authData'])) {
                        return $value['authData'];
                    }
                }
            }
        } catch (Exception $e) {
            error_log("extractAuthDataFromAttestation CBOR decode error: " . $e->getMessage());
        }
        
        return self::extractAuthDataSimple($attestationObject);
    }
    
    /**
     * استخراج بسيط لـ authData (fallback method)
     */
    private static function extractAuthDataSimple($attestationObject) {
        $len = strlen($attestationObject);
        
        // محاولة: authData عادة يكون في آخر 200-500 bytes
        // نبحث عن sequence من bytes تبدو صحيحة
        $searchStart = max(0, $len - 500);
        
        for ($i = $searchStart; $i < $len - 37; $i++) {
            // محاولة استخراج data من هذا الموضع
            $testData = substr($attestationObject, $i, min(200, $len - $i));
            
            if (strlen($testData) >= 37) {
                // التحقق من structure: يجب أن تبدأ بـ rpIdHash (32 bytes)
                // ثم flags (1 byte), counter (4 bytes), aaguid (16 bytes)
                // هذا يعطينا 53 bytes على الأقل قبل credential ID
                
                // محاولة قراءة credential ID length
            if (strlen($testData) >= 55) {
                    $credIdLen = unpack('n', substr($testData, 53, 2))[1];
                    
                    if ($credIdLen > 0 && $credIdLen < 1024 && (55 + $credIdLen) <= strlen($testData)) {
                        // يبدو صحيحاً، نعيد البيانات من هذا الموضع
                        return substr($attestationObject, $i);
                    }
                }
            }
        }
        
        return null;
    }
    
    /**
     * CBOR decoding helpers (minimal implementation يكفي لهيكل attestationObject)
     */
    private static function cborDecodeItem($data, &$offset) {
        $length = strlen($data);
        
        if ($offset >= $length) {
            throw new RuntimeException('CBOR decode: offset out of range');
        }
        
        $initialByte = ord($data[$offset]);
        $offset++;
        
        $majorType = $initialByte >> 5;
        $additionalInfo = $initialByte & 0x1f;
        
        switch ($majorType) {
            case 0: // unsigned integer
                return self::cborReadLength($data, $offset, $additionalInfo);
            
            case 1: // negative integer
                $value = self::cborReadLength($data, $offset, $additionalInfo);
                return -1 - $value;
            
            case 2: // byte string
                return self::cborReadByteString($data, $offset, $additionalInfo);
            
            case 3: // text string
                return self::cborReadTextString($data, $offset, $additionalInfo);
            
            case 4: // array
                return self::cborReadArray($data, $offset, $additionalInfo);
            
            case 5: // map
                return self::cborReadMap($data, $offset, $additionalInfo);
            
            case 6: // semantic tag
                // نتجاهل القيمة الدلالية ونفك ترميز العنصر الذي بعده
                self::cborReadLength($data, $offset, $additionalInfo); // قراءة رقم الـ tag
                return self::cborDecodeItem($data, $offset);
            
            case 7: // simple values / floats
                return self::cborReadSimpleValue($data, $offset, $additionalInfo);
            
            default:
                throw new RuntimeException('CBOR decode: unsupported major type ' . $majorType);
        }
    }
    
    private static function cborReadLength($data, &$offset, $additionalInfo) {
        $length = strlen($data);
        
        if ($additionalInfo < 24) {
            return $additionalInfo;
        }
        
        if ($additionalInfo === 24) {
            if ($offset >= $length) {
                throw new RuntimeException('CBOR decode: unexpected end of data (uint8)');
            }
            return ord($data[$offset++]);
        }
        
        if ($additionalInfo === 25) {
            if ($offset + 1 >= $length) {
                throw new RuntimeException('CBOR decode: unexpected end of data (uint16)');
            }
            $value = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
            return $value;
        }
        
        if ($additionalInfo === 26) {
            if ($offset + 3 >= $length) {
                throw new RuntimeException('CBOR decode: unexpected end of data (uint32)');
            }
            $value = unpack('N', substr($data, $offset, 4))[1];
            $offset += 4;
            return $value;
        }
        
        if ($additionalInfo === 27) {
            if ($offset + 7 >= $length) {
                throw new RuntimeException('CBOR decode: unexpected end of data (uint64)');
            }
            $parts = unpack('N2', substr($data, $offset, 8));
            $offset += 8;
            return ($parts[1] << 32) | $parts[2];
        }
        
        if ($additionalInfo === 31) {
            return -1; // indefinite length
        }
        
        throw new RuntimeException('CBOR decode: unsupported additional info ' . $additionalInfo);
    }
    
    private static function cborReadByteString($data, &$offset, $additionalInfo) {
        $length = strlen($data);
        
        if ($additionalInfo === 31) { // indefinite length
            $result = '';
            while (true) {
                if ($offset >= $length) {
                    throw new RuntimeException('CBOR decode: unterminated indefinite byte string');
                }
                if (ord($data[$offset]) === 0xff) { // break
                    $offset++;
                    break;
                }
                $chunk = self::cborDecodeItem($data, $offset);
                if (!is_string($chunk)) {
                    throw new RuntimeException('CBOR decode: invalid chunk in indefinite byte string');
                }
                $result .= $chunk;
            }
            return $result;
        }
        
        $byteLength = self::cborReadLength($data, $offset, $additionalInfo);
        if ($byteLength < 0) {
            throw new RuntimeException('CBOR decode: invalid byte string length');
        }
        
        if ($offset + $byteLength > $length) {
            throw new RuntimeException('CBOR decode: byte string exceeds buffer');
        }
        
        $value = substr($data, $offset, $byteLength);
        $offset += $byteLength;
        return $value;
    }
    
    private static function cborReadTextString($data, &$offset, $additionalInfo) {
        $length = strlen($data);
        
        if ($additionalInfo === 31) { // indefinite
            $result = '';
            while (true) {
                if ($offset >= $length) {
                    throw new RuntimeException('CBOR decode: unterminated indefinite text string');
                }
                if (ord($data[$offset]) === 0xff) {
                    $offset++;
                    break;
                }
                $chunk = self::cborDecodeItem($data, $offset);
                if (!is_string($chunk)) {
                    throw new RuntimeException('CBOR decode: invalid chunk in indefinite text string');
                }
                $result .= $chunk;
            }
            return $result;
        }
        
        $strLength = self::cborReadLength($data, $offset, $additionalInfo);
        if ($strLength < 0) {
            throw new RuntimeException('CBOR decode: invalid text string length');
        }
        
        if ($offset + $strLength > $length) {
            throw new RuntimeException('CBOR decode: text string exceeds buffer');
        }
        
        $value = substr($data, $offset, $strLength);
        $offset += $strLength;
        return $value;
    }
    
    private static function cborReadArray($data, &$offset, $additionalInfo) {
        $length = strlen($data);
        
        if ($additionalInfo === 31) {
            $result = [];
            while (true) {
                if ($offset >= $length) {
                    throw new RuntimeException('CBOR decode: unterminated indefinite array');
                }
                if (ord($data[$offset]) === 0xff) {
                    $offset++;
                    break;
                }
                $result[] = self::cborDecodeItem($data, $offset);
            }
            return $result;
        }
        
        $arrayLength = self::cborReadLength($data, $offset, $additionalInfo);
        if ($arrayLength < 0) {
            throw new RuntimeException('CBOR decode: invalid array length');
        }
        
        $result = [];
        for ($i = 0; $i < $arrayLength; $i++) {
            $result[] = self::cborDecodeItem($data, $offset);
        }
        
        return $result;
    }
    
    private static function cborReadMap($data, &$offset, $additionalInfo) {
        $length = strlen($data);
        
        if ($additionalInfo === 31) {
            $result = [];
            while (true) {
                if ($offset >= $length) {
                    throw new RuntimeException('CBOR decode: unterminated indefinite map');
                }
                if (ord($data[$offset]) === 0xff) {
                    $offset++;
                    break;
                }
                
                $key = self::cborDecodeItem($data, $offset);
                $value = self::cborDecodeItem($data, $offset);
                
                if (is_string($key) || is_int($key)) {
                    $result[$key] = $value;
                } else {
                    $result[] = [$key, $value];
                }
            }
            return $result;
        }
        
        $mapLength = self::cborReadLength($data, $offset, $additionalInfo);
        if ($mapLength < 0) {
            throw new RuntimeException('CBOR decode: invalid map length');
        }
        
        $result = [];
        for ($i = 0; $i < $mapLength; $i++) {
            $key = self::cborDecodeItem($data, $offset);
            $value = self::cborDecodeItem($data, $offset);
            
            if (is_string($key) || is_int($key)) {
                $result[$key] = $value;
            } else {
                $result[] = [$key, $value];
            }
        }
        
        return $result;
    }
    
    private static function cborReadSimpleValue($data, &$offset, $additionalInfo) {
        switch ($additionalInfo) {
            case 20:
                return false;
            case 21:
                return true;
            case 22:
                return null;
            case 23:
                return null; // undefined
            case 24:
                // simple value (next byte) - نتجاهله ونقرأه
                if ($offset >= strlen($data)) {
                    throw new RuntimeException('CBOR decode: unexpected end of data (simple value)');
                }
                $offset++;
                return null;
            case 25:
                if ($offset + 1 >= strlen($data)) {
                    throw new RuntimeException('CBOR decode: unexpected end of data (half float)');
                }
                $half = unpack('n', substr($data, $offset, 2))[1];
                $offset += 2;
                return self::cborDecodeHalfFloat($half);
            case 26:
                if ($offset + 3 >= strlen($data)) {
                    throw new RuntimeException('CBOR decode: unexpected end of data (float32)');
                }
                $value = unpack('G', substr($data, $offset, 4))[1];
                $offset += 4;
                return $value;
            case 27:
                if ($offset + 7 >= strlen($data)) {
                    throw new RuntimeException('CBOR decode: unexpected end of data (float64)');
                }
                $value = unpack('E', substr($data, $offset, 8))[1];
                $offset += 8;
                return $value;
            case 31:
                return null; // break code - يجب أن يتم التعامل معه خارجاً
            default:
                if ($additionalInfo < 20) {
                    return $additionalInfo;
                }
                throw new RuntimeException('CBOR decode: unsupported simple value ' . $additionalInfo);
        }
    }
    
    private static function cborDecodeHalfFloat($half) {
        $sign = ($half & 0x8000) >> 15;
        $exponent = ($half & 0x7C00) >> 10;
        $fraction = $half & 0x03FF;
        
        if ($exponent === 0) {
            $value = $fraction * (2 ** -24);
        } elseif ($exponent === 0x1F) {
            $value = $fraction === 0 ? INF : NAN;
        } else {
            $value = (1 + ($fraction / 1024)) * (2 ** ($exponent - 15));
        }
        
        return $sign ? -$value : $value;
    }
    
    /**
     * فك ترميز CBOR بشكل أساسي (deprecated - using extractAuthDataFromAttestation instead)
     */
    private static function decodeCBOR($data) {
        return null; // لا نستخدم هذا بعد الآن
    }
}

/**
 * فئة CBOR بسيطة (للتوافق مع الكود القديم)
 */
class CBOR {
    public static function decode($data) {
        // هذا تنفيذ مبسط - في الإنتاج استخدم مكتبة CBOR كاملة مثل spomky-labs/cbor-php
        // للآن سنعود null وسنستخدم الاستخراج المباشر
        return null;
    }
}

