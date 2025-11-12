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

$pageStylesheets = isset($pageStylesheets) && is_array($pageStylesheets) ? $pageStylesheets : [];
if (!in_array('assets/css/group-chat.css', $pageStylesheets, true)) {
    $pageStylesheets[] = 'assets/css/group-chat.css';
}

$extraScripts = isset($extraScripts) && is_array($extraScripts) ? $extraScripts : [];
$groupChatScriptPath = getRelativeUrl('assets/js/group-chat.js');
if (!in_array($groupChatScriptPath, $extraScripts, true)) {
    $extraScripts[] = $groupChatScriptPath;
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
    <div class="page-header d-flex align-items-center justify-content-between">
        <div>
            <h2 class="mb-1"><i class="bi bi-chat-dots-fill me-2"></i><?php echo htmlspecialchars($chatTitle); ?></h2>
            <p class="text-muted mb-0">تواصل مباشر بين جميع أعضاء الفريق مع إمكانية الرد والتعديل والحذف.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-gradient" style="background: linear-gradient(135deg, #0f5bea, #1bb0f8);" id="groupChatMessageCount">0</span>
            <?php if ($canModerate): ?>
                <button type="button" class="btn btn-outline-danger btn-sm" id="groupChatPurge" data-confirm="هل أنت متأكد من حذف جميع الرسائل؟" title="حذف جميع رسائل الدردشة">
                    <i class="bi bi-trash3"></i>
                    مسح الكل
                </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="group-chat-shell">
        <div class="group-chat-card">
            <div class="group-chat-header">
                <h2><i class="bi bi-people-fill"></i><?php echo htmlspecialchars($chatTitle); ?></h2>
                <div class="group-chat-meta">
                    <span class="status-dot"></span>
                    <span>متصل</span>
                </div>
            </div>

            <div class="group-chat-body">
                <div class="chat-messages-wrapper">
                    <div class="chat-messages-scroll" id="groupChatMessages"></div>
                    <div class="chat-empty-state d-none" id="groupChatEmptyState">
                        <i class="bi bi-stars"></i>
                        <p class="mb-1">ابدأ المحادثة برسالتك الأولى!</p>
                        <small>شارك المستجدات والمهام مع الفريق بكل سهولة.</small>
                    </div>
                </div>

                <aside class="chat-sidebar">
                    <div>
                        <h3><i class="bi bi-lightning-charge-fill me-2"></i>نصائح سريعة</h3>
                        <div class="chat-tip">
                            <i class="bi bi-reply-fill"></i>
                            استخدم زر <strong>الرد</strong> لربط رسالتك بالسياق المناسب وإبقاء النقاش منظمًا.
                        </div>
                    </div>
                    <div>
                        <h3><i class="bi bi-shield-lock-fill me-2"></i>سياسة الأمان</h3>
                        <div class="chat-tip">
                            <i class="bi bi-info-circle-fill"></i>
                            يمكن لكل مستخدم تعديل أو حذف رسائله الخاصة، بينما يمتلك المدير صلاحية الإشراف الكامل لضمان تجربة آمنة.
                        </div>
                    </div>
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
