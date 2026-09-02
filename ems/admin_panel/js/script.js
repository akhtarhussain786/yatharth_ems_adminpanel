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
    }
}

/* ============================================================
   Notifications & Real-time Sound/Popup Alerts
   ============================================================ */
var seenNotifIds = null;
var notifPollTimer = null;
var audioCtx = null;

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

function playNotificationSound() {
    try {
        var ctx = getAudioContext();
        if (!ctx) return;

        var playChime = function () {
            var now = ctx.currentTime;
            
            // First note (E5, 659.25Hz)
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

            // Second note (B5, 987.77Hz)
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

function showNotificationToast(n) {
    ensureToastContainer();

    var icon = "bell";
    var color = "#2563eb";
    if (n.type === "leave" || (n.title && n.title.indexOf("Leave") > -1)) {
        icon = "envelope";
        color = "#ea580c";
    } else if (n.type === "attendance" || (n.title && n.title.indexOf("Attendance") > -1)) {
        icon = "calendar-check";
        color = "#059669";
    } else if (n.type === "announcement" || (n.title && n.title.indexOf("Notice") > -1)) {
        icon = "bullhorn";
        color = "#0284c7";
    }

    var title = n.title || "New Notification";
    var message = n.message || "";
    var timeAgo = getTimeAgo(n.created_at) || "Just now";
    var toastId = "notifToast_" + (n.id || Date.now());

    if ($("#" + toastId).length > 0) return;

    var toastHtml = '<div id="' + toastId + '" class="admin-notif-toast" data-id="' + (n.id || '') + '">' +
        '<div class="toast-icon" style="background:' + color + '15; color:' + color + '; border-radius: 50%; width: 38px; height: 38px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">' +
        '<i class="fas fa-' + icon + '"></i>' +
        '</div>' +
        '<div class="toast-content" style="flex: 1; min-width: 0;">' +
        '<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 2px;">' +
        '<span style="font-weight: 700; font-size: 0.88rem; color: var(--text-color, #1e293b); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">' + escapeHtml(title) + '</span>' +
        '<span style="font-size: 0.72rem; color: #94a3b8; margin-left: 8px;">' + escapeHtml(timeAgo) + '</span>' +
        '</div>' +
        (message ? '<div style="font-size: 0.8rem; color: #475569; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; line-height: 1.35;">' + escapeHtml(message) + '</div>' : '') +
        '</div>' +
        '<button type="button" style="background: none; border: none; color: #94a3b8; font-size: 1.1rem; line-height: 1; cursor: pointer; padding: 0 0 0 8px; margin-top: -2px;" onclick="closeAdminToast(\'' + toastId + '\')">&times;</button>' +
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
    $("#notifBtn").off("shown.bs.dropdown").on("shown.bs.dropdown", markNotificationsSeen);

    fetchAdminNotifications();

    if (!notifPollTimer) {
        notifPollTimer = setInterval(fetchAdminNotifications, 10000);
    }
}

function fetchAdminNotifications() {
    var baseUrl = window.location.origin + "/ems/admin_panel/";
    $.get(baseUrl + "modules/notices.php?action=ajax", function (data) {
        var notifBody = $("#notifBody");
        var unreadCount = 0;
        var newCount = 0;
        try {
            var notices = typeof data === "string" ? JSON.parse(data) : data;
            var unreadNotices = notices.filter(function (n) { return n.is_read == 0 || n.is_read == "0"; });

            var isFirstLoad = (seenNotifIds === null);
            if (isFirstLoad) {
                seenNotifIds = {};
            }

            var newlyArrivedList = [];

            if (unreadNotices.length > 0) {
                var html = "";
                unreadNotices.forEach(function (n) {
                    unreadCount++;
                    var icon = "bell";
                    var color = "blue";
                    if (n.type === "leave" || (n.title && n.title.indexOf("Leave") > -1)) { icon = "envelope"; color = "orange"; }
                    else if (n.type === "attendance" || (n.title && n.title.indexOf("Attendance") > -1)) { icon = "calendar-check"; color = "green"; }
                    else if (n.type === "announcement" || (n.title && n.title.indexOf("Notice") > -1)) { icon = "bullhorn"; color = "info"; }
                    var timeAgo = getTimeAgo(n.created_at);
                    var isNew = (n.is_new == 1 || n.is_new == "1");
                    if (isNew) { newCount++; }

                    if (isFirstLoad) {
                        seenNotifIds[n.id] = true;
                        if (isNew) {
                            newlyArrivedList.push(n);
                        }
                    } else if (!seenNotifIds[n.id]) {
                        seenNotifIds[n.id] = true;
                        newlyArrivedList.push(n);
                    }

                    html += '<div class="notif-item unread' + (isNew ? ' is-new' : '') + '" data-id="' + n.id + '" style="cursor:pointer">';
                    html += '<div class="notif-icon" style="background:var(--' + color + '-light);color:var(--' + color + ');"><i class="fas fa-' + icon + '"></i></div>';
                    html += '<div class="notif-content">';
                    html += '<div class="title">' + (n.title || "Notification") + (isNew ? ' <span class="notif-new-badge">NEW</span>' : '') + '</div>';
                    html += '<div class="message">' + (n.message || "") + '</div>';
                    html += '<div class="time">' + timeAgo + '</div>';
                    html += '</div></div>';
                });
                notifBody.html(html);

                notifBody.find(".notif-item").off("click").on("click", function () {
                    var id = $(this).data("id");
                    $.get(baseUrl + "modules/notifications.php?read=ajax&id=" + id, function () {
                        var item = notifBody.find('.notif-item[data-id="' + id + '"]');
                        item.fadeOut(300, function () { item.remove(); updateNotifDot(); });
                    });
                });
                $("#notifDot").show().text(unreadCount).toggleClass("has-new", newCount > 0);
            } else {
                notifBody.html('<div class="text-center text-muted py-4" style="font-size:0.8rem;"><i class="fas fa-bell-slash me-1"></i> No notifications</div>');
                $("#notifDot").hide();
            }

            if (newlyArrivedList.length > 0) {
                playNotificationSound();
                newlyArrivedList.slice(0, 3).forEach(function (n) {
                    showNotificationToast(n);
                });
            }

        } catch (e) {
            notifBody.html('<div class="text-center text-muted py-4" style="font-size:0.8rem;">No notifications</div>');
            $("#notifDot").hide();
        }
    }).fail(function () {
        $("#notifBody").html('<div class="text-center text-muted py-4" style="font-size:0.8rem;">Could not load notifications</div>');
        $("#notifDot").hide();
    });
}


// Opening the bell is what marks this batch as seen: the pulse stops and the
// NEW badges go, but nothing is marked read — that still needs a click.
function markNotificationsSeen() {
    var baseUrl = window.location.origin + "/ems/admin_panel/";
    $("#notifDot").removeClass("has-new");
    $("#notifBody .notif-item.is-new").removeClass("is-new").find(".notif-new-badge").remove();
    $.get(baseUrl + "modules/notices.php?action=notif_seen");
}

function updateNotifDot() {
    var count = $("#notifBody .notif-item").length;
    if (count > 0) { $("#notifDot").show().text(count); }
    else { $("#notifDot").hide(); }
}

function markAllRead() {
    $.post(window.location.origin + "/ems/admin_panel/modules/notices.php", { action: "mark_read" }, function () {
        $("#notifBody").html('<div class="text-center text-muted py-4" style="font-size:0.8rem;"><i class="fas fa-bell-slash me-1"></i> No notifications</div>');
        $("#notifDot").hide();
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
