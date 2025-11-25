/**
 * Sidebar Control Script
 * سكريبت للتحكم في الشريط الجانبي
 */

(function() {
    'use strict';
    
    // Initialize sidebar on page load
    document.addEventListener('DOMContentLoaded', function() {
        initSidebar();
        initMobileMenu();
        loadNotifications();
    });
    
    /**
     * Initialize Sidebar
     */
    function initSidebar() {
        const sidebarToggle = document.getElementById('sidebarToggle');
        const dashboardWrapper = document.querySelector('.dashboard-wrapper');
        
        if (!sidebarToggle || !dashboardWrapper) return;
        
        // Check localStorage for sidebar state
        const isMobile = window.innerWidth <= 768;
        const sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        if (sidebarCollapsed && !isMobile) {
            dashboardWrapper.classList.add('sidebar-collapsed');
        } else {
            dashboardWrapper.classList.remove('sidebar-collapsed');
        }
        
        // Toggle sidebar on button click
        sidebarToggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            if (window.innerWidth <= 768) {
                dashboardWrapper.classList.remove('sidebar-open');
                document.body.classList.remove('sidebar-open');
                return;
            }
            
            dashboardWrapper.classList.toggle('sidebar-collapsed');
            
            // Save state to localStorage
            const isCollapsed = dashboardWrapper.classList.contains('sidebar-collapsed');
            localStorage.setItem('sidebarCollapsed', isCollapsed);
        });
        
        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth <= 768) {
                dashboardWrapper.classList.remove('sidebar-open');
                dashboardWrapper.classList.remove('sidebar-collapsed');
            } else {
                const storedCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
                if (storedCollapsed) {
                    dashboardWrapper.classList.add('sidebar-collapsed');
                } else {
                    dashboardWrapper.classList.remove('sidebar-collapsed');
                }
            }
        });
    }
    
    /**
     * Initialize Mobile Menu
     */
    function initMobileMenu() {
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const dashboardWrapper = document.querySelector('.dashboard-wrapper');
        
        if (!mobileMenuToggle || !dashboardWrapper) return;
        
        mobileMenuToggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const isOpening = !dashboardWrapper.classList.contains('sidebar-open');
            dashboardWrapper.classList.toggle('sidebar-open');
            if (isOpening) {
                dashboardWrapper.classList.remove('sidebar-collapsed');
            }
            
            // Prevent body scroll when sidebar is open
            if (isOpening) {
                document.body.classList.add('sidebar-open');
            } else {
                document.body.classList.remove('sidebar-open');
            }
        });
        
        // Close sidebar when clicking on overlay or outside on mobile
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 768) {
                const sidebar = document.querySelector('.homeline-sidebar');
                const isClickInsideSidebar = sidebar && sidebar.contains(e.target);
                const isClickOnToggle = mobileMenuToggle && mobileMenuToggle.contains(e.target);
                
                // Check if click is on overlay (before pseudo-element area)
                if (!isClickInsideSidebar && !isClickOnToggle && dashboardWrapper.classList.contains('sidebar-open')) {
                    dashboardWrapper.classList.remove('sidebar-open');
                    document.body.classList.remove('sidebar-open');
                }
            }
        });
        
        // Close sidebar on window resize if it becomes desktop
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                dashboardWrapper.classList.remove('sidebar-open');
                document.body.classList.remove('sidebar-open');
            }
        });
    }
    
    /**
     * Load Notifications
     */
    function loadNotifications() {
        const notificationsList = document.getElementById('notificationsList');
        const notificationBadge = document.getElementById('notificationBadge');
        
        if (!notificationsList) return;
        
        // تحديد المسار الصحيح لـ API
        const currentPath = window.location.pathname;
        const parts = currentPath.split('/').filter(p => p);
        const basePath = parts.find(p => p !== 'dashboard' && p !== 'api' && !p.endsWith('.php')) || '';
        
        let apiPath = basePath ? '/' + basePath + '/api/notifications.php' : '/api/notifications.php';
        apiPath = apiPath.replace(/\/+/g, '/');
        
        // Fetch notifications from API
        fetch(apiPath + '?action=get_unread')
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success && data.notifications) {
                    updateNotificationBadge(data.notifications.length);
                    renderNotifications(data.notifications);
                } else {
                    notificationsList.innerHTML = '<small class="text-muted">' + (data.message || 'لا توجد إشعارات') + '</small>';
                }
            })
            .catch(error => {
                console.error('Error loading notifications:', error);
                notificationsList.innerHTML = '<small class="text-muted">خطأ في تحميل الإشعارات</small>';
            });
    }
    
    /**
     * Update Notification Badge
     */
    function updateNotificationBadge(count) {
        const badge = document.getElementById('notificationBadge');
        if (badge) {
            if (count > 0) {
                badge.textContent = count > 99 ? '99+' : count;
                badge.style.display = 'inline-block';
            } else {
                badge.style.display = 'none';
            }
        }
    }
    
    /**
     * Render Notifications
     */
    function renderNotifications(notifications) {
        const notificationsList = document.getElementById('notificationsList');
        if (!notificationsList) return;
        
        if (notifications.length === 0) {
            notificationsList.innerHTML = '<small class="text-muted">لا توجد إشعارات جديدة</small>';
            return;
        }
        
        let html = '';
        notifications.slice(0, 5).forEach(notification => {
            const unreadClass = notification.read ? '' : 'unread';
            html += `
                <div class="notification-item ${unreadClass}">
                    <div class="d-flex align-items-start">
                        <div class="flex-grow-1">
                            <div class="fw-bold small">${escapeHtml(notification.title || 'إشعار')}</div>
                            <div class="text-muted small">${escapeHtml(notification.message || '')}</div>
                            <div class="text-muted" style="font-size: 11px; margin-top: 4px;">
                                ${formatTime(notification.created_at)}
                            </div>
                        </div>
                    </div>
                </div>
            `;
        });
        
        if (notifications.length > 5) {
            html += `
                <div class="dropdown-divider"></div>
                <a href="#" class="dropdown-item text-center">
                    <small>عرض جميع الإشعارات</small>
                </a>
            `;
        }
        
        notificationsList.innerHTML = html;
    }
    
    /**
     * Helper: Escape HTML
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    /**
     * Helper: Format Time
     */
    function formatTime(timestamp) {
        if (!timestamp) return '';
        
        const date = new Date(timestamp);
        const now = new Date();
        const diff = now - date;
        const minutes = Math.floor(diff / 60000);
        const hours = Math.floor(diff / 3600000);
        const days = Math.floor(diff / 86400000);
        
        if (minutes < 1) return 'الآن';
        if (minutes < 60) return `منذ ${minutes} دقيقة`;
        if (hours < 24) return `منذ ${hours} ساعة`;
        if (days < 7) return `منذ ${days} يوم`;
        
        return date.toLocaleDateString('ar-EG');
    }
})();

