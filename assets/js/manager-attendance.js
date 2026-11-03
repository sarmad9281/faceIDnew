(function () {
    'use strict';
    var card = document.getElementById('inside-card');
    if (!card || !window.fetch) return;
    var list = document.getElementById('inside-list');
    var chip = document.getElementById('inside-chip');
    var kpi = document.getElementById('kpi-inside');
    var message = document.getElementById('inside-update-status');
    var button = document.getElementById('inside-refresh');
    var busy = false;
    function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function(c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
    function duration(n) { n = Math.max(0, parseInt(n, 10) || 0); return Math.floor(n / 60).toLocaleString('fa-IR') + ' ساعت و ' + (n % 60).toLocaleString('fa-IR') + ' دقیقه'; }
    function refresh() {
        if (busy) return;
        busy = true; if (button) button.disabled = true;
        var url = card.dataset.liveUrl + '?branch_id=' + encodeURIComponent(card.dataset.branch || '0');
        fetch(url, {headers: {'Accept': 'application/json'}, cache: 'no-store', credentials: 'same-origin'})
            .then(function(r) { if (!r.ok) throw Error('HTTP ' + r.status); return r.json(); })
            .then(function(payload) {
                if (!payload.success || !payload.data || !Array.isArray(payload.data.inside)) throw Error('invalid response');
                var rows = payload.data.inside, count = payload.meta.count;
                if (chip) chip.textContent = 'الان در مجموعه: ' + count + ' نفر';
                if (kpi) kpi.textContent = count;
                if (message) message.textContent = (payload.meta.truncated ? 'نمایش ۵۰۰ مورد نخست — ' : '') + 'آخرین به‌روزرسانی: ' + payload.meta.server_time;
                list.innerHTML = rows.length ? rows.map(function(row) {
                    return '<div class="list-row"><div><strong>' + esc(row.name) + '</strong><small class="ltr">' + esc(row.employee_code) + '</small><small>' + esc(row.branch_name) + '</small></div>'
                        + '<div class="muted small">ورود: <span class="ltr num">' + esc(row.entry_time) + '</span> — مدت حضور: <span class="num">' + esc(duration(row.inside_minutes)) + '</span></div><div class="row-actions">'
                        + (row.is_offline ? '<span class="badge badge-warn">آفلاین</span>' : '')
                        + (row.late_minutes > 0 ? '<span class="badge badge-warn">تأخیر ' + esc(row.late_minutes) + '′</span>' : '')
                        + '<span class="badge">' + esc(row.status_label) + '</span></div></div>';
                }).join('') : '<div class="empty"><div class="empty-art">🕒</div><strong>در این لحظه کسی داخل مجموعه نیست</strong></div>';
            }).catch(function() { if (message) message.textContent = 'به‌روزرسانی ناموفق بود؛ اطلاعات قبلی نمایش داده می‌شود.'; })
            .finally(function() { busy = false; if (button) button.disabled = false; });
    }
    if (button) button.addEventListener('click', refresh);
    window.setInterval(refresh, 30000);
}());
