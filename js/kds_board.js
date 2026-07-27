(function () {
    'use strict';

    var screen = document.getElementById('kdsScreen');
    if (!screen) {
        return;
    }

    var STATION = screen.getAttribute('data-station');
    var CSRF = screen.getAttribute('data-csrf');
    var CAN_COMPLETE = screen.getAttribute('data-can-complete') === '1';
    var WARN_AFTER = parseInt(screen.getAttribute('data-warn'), 10) || 360;
    var LATE_AFTER = parseInt(screen.getAttribute('data-late'), 10) || 720;
    var POLL_INTERVAL = 2500;

    var grid = document.getElementById('kdsGrid');
    var emptyState = document.getElementById('kdsEmpty');
    var connectionBanner = document.getElementById('kdsConnection');
    var activeCountEl = document.getElementById('kdsActiveCount');
    var clockEl = document.getElementById('kdsClock');

    var tickets = {};          // id -> ticket
    var kitchenEvents = {};    // id -> unacknowledged append-only event
    var historyTickets = {};   // id -> ticket (history drawer)
    var cursor = 0;
    var soundEnabled = true;
    var failureStreak = 0;
    var polling = false;
    var audioCtx = null;
    var actionError = null;

    function clearActionError() {
        if (actionError) {
            actionError.remove();
            actionError = null;
        }
    }

    function showActionError(message) {
        clearActionError();
        actionError = document.createElement('div');
        actionError.className = 'kds-action-error';
        actionError.setAttribute('role', 'alert');
        actionError.textContent = 'تعذر تنفيذ إجراء المطبخ. لم يتم تأكيد الحدث وسيظل ظاهراً. ' + (message || 'حاول مرة أخرى.');
        screen.insertBefore(actionError, screen.firstChild);
    }

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    function ensureAudio() {
        if (audioCtx) {
            return;
        }
        try {
            var Ctx = window.AudioContext || window.webkitAudioContext;
            if (Ctx) {
                audioCtx = new Ctx();
            }
        } catch (e) {
            audioCtx = null;
        }
    }

    function chime() {
        if (!soundEnabled) {
            return;
        }
        ensureAudio();
        if (!audioCtx) {
            return;
        }
        try {
            var now = audioCtx.currentTime;
            [880, 1320].forEach(function (freq, index) {
                var osc = audioCtx.createOscillator();
                var gain = audioCtx.createGain();
                osc.type = 'sine';
                osc.frequency.value = freq;
                var start = now + index * 0.16;
                gain.gain.setValueAtTime(0.0001, start);
                gain.gain.exponentialRampToValueAtTime(0.25, start + 0.03);
                gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.32);
                osc.connect(gain);
                gain.connect(audioCtx.destination);
                osc.start(start);
                osc.stop(start + 0.34);
            });
        } catch (e) { /* ignore */ }
    }

    function slaClass(seconds) {
        if (seconds >= LATE_AFTER) {
            return 'is-late';
        }
        if (seconds >= WARN_AFTER) {
            return 'is-warn';
        }
        return 'is-fresh';
    }

    function formatElapsed(seconds) {
        seconds = Math.max(0, seconds | 0);
        var m = Math.floor(seconds / 60);
        var s = seconds % 60;
        return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
    }

    function baseElapsed(ticket) {
        // Anchor elapsed time to client clock using the server-provided offset.
        return (ticket.seconds_elapsed || 0) + Math.floor((Date.now() - ticket._receivedAt) / 1000);
    }

    function formatWhen(value) {
        if (!value) {
            return '';
        }
        var normalized = String(value).replace(' ', 'T');
        var date = new Date(normalized);
        if (isNaN(date.getTime())) {
            return String(value);
        }
        return date.toLocaleString('ar-EG', {
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function historyPreviewHtml(lines, maxLines) {
        var list = Array.isArray(lines) ? lines : [];
        if (!list.length) {
            return '<div class="kds-history-item__more">لا توجد أصناف</div>';
        }
        var shown = list.slice(0, maxLines);
        var html = '<ul class="kds-history-item__preview">' + shown.map(function (line) {
            var qty = Number(line.qty || 0);
            var qtyLabel = (qty % 1 === 0) ? qty.toFixed(0) : qty.toFixed(3);
            return '<li>' + escapeHtml(qtyLabel) + '× ' + escapeHtml(line.name || '') + '</li>';
        }).join('') + '</ul>';
        if (list.length > maxLines) {
            html += '<div class="kds-history-item__more">+' + (list.length - maxLines) + ' صنف آخر · اضغط للتفاصيل</div>';
        }
        return html;
    }

    function ticketDetailHtml(ticket) {
        var location = ticket.table_name
            ? '<i class="fas fa-chair"></i> ' + escapeHtml(ticket.table_name)
            : '<i class="fas fa-bag-shopping"></i> ' + escapeHtml(ticket.order_type || 'سفري');
        var statusLabel = ticket.status === 'completed' ? 'مكتمل' : (ticket.status === 'cancelled' ? 'ملغي' : ticket.status);
        return '<div class="kds-modal__meta">' +
            '<span>' + location + '</span>' +
            '<span>' + escapeHtml(statusLabel) + '</span>' +
            (ticket.completed_at ? '<span><i class="fas fa-clock"></i> ' + escapeHtml(formatWhen(ticket.completed_at)) + '</span>' : '') +
            '</div>' +
            '<ul class="kds-modal__lines">' + (ticket.lines || []).map(lineHtml).join('') + '</ul>';
    }

    function ticketHasNewLines(ticket) {
        return (ticket.lines || []).some(function (line) {
            return line.line_status === 'new';
        });
    }

    function startButtonLabel(ticket) {
        if (ticket.is_supplement) {
            return 'بدء الإضافة';
        }
        if (ticket.status === 'in_progress' && ticketHasNewLines(ticket)) {
            return 'بدء التعديل';
        }
        return 'بدء';
    }

    function shouldShowStartButton(ticket) {
        if (ticket.status === 'new') {
            return true;
        }
        return ticket.status === 'in_progress' && ticketHasNewLines(ticket);
    }

    function lineHtml(line) {
        var qty = Number(line.qty || 0);
        var qtyLabel = (qty % 1 === 0) ? qty.toFixed(0) : qty.toFixed(3);
        var mods = '';
        if (line.modifiers && line.modifiers.length) {
            mods = '<div class="kds-line__mods">' + line.modifiers.map(function (m) {
                var label = typeof m === 'string' ? m : (m.name_ar || m.name_en || m.name || m.option_name || m.label || '');
                var modifierQty = typeof m === 'object' ? Number(m.qty || 1) : 1;
                var qtySuffix = modifierQty > 1 ? ' ×' + ((modifierQty % 1 === 0) ? modifierQty.toFixed(0) : modifierQty.toFixed(3)) : '';
                return escapeHtml(label + qtySuffix);
            }).filter(Boolean).join('، ') + '</div>';
        }
        var notes = line.notes ? '<div class="kds-line__notes"><i class="fas fa-pen"></i> ' + escapeHtml(line.notes) + '</div>' : '';
        var preparation = '';
        if (line.preparation_values && line.preparation_values.length) {
            preparation = '<div class="kds-line__preparation"><i class="fas fa-mug-hot"></i> ' +
                line.preparation_values.map(function (value) {
                    var selected = Number(value.value || 0);
                    var label = value.label_ar || value.code || '';
                    return selected === 0 ? 'بدون سكر' : label + ': ' + selected + ' ملعقة';
                }).map(escapeHtml).join('، ') +
                '</div>';
        }

        var status = line.line_status || 'new';
        var cls = 'kds-line line-' + status;
        if (status === 'new') {
            cls += ' line-is-new';
        }
        if (line.is_changed && status === 'new') {
            cls += ' is-changed';
        }

        var tag = '';
        if (status === 'voided') {
            tag = '<span class="kds-line__tag kds-line__tag--void">محذوف</span>';
        } else if (status === 'done') {
            tag = '<span class="kds-line__tag kds-line__tag--done"><i class="fas fa-check"></i></span>';
        } else if (line.is_changed && status === 'new') {
            tag = '<span class="kds-line__tag kds-line__tag--new">جديد</span>';
        }

        return '<li class="' + cls + '">' +
            '<span class="kds-line__qty">' + escapeHtml(qtyLabel) + '×</span>' +
            '<span class="kds-line__name">' + escapeHtml(line.name) + mods + notes + preparation + '</span>' +
            tag +
            '</li>';
    }

    function ticketSignature(ticket) {
        return [
            ticket.status,
            ticket.is_supplement ? '1' : '0',
            ticket.table_name || '',
            ticket.order_type || '',
            ticket.order_label || ticket.order_id || '',
            JSON.stringify(ticket.lines || [])
        ].join('|');
    }

    function mountCard(ticket) {
        var wrapper = document.createElement('div');
        wrapper.innerHTML = ticketCardHtml(ticket);
        var card = wrapper.firstElementChild;
        card.setAttribute('data-sig', ticketSignature(ticket));
        return card;
    }

    function ticketCardHtml(ticket) {
        var elapsed = baseElapsed(ticket);
        var sla = slaClass(elapsed);
        var lines = (ticket.lines || []).map(lineHtml).join('');
        var statusLabel = ticket.is_supplement
            ? 'إضافة'
            : ({
                'new': 'جديد',
                'in_progress': 'تحت التحضير'
            }[ticket.status] || ticket.status);

        var location = ticket.table_name
            ? '<i class="fas fa-chair"></i> ' + escapeHtml(ticket.table_name)
            : '<i class="fas fa-bag-shopping"></i> ' + escapeHtml(ticket.order_type || 'سفري');

        var actions = '';
        if (CAN_COMPLETE) {
            actions += '<div class="kds-card__actions">';
            if (shouldShowStartButton(ticket)) {
                actions += '<button class="kds-act kds-act--start" data-action="start" data-id="' + ticket.id + '"><i class="fas fa-play"></i> ' + escapeHtml(startButtonLabel(ticket)) + '</button>';
            }
            actions += '<button class="kds-act kds-act--done" data-action="complete" data-id="' + ticket.id + '"><i class="fas fa-check"></i> إنهاء</button>';
            actions += '</div>';
        }

        var cardClass = 'kds-card status-' + escapeHtml(ticket.status) + ' ' + sla;
        if (ticket.is_supplement) {
            cardClass += ' kds-card--supplement';
        }

        return '<article class="' + cardClass + '" data-id="' + ticket.id + '" data-placed="' + ticket.seconds_elapsed + '" data-received="' + ticket._receivedAt + '">' +
            '<div class="kds-card__head">' +
            '<span class="kds-card__order">#' + escapeHtml(ticket.order_label || ticket.order_id) + (ticket.is_supplement ? ' <span class="kds-card__badge">إضافة</span>' : '') + '</span>' +
            '<span class="kds-card__timer">' + formatElapsed(elapsed) + '</span>' +
            '</div>' +
            '<div class="kds-card__meta"><span class="kds-card__loc">' + location + '</span>' +
            '<span class="kds-card__status">' + escapeHtml(statusLabel) + '</span></div>' +
            '<ul class="kds-card__lines">' + lines + '</ul>' +
            actions +
            '</article>';
    }

    function eventLabel(event) {
        if (event.event_type === 'order_cancel') {
            return 'إلغاء طلب';
        }
        if (event.event_type === 'line_cancel') {
            return 'إلغاء صنف';
        }
        return 'تعديل طلب';
    }

    function eventCardHtml(event) {
        var before = Array.isArray(event.before) ? event.before : [];
        var after = Array.isArray(event.after) ? event.after : [];
        var reason = event.reason
            ? '<div class="kds-event__reason"><i class="fas fa-comment"></i> السبب: ' + escapeHtml(event.reason) + '</div>'
            : '';
        var beforeHtml = before.length
            ? '<div class="kds-event__section"><strong>قبل</strong><ul class="kds-card__lines">' + before.map(lineHtml).join('') + '</ul></div>'
            : '';
        var afterHtml = after.length
            ? '<div class="kds-event__section"><strong>بعد</strong><ul class="kds-card__lines">' + after.map(lineHtml).join('') + '</ul></div>'
            : '<div class="kds-event__cancelled">تم طلب الإلغاء بالكامل</div>';
        var action = CAN_COMPLETE
            ? '<div class="kds-card__actions"><button class="kds-act kds-act--ack" data-action="acknowledge_event" data-event-id="' +
                event.id + '" data-event-version="' + event.version + '"><i class="fas fa-check-double"></i> تأكيد الاستلام</button></div>'
            : '';

        return '<article class="kds-card kds-event-card kds-event--' + escapeHtml(event.event_type) +
            '" data-id="event-' + event.id + '" data-event-id="' + event.id + '">' +
            '<div class="kds-card__head"><span class="kds-card__order">#' + escapeHtml(event.order_id) +
            ' <span class="kds-card__badge">' + escapeHtml(eventLabel(event)) + '</span></span>' +
            '<span class="kds-card__status">بانتظار التأكيد</span></div>' +
            reason + beforeHtml + afterHtml + action + '</article>';
    }

    function sortedTickets() {
        return Object.keys(tickets).map(function (id) {
            return tickets[id];
        }).sort(function (a, b) {
            return (a.placed_ts || 0) - (b.placed_ts || 0) || a.id - b.id;
        });
    }

    function updateCardInPlace(card, ticket) {
        var elapsed = baseElapsed(ticket);
        var sla = slaClass(elapsed);
        var busy = card.classList.contains('is-busy');

        card.className = 'kds-card status-' + ticket.status + ' ' + sla;
        if (ticket.is_supplement) {
            card.classList.add('kds-card--supplement');
        }
        if (busy) {
            card.classList.add('is-busy');
        }
        card.setAttribute('data-placed', ticket.seconds_elapsed);
        card.setAttribute('data-received', ticket._receivedAt);
        card.setAttribute('data-sig', ticketSignature(ticket));

        var statusLabel = ticket.is_supplement
            ? 'إضافة'
            : ({
                'new': 'جديد',
                'in_progress': 'تحت التحضير'
            }[ticket.status] || ticket.status);

        var timer = card.querySelector('.kds-card__timer');
        if (timer) {
            timer.textContent = formatElapsed(elapsed);
        }
        var statusEl = card.querySelector('.kds-card__status');
        if (statusEl) {
            statusEl.textContent = statusLabel;
        }
        var orderEl = card.querySelector('.kds-card__order');
        if (orderEl) {
            orderEl.innerHTML = '#' + escapeHtml(ticket.order_label || ticket.order_id)
                + (ticket.is_supplement ? ' <span class="kds-card__badge">إضافة</span>' : '');
        }

        var linesEl = card.querySelector('.kds-card__lines');
        if (linesEl) {
            linesEl.innerHTML = (ticket.lines || []).map(lineHtml).join('');
        }

        if (!CAN_COMPLETE) {
            return;
        }

        var actionsHtml = '';
        if (shouldShowStartButton(ticket)) {
            actionsHtml += '<button class="kds-act kds-act--start" data-action="start" data-id="' + ticket.id + '"><i class="fas fa-play"></i> ' + escapeHtml(startButtonLabel(ticket)) + '</button>';
        }
        actionsHtml += '<button class="kds-act kds-act--done" data-action="complete" data-id="' + ticket.id + '"><i class="fas fa-check"></i> إنهاء</button>';

        var actionsEl = card.querySelector('.kds-card__actions');
        if (actionsEl) {
            actionsEl.innerHTML = actionsHtml;
        } else {
            var actionsWrap = document.createElement('div');
            actionsWrap.className = 'kds-card__actions';
            actionsWrap.innerHTML = actionsHtml;
            card.appendChild(actionsWrap);
        }
    }

    function placeCards(orderedCards) {
        orderedCards.forEach(function (card, index) {
            if (grid.children[index] !== card) {
                grid.insertBefore(card, grid.children[index] || null);
            }
        });
        while (grid.children.length > orderedCards.length) {
            grid.removeChild(grid.lastChild);
        }
    }

    function render() {
        var list = sortedTickets();
        var eventList = Object.keys(kitchenEvents).map(function (id) {
            return kitchenEvents[id];
        }).sort(function (a, b) {
            return a.id - b.id;
        });
        if (!list.length && !eventList.length) {
            grid.innerHTML = '';
            emptyState.hidden = false;
            activeCountEl.textContent = '0';
            return;
        }

        emptyState.hidden = true;
        activeCountEl.textContent = list.length + eventList.length;

        var existing = new Map();
        grid.querySelectorAll('.kds-card').forEach(function (card) {
            existing.set(card.getAttribute('data-id'), card);
        });

        var orderedCards = [];
        eventList.forEach(function (event) {
            var key = 'event-' + event.id;
            var card = existing.get(key);
            if (card) {
                existing.delete(key);
                orderedCards.push(card);
                return;
            }
            var wrapper = document.createElement('div');
            wrapper.innerHTML = eventCardHtml(event);
            orderedCards.push(wrapper.firstElementChild);
        });
        list.forEach(function (ticket) {
            var id = String(ticket.id);
            var sig = ticketSignature(ticket);
            var card = existing.get(id);
            if (card) {
                existing.delete(id);
                if (card.getAttribute('data-sig') !== sig) {
                    updateCardInPlace(card, ticket);
                }
                orderedCards.push(card);
                return;
            }

            orderedCards.push(mountCard(ticket));
        });

        existing.forEach(function (card) {
            card.remove();
        });
        placeCards(orderedCards);
        grid.querySelectorAll('.kds-card.is-busy').forEach(function (card) {
            card.classList.remove('is-busy');
        });
    }

    function applyFeed(data) {
        var hadNew = false;
        if (data.full) {
            tickets = {};
        }
        kitchenEvents = {};
        (data.events || []).forEach(function (event) {
            kitchenEvents[event.id] = event;
        });
        var changes = data.changes || [];
        if (!changes.length && !data.full) {
            if (typeof data.cursor === 'number') {
                cursor = data.cursor;
            }
            render();
            return;
        }
        changes.forEach(function (change) {
            if (change.type === 'removed') {
                delete tickets[change.ticket_id];
                return;
            }
            var ticket = change.ticket;
            if (!ticket) {
                return;
            }
            ticket._receivedAt = Date.now();
            if (!data.full && !tickets[ticket.id] && ticket.status === 'new') {
                hadNew = true;
            }
            tickets[ticket.id] = ticket;
        });

        if (typeof data.cursor === 'number') {
            cursor = data.cursor;
        }
        render();
        if (hadNew) {
            chime();
            flashScreen();
        }
    }

    function flashScreen() {
        screen.classList.add('kds-flash');
        setTimeout(function () {
            screen.classList.remove('kds-flash');
        }, 700);
    }

    function setConnected(connected) {
        connectionBanner.hidden = connected;
    }

    function poll() {
        if (polling) {
            return;
        }
        polling = true;
        var url = 'ajax/kds_tickets_list.php?station=' + encodeURIComponent(STATION) + '&since=' + cursor;
        fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (res) {
                if (!res.ok) {
                    throw new Error('HTTP ' + res.status);
                }
                return res.json();
            })
            .then(function (data) {
                if (!data || !data.success) {
                    throw new Error((data && data.message) || 'bad response');
                }
                failureStreak = 0;
                setConnected(true);
                applyFeed(data);
            })
            .catch(function () {
                failureStreak++;
                if (failureStreak >= 2) {
                    setConnected(false);
                }
            })
            .then(function () {
                polling = false;
            });
    }

    function sendAction(ticketId, action) {
        var body = new URLSearchParams();
        body.set('ticket_id', ticketId);
        body.set('action', action);
        body.set('csrf_token', CSRF);
        return fetch('do/kds_ticket_action.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': CSRF
            },
            credentials: 'same-origin',
            body: body.toString()
        }).then(function (res) {
            return res.json();
        });
    }

    function acknowledgeEvent(eventId, eventVersion) {
        var body = new URLSearchParams();
        body.set('action', 'acknowledge_event');
        body.set('event_id', eventId);
        body.set('event_version', eventVersion);
        body.set('csrf_token', CSRF);
        return fetch('do/kds_ticket_action.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': CSRF
            },
            credentials: 'same-origin',
            body: body.toString()
        }).then(function (res) {
            return res.json().then(function (data) {
                if (!res.ok) {
                    throw new Error((data && data.message) || ('HTTP ' + res.status));
                }
                return data;
            });
        });
    }

    grid.addEventListener('click', function (event) {
        var btn = event.target.closest('.kds-act');
        if (!btn) {
            return;
        }
        ensureAudio();
        var ticketId = btn.getAttribute('data-id');
        var action = btn.getAttribute('data-action');
        var card = btn.closest('.kds-card');
        if (card) {
            card.classList.add('is-busy');
        }
        var request = action === 'acknowledge_event'
            ? acknowledgeEvent(btn.getAttribute('data-event-id'), btn.getAttribute('data-event-version'))
            : sendAction(ticketId, action);
        request
            .then(function (response) {
                if (!response || !response.success) {
                    throw new Error((response && response.message) || 'action failed');
                }
                clearActionError();
                poll();
            })
            .catch(function (error) {
                if (card) {
                    card.classList.remove('is-busy');
                }
                showActionError(error && error.message ? error.message : '');
            });
    });

    // Local SLA timer tick (no network).
    function tickTimers() {
        var cards = grid.querySelectorAll('.kds-card');
        cards.forEach(function (card) {
            var ticket = tickets[card.getAttribute('data-id')];
            if (!ticket) {
                return;
            }
            var elapsed = baseElapsed(ticket);
            var timer = card.querySelector('.kds-card__timer');
            if (timer) {
                timer.textContent = formatElapsed(elapsed);
            }
            card.classList.remove('is-fresh', 'is-warn', 'is-late');
            card.classList.add(slaClass(elapsed));
        });
    }

    function updateClock() {
        var now = new Date();
        var h = now.getHours();
        var m = now.getMinutes();
        clockEl.textContent = (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m;
    }

    // History drawer + detail modal
    var drawer = document.getElementById('kdsDrawer');
    var drawerBackdrop = document.getElementById('kdsDrawerBackdrop');
    var historyList = document.getElementById('kdsHistoryList');
    var detailModal = document.getElementById('kdsDetailModal');
    var detailBody = document.getElementById('kdsDetailBody');
    var detailFoot = document.getElementById('kdsDetailFoot');
    var detailTitle = document.getElementById('kdsDetailTitle');

    function openDetailModal(ticket) {
        if (!ticket || !detailModal) {
            return;
        }
        detailTitle.textContent = '#' + (ticket.order_label || ticket.order_id);
        detailBody.innerHTML = ticketDetailHtml(ticket);
        var recallHtml = '';
        if (CAN_COMPLETE && ticket.status === 'completed') {
            recallHtml = '<button type="button" class="kds-act kds-act--recall" data-action="recall" data-id="' + ticket.id + '"><i class="fas fa-undo"></i> استرجاع للمطبخ</button>';
        }
        detailFoot.innerHTML = recallHtml;
        detailModal.hidden = false;
    }

    function closeDetailModal() {
        if (detailModal) {
            detailModal.hidden = true;
        }
    }

    function openHistory() {
        drawer.hidden = false;
        drawerBackdrop.hidden = false;
        historyList.innerHTML = '<div class="kds-history-loading">جارٍ التحميل…</div>';
        fetch('ajax/kds_history.php?station=' + encodeURIComponent(STATION) + '&limit=50', {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    throw new Error('history failed');
                }
                if (!data.tickets.length) {
                    historyList.innerHTML = '<div class="kds-history-loading">لا يوجد سجل بعد</div>';
                    return;
                }
                historyTickets = {};
                historyList.innerHTML = data.tickets.map(function (t) {
                    historyTickets[t.id] = t;
                    var when = formatWhen(t.completed_at);
                    return '<button type="button" class="kds-history-card" data-ticket-id="' + t.id + '">' +
                        '<div class="kds-history-item__head"><strong>#' + escapeHtml(t.order_label || t.order_id) + '</strong>' +
                        '<span class="kds-history-item__status status-' + escapeHtml(t.status) + '">' + (t.status === 'completed' ? 'مكتمل' : 'ملغي') + '</span></div>' +
                        '<div class="kds-history-item__meta">' + escapeHtml(t.table_name || t.order_type || '') +
                        (when ? ' · ' + escapeHtml(when) : '') + '</div>' +
                        historyPreviewHtml(t.lines, 3) +
                        '</button>';
                }).join('');
            })
            .catch(function () {
                historyList.innerHTML = '<div class="kds-history-loading">تعذر تحميل السجل</div>';
            });
    }

    function closeHistory() {
        drawer.hidden = true;
        drawerBackdrop.hidden = true;
    }

    historyList.addEventListener('click', function (event) {
        var recallBtn = event.target.closest('.kds-act--recall');
        if (recallBtn) {
            event.stopPropagation();
            sendAction(recallBtn.getAttribute('data-id'), 'recall').then(function () {
                closeDetailModal();
                closeHistory();
                poll();
            });
            return;
        }

        var card = event.target.closest('.kds-history-card');
        if (!card) {
            return;
        }
        var ticketId = card.getAttribute('data-ticket-id');
        var ticket = historyTickets[ticketId];
        if (ticket) {
            openDetailModal(ticket);
        }
    });

    document.getElementById('kdsHistoryBtn').addEventListener('click', openHistory);
    document.getElementById('kdsDrawerClose').addEventListener('click', closeHistory);
    drawerBackdrop.addEventListener('click', closeHistory);
    document.getElementById('kdsDetailClose').addEventListener('click', closeDetailModal);
    document.getElementById('kdsDetailBackdrop').addEventListener('click', closeDetailModal);
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            if (detailModal && !detailModal.hidden) {
                closeDetailModal();
            } else if (drawer && !drawer.hidden) {
                closeHistory();
            }
        }
    });
    detailFoot.addEventListener('click', function (event) {
        var recallBtn = event.target.closest('.kds-act--recall');
        if (!recallBtn) {
            return;
        }
        sendAction(recallBtn.getAttribute('data-id'), 'recall').then(function () {
            closeDetailModal();
            closeHistory();
            poll();
        });
    });

    document.getElementById('kdsSoundToggle').addEventListener('click', function () {
        soundEnabled = !soundEnabled;
        ensureAudio();
        this.innerHTML = soundEnabled
            ? '<i class="fas fa-volume-up"></i>'
            : '<i class="fas fa-volume-mute"></i>';
        this.classList.toggle('is-off', !soundEnabled);
        this.setAttribute('aria-pressed', soundEnabled ? 'true' : 'false');
    });

    document.getElementById('kdsFullscreenBtn').addEventListener('click', function () {
        if (!document.fullscreenElement) {
            (document.documentElement.requestFullscreen || function () {}).call(document.documentElement);
        } else {
            (document.exitFullscreen || function () {}).call(document);
        }
    });

    // Boot
    updateClock();
    poll();
    setInterval(poll, POLL_INTERVAL);
    setInterval(tickTimers, 1000);
    setInterval(updateClock, 15000);
})();
