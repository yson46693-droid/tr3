/**
 * نظام WebAuthn مبسط ونظيف
 */

class SimpleWebAuthn {
    constructor() {
        this.apiBase = this.getApiBase();
    }

    /**
     * الحصول على المسار الأساسي لـ API
     */
    getApiBase() {
        const currentPath = window.location.pathname;
        const pathParts = currentPath.split('/').filter(p => p && !p.endsWith('.php'));
        
        // استخدام مسار مطلق بناءً على موقع الصفحة الحالية
        // إذا كنا في الجذر (مثل /v1/profile.php)، المسار سيكون /v1/api/webauthn_register.php
        // إذا كنا في مجلد فرعي، نستخدم المسار النسبي
        
        if (pathParts.length === 0) {
            // في الجذر - استخدام مسار نسبي
            return 'api/webauthn_register.php';
        } else {
            // في مجلد فرعي - بناء مسار مطلق
            const basePath = '/' + pathParts[0];
            return basePath + '/api/webauthn_register.php';
        }
    }

    /**
     * التحقق من دعم WebAuthn
     */
    isSupported() {
        return !!(window.PublicKeyCredential && navigator.credentials && navigator.credentials.create);
    }

    /**
     * تحويل Base64 إلى ArrayBuffer
     */
    base64ToArrayBuffer(base64) {
        if (typeof base64 !== 'string' || base64.length === 0) {
            throw new Error('بيانات Base64 غير صالحة');
        }

        const normalized = this.normalizeBase64(base64);
        let binaryString;

        try {
            binaryString = window.atob(normalized);
        } catch (error) {
            console.error('WebAuthn: Invalid Base64 input', {
                original: base64,
                normalized,
                length: normalized.length,
                error: error.message
            });
            throw new Error('فشل في قراءة بيانات الترميز (Base64).');
        }
        const bytes = new Uint8Array(binaryString.length);
        for (let i = 0; i < binaryString.length; i++) {
            bytes[i] = binaryString.charCodeAt(i);
        }
        return bytes.buffer;
    }

    /**
     * تحويل ArrayBuffer إلى Base64
     */
    arrayBufferToBase64(buffer) {
        const bytes = new Uint8Array(buffer);
        let binary = '';
        for (let i = 0; i < bytes.byteLength; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return window.btoa(binary);
    }

    /**
     * تحويل base64url إلى base64 عادي مع الحشو
     */
    normalizeBase64(value) {
        let normalized = value.replace(/-/g, '+').replace(/_/g, '/').replace(/\s+/g, '');
        const paddingNeeded = normalized.length % 4;
        if (paddingNeeded) {
            normalized += '='.repeat(4 - paddingNeeded);
        }
        return normalized;
    }

    /**
     * محاولة تخمين اسم الجهاز من الـ User-Agent
     */
    detectDeviceName() {
        const ua = navigator.userAgent || '';

        if (/iPhone/i.test(ua)) {
            return 'iPhone';
        }
        if (/iPad/i.test(ua)) {
            return 'iPad';
        }
        if (/Android/i.test(ua)) {
            const match = ua.match(/Android\s+([\d\.]+)/i);
            return match ? `Android ${match[1]}` : 'Android Device';
        }
        if (/Macintosh/i.test(ua)) {
            return 'Mac';
        }
        if (/Windows/i.test(ua)) {
            return 'Windows';
        }

        if (/Chrome/i.test(ua)) {
            return 'Chrome Browser';
        }
        if (/Safari/i.test(ua)) {
            return 'Safari Browser';
        }

        return 'Unknown Device';
    }

    /**
     * تسجيل بصمة جديدة
     */
    async register(deviceName = null) {
        try {
            // التحقق من الدعم
            if (!this.isSupported()) {
                throw new Error('WebAuthn غير مدعوم في هذا المتصفح. يرجى استخدام متصفح حديث.');
            }

            // التحقق من HTTPS (مطلوب لـ WebAuthn)
            if (window.location.protocol !== 'https:' && window.location.hostname !== 'localhost') {
                throw new Error('WebAuthn يتطلب HTTPS. الموقع الحالي: ' + window.location.protocol);
            }

            // الحصول على اسم الجهاز بشكل تلقائي إن لم يُرسل من الواجهة
            if (!deviceName || deviceName.trim() === '') {
                deviceName = this.detectDeviceName();
            }
            deviceName = deviceName.trim();

            // 1. الحصول على challenge من الخادم
            const challengeResponse = await fetch(this.apiBase, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    action: 'challenge'
                })
            });

            if (!challengeResponse.ok) {
                throw new Error(`خطأ في الاتصال بالخادم: ${challengeResponse.status}`);
            }

            const challengeData = await challengeResponse.json();

            if (!challengeData.success || !challengeData.data) {
                throw new Error(challengeData.message || challengeData.error || 'فشل في إنشاء التحدي');
            }

            const challenge = challengeData.data;

            // 2. تحويل البيانات إلى ArrayBuffer
            const challengeBuffer = this.base64ToArrayBuffer(challenge.challenge);
            const userIdBuffer = this.base64ToArrayBuffer(challenge.user.id);

            // 3. تحويل excludeCredentials
            const excludeCredentials = (challenge.excludeCredentials || [])
                .filter(cred => cred && cred.id)
                .map(cred => {
                    try {
                        return {
                            id: this.base64ToArrayBuffer(cred.id),
                            type: cred.type || 'public-key'
                        };
                    } catch (error) {
                        console.warn('WebAuthn: تجاهل excludeCredential غير صالح', cred, error);
                        return null;
                    }
                })
                .filter(Boolean);

            // 4. إعداد rpId
            let rpId = challenge.rp?.id || window.location.hostname;
            rpId = rpId.replace(/^www\./, '').split(':')[0];

            // 5. إنشاء challenge object - نظام مبسط يعمل على الموبايل
            const pubKeyCredParams = Array.isArray(challenge.pubKeyCredParams) && challenge.pubKeyCredParams.length > 0
                ? challenge.pubKeyCredParams
                : [
                    { type: 'public-key', alg: -7 },   // ES256
                    { type: 'public-key', alg: -257 }  // RS256
                ];

            const authenticatorSelection = { ...(challenge.authenticatorSelection || {}) };

            if (!authenticatorSelection.userVerification) {
                authenticatorSelection.userVerification = 'preferred';
            }

            // إذا لم يحدد الخادم نوع authenticatorAttachment، نتركه فارغاً
            if (!('authenticatorAttachment' in authenticatorSelection)) {
                delete authenticatorSelection.authenticatorAttachment;
            }

            const publicKeyTimeout = typeof challenge.timeout === 'number' ? challenge.timeout : 60000;
            const attestation = challenge.attestation || 'none';

            const publicKeyCredentialCreationOptions = {
                challenge: challengeBuffer,
                rp: {
                    name: challenge.rp?.name || 'نظام الإدارة المتكاملة',
                    id: rpId
                },
                user: {
                    id: userIdBuffer,
                    name: challenge.user.name,
                    displayName: challenge.user.displayName || challenge.user.name
                },
                pubKeyCredParams,
                timeout: publicKeyTimeout,
                attestation
            };

            if (Object.keys(authenticatorSelection).length > 0) {
                publicKeyCredentialCreationOptions.authenticatorSelection = authenticatorSelection;
            }

            if (excludeCredentials.length > 0) {
                publicKeyCredentialCreationOptions.excludeCredentials = excludeCredentials;
            }

            console.log('WebAuthn Registration Options:', {
                rpId: rpId,
                timeout: publicKeyCredentialCreationOptions.timeout,
                authenticatorSelection: publicKeyCredentialCreationOptions.authenticatorSelection,
                attestation: publicKeyCredentialCreationOptions.attestation,
                pubKeyCredParams: publicKeyCredentialCreationOptions.pubKeyCredParams,
                excludeCredentialsCount: excludeCredentials.length
            });

            // 6. إنشاء الاعتماد
            let credential;
            try {
                console.log('Requesting WebAuthn credential...');
                credential = await navigator.credentials.create({
                    publicKey: publicKeyCredentialCreationOptions
                });
                console.log('WebAuthn credential created successfully');
            } catch (error) {
                console.error('WebAuthn error:', error);
                console.error('Error name:', error.name);
                console.error('Error message:', error.message);
                
                // رسالة خطأ أوضح
                let errorMessage = 'فشل في التسجيل البيومتري.';
                if (error.name === 'NotAllowedError') {
                    errorMessage = 'تم إلغاء العملية أو رفض الطلب.\n\nتأكد من:\n1. السماح للموقع بالوصول إلى البصمة/المفتاح\n2. الضغط على "Allow" عند ظهور نافذة البصمة\n3. تفعيل Face ID/Touch ID في إعدادات الجهاز';
                } else if (error.name === 'NotSupportedError') {
                    errorMessage = 'المتصفح أو الجهاز لا يدعم WebAuthn. يرجى استخدام متصفح حديث.';
                } else if (error.name === 'InvalidStateError') {
                    errorMessage = 'البصمة مسجلة بالفعل على هذا الجهاز.';
                } else {
                    errorMessage = 'فشل في التسجيل البيومتري: ' + (error.message || error.name) + '\n\nتأكد من تفعيل البصمة أو Face ID';
                }
                
                throw new Error(errorMessage);
            }

            if (!credential) {
                throw new Error('فشل في إنشاء الاعتماد');
            }

            // 7. تحويل البيانات إلى base64
            const credentialId = this.arrayBufferToBase64(credential.rawId);
            const attestationObject = this.arrayBufferToBase64(credential.response.attestationObject);
            const clientDataJSON = this.arrayBufferToBase64(credential.response.clientDataJSON);

            // 8. إرسال البيانات للتحقق
            const verifyResponse = await fetch(this.apiBase, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    action: 'verify',
                    response: {
                        id: credential.id,
                        rawId: credentialId,
                        type: credential.type,
                        response: {
                            clientDataJSON: clientDataJSON,
                            attestationObject: attestationObject
                        },
                        deviceName: deviceName.trim()
                    }
                })
            });

            if (!verifyResponse.ok) {
                throw new Error(`خطأ في التحقق: ${verifyResponse.status}`);
            }

            const verifyData = await verifyResponse.json();

            if (!verifyData.success) {
                throw new Error(verifyData.message || verifyData.error || 'فشل التحقق من البصمة');
            }

            return {
                success: true,
                message: verifyData.message || 'تم تسجيل البصمة بنجاح'
            };

        } catch (error) {
            console.error('WebAuthn Registration Error:', error);
            
            // معالجة الأخطاء الشائعة
            let errorMessage = 'خطأ في تسجيل البصمة';
            
            if (error.name === 'NotAllowedError') {
                errorMessage = 'تم إلغاء العملية أو رفض الطلب.\n\n' +
                    'تأكد من:\n' +
                    '1. السماح للموقع بالوصول إلى البصمة/المفتاح عند الطلب\n' +
                    '2. الضغط على "Allow" أو "Allow once" عند ظهور نافذة البصمة\n' +
                    '3. تفعيل Face ID/Touch ID في إعدادات الجهاز';
            } else if (error.name === 'NotSupportedError') {
                errorMessage = 'الجهاز أو المتصفح لا يدعم WebAuthn. يرجى استخدام:\n' +
                    '- Chrome 67+\n' +
                    '- Safari 14+ (iOS 14+)\n' +
                    '- Firefox 60+';
            } else if (error.name === 'InvalidStateError') {
                errorMessage = 'البصمة مسجلة بالفعل على هذا الجهاز. احذف البصمة القديمة أولاً.';
            } else if (error.name === 'SecurityError') {
                errorMessage = 'خطأ أمني. تأكد من:\n' +
                    '1. أن الموقع يستخدم HTTPS\n' +
                    '2. أن rpId صحيح\n' +
                    '3. أن الموقع مسموح به في إعدادات الأمان';
            } else if (error.message) {
                errorMessage = error.message;
            }

            return {
                success: false,
                message: errorMessage
            };
        }
    }

    /**
     * تسجيل الدخول باستخدام WebAuthn
     */
    async login(username) {
        try {
            // التحقق من الدعم
            if (!this.isSupported()) {
                throw new Error('WebAuthn غير مدعوم في هذا المتصفح. يرجى استخدام متصفح حديث.');
            }

            // التحقق من HTTPS
            if (window.location.protocol !== 'https:' && window.location.hostname !== 'localhost') {
                throw new Error('WebAuthn يتطلب HTTPS. الموقع الحالي: ' + window.location.protocol);
            }

            if (!username) {
                throw new Error('اسم المستخدم مطلوب');
            }

            // الحصول على مسار API لتسجيل الدخول - استخدام مسار مطلق
            const currentPath = window.location.pathname;
            const pathParts = currentPath.split('/').filter(p => p && !p.endsWith('.php'));
            
            let loginApiPath;
            if (pathParts.length === 0) {
                // في الجذر
                loginApiPath = 'api/webauthn_login.php';
            } else {
                // في مجلد فرعي - استخدام مسار مطلق
                loginApiPath = '/' + pathParts[0] + '/api/webauthn_login.php';
            }
            
            console.log('WebAuthn Login API path:', loginApiPath, 'Path parts:', pathParts);

            // 1. الحصول على challenge
            const challengeResponse = await fetch(loginApiPath, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                credentials: 'same-origin',
                body: new URLSearchParams({
                    action: 'create_challenge',
                    username: username
                })
            });
            
            console.log('Challenge response status:', challengeResponse.status);

            if (!challengeResponse.ok) {
                throw new Error(`خطأ في الاتصال بالخادم: ${challengeResponse.status}`);
            }

            const challengeData = await challengeResponse.json();

            if (!challengeData.success || !challengeData.challenge) {
                throw new Error(challengeData.error || 'لا توجد بصمات مسجلة لهذا المستخدم');
            }

            const challenge = challengeData.challenge;

            // 2. تحويل البيانات
            challenge.challenge = this.base64ToArrayBuffer(challenge.challenge);

            if (challenge.allowCredentials && Array.isArray(challenge.allowCredentials)) {
                challenge.allowCredentials = challenge.allowCredentials.map(cred => ({
                    id: this.base64ToArrayBuffer(cred.id),
                    type: cred.type || 'public-key'
                })).filter(cred => cred !== null);
            }

            // 3. إعداد rpId
            let rpId = challenge.rpId || window.location.hostname;
            rpId = rpId.replace(/^www\./, '').split(':')[0];
            challenge.rpId = rpId;

            // 4. إعدادات للموبايل
            const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
            if (isMobile) {
                challenge.timeout = 180000;
                challenge.userVerification = 'preferred';
            }

            if (!challenge.allowCredentials || challenge.allowCredentials.length === 0) {
                throw new Error('لا توجد بصمات مسجلة لهذا المستخدم');
            }

            // 5. الحصول على الاعتماد
            const credential = await navigator.credentials.get({
                publicKey: challenge
            });

            if (!credential) {
                throw new Error('فشل في الحصول على الاعتماد');
            }

            // 6. تحويل البيانات
            const clientDataJSON = this.arrayBufferToBase64(credential.response.clientDataJSON);
            const authenticatorData = this.arrayBufferToBase64(credential.response.authenticatorData);
            const signature = this.arrayBufferToBase64(credential.response.signature);
            const credentialIdBase64 = this.arrayBufferToBase64(credential.rawId);

            // 7. التحقق من البصمة
            const verifyResponse = await fetch(loginApiPath, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                credentials: 'same-origin',
                body: new URLSearchParams({
                    action: 'verify',
                    response: JSON.stringify({
                        id: credential.id,
                        rawId: credentialIdBase64,
                        type: credential.type,
                        response: {
                            clientDataJSON: clientDataJSON,
                            authenticatorData: authenticatorData,
                            signature: signature
                        }
                    })
                })
            });
            
            console.log('Verify response status:', verifyResponse.status, verifyResponse.statusText);

            if (!verifyResponse.ok) {
                const errorText = await verifyResponse.text();
                console.error('Verify error response:', errorText);
                throw new Error(`خطأ في التحقق: ${verifyResponse.status} - ${errorText}`);
            }

            const verifyData = await verifyResponse.json();
            console.log('Verify data:', verifyData);

            if (verifyData.success) {
                // إعادة توجيه إلى لوحة التحكم - استخدام مسار مطلق
                const userRole = verifyData.user?.role || 'accountant';
                
                // حساب المسار بناءً على موقع الصفحة الحالية
                const currentPath = window.location.pathname;
                const pathParts = currentPath.split('/').filter(p => p && !p.endsWith('.php'));
                
                let dashboardUrl;
                if (pathParts.length === 0) {
                    // في الجذر
                    dashboardUrl = 'dashboard/' + userRole + '.php';
                } else {
                    // في مجلد فرعي - استخدام مسار مطلق
                    dashboardUrl = '/' + pathParts[0] + '/dashboard/' + userRole + '.php';
                }
                
                console.log('Redirecting to dashboard:', dashboardUrl);
                window.location.href = dashboardUrl;
                return {
                    success: true,
                    message: 'تم تسجيل الدخول بنجاح',
                    redirect: dashboardUrl
                };
            } else {
                throw new Error(verifyData.error || 'فشل التحقق من البصمة');
            }

        } catch (error) {
            console.error('WebAuthn Login Error:', error);
            
            let errorMessage = 'خطأ في تسجيل الدخول';
            if (error.message) {
                errorMessage = error.message;
            } else if (error.name === 'NotAllowedError') {
                errorMessage = 'تم إلغاء العملية. يرجى المحاولة مرة أخرى.';
            } else if (error.name === 'NotSupportedError') {
                errorMessage = 'الجهاز أو المتصفح لا يدعم WebAuthn';
            }

            alert(errorMessage);
            return false;
        }
    }
}

// إنشاء كائن عام
const simpleWebAuthn = new SimpleWebAuthn();

// للتوافق مع الكود القديم
const webauthnManager = {
    login: (username) => simpleWebAuthn.login(username),
    register: () => simpleWebAuthn.register()
};

