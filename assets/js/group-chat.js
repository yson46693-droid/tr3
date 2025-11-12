(() => {
    const config = window.GROUP_CHAT_CONFIG || {};
    const apiUrl = config.apiUrl;
    const pollInterval = Number(config.pollInterval || 10000);
    const currentUser = config.currentUser || {};
    const initialMessages = Array.isArray(config.initialMessages) ? config.initialMessages : [];

    const elements = {
        container: document.querySelector('[data-chat-root]'),
        messagesWrapper: document.getElementById('groupChatMessages'),
        emptyState: document.getElementById('groupChatEmptyState'),
        textarea: document.getElementById('groupChatInput'),
        sendButton: document.getElementById('groupChatSend'),
        cancelReply: document.getElementById('groupChatCancelReply'),
        replyBanner: document.getElementById('groupChatReplyBanner'),
        replyText: document.getElementById('groupChatReplyText'),
        replyMeta: document.getElementById('groupChatReplyMeta'),
        dismissReply: document.getElementById('groupChatDismissReply'),
        typingIndicator: document.getElementById('groupChatLoading'),
        headerBadge: document.getElementById('groupChatMessageCount'),
        purgeButton: document.getElementById('groupChatPurge'),
    };

    if (!elements.container || !apiUrl) {
        return;
    }

    const state = {
        messages: [...initialMessages],
        latestId: null,
        replyTo: null,
        editingId: null,
        isSending: false,
        isLoading: false,
        pollTimer: null,
    };

    const getInitials = (message) => {
        const author = message.author || {};
        const fullName = author.full_name || author.username || '';
        const trimmed = fullName.trim();
        if (!trimmed) {
            return '??';
        }

        const parts = trimmed.split(/\s+/);
        const first = parts[0] || '';
        const last = parts.length > 1 ? parts[parts.length - 1] : '';
        const initials = (first.charAt(0) + (last.charAt(0) || '')).toUpperCase();
        return initials || first.slice(0, 2).toUpperCase();
    };

    const formatTimestamp = (value) => {
        if (!value) {
            return '';
        }

        try {
            const date = new Date(value);
            if (Number.isNaN(date.getTime())) {
                return value;
            }

            const formatter = new Intl.DateTimeFormat('ar-EG', {
                dateStyle: 'medium',
                timeStyle: 'short',
            });

            return formatter.format(date);
        } catch (error) {
            return value;
        }
    };

    const showToast = (message, type = 'info') => {
        if (window.Toastify) {
            window.Toastify({
                text: message,
                gravity: 'top',
                position: 'left',
                backgroundColor: type === 'error' ? '#ff4d4f' : '#1f4eb5',
                duration: 4000,
            }).showToast();
        } else {
            if (type === 'error') {
                alert(message);
            } else {
                console.log(message);
            }
        }
    };

    const updateMessageCount = () => {
        if (!elements.headerBadge) {
            return;
        }
        const visibleMessages = state.messages.filter((msg) => !msg.deleted_at);
        elements.headerBadge.textContent = visibleMessages.length.toString();
    };

    const toggleLoading = (isLoading) => {
        state.isLoading = isLoading;
        if (elements.typingIndicator) {
            elements.typingIndicator.classList.toggle('d-none', !isLoading);
        }
    };

    const setSendButtonState = (disabled) => {
        if (!elements.sendButton) {
            return;
        }
        elements.sendButton.disabled = disabled;
        if (disabled) {
            elements.sendButton.classList.add('disabled');
        } else {
            elements.sendButton.classList.remove('disabled');
        }
    };

    const clearReplyTarget = () => {
        state.replyTo = null;
        if (elements.replyBanner) {
            elements.replyBanner.classList.add('d-none');
        }
    };

    const clearEditTarget = () => {
        state.editingId = null;
        if (elements.sendButton) {
            elements.sendButton.innerHTML = '<i class="bi bi-send"></i> إرسال';
        }
    };

    const setReplyTarget = (message) => {
        state.replyTo = message;
        if (!elements.replyBanner || !message) {
            clearReplyTarget();
            return;
        }

        elements.replyBanner.classList.remove('d-none');
        const author = message.author || {};
        if (elements.replyMeta) {
            elements.replyMeta.textContent = `${author.full_name || author.username || 'مستخدم مجهول'}`;
        }
        if (elements.replyText) {
            const preview = message.message ? message.message.slice(0, 140) : '';
            elements.replyText.textContent = preview;
        }
    };

    const setEditTarget = (message) => {
        state.editingId = message.id;
        setReplyTarget(message.parent ? message.parent : null);
        if (elements.textarea) {
            elements.textarea.value = message.message || '';
            elements.textarea.focus();
            setSendButtonState(false);
        }
        if (elements.sendButton) {
            elements.sendButton.innerHTML = '<i class="bi bi-check-circle"></i> حفظ التعديلات';
        }
    };

    const buildReplyPreview = (message) => {
        if (!message.parent || !message.parent.id) {
            return null;
        }

        const reply = document.createElement('div');
        reply.className = 'reply-preview';
        const name = message.parent.author?.full_name || message.parent.author?.username || 'مستخدم';
        const title = document.createElement('strong');
        title.textContent = name;
        reply.appendChild(title);

        const text = document.createElement('span');
        if (message.parent.deleted_at) {
            text.textContent = 'تم حذف هذه الرسالة';
        } else {
            text.textContent = (message.parent.message || '').slice(0, 160);
        }
        reply.appendChild(text);

        return reply;
    };

    const createActionButton = (icon, label) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.setAttribute('aria-label', label);
        btn.innerHTML = `<i class="bi bi-${icon}"></i>`;
        return btn;
    };

    const renderMessages = (scrollToBottom = false) => {
        if (!elements.messagesWrapper) {
            return;
        }

        const fragment = document.createDocumentFragment();

        if (!state.messages.length) {
            if (elements.emptyState) {
                elements.emptyState.classList.remove('d-none');
            }
        } else if (elements.emptyState) {
            elements.emptyState.classList.add('d-none');
        }

        state.messages.forEach((message) => {
            const card = document.createElement('div');
            card.className = 'chat-message';
            if (message.user_id === currentUser.id) {
                card.classList.add('mine');
            }
            if (message.deleted_at) {
                card.classList.add('deleted');
            }

            const header = document.createElement('div');
            header.className = 'message-author';

            const avatar = document.createElement('div');
            avatar.className = 'author-avatar';
            avatar.textContent = getInitials(message);
            header.appendChild(avatar);

            const names = document.createElement('div');
            const displayName = document.createElement('div');
            displayName.className = 'author-name';
            displayName.textContent = message.author?.full_name || message.author?.username || 'مستخدم';
            names.appendChild(displayName);

            if (message.author?.role) {
                const role = document.createElement('span');
                role.className = 'author-role';
                role.textContent = message.author.role;
                names.appendChild(role);
            }

            header.appendChild(names);
            card.appendChild(header);

            const replyPreview = buildReplyPreview(message);
            if (replyPreview) {
                card.appendChild(replyPreview);
            }

            const content = document.createElement('div');
            content.className = 'message-content';
            if (message.deleted_at) {
                content.textContent = 'تم حذف هذه الرسالة';
            } else {
                content.textContent = message.message || '';
            }
            card.appendChild(content);

            const meta = document.createElement('div');
            meta.className = 'message-meta';

            const timestamp = document.createElement('span');
            timestamp.textContent = formatTimestamp(message.updated_at || message.created_at);
            meta.appendChild(timestamp);

            const actions = document.createElement('div');
            actions.className = 'message-actions';

            const replyBtn = createActionButton('reply', 'رد');
            replyBtn.addEventListener('click', () => {
                setReplyTarget(message);
                elements.textarea?.focus();
            });
            actions.appendChild(replyBtn);

            if (!message.deleted_at && message.can_edit) {
                const editBtn = createActionButton('pencil', 'تعديل');
                editBtn.addEventListener('click', () => setEditTarget(message));
                actions.appendChild(editBtn);
            }

            if (!message.deleted_at && message.can_delete) {
                const deleteBtn = createActionButton('trash', 'حذف');
                deleteBtn.classList.add('danger');
                deleteBtn.addEventListener('click', () => handleDelete(message));
                actions.appendChild(deleteBtn);
            }

            meta.appendChild(actions);
            card.appendChild(meta);

            fragment.appendChild(card);
        });

        const shouldStickToBottom = scrollToBottom || isNearBottom();

        elements.messagesWrapper.innerHTML = '';
        elements.messagesWrapper.appendChild(fragment);

        if (shouldStickToBottom) {
            requestAnimationFrame(() => {
                elements.messagesWrapper.scrollTop = elements.messagesWrapper.scrollHeight;
            });
        }

        updateMessageCount();
    };

    const isNearBottom = () => {
        if (!elements.messagesWrapper) {
            return false;
        }
        const { scrollTop, scrollHeight, clientHeight } = elements.messagesWrapper;
        return scrollHeight - (scrollTop + clientHeight) < 120;
    };

    const sortMessages = () => {
        state.messages.sort((a, b) => {
            const aDate = new Date(a.created_at || 0).getTime();
            const bDate = new Date(b.created_at || 0).getTime();
            if (aDate === bDate) {
                return (a.id || 0) - (b.id || 0);
            }
            return aDate - bDate;
        });

        const ids = state.messages.map((m) => m.id || 0);
        state.latestId = ids.length ? Math.max(...ids) : null;
    };

    const upsertMessage = (message) => {
        if (!message || !message.id) {
            return;
        }

        const index = state.messages.findIndex((item) => item.id === message.id);
        if (index >= 0) {
            state.messages[index] = message;
        } else {
            state.messages.push(message);
        }
    };

    const mergeMessages = (messages) => {
        if (!Array.isArray(messages)) {
            return;
        }

        let isNew = false;
        messages.forEach((message) => {
            const beforeCount = state.messages.length;
            upsertMessage(message);
            if (state.messages.length > beforeCount) {
                isNew = true;
            }
        });

        sortMessages();
        renderMessages(isNew);
    };

    const handleDelete = async (message) => {
        if (!message?.id) {
            return;
        }

        const confirmed = window.confirm('هل أنت متأكد من حذف الرسالة؟');
        if (!confirmed) {
            return;
        }

        try {
            const response = await fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    action: 'delete',
                    message_id: message.id,
                }),
            });

            if (!response.ok) {
                throw new Error('Delete failed');
            }

            const data = await response.json();
            if (!data.success) {
                throw new Error(data.error || 'Delete failed');
            }

            const updated = {
                ...message,
                deleted_at: new Date().toISOString(),
            };
            upsertMessage(updated);
            sortMessages();
            renderMessages();
            showToast('تم حذف الرسالة', 'info');
        } catch (error) {
            showToast('تعذر حذف الرسالة. حاول لاحقاً.', 'error');
        }
    };

    const fetchMessages = async () => {
        if (state.isLoading) {
            return;
        }

        toggleLoading(true);

        try {
            const params = new URLSearchParams();
            params.set('limit', '200');
            if (state.latestId) {
                params.set('since_id', String(state.latestId));
            }

            const response = await fetch(`${apiUrl}?${params.toString()}`, {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error('Fetch failed');
            }

            const data = await response.json();
            if (Array.isArray(data.data)) {
                mergeMessages(data.data);
            }
        } catch (error) {
            console.error('Group chat fetch error', error);
        } finally {
            toggleLoading(false);
        }
    };

    const submitMessage = async () => {
        if (!elements.textarea) {
            return;
        }

        const text = elements.textarea.value.trim();
        if (!text) {
            showToast('يرجى كتابة رسالة أولاً.', 'error');
            return;
        }

        if (state.isSending) {
            return;
        }

        state.isSending = true;
        setSendButtonState(true);

        const payload = {
            action: state.editingId ? 'edit' : 'create',
            message: text,
        };

        if (state.replyTo?.id) {
            payload.parent_id = state.replyTo.id;
        }
        if (state.editingId) {
            payload.message_id = state.editingId;
        }

        try {
            const response = await fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload),
            });

            if (!response.ok) {
                throw new Error('Request failed');
            }

            const data = await response.json();
            if (!data.success || !data.data) {
                throw new Error(data.error || 'Request failed');
            }

            upsertMessage(data.data);
            sortMessages();
            renderMessages(true);

            elements.textarea.value = '';
            clearReplyTarget();
            clearEditTarget();
        } catch (error) {
            showToast('حدث خطأ أثناء إرسال الرسالة.', 'error');
        } finally {
            state.isSending = false;
            const shouldDisable = !(elements.textarea && elements.textarea.value.trim().length);
            setSendButtonState(shouldDisable);
        }
    };

    const initPoller = () => {
        if (state.pollTimer) {
            clearInterval(state.pollTimer);
        }
        state.pollTimer = window.setInterval(fetchMessages, pollInterval);
    };

    function autoResizeTextarea() {
        if (!elements.textarea) {
            return;
        }
        elements.textarea.style.height = 'auto';
        const newHeight = Math.min(elements.textarea.scrollHeight, 220);
        elements.textarea.style.height = `${newHeight}px`;
    }

    const handlePurge = async () => {
        if (!elements.purgeButton) {
            return;
        }

        const confirmMessage = elements.purgeButton.dataset.confirm || 'Delete all chat messages?';
        if (!window.confirm(confirmMessage)) {
            return;
        }

        try {
            elements.purgeButton.disabled = true;

            const response = await fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ action: 'purge' }),
            });

            if (!response.ok) {
                throw new Error('Purge failed');
            }

            const data = await response.json();
            if (!data.success) {
                throw new Error(data.error || 'Purge failed');
            }

            state.messages = [];
            state.latestId = null;
            renderMessages();
            showToast('تم حذف جميع الرسائل.', 'info');
        } catch (error) {
            showToast('تعذر حذف الرسائل حالياً.', 'error');
        } finally {
            elements.purgeButton.disabled = false;
        }
    };

    const registerEvents = () => {
        elements.sendButton?.addEventListener('click', submitMessage);
        elements.cancelReply?.addEventListener('click', () => {
            clearReplyTarget();
            clearEditTarget();
            if (elements.textarea) {
                elements.textarea.value = '';
                autoResizeTextarea();
                elements.textarea.focus();
            }
            setSendButtonState(true);
        });
        elements.dismissReply?.addEventListener('click', () => {
            clearReplyTarget();
            if (!state.editingId) {
                elements.textarea?.focus();
            }
        });

        elements.textarea?.addEventListener('keydown', (event) => {
            if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
                event.preventDefault();
                submitMessage();
            }
        });

        elements.textarea?.addEventListener('input', () => {
            autoResizeTextarea();
            const hasText = elements.textarea.value.trim().length > 0;
            setSendButtonState(state.isSending || !hasText);
        });

        elements.purgeButton?.addEventListener('click', handlePurge);
    };

    sortMessages();
    renderMessages();
    updateMessageCount();
    registerEvents();
    setSendButtonState(!(elements.textarea && elements.textarea.value.trim().length));
    autoResizeTextarea();
    initPoller();

    if (!state.messages.length) {
        fetchMessages();
    }

    window.GROUP_CHAT_API = {
        refresh: fetchMessages,
        messages: () => [...state.messages],
    };
})();
