/* Admin Panel JavaScript - Real-time features, confirmations, auto-refresh */

(function() {
    'use strict';

    /* Confirm dialogs for dangerous buttons: <form data-confirm="Are you sure?"> */
    document.addEventListener('submit', function(e) {
        var m = e.target && e.target.getAttribute && e.target.getAttribute('data-confirm');
        if (m && !window.confirm(m)) { e.preventDefault(); }
    });

    /* Real-time notification polling for admin panel */
    var NOTIF_POLL_INTERVAL = 15000; // 15 seconds
    var notificationCounts = {
        messages: 0,
        reports: 0,
        appeals: 0,
        deletions: 0
    };

    function pollAdminNotifications() {
        // Poll for new contact messages
        fetch('/api/admin/notifications/?type=messages', { cache: 'no-store' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.ok) { updateNotifBadge('messages', data.count); }
            })
            .catch(function() {});

        // Poll for new reports
        fetch('/api/admin/notifications/?type=reports', { cache: 'no-store' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.ok) { updateNotifBadge('reports', data.count); }
            })
            .catch(function() {});

        // Poll for new appeals
        fetch('/api/admin/notifications/?type=appeals', { cache: 'no-store' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.ok) { updateNotifBadge('appeals', data.count); }
            })
            .catch(function() {});

        // Poll for deletions
        fetch('/api/admin/notifications/?type=deletions', { cache: 'no-store' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.ok) { updateNotifBadge('deletions', data.count); }
            })
            .catch(function() {});
    }

    function updateNotifBadge(type, count) {
        notificationCounts[type] = count;
        var badge = document.querySelector('[data-notif="' + type + '"]');
        if (badge) {
            badge.textContent = count > 0 ? count : '';
            badge.style.display = count > 0 ? 'inline-flex' : 'none';
            badge.classList.toggle('hot', count > 0);
        }
        // Update nav link if present
        var navLink = document.querySelector('.side nav a[href*="' + type + '"]');
        if (navLink && count > 0) {
            if (!navLink.querySelector('.notif-count')) {
                var span = document.createElement('span');
                span.className = 'notif-count';
                span.style.cssText = 'margin-left:8px;padding:2px 6px;background:var(--mg);color:#fff;border-radius:99px;font-size:11px;font-weight:800;';
                span.textContent = count;
                navLink.appendChild(span);
            } else {
                navLink.querySelector('.notif-count').textContent = count;
            }
        } else if (navLink) {
            var existing = navLink.querySelector('.notif-count');
            if (existing) existing.remove();
        }
    }

    /* Auto-refresh for report/detail pages */
    var AUTO_REFRESH_INTERVAL = 30000; // 30 seconds
    var refreshTimers = {};

    function startAutoRefresh(pageType, id) {
        var key = pageType + '_' + id;
        if (refreshTimers[key]) return;

        refreshTimers[key] = setInterval(function() {
            if (document.hidden) return;

            if (pageType === 'report') {
                refreshReportMessages(id);
            } else if (pageType === 'match') {
                refreshMatchState(id);
            }
        }, AUTO_REFRESH_INTERVAL);
    }

    function stopAutoRefresh(pageType, id) {
        var key = pageType + '_' + id;
        if (refreshTimers[key]) {
            clearInterval(refreshTimers[key]);
            delete refreshTimers[key];
        }
    }

    function refreshReportMessages(reportId) {
        fetch('/api/admin/report-messages/?id=' + reportId, { cache: 'no-store' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.ok && data.messages) {
                    renderReportMessages(data.messages);
                    updateUnreadIndicators(data.messages);
                }
            })
            .catch(function() {});
    }

    function renderReportMessages(messages) {
        var container = document.querySelector('.msg-thread');
        if (!container) return;

        var currentScroll = container.scrollTop;
        var wasAtBottom = container.scrollHeight - container.scrollTop === container.clientHeight;

        container.innerHTML = '';
        messages.forEach(function(msg) {
            var sender = msg.sender || {};
            var isAdmin = msg.is_admin;
            var div = document.createElement('div');
            div.className = 'msg' + (isAdmin ? ' admin' : '');
            div.innerHTML =
                '<div class="msg-avatar">' +
                (sender.avatar_url ? '<img src="' + escapeHtml(sender.avatar_url) + '" alt="">' : '<svg aria-hidden="true"><use href="#' + (isAdmin ? 'i-shield' : 'i-bot') + '"/></svg>') +
                '</div>' +
                '<div class="msg-content">' +
                '<div class="msg-header">' +
                '<span class="msg-sender' + (isAdmin ? ' admin' : '') + '">' + escapeHtml(isAdmin ? 'Admin' + (sender.username ? ' (' + sender.username + ')' : '') : 'User') + '</span>' +
                '<span class="msg-time">' + fmtTime(msg.created_at) + '</span>' +
                (isAdmin && !msg.read_by_user ? '<span class="unread-indicator">Unread by user</span>' : '') +
                (!isAdmin && !msg.read_by_admin ? '<span class="unread-indicator" style="background:var(--mg)">Unread by admin</span>' : '') +
                '</div>' +
                '<div class="msg-text">' + escapeHtml(msg.message) + '</div>' +
                '</div>';
            container.appendChild(div);
        });

        if (wasAtBottom) { container.scrollTop = container.scrollHeight; }
        else { container.scrollTop = currentScroll; }
    }

    function updateUnreadIndicators(messages) {
        var unreadAdmin = messages.filter(function(m) { return !m.is_admin && !m.read_by_admin; }).length;
        var unreadUser = messages.filter(function(m) { return m.is_admin && !m.read_by_user; }).length;

        var badge = document.querySelector('[data-unread-admin]');
        if (badge) badge.textContent = unreadAdmin > 0 ? unreadAdmin : '';

        badge = document.querySelector('[data-unread-user]');
        if (badge) badge.textContent = unreadUser > 0 ? unreadUser : '';
    }

    function refreshMatchState(matchId) {
        fetch('/api/tournament/match/?id=' + matchId, { cache: 'no-store' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.ok) {
                    updateMatchUI(data);
                }
            })
            .catch(function() {});
    }

    function updateMatchUI(data) {
        // Update match status badges
        var statusEl = document.querySelector('[data-match-status]');
        if (statusEl) {
            statusEl.textContent = data.status;
            statusEl.className = 'badge ' + getStatusClass(data.status);
        }
        // Update board if needed
        var boardEl = document.querySelector('[data-match-board]');
        if (boardEl && data.board_state) {
            // Board update handled by frontend game logic
        }
    }

    function getStatusClass(status) {
        var map = {
            'pending': 'b-grey', 'scheduled': 'b-gold', 'ready': 'b-gold',
            'in_progress': 'b-pink', 'completed': 'b-teal', 'walkover': 'b-red',
            'disputed': 'b-red', 'cancelled': 'b-grey'
        };
        return map[status] || 'b-grey';
    }

    function fmtTime(iso) {
        var s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
        if (s < 60) return 'just now';
        if (s < 3600) return Math.floor(s / 60) + ' min ago';
        if (s < 86400) return Math.floor(s / 3600) + ' h ago';
        return Math.floor(s / 86400) + ' d ago';
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    /* Initialize on page load */
    document.addEventListener('DOMContentLoaded', function() {
        // Start notification polling
        pollAdminNotifications();
        setInterval(pollAdminNotifications, NOTIF_POLL_INTERVAL);

        // Start auto-refresh for report detail pages
        var reportId = document.body.dataset.reportId;
        if (reportId) { startAutoRefresh('report', reportId); }

        // Start auto-refresh for match pages
        var matchId = document.body.dataset.matchId;
        if (matchId) { startAutoRefresh('match', matchId); }

        // Cleanup on page unload
        window.addEventListener('beforeunload', function() {
            Object.keys(refreshTimers).forEach(function(key) { clearInterval(refreshTimers[key]); });
        });

        // Visibility change - refresh when tab becomes visible
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) {
                pollAdminNotifications();
                if (reportId) refreshReportMessages(reportId);
                if (matchId) refreshMatchState(matchId);
            }
        });
    });

    /* Utility: mark messages as read when admin views them */
    var reportMsgContainer = document.querySelector('.msg-thread');
    if (reportMsgContainer) {
        // Mark admin messages as read when scrolled into view
        var observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    var msgEl = entry.target;
                    var msgId = msgEl.dataset.msgId;
                    if (msgId && msgEl.classList.contains('admin') && !msgEl.dataset.readMarked) {
                        msgEl.dataset.readMarked = 'true';
                        fetch('/api/admin/report-message/read/', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ id: msgId })
                        }).catch(function() {});
                    }
                }
            });
        }, { threshold: 0.5 });

        reportMsgContainer.querySelectorAll('.msg.admin').forEach(function(el) { observer.observe(el); });
    }
})();