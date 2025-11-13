<?php
/**
 * صفحة الدردشة الجماعية
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/group_chat.php';
require_once __DIR__ . '/../../includes/path_helper.php';

requireLogin();

if (!defined('GROUP_CHAT_ASSETS_EMITTED')) {
    $chatCssHref = htmlspecialchars(getRelativeUrl('assets/css/group-chat.css'), ENT_QUOTES, 'UTF-8');
    $chatJsSrc = htmlspecialchars(getRelativeUrl('assets/js/group-chat.js'), ENT_QUOTES, 'UTF-8');
    echo '<link rel="stylesheet" href="' . $chatCssHref . '">';
    echo '<script defer src="' . $chatJsSrc . '"></script>';
    define('GROUP_CHAT_ASSETS_EMITTED', true);
}

$currentUser = getCurrentUser();
$canModerate = isGroupChatModeratorRole($currentUser['role'] ?? null);

$initialMessages = getGroupChatMessages(['limit' => 120]);
$initialMessages = array_map(static function (array $message) use ($currentUser, $canModerate) {
    $ownerId = $message['user_id'] ?? 0;
    $isOwner = (int) $ownerId === (int) ($currentUser['id'] ?? 0);
    $message['can_edit'] = $isOwner || $canModerate;
    $message['can_delete'] = $isOwner || $canModerate;
    return $message;
}, $initialMessages);

$chatTitle = $lang['menu_group_chat'] ?? 'الدردشة الجماعية';

$chatWords = preg_split('/\s+/u', trim((string) $chatTitle));
$chatInitials = '';
if (is_array($chatWords)) {
    foreach ($chatWords as $word) {
        if ($word === '') {
            continue;
        }
        $chatInitials .= mb_substr($word, 0, 1, 'UTF-8');
        if (mb_strlen($chatInitials, 'UTF-8') >= 2) {
            break;
        }
    }
}

$chatInitials = mb_strtoupper(mb_substr($chatInitials, 0, 2, 'UTF-8'), 'UTF-8');
if ($chatInitials === '') {
    $chatInitials = 'GC';
}

$apiUrl = getRelativeUrl('api/group_chat.php');
$pollIntervalMs = 10000;

$chatConfig = [
    'apiUrl' => $apiUrl,
    'pollInterval' => $pollIntervalMs,
    'currentUser' => [
        'id' => (int) ($currentUser['id'] ?? 0),
        'role' => $currentUser['role'] ?? null,
        'full_name' => $currentUser['full_name'] ?? ($currentUser['username'] ?? ''),
        'username' => $currentUser['username'] ?? '',
    ],
    'initialMessages' => $initialMessages,
    'canModerate' => $canModerate,
];
?>

<div class="group-chat-page" data-chat-root>
    <div class="page-header group-chat-page-header">
        <h2 class="page-title">
            <i class="bi bi-chat-dots-fill me-2"></i><?php echo htmlspecialchars($chatTitle); ?>
        </h2>
        <p class="page-subtitle">تواصل مباشر بين جميع أعضاء الفريق مع إمكانية الرد والتعديل والحذف.</p>
    </div>

    <div class="group-chat-shell">
        <div class="group-chat-card">
            <div class="group-chat-header">
                <div class="conversation-header">
                    <div class="conversation-avatar" aria-hidden="true">
                        <span class="avatar-initials"><?php echo htmlspecialchars($chatInitials); ?></span>
                        <span class="avatar-status"></span>
                    </div>
                    <div class="conversation-details">
                        <h3 class="conversation-title"><?php echo htmlspecialchars($chatTitle); ?></h3>
                        <div class="group-chat-meta">
                            <span class="status-dot" aria-hidden="true"></span>
                            <span class="meta-text">متصل الآن</span>
                            <span class="meta-divider">•</span>
                            <span class="meta-text">يتم التحديث كل <?php echo (int) ($pollIntervalMs / 1000); ?> ثوانٍ</span>
                        </div>
                    </div>
                </div>
                <div class="header-actions">
                    <span class="header-pill" id="groupChatMessageCount">0</span>
                    <?php if ($canModerate): ?>
                        <button type="button" class="header-action-btn" id="groupChatPurge" data-confirm="هل أنت متأكد من حذف جميع الرسائل؟" title="حذف جميع رسائل الدردشة">
                            <i class="bi bi-trash3"></i>
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="group-chat-body">
                <div class="chat-messages-wrapper">
                    <div class="chat-messages-scroll" id="groupChatMessages"></div>
                    <div class="chat-empty-state d-none" id="groupChatEmptyState">
                        <div class="chat-empty-illustration">
                            <div class="bubble bubble-in">
                                <span class="bubble-author">أحمد</span>
                                <span class="bubble-text">مرحباً بالجميع 👋</span>
                                <span class="bubble-time">9:41 م</span>
                            </div>
                            <div class="bubble bubble-out">
                                <span class="bubble-author">أنا</span>
                                <span class="bubble-text">أهلاً! هذا المثال يوضح شكل المحادثة.</span>
                                <span class="bubble-time">9:42 م</span>
                            </div>
                        </div>
                        <i class="bi bi-stars"></i>
                        <p class="mb-1">ابدأ المحادثة الآن</p>
                        <small>أرسل أول رسالة وستظهر المحادثة بتنسيق شبيه بـ Signal.</small>
                    </div>
                </div>

                <aside class="chat-sidebar">
                    <section class="chat-sidebar-card">
                        <h3><i class="bi bi-info-circle me-2"></i>تفاصيل المحادثة</h3>
                        <ul class="chat-sidebar-list">
                            <li>
                                <span>تحديث تلقائي</span>
                                <span><?php echo (int) ($pollIntervalMs / 1000); ?> ثانية</span>
                            </li>
                            <li>
                                <span>إجمالي الرسائل</span>
                                <span class="info-value" data-chat-stat="messages">—</span>
                            </li>
                        </ul>
                    </section>
                    <section class="chat-sidebar-card">
                        <h3><i class="bi bi-lightning-charge-fill me-2"></i>نصائح سريعة</h3>
                        <div class="chat-tip">
                            <i class="bi bi-reply-fill"></i>
                            استخدم زر <strong>الرد</strong> لربط رسالتك بالسياق المناسب وإبقاء النقاش منظمًا.
                        </div>
                    </section>
                    <section class="chat-sidebar-card">
                        <h3><i class="bi bi-shield-lock-fill me-2"></i>سياسة الأمان</h3>
                        <div class="chat-tip">
                            <i class="bi bi-info-circle-fill"></i>
                            يمكن لكل مستخدم تعديل أو حذف رسائله الخاصة، بينما يمتلك المدير صلاحية الإشراف الكامل لضمان تجربة آمنة.
                        </div>
                    </section>
                    <div class="loading-indicator d-none" id="groupChatLoading">
                        <div class="spinner-border text-primary" role="status"></div>
                        <span>يتم تحميل التحديثات...</span>
                    </div>
                </aside>
            </div>

            <div class="chat-input-area">
                <div class="chat-input-shell">
                    <div class="reply-target-banner d-none" id="groupChatReplyBanner">
                        <div class="flex-grow-1">
                            <strong id="groupChatReplyMeta">رد على:</strong>
                            <div id="groupChatReplyText" class="small text-muted"></div>
                        </div>
                        <button id="groupChatDismissReply" type="button" class="reply-target-dismiss" aria-label="إلغاء الرد">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>

                    <textarea id="groupChatInput" class="form-control" placeholder="اكتب رسالتك هنا..." maxlength="2000"></textarea>

                    <div class="chat-input-actions">
                        <div class="text-muted small">
                            <i class="bi bi-command"></i> + <i class="bi bi-arrow-return-left"></i> لإرسال سريع
                        </div>
                        <div class="action-buttons">
                            <button type="button" class="chat-btn chat-btn-secondary" id="groupChatCancelReply">
                                <i class="bi bi-arrow-counterclockwise"></i>
                                إلغاء
                            </button>
                            <button type="button" class="chat-btn chat-btn-primary" id="groupChatSend">
                                <i class="bi bi-send"></i>
                                إرسال
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    window.GROUP_CHAT_CONFIG = <?php echo json_encode($chatConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
</script>
