/**
 * نظام تسجيل الحضور والانصراف مع الكاميرا
 */

let currentStream = null;
let capturedPhoto = null;
let currentAction = null;

// الحصول على API path ديناميكياً
function getAttendanceApiPath() {
    const currentPath = window.location.pathname;
    const pathParts = currentPath.split('/').filter(p => p && !p.endsWith('.php') && p !== 'dashboard' && p !== 'modules');
    
    // بناء المسار الأساسي
    let basePath = '/';
    if (pathParts.length > 0) {
        basePath = '/' + pathParts[0] + '/';
    }
    
    return basePath + 'api/attendance.php';
}

// تهيئة الكاميرا
async function initCamera() {
    try {
        const video = document.getElementById('video');
        const cameraLoading = document.getElementById('cameraLoading');
        const cameraError = document.getElementById('cameraError');
        
        if (!video) {
            console.error('Video element not found');
            showCameraError('عنصر الفيديو غير موجود');
            return;
        }
        
        // إظهار حالة التحميل
        if (cameraLoading) cameraLoading.style.display = 'block';
        if (cameraError) cameraError.style.display = 'none';
        video.style.display = 'none';
        
        // التحقق من دعم getUserMedia
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            // محاولة استخدام API القديم
            const getUserMedia = navigator.getUserMedia || 
                                navigator.webkitGetUserMedia || 
                                navigator.mozGetUserMedia || 
                                navigator.msGetUserMedia;
            
            if (!getUserMedia) {
                throw new Error('الكاميرا غير مدعومة في هذا المتصفح');
            }
        }
        
        // إيقاف أي stream سابق
        if (currentStream) {
            stopCamera();
        }
        
        // إعادة تعيين srcObject
        video.srcObject = null;
        
        // كشف ما إذا كان الجهاز موبايل
        const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
        
        // محاولة الوصول للكاميرا مع خيارات مختلفة
        const constraints = {
            video: {
                width: { ideal: 1280, min: 640 },
                height: { ideal: 720, min: 480 },
                aspectRatio: { ideal: 16/9 }
            }
        };
        
        // على الموبايل نحتاج الكاميرا الأمامية بشكل افتراضي
        if (isMobile) {
            constraints.video.facingMode = { ideal: 'user' };
        } else {
            constraints.video.facingMode = { ideal: 'user' };
        }
        
        // محاولة الوصول للكاميرا
        let stream = null;
        try {
            if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
                stream = await navigator.mediaDevices.getUserMedia(constraints);
            } else {
                // استخدام API القديم
                return new Promise((resolve, reject) => {
                    const getUserMedia = navigator.getUserMedia || 
                                        navigator.webkitGetUserMedia || 
                                        navigator.mozGetUserMedia;
                    getUserMedia.call(navigator, constraints, resolve, reject);
                });
            }
        } catch (firstError) {
            // إذا فشلت المحاولة الأولى، جرب بدون تحديد facingMode
            delete constraints.video.facingMode;
            try {
                stream = await navigator.mediaDevices.getUserMedia(constraints);
            } catch (secondError) {
                throw firstError;
            }
        }
        
        currentStream = stream;
        video.srcObject = currentStream;
        video.style.display = 'block';
        
        // انتظر حتى يكون الفيديو جاهزاً
        await new Promise((resolve, reject) => {
            const timeout = setTimeout(() => {
                if (video.readyState < 2) {
                    reject(new Error('Timeout waiting for video to load'));
                }
            }, 10000);
            
            video.onloadedmetadata = () => {
                clearTimeout(timeout);
                video.play().then(() => {
                    resolve();
                }).catch(reject);
            };
            video.onerror = (e) => {
                clearTimeout(timeout);
                reject(new Error('Video playback error'));
            };
            
            // إذا كان الفيديو جاهزاً بالفعل
            if (video.readyState >= 2) {
                clearTimeout(timeout);
                video.play().then(resolve).catch(reject);
            }
        });
        
        // إخفاء حالة التحميل
        if (cameraLoading) cameraLoading.style.display = 'none';
        if (cameraError) cameraError.style.display = 'none';
        
        const captureBtn = document.getElementById('captureBtn');
        if (captureBtn) captureBtn.style.display = 'inline-block';
        
        console.log('Camera initialized successfully');
        
    } catch (error) {
        console.error('Error accessing camera:', error);
        showCameraError(error);
    }
}

// إظهار رسالة خطأ الكاميرا
function showCameraError(error) {
    const cameraError = document.getElementById('cameraError');
    const cameraErrorText = document.getElementById('cameraErrorText');
    const captureBtn = document.getElementById('captureBtn');
    const cameraLoading = document.getElementById('cameraLoading');
    const video = document.getElementById('video');
    
    // إخفاء حالة التحميل
    if (cameraLoading) cameraLoading.style.display = 'none';
    
    // إظهار عنصر الفيديو حتى لو كان هناك خطأ (لإظهار الصندوق الأسود بدلاً من عدم وجود شيء)
    if (video) video.style.display = 'block';
    
    if (cameraError) cameraError.style.display = 'block';
    if (cameraErrorText) {
        let errorMessage = 'فشل في الوصول إلى الكاميرا. يرجى التأكد من السماح بالوصول إلى الكاميرا.';
        
        if (error && (error.name === 'NotAllowedError' || error.name === 'PermissionDeniedError')) {
            errorMessage = 'تم رفض الوصول إلى الكاميرا. يرجى السماح بالوصول في إعدادات المتصفح وإعادة المحاولة.';
        } else if (error && (error.name === 'NotFoundError' || error.name === 'DevicesNotFoundError')) {
            errorMessage = 'لم يتم العثور على كاميرا. يرجى التأكد من وجود كاميرا متصلة.';
        } else if (error && (error.name === 'NotReadableError' || error.name === 'TrackStartError')) {
            errorMessage = 'الكاميرا مستخدمة من قبل تطبيق آخر. يرجى إغلاق التطبيقات الأخرى وإعادة المحاولة.';
        } else if (error && error.message && error.message.includes('Timeout')) {
            errorMessage = 'انتهت مهلة الوصول للكاميرا. يرجى إعادة المحاولة.';
        } else if (error && error.message) {
            errorMessage = 'خطأ في الكاميرا: ' + error.message;
        } else if (typeof error === 'string') {
            errorMessage = error;
        }
        
        cameraErrorText.textContent = errorMessage;
    }
    if (captureBtn) captureBtn.style.display = 'none';
}

// إيقاف الكاميرا
function stopCamera() {
    if (currentStream) {
        currentStream.getTracks().forEach(track => track.stop());
        currentStream = null;
    }
}

// التقاط صورة
function capturePhoto() {
    const video = document.getElementById('video');
    const canvas = document.getElementById('canvas');
    const capturedImage = document.getElementById('capturedImage');
    const cameraContainer = document.getElementById('cameraContainer');
    const capturedImageContainer = document.getElementById('capturedImageContainer');
    
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0);
    
    // تحويل إلى base64
    capturedPhoto = canvas.toDataURL('image/jpeg', 0.8);
    capturedImage.src = capturedPhoto;
    
    // إيقاف الكاميرا
    stopCamera();
    
    // إخفاء الكاميرا وإظهار الصورة
    cameraContainer.style.display = 'none';
    capturedImageContainer.style.display = 'block';
    
    // إظهار أزرار إعادة التقاط والتأكيد
    document.getElementById('captureBtn').style.display = 'none';
    document.getElementById('retakeBtn').style.display = 'inline-block';
    document.getElementById('submitBtn').style.display = 'inline-block';
}

// إعادة التقاط
function retakePhoto() {
    capturedPhoto = null;
    document.getElementById('capturedImageContainer').style.display = 'none';
    document.getElementById('cameraContainer').style.display = 'block';
    document.getElementById('retakeBtn').style.display = 'none';
    document.getElementById('submitBtn').style.display = 'none';
    document.getElementById('captureBtn').style.display = 'inline-block';
    
    initCamera();
}

// إرسال تسجيل الحضور/الانصراف
async function submitAttendance(action) {
    if (!capturedPhoto) {
        alert('يجب التقاط صورة أولاً');
        return;
    }
    
    const submitBtn = document.getElementById('submitBtn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري الإرسال...';
    
    try {
        const apiPath = getAttendanceApiPath();
        
        // تسجيل معلومات الصورة في console للتأكد
        console.log('Submitting attendance:', {
            action: action,
            photoLength: capturedPhoto.length,
            photoPrefix: capturedPhoto.substring(0, 50),
            apiPath: apiPath
        });
        
        // إرسال الصورة كـ JSON (أفضل للبيانات الكبيرة)
        const payload = {
            action: action,
            photo: capturedPhoto
        };
        
        console.log('Payload photo value:', payload.photo ? 'exists (length: ' + payload.photo.length + ')' : 'missing');
        
        const response = await fetch(apiPath, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json; charset=utf-8'
            },
            body: JSON.stringify(payload)
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            const text = await response.text();
            console.error('Non-JSON response:', text);
            throw new Error('استجابة غير صحيحة من الخادم');
        }
        
        const data = await response.json();
        
        console.log('API Response:', data);
        
        if (data.success) {
            // إغلاق الـ modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('cameraModal'));
            if (modal) {
                modal.hide();
            }
            
            // إظهار رسالة نجاح
            showAlert('success', data.message || 'تم التسجيل بنجاح');
            
            // تحديث حالة الأزرار بناءً على الإجراء
            updateButtonsState(action);
            
            // إعادة تحميل الصفحة بعد ثانية ونصف
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            showAlert('danger', data.message || 'فشل التسجيل');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="bi bi-check-circle me-2"></i>تأكيد وإرسال';
        }
        
    } catch (error) {
        console.error('Error submitting attendance:', error);
        showAlert('danger', 'حدث خطأ أثناء الإرسال: ' + error.message);
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="bi bi-check-circle me-2"></i>تأكيد وإرسال';
    }
}

// تحديث حالة الأزرار بعد التسجيل
function updateButtonsState(action) {
    const checkInBtn = document.getElementById('checkInBtn');
    const checkOutBtn = document.getElementById('checkOutBtn');
    
    if (action === 'check_in') {
        // بعد تسجيل حضور، تعطيل زر الحضور وتفعيل زر الانصراف
        if (checkInBtn) {
            checkInBtn.disabled = true;
            checkInBtn.innerHTML = '<i class="bi bi-camera me-2"></i>تم تسجيل الحضور';
            // تحديث النص التوضيحي
            const checkInCard = checkInBtn.closest('.card-body');
            if (checkInCard) {
                const smallText = checkInCard.querySelector('small');
                if (smallText) {
                    smallText.className = 'text-warning d-block mt-2';
                    smallText.innerHTML = '<i class="bi bi-info-circle me-1"></i>يجب تسجيل الانصراف أولاً';
                }
            }
        }
        if (checkOutBtn) {
            checkOutBtn.disabled = false;
            checkOutBtn.innerHTML = '<i class="bi bi-camera me-2"></i>تسجيل الانصراف';
            // تحديث النص التوضيحي
            const checkOutCard = checkOutBtn.closest('.card-body');
            if (checkOutCard) {
                const smallText = checkOutCard.querySelector('small');
                if (smallText) {
                    smallText.className = 'text-muted d-block mt-2';
                    smallText.innerHTML = 'سيتم التقاط صورة تلقائياً';
                }
            }
        }
    } else if (action === 'check_out') {
        // بعد تسجيل انصراف، تفعيل زر الحضور وتعطيل زر الانصراف
        if (checkInBtn) {
            checkInBtn.disabled = false;
            checkInBtn.innerHTML = '<i class="bi bi-camera me-2"></i>تسجيل الحضور';
            // تحديث النص التوضيحي
            const checkInCard = checkInBtn.closest('.card-body');
            if (checkInCard) {
                const smallText = checkInCard.querySelector('small');
                if (smallText) {
                    smallText.className = 'text-muted d-block mt-2';
                    smallText.innerHTML = 'سيتم التقاط صورة تلقائياً';
                }
            }
        }
        if (checkOutBtn) {
            checkOutBtn.disabled = true;
            checkOutBtn.innerHTML = '<i class="bi bi-camera me-2"></i>لا يمكن تسجيل الانصراف';
            // تحديث النص التوضيحي
            const checkOutCard = checkOutBtn.closest('.card-body');
            if (checkOutCard) {
                const smallText = checkOutCard.querySelector('small');
                if (smallText) {
                    smallText.className = 'text-warning d-block mt-2';
                    smallText.innerHTML = '<i class="bi bi-info-circle me-1"></i>يجب تسجيل الحضور أولاً';
                }
            }
        }
    }
}

// إظهار تنبيه
function showAlert(type, message) {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3`;
    alertDiv.style.zIndex = '9999';
    alertDiv.innerHTML = `
        <i class="bi bi-${type === 'success' ? 'check-circle' : 'exclamation-triangle'}-fill me-2"></i>
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    document.body.appendChild(alertDiv);
    
    setTimeout(() => {
        alertDiv.remove();
    }, 5000);
}

// معالجة فتح الـ modal
document.addEventListener('DOMContentLoaded', function() {
    const cameraModal = document.getElementById('cameraModal');
    const checkInBtn = document.getElementById('checkInBtn');
    const checkOutBtn = document.getElementById('checkOutBtn');
    const captureBtn = document.getElementById('captureBtn');
    const retakeBtn = document.getElementById('retakeBtn');
    const submitBtn = document.getElementById('submitBtn');
    const cancelBtn = document.getElementById('cancelBtn');
    
    // التحقق من وجود العناصر
    if (!cameraModal) {
        console.error('Camera modal not found');
        return;
    }
    
    // عند فتح الـ modal
    cameraModal.addEventListener('show.bs.modal', function(event) {
        const button = event.relatedTarget;
        
        // إذا لم يكن button موجوداً (أي تم فتح الـ modal برمجياً)، استخدم currentAction
        if (button) {
            currentAction = button.getAttribute('data-action');
        }
        
        // تحديث العنوان
        const title = document.getElementById('cameraModalTitle');
        if (title) {
            if (currentAction === 'check_in') {
                title.textContent = 'تسجيل الحضور - التقاط صورة';
            } else {
                title.textContent = 'تسجيل الانصراف - التقاط صورة';
            }
        }
        
        // إعادة تعيين الحالة
        capturedPhoto = null;
        const cameraContainer = document.getElementById('cameraContainer');
        const capturedImageContainer = document.getElementById('capturedImageContainer');
        
        if (cameraContainer) cameraContainer.style.display = 'block';
        if (capturedImageContainer) capturedImageContainer.style.display = 'none';
        if (captureBtn) captureBtn.style.display = 'none';
        if (retakeBtn) retakeBtn.style.display = 'none';
        if (submitBtn) submitBtn.style.display = 'none';
        
        // إيقاف أي stream سابق
        stopCamera();
        
        // تهيئة الكاميرا بعد تأخير قصير لضمان أن الـ modal مفتوح بالكامل
        setTimeout(() => {
            initCamera();
        }, 500);
    });
    
    // عند إغلاق الـ modal
    cameraModal.addEventListener('hidden.bs.modal', function() {
        stopCamera();
        capturedPhoto = null;
        currentAction = null;
    });
    
    // إضافة event listeners للأزرار مع التحقق من حالة التعطيل
    if (checkInBtn) {
        checkInBtn.addEventListener('click', function(e) {
            if (checkInBtn.disabled) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            }
            // تعيين الإجراء وفتح modal
            currentAction = 'check_in';
            const modal = new bootstrap.Modal(cameraModal);
            modal.show();
        });
    }
    
    if (checkOutBtn) {
        checkOutBtn.addEventListener('click', function(e) {
            if (checkOutBtn.disabled) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            }
            // تعيين الإجراء وفتح modal
            currentAction = 'check_out';
            const modal = new bootstrap.Modal(cameraModal);
            modal.show();
        });
    }
    
    // أحداث الأزرار
    if (captureBtn) {
        captureBtn.addEventListener('click', capturePhoto);
    }
    if (retakeBtn) {
        retakeBtn.addEventListener('click', retakePhoto);
    }
    if (submitBtn) {
        submitBtn.addEventListener('click', function() {
            if (currentAction) {
                submitAttendance(currentAction);
            }
        });
    }
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function() {
            stopCamera();
        });
    }
});

