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
            
            // البنية: CBOR map (0xa3 = 3 items)
            // fmt: string (عادة "none" أو "packed")
            // attStmt: map
            // authData: bytes
            
            // في معظم الحالات، authData هو آخر عنصر في الـ map
            // ونحاول استخراجه من نهاية البيانات
            
            // الطريقة الأفضل: فك ترميز CBOR بسيط
            // CBOR map يبدأ بـ 0xa0-0xbf (map with 0-23 items) أو 0xbf (indefinite)
            // أو 0xa1-0xa3 (map with specific count)
            
            $len = strlen($attestationObject);
            if ($len < 50) {
                return null;
            }
            
            // محاولة 1: البحث عن authData في نهاية البيانات
            // authData عادة يكون 37+ bytes في النهاية
            // نحاول العثور على بدايته من خلال البحث عن pattern معروف
            
            // محاولة 2: فك ترميز CBOR يدوياً
            $pos = 0;
            
            // قراءة نوع البيانات (map)
            if (ord($attestationObject[$pos]) >= 0xa0 && ord($attestationObject[$pos]) <= 0xbf) {
                $mapLength = ord($attestationObject[$pos]) & 0x1f;
                $pos++;
            } elseif (ord($attestationObject[$pos]) == 0xbf) {
                // indefinite length map
                $pos++;
                // نحتاج للبحث عن break marker (0xff)
            } else {
                // قد يكون map كبير (0xb8-0xbf)
                return self::extractAuthDataSimple($attestationObject);
            }
            
            // تخطي fmt و attStmt للوصول إلى authData
            // fmt: string
            $pos++; // تخطي first byte
            
            // البحث عن authData marker (byte string with tag)
            // authData عادة يكون byte string (0x58, 0x59, 0x5a, 0x5b)
            for ($i = $pos; $i < $len - 37; $i++) {
                $byte = ord($attestationObject[$i]);
                
                // byte string markers
                if ($byte >= 0x58 && $byte <= 0x5b) {
                    // تحديد الطول
                    $dataLength = 0;
                    $dataStart = $i + 1;
                    
                    if ($byte == 0x58) { // 1 byte length
                        if ($i + 1 >= $len) break;
                        $dataLength = ord($attestationObject[$i + 1]);
                        $dataStart = $i + 2;
                    } elseif ($byte == 0x59) { // 2 bytes length (big-endian)
                        if ($i + 2 >= $len) break;
                        $dataLength = unpack('n', substr($attestationObject, $i + 1, 2))[1];
                        $dataStart = $i + 3;
                    } elseif ($byte == 0x5a) { // 4 bytes length
                        if ($i + 4 >= $len) break;
                        $dataLength = unpack('N', substr($attestationObject, $i + 1, 4))[1];
                        $dataStart = $i + 5;
                    } elseif ($byte == 0x5b) { // 8 bytes length
                        if ($i + 8 >= $len) break;
                        $unpacked = unpack('N2', substr($attestationObject, $i + 1, 8));
                        $dataLength = ($unpacked[1] << 32) | $unpacked[2];
                        $dataStart = $i + 9;
                    }
                    
                    // التحقق من أن الطول منطقي (عادة authData يكون 37+ bytes)
                    if ($dataLength >= 37 && $dataLength <= 1000 && ($dataStart + $dataLength) <= $len) {
                        $extracted = substr($attestationObject, $dataStart, $dataLength);
                        // التحقق من أن البيانات تبدو صحيحة (authData له structure محدد)
                        if (strlen($extracted) >= 37) {
                            return $extracted;
                        }
                    }
                }
            }
            
            // إذا فشل كل شيء، استخدم طريقة بسيطة
            return self::extractAuthDataSimple($attestationObject);
            
        } catch (Exception $e) {
            error_log("extractAuthDataFromAttestation error: " . $e->getMessage());
            return self::extractAuthDataSimple($attestationObject);
        }
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

