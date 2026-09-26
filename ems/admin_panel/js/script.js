/**
 * EMS - Enterprise Management System
 * Dashboard Scripts
 */

$(document).ready(function () {
    initDataTables();
    initSidebar();
    initNotifications();
    initSearch();
    initTooltips();
    initCounterAnimation();
});

/* ============================================================
   DataTables
   ============================================================ */
function initDataTables() {
    if ($.fn.DataTable) {
        $.fn.dataTable.ext.errMode = 'none';
        $(".datatable").each(function () {
            if (!$.fn.DataTable.isDataTable(this)) {
                $(this).DataTable({
                    pageLength: 25,
                    language: {
                        search: "Search:",
                        searchPlaceholder: "Type to search...",
                        lengthMenu: "Show _MENU_ entries",
                        info: "Showing _START_ to _END_ of _TOTAL_ entries",
                        infoEmpty: "No entries found",
                        infoFiltered: "(filtered from _MAX_ total entries)",
                        paginate: {
                            first: "<i class='fas fa-angle-double-left'></i>",
                            previous: "<i class='fas fa-angle-left'></i>",
                            next: "<i class='fas fa-angle-right'></i>",
                            last: "<i class='fas fa-angle-double-right'></i>"
                        }
                    },
                    dom: "<'d-flex justify-content-between align-items-center mb-3'<'d-flex gap-2'B><'d-flex'f>>" +
                        "<'table-responsive'tr>" +
                        "<'d-flex justify-content-between align-items-center mt-3'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
                    buttons: [
                        {
                            text: '<i class="fas fa-file-excel me-1"></i>Excel',
                            className: 'export-btn',
                            action: function () { exportTable('excel', this); }
                        },
                        {
                            text: '<i class="fas fa-file-pdf me-1"></i>PDF',
                            className: 'export-btn',
                            action: function () { exportTable('pdf', this); }
                        }
                    ]
                });
            }
        });
    }
}

function exportTable(type, dtInstance) {
    var table = dtInstance ? dtInstance.table() : null;
    if (!table) return;
    var data = table.data().toArray();
    var headers = [];
    table.header().querySelectorAll("th").forEach(function (th) { headers.push(th.innerText.trim()); });

    if (type === "excel") {
        var csv = "\ufeff" + headers.join(",") + "\n";
        data.forEach(function (row) {
            var rowData = [];
            row.forEach(function (cell) {
                var val = String(cell).replace(/"/g, '""');
                rowData.push('"' + val + '"');
            });
            csv += rowData.join(",") + "\n";
        });
        var blob = new Blob([csv], { type: "text/csv;charset=utf-8;" });
        var link = document.createElement("a");
        link.href = URL.createObjectURL(blob);
        link.download = "export_" + new Date().toISOString().slice(0, 10) + ".csv";
        link.click();
    } else {
        var printWin = window.open("", "_blank");
        printWin.document.write("<html><head><title>Export</title><style>table{border-collapse:collapse;width:100%}th,td{border:1px solid #ddd;padding:8px;text-align:left}th{background:#f5f5f5}</style></head><body>");
        printWin.document.write("<h3>Report</h3>");
        printWin.document.write("<table><thead><tr>");
        headers.forEach(function (h) { printWin.document.write("<th>" + h + "</th>"); });
        printWin.document.write("</tr></thead><tbody>");
        data.forEach(function (row) {
            printWin.document.write("<tr>");
            row.forEach(function (cell) { printWin.document.write("<td>" + cell + "</td>"); });
            printWin.document.write("</tr>");
        });
        printWin.document.write("</tbody></table></body></html>");
        printWin.document.close();
        printWin.print();
    }
}

/* ============================================================
   Sidebar
   ============================================================ */
function initSidebar() {
    // Restore desktop collapsed state
    if ($(window).width() >= 1200) {
        var isCollapsed = localStorage.getItem('sidebar_collapsed') === 'true';
        if (isCollapsed) {
            $('body').addClass('sidebar-collapsed');
        }
    }

    $(document).on("click", function (e) {
        if ($(window).width() < 1200) {
            if (!$(e.target).closest(".sidebar").length && !$(e.target).closest(".sidebar-toggle").length) {
                $("#sidebar").removeClass("show");
                $("#sidebarOverlay").hide();
            }
        }
    });
}

function toggleSidebar() {
    if ($(window).width() < 1200) {
        $("#sidebar").toggleClass("show");
        $("#sidebarOverlay").toggle();
    } else {
        $('body').toggleClass('sidebar-collapsed');
        var isCollapsed = $('body').hasClass('sidebar-collapsed');
        localStorage.setItem('sidebar_collapsed', isCollapsed);
    }
}

/* ============================================================
   Notifications & Real-time Sound/Popup Alerts
   ============================================================ */
var lastNotifId = 0;
var isInitialNotifLoad = true;
var notifPollTimer = null;
var audioCtx = null;
var isNotifSoundEnabled = (localStorage.getItem('admin_notif_sound') !== 'false');

function getAudioContext() {
    if (!audioCtx) {
        var AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (AudioCtx) audioCtx = new AudioCtx();
    }
    if (audioCtx && audioCtx.state === 'suspended') {
        audioCtx.resume();
    }
    return audioCtx;
}

$(document).one('click keydown pointerdown', function () {
    getAudioContext();
});

function toggleNotificationSound(e) {
    if (e) e.stopPropagation();
    isNotifSoundEnabled = !isNotifSoundEnabled;
    localStorage.setItem('admin_notif_sound', isNotifSoundEnabled ? 'true' : 'false');
    updateSoundToggleUI();
    if (isNotifSoundEnabled) {
        playNotificationSound();
    }
}

function updateSoundToggleUI() {
    var icon = $("#notifSoundIcon");
    var btn = $("#notifSoundToggle");
    if (isNotifSoundEnabled) {
        icon.attr('class', 'fas fa-volume-up text-primary');
        btn.attr('title', 'Notification Sound: ON');
    } else {
        icon.attr('class', 'fas fa-volume-mute text-danger');
        btn.attr('title', 'Notification Sound: OFF');
    }
}

function playNotificationSound() {
    if (!isNotifSoundEnabled) return;
    try {
        var ctx = getAudioContext();
        if (!ctx) return;

        var playChime = function () {
            var now = ctx.currentTime;
            
            // First harmonic chime note (E5, 659.25Hz)
            var osc1 = ctx.createOscillator();
            var gain1 = ctx.createGain();
            osc1.type = 'sine';
            osc1.frequency.setValueAtTime(659.25, now);
            gain1.gain.setValueAtTime(0.18, now);
            gain1.gain.exponentialRampToValueAtTime(0.001, now + 0.18);
            osc1.connect(gain1);
            gain1.connect(ctx.destination);
            osc1.start(now);
            osc1.stop(now + 0.18);

            // Second harmonic chime note (B5, 987.77Hz)
            var osc2 = ctx.createOscillator();
            var gain2 = ctx.createGain();
            osc2.type = 'sine';
            osc2.frequency.setValueAtTime(987.77, now + 0.12);
            gain2.gain.setValueAtTime(0.22, now + 0.12);
            gain2.gain.exponentialRampToValueAtTime(0.001, now + 0.38);
            osc2.connect(gain2);
            gain2.connect(ctx.destination);
            osc2.start(now + 0.12);
            osc2.stop(now + 0.38);
        };

        if (ctx.state === 'suspended') {
            ctx.resume().then(playChime).catch(function () {});
        } else {
            playChime();
        }
    } catch (e) {
        console.log("Audio notification error:", e);
    }
}

function ensureToastContainer() {
    if ($("#adminToastContainer").length === 0) {
        $("body").append('<div id="adminToastContainer"></div>');
    }
}

function getNotificationMeta(n) {
    var type = (n.type || '').toLowerCase();
    var title = (n.title || '').toLowerCase();

    if (type === 'lead' || title.indexOf('lead') > -1) {
        return { icon: 'user-plus', color: '#2563eb', bg: '#eff6ff', label: 'View Lead' };
    } else if (type === 'leave' || title.indexOf('leave') > -1) {
        return { icon: 'calendar-minus', color: '#ea580c', bg: '#fff7ed', label: 'View Leave' };
    } else if (type === 'attendance' || title.indexOf('attendance') > -1 || title.indexOf('check-in') > -1 || title.indexOf('late') > -1) {
        return { icon: 'clock', color: '#059669', bg: '#f0fdf4', label: 'View Attendance' };
    } else if (type === 'task' || title.indexOf('task') > -1) {
        return { icon: 'check-double', color: '#7c3aed', bg: '#f5f3ff', label: 'View Task' };
    } else if (type === 'work' || type === 'work_report' || title.indexOf('work') > -1) {
        return { icon: 'file-alt', color: '#0284c7', bg: '#f0f9ff', label: 'View Report' };
    } else {
        return { icon: 'bell', color: '#64748b', bg: '#f8fafc', label: 'View' };
    }
}

function showNotificationToast(n) {
    ensureToastContainer();

    var meta = getNotificationMeta(n);
    var title = n.title || "New Notification";
    var message = n.message || "";
    var timeAgo = getTimeAgo(n.created_at) || "Just now";
    var toastId = "notifToast_" + (n.id || Date.now());
    var targetUrl = n.target_url || (window.location.origin + '/ems/admin_panel/modules/notifications');
    var actionLabel = n.action_label || meta.label || 'View Details';

    if ($("#" + toastId).length > 0) return;

    var toastHtml = '<div id="' + toastId + '" class="admin-notif-toast shadow-lg" data-id="' + (n.id || '') + '">' +
        '<div class="toast-icon" style="background:' + meta.bg + '; color:' + meta.color + '; border-radius: 50%; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 1rem;">' +
        '<i class="fas fa-' + meta.icon + '"></i>' +
        '</div>' +
        '<div class="toast-content" style="flex: 1; min-width: 0;">' +
        '<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 2px;">' +
        '<span style="font-weight: 700; font-size: 0.88rem; color: #1e293b; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">' + escapeHtml(title) + '</span>' +
        '<span style="font-size: 0.72rem; color: #94a3b8; margin-left: 8px;">' + escapeHtml(timeAgo) + '</span>' +
        '</div>' +
        (message ? '<div style="font-size: 0.8rem; color: #475569; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; line-height: 1.35; margin-bottom: 6px;">' + escapeHtml(message) + '</div>' : '') +
        '<div style="display: flex; align-items: center; justify-content: space-between;">' +
        (n.employee_name ? '<span class="text-muted" style="font-size:0.72rem;"><i class="fas fa-user me-1"></i>' + escapeHtml(n.employee_name) + '</span>' : '<span></span>') +
        '<a href="' + targetUrl + '" class="btn btn-xs btn-primary py-1 px-2 text-decoration-none" style="font-size:0.75rem; border-radius:4px;" onclick="markSingleRead(' + (n.id || 0) + ')">' + actionLabel + ' &rarr;</a>' +
        '</div>' +
        '</div>' +
        '<button type="button" style="background: none; border: none; color: #94a3b8; font-size: 1.2rem; line-height: 1; cursor: pointer; padding: 0 0 0 8px; margin-top: -2px;" onclick="closeAdminToast(\'' + toastId + '\')">&times;</button>' +
        '</div>';

    $("#adminToastContainer").append(toastHtml);

    setTimeout(function () {
        closeAdminToast(toastId);
    }, 7000);
}

function closeAdminToast(toastId) {
    var $t = $("#" + toastId);
    if ($t.length > 0) {
        $t.css({ opacity: "0", transform: "translateX(40px)" });
        setTimeout(function () {
            $t.remove();
        }, 300);
    }
}

function escapeHtml(text) {
    if (!text) return "";
    return String(text)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function initNotifications() {
    updateSoundToggleUI();
    fetchAdminNotifications();

    if (!notifPollTimer) {
        notifPollTimer = setInterval(fetchAdminNotifications, 10000);
    }
}

function fetchAdminNotifications() {
    var ajaxUrl = window.location.origin + "/ems/admin_panel/ajax/notifications.php";
    $.getJSON(ajaxUrl, { action: "poll", last_id: lastNotifId }, function (res) {
        if (!res || !res.success) return;

        var unreadCount = res.unread_count || 0;
        var notifBody = $("#notifBody");
        var notifDot = $("#notifDot");

        // Update Bell Badge
        if (unreadCount > 0) {
            notifDot.show().text(unreadCount > 99 ? '99+' : unreadCount);
        } else {
            notifDot.hide().text('0');
        }

        // Check for new notifications
        if (!isInitialNotifLoad && res.new_notifications && res.new_notifications.length > 0) {
            playNotificationSound();
            res.new_notifications.slice(0, 3).forEach(function (n) {
                showNotificationToast(n);
            });
        }

        if (res.max_id > lastNotifId) {
            lastNotifId = res.max_id;
        }

        isInitialNotifLoad = false;

        // Render Dropdown List
        var items = res.notifications || [];
        if (items.length === 0) {
            notifBody.html('<div class="text-center text-muted py-4" style="font-size:0.8rem;"><i class="fas fa-bell-slash me-1"></i> No notifications yet</div>');
            return;
        }

        var unreadItems = items.filter(function(n) { return n.is_read == 0; });
        var readItems = items.filter(function(n) { return n.is_read != 0; });

        var html = '';

        if (unreadItems.length > 0) {
            html += '<div class="notif-section-header text-primary"><i class="fas fa-circle me-1" style="font-size:0.5rem;"></i> New Notifications</div>';
            unreadItems.forEach(function(n) {
                html += renderNotifDropdownItem(n, true);
            });
        }

        if (readItems.length > 0) {
            if (unreadItems.length > 0) {
                html += '<div class="notif-section-header text-muted mt-2 border-top pt-2">Earlier</div>';
            }
            readItems.slice(0, 8).forEach(function(n) {
                html += renderNotifDropdownItem(n, false);
            });
        }

        notifBody.html(html);

    }).fail(function () {
        // Silent fail on network blip
    });
}

function renderNotifDropdownItem(n, isUnread) {
    var meta = getNotificationMeta(n);
    var timeAgo = getTimeAgo(n.created_at);
    var targetUrl = n.target_url || '#';

    return '<a href="' + targetUrl + '" class="notif-item ' + (isUnread ? 'unread' : '') + '" onclick="markSingleRead(' + (n.id || 0) + ')">' +
        '<div class="notif-icon" style="background:' + meta.bg + '; color:' + meta.color + ';"><i class="fas fa-' + meta.icon + '"></i></div>' +
        '<div class="notif-content">' +
        '<div class="title">' + escapeHtml(n.title || 'Notification') + (isUnread ? ' <span class="notif-new-badge">NEW</span>' : '') + '</div>' +
        '<div class="message">' + escapeHtml(n.message || '') + '</div>' +
        '<div class="time"><i class="far fa-clock me-1"></i>' + timeAgo + '</div>' +
        '</div>' +
        '</a>';
}

function markSingleRead(id) {
    if (!id) return;
    $.post(window.location.origin + "/ems/admin_panel/ajax/notifications.php", { action: "mark_read", id: id }, function () {
        fetchAdminNotifications();
    });
}

function markAllRead(e) {
    if (e) e.stopPropagation();
    $.post(window.location.origin + "/ems/admin_panel/ajax/notifications.php", { action: "mark_all_read" }, function () {
        $("#notifDot").hide().text('0');
        fetchAdminNotifications();
    });
}

function getTimeAgo(dateStr) {
    if (!dateStr) return "";
    var date = new Date(dateStr.replace(" ", "T"));
    var now = new Date();
    var diffMs = now - date;
    var mins = Math.floor(diffMs / 60000);
    if (mins < 1) return "Just now";
    if (mins < 60) return mins + "m ago";
    var hrs = Math.floor(mins / 60);
    if (hrs < 24) return hrs + "h ago";
    var days = Math.floor(hrs / 24);
    if (days < 7) return days + "d ago";
    return date.toLocaleDateString();
}

/* ============================================================
   Global Search
   ============================================================ */
function initSearch() {
    var menuItems = [];
    $(".sidebar .nav-link").each(function () {
        var text = $(this).text().trim();
        var href = $(this).attr("href");
        if (text && href) menuItems.push({ text: text, href: href });
    });

    var searchBox = $("#globalSearch");
    var searchDropdown = $("<div class='dropdown-menu' id='searchResults' style='width:100%;max-height:300px;overflow-y:auto;'></div>");
    searchBox.after(searchDropdown);

    searchBox.on("input", function () {
        var q = $(this).val().toLowerCase().trim();
        if (q.length < 1) { searchDropdown.hide(); return; }
        var results = menuItems.filter(function (item) { return item.text.toLowerCase().indexOf(q) > -1; });
        if (results.length > 0) {
            var html = "";
            results.forEach(function (r) {
                var idx = r.text.toLowerCase().indexOf(q);
                var highlighted = r.text.substring(0, idx) + "<strong>" + r.text.substring(idx, idx + q.length) + "</strong>" + r.text.substring(idx + q.length);
                html += '<a class="dropdown-item" href="' + r.href + '">' + highlighted + "</a>";
            });
            searchDropdown.html(html).show();
        } else {
            searchDropdown.html('<div class="dropdown-item text-muted">No results found</div>').show();
        }
    });

    $(document).on("click", function (e) {
        if (!$(e.target).closest(".header-search").length) searchDropdown.hide();
    });
}

/* ============================================================
   Tooltips
   ============================================================ */
function initTooltips() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (el) { return new bootstrap.Tooltip(el); });
}

/* ============================================================
   Counter Animation
   ============================================================ */
function initCounterAnimation() {
    $(".counter-animate").each(function () {
        var $this = $(this);
        var target = parseInt($this.text().replace(/,/g, "")) || 0;
        var duration = 1000;
        var step = Math.ceil(target / 30);
        var current = 0;
        var timer = setInterval(function () {
            current += step;
            if (current >= target) { current = target; clearInterval(timer); }
            $this.text(current.toLocaleString());
        }, duration / 30);
    });
}

/* ============================================================
   Charts
   ============================================================ */

/* Attendance Trend Line Chart */
function createAttendanceChart(canvasId, labels, presentData, lateData) {
    var ctx = document.getElementById(canvasId);
    if (!ctx) return;
    new Chart(ctx, {
        type: "line",
        data: {
            labels: labels,
            datasets: [
                {
                    label: "Present",
                    data: presentData,
                    borderColor: "#059669",
                    backgroundColor: "rgba(5,150,105,0.08)",
                    tension: 0.4,
                    fill: true,
                    pointRadius: 3,
                    pointHoverRadius: 6,
                    pointBackgroundColor: "#059669",
                    borderWidth: 2
                },
                {
                    label: "Late",
                    data: lateData,
                    borderColor: "#D97706",
                    backgroundColor: "rgba(217,119,6,0.08)",
                    tension: 0.4,
                    fill: true,
                    pointRadius: 3,
                    pointHoverRadius: 6,
                    pointBackgroundColor: "#D97706",
                    borderWidth: 2
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            aspectRatio: 2,
            interaction: { intersect: false, mode: "index" },
            plugins: {
                legend: { position: "top", labels: { usePointStyle: true, padding: 16, font: { size: 11 } } },
                tooltip: {
                    backgroundColor: "#1E293B",
                    titleFont: { size: 12 },
                    bodyFont: { size: 11 },
                    padding: 10,
                    cornerRadius: 8
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { stepSize: 1, font: { size: 10 } },
                    grid: { color: "rgba(0,0,0,0.04)" }
                },
                x: {
                    ticks: { font: { size: 9 }, maxTicksLimit: 15 },
                    grid: { display: false }
                }
            }
        }
    });
}

/* Present vs Absent Pie/Doughnut Chart */
function createAttendancePieChart(canvasId, present, absent, late) {
    var ctx = document.getElementById(canvasId);
    if (!ctx) return;
    new Chart(ctx, {
        type: "doughnut",
        data: {
            labels: ["Present", "Absent", "Late"],
            datasets: [{
                data: [present, absent, late],
                backgroundColor: ["#059669", "#DC2626", "#D97706"],
                borderWidth: 0,
                hoverOffset: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            cutout: "70%",
            plugins: {
                legend: {
                    position: "bottom",
                    labels: { usePointStyle: true, padding: 12, font: { size: 11 } }
                }
            }
        }
    });
}

/* Department Wise Bar Chart */
function createDepartmentChart(canvasId, labels, data) {
    var ctx = document.getElementById(canvasId);
    if (!ctx) return;
    var colors = ["#2563EB", "#059669", "#D97706", "#DC2626", "#7C3AED", "#DB2777", "#0284C7", "#EA580C", "#10B981"];
    new Chart(ctx, {
        type: "bar",
        data: {
            labels: labels,
            datasets: [{
                label: "Employees",
                data: data,
                backgroundColor: colors.slice(0, labels.length),
                borderRadius: 4,
                borderSkipped: false
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            indexAxis: "y",
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: "#1E293B",
                    padding: 10,
                    cornerRadius: 8
                }
            },
            scales: {
                x: { beginAtZero: true, ticks: { stepSize: 1, font: { size: 10 } }, grid: { color: "rgba(0,0,0,0.04)" } },
                y: { ticks: { font: { size: 10 } }, grid: { display: false } }
            }
        }
    });
}

/* Weekly Attendance Line Chart */
function createWeeklyChart(canvasId, labels, presentData, absentData, lateData) {
    var ctx = document.getElementById(canvasId);
    if (!ctx) return;
    new Chart(ctx, {
        type: "line",
        data: {
            labels: labels,
            datasets: [
                { label: "Present", data: presentData, borderColor: "#059669", backgroundColor: "rgba(5,150,105,0.1)", tension: 0.4, fill: true, pointRadius: 3, borderWidth: 2 },
                { label: "Absent", data: absentData, borderColor: "#DC2626", backgroundColor: "rgba(220,38,38,0.1)", tension: 0.4, fill: true, pointRadius: 3, borderWidth: 2 },
                { label: "Late", data: lateData, borderColor: "#D97706", backgroundColor: "rgba(217,119,6,0.1)", tension: 0.4, fill: true, pointRadius: 3, borderWidth: 2 }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            interaction: { intersect: false, mode: "index" },
            plugins: {
                legend: { position: "top", labels: { usePointStyle: true, padding: 12, font: { size: 10 } } },
                tooltip: { backgroundColor: "#1E293B", padding: 10, cornerRadius: 8 }
            },
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1, font: { size: 10 } }, grid: { color: "rgba(0,0,0,0.04)" } },
                x: { ticks: { font: { size: 10 } }, grid: { display: false } }
            }
        }
    });
}

/* ============================================================
   Calendar Widget
   ============================================================ */
function renderCalendar(containerId, events) {
    var container = document.getElementById(containerId);
    if (!container) return;
    var now = new Date();
    var year = now.getFullYear();
    var month = now.getMonth();
    var months = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
    var days = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
    var firstDay = new Date(year, month, 1).getDay();
    var daysInMonth = new Date(year, month + 1, 0).getDate();
    var today = now.getDate();

    var html = '<div class="calendar-header"><button onclick="prevMonth()"><i class="fas fa-chevron-left"></i></button>';
    html += '<h6>' + months[month] + " " + year + '</h6>';
    html += '<button onclick="nextMonth()"><i class="fas fa-chevron-right"></i></button></div>';
    html += '<table><thead><tr>';
    days.forEach(function (d) { html += "<th>" + d + "</th>"; });
    html += "</tr></thead><tbody><tr>";

    for (var i = 0; i < firstDay; i++) html += "<td class='other-month'></td>";

    for (var day = 1; day <= daysInMonth; day++) {
        var isToday = day === today ? "today" : "";
        var hasEvent = events && events.indexOf(day) > -1 ? "event" : "";
        html += "<td class='" + isToday + " " + hasEvent + "'>" + day + "</td>";
        if ((firstDay + day) % 7 === 0 && day < daysInMonth) html += "</tr><tr>";
    }

    var remaining = 7 - ((firstDay + daysInMonth) % 7);
    if (remaining < 7) { for (var i = 0; i < remaining; i++) html += "<td class='other-month'></td>"; }

    html += "</tr></tbody></table>";
    container.innerHTML = html;
}

var calMonth = new Date().getMonth();
var calYear = new Date().getFullYear();

function prevMonth() {
    calMonth--;
    if (calMonth < 0) { calMonth = 11; calYear--; }
    renderCalendar("calendarWidget", []);
}

function nextMonth() {
    calMonth++;
    if (calMonth > 11) { calMonth = 0; calYear++; }
    renderCalendar("calendarWidget", []);
}

/* ============================================================
   Chart.js Defaults
   ============================================================ */
Chart.defaults.font.family = "'Inter', 'Segoe UI', sans-serif";
Chart.defaults.color = "#64748b";
Chart.defaults.plugins.legend.labels.usePointStyle = true;

/* ============================================================
   Alert Auto-hide
   ============================================================ */
setTimeout(function () { $(".alert").fadeOut("slow"); }, 5000);

/* ============================================================
   Confirm Delete
   ============================================================ */
$(document).on("click", ".btn-delete", function (e) {
    if (!confirm("Are you sure you want to delete this record?")) e.preventDefault();
});
