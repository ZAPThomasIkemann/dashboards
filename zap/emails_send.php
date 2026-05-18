<?php require_once 'config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>5. E-Mails Send - ZAP Dashboard</title>
    <?php include 'includes/favicon.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=Rubik:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/main.css?v=20260515a">
    <style>
        /* ── Layout ── */
        .email-grid { display:grid; gap:18px; }

        /* ── SMTP Config Card ── */
        .smtp-card { border:1px solid rgba(255,255,255,.08); background:rgba(255,255,255,.03); border-radius:18px; overflow:hidden; }
        .smtp-header { display:flex; align-items:center; gap:12px; padding:16px 20px; cursor:pointer; user-select:none; }
        .smtp-header:hover { background:rgba(255,255,255,.03); }
        .smtp-header h3 { margin:0; font-size:.95rem; flex:1; display:flex; align-items:center; gap:8px; }
        .smtp-header h3 i { color:var(--zap-green); }
        #smtpStatus { font-size:.82rem; color:var(--zap-green); }
        .smtp-body { padding:20px; border-top:1px solid rgba(255,255,255,.06); }
        .smtp-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:12px; }
        .smtp-row-2 { display:grid; grid-template-columns: 1fr; gap:12px; margin-top:12px; }
        .smtp-field { display:grid; gap:6px; }
        .smtp-field label { font-size:.82rem; font-weight:600; color:var(--zap-text-muted); }
        .smtp-field textarea { resize:vertical; min-height:80px; }

        /* ── Stats ── */
        .email-stats { display:grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap:12px; }
        .email-stat { border:1px solid rgba(255,255,255,.08); background:rgba(255,255,255,.03); border-radius:14px; padding:14px 16px; text-align:center; }
        .email-stat-val { font-size:1.6rem; font-weight:800; line-height:1; }
        .email-stat-lbl { font-size:.75rem; color:var(--zap-text-muted); margin-top:4px; font-weight:600; letter-spacing:.04em; }
        .email-stat.s-send  .email-stat-val { color:#18e888; }
        .email-stat.s-sent  .email-stat-val { color:#00c8ff; }
        .email-stat.s-reply .email-stat-val { color:#a78bfa; }
        .email-stat.s-disc  .email-stat-val { color:#fbbf24; }
        .email-stat.s-cont  .email-stat-val { color:#f472b6; }
        .email-stat.s-done  .email-stat-val { color:#4ade80; }

        /* ── Kanban Board ── */
        .kanban-toolbar { display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin-bottom:16px; }
        #kanbanBoard {
            display:grid;
            grid-template-columns: repeat(6, minmax(220px, 1fr));
            gap:14px;
            overflow-x:auto;
            padding-bottom:8px;
        }
        .kb-col { display:flex; flex-direction:column; gap:0; min-width:0; }
        .kb-col-header { display:flex; align-items:center; gap:8px; padding:10px 12px; border-radius:14px 14px 0 0; border:1px solid rgba(255,255,255,.08); border-bottom:0; background:rgba(255,255,255,.04); }
        .kb-col-icon { font-size:.9rem; }
        .kb-col-title { font-size:.85rem; font-weight:700; flex:1; }
        .kb-col-count { font-size:.78rem; background:rgba(255,255,255,.08); padding:2px 7px; border-radius:999px; font-weight:700; min-width:24px; text-align:center; }
        .kb-col-cards {
            border:1px solid rgba(255,255,255,.08);
            border-top:0;
            border-bottom:0;
            background:rgba(255,255,255,.02);
            min-height:120px;
            padding:8px;
            display:flex;
            flex-direction:column;
            gap:8px;
            transition:background .15s;
        }
        .kb-col-cards.kb-drag-over { background:rgba(24,232,136,.06); border-color:rgba(24,232,136,.3); }
        .kb-col-footer { border:1px solid rgba(255,255,255,.08); border-top:0; border-radius:0 0 14px 14px; padding:8px; background:rgba(255,255,255,.02); }
        .kb-loading { display:flex; justify-content:center; padding:16px; }
        .kb-empty { text-align:center; color:var(--zap-text-muted); font-size:.82rem; padding:20px 8px; }

        /* ── Kanban Card ── */
        .kb-card {
            border:1px solid rgba(255,255,255,.08);
            background:rgba(255,255,255,.04);
            border-radius:12px;
            padding:12px;
            cursor:grab;
            transition:border-color .15s, transform .1s, box-shadow .15s;
            display:flex;
            flex-direction:column;
            gap:6px;
        }
        .kb-card:hover { border-color:rgba(24,232,136,.3); transform:translateY(-1px); box-shadow:0 4px 18px rgba(0,0,0,.3); }
        .kb-card.kb-dragging { opacity:.5; cursor:grabbing; }
        .kb-card-top { display:flex; align-items:center; justify-content:space-between; gap:8px; }
        .kb-card-domain { font-weight:700; font-size:.82rem; word-break:break-word; color:var(--zap-text); flex:1; }
        .kb-card-email { font-size:.78rem; color:#00c8ff; word-break:break-all; }
        .kb-card-meta { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
        .kb-card-kw { font-size:.74rem; color:var(--zap-text-muted); flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .kb-card-vol { font-size:.74rem; color:var(--zap-text-muted); white-space:nowrap; }
        .kb-card-notes { font-size:.74rem; color:#fbbf24; padding:4px 6px; background:rgba(251,191,36,.08); border-radius:6px; word-break:break-word; }
        .kb-card-actions { display:flex; gap:6px; align-items:center; margin-top:4px; }
        .kb-card-actions .search-input { font-size:.75rem; padding:4px 8px; flex:1; }
        .kb-note-btn { padding:4px 8px; font-size:.75rem; }

        /* ── Responsive ── */
        @media (max-width: 1400px) { #kanbanBoard { grid-template-columns: repeat(3, minmax(200px, 1fr)); } }
        @media (max-width: 900px)  { #kanbanBoard { grid-template-columns: repeat(2, minmax(200px, 1fr)); } }
        @media (max-width: 600px)  { #kanbanBoard { grid-template-columns: 1fr; } }
        .hidden { display:none !important; }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include 'includes/topbar.php'; ?>
        <div class="page-content">

            <div class="page-header">
                <h1><i class="fas fa-paper-plane"></i> 5. E-Mails Send</h1>
                <span class="page-subtitle">Outreach pipeline — track every prospect from discovery to done. Configure SMTP, move cards through the Kanban, and monitor your results.</span>
            </div>

            <div class="email-grid">

                <!-- ── SMTP Config ── -->
                <div class="smtp-card">
                    <div class="smtp-header" id="smtpToggle">
                        <h3><i class="fas fa-cog"></i> SMTP Configuration &amp; Rate Limits</h3>
                        <span id="smtpStatus">Not configured</span>
                        <i class="fas fa-chevron-down" id="smtpToggleIcon" style="color:var(--zap-text-muted);margin-left:8px;"></i>
                    </div>
                    <div class="smtp-body" id="smtpBody" style="display:none">
                        <form id="smtpForm">
                            <div class="smtp-grid">
                                <div class="smtp-field">
                                    <label for="smtpHost">SMTP Host</label>
                                    <input type="text" id="smtpHost" class="search-input" placeholder="smtp.example.com">
                                </div>
                                <div class="smtp-field">
                                    <label for="smtpPort">Port</label>
                                    <input type="number" id="smtpPort" class="search-input" value="587" min="1" max="65535">
                                </div>
                                <div class="smtp-field">
                                    <label for="smtpUser">Username</label>
                                    <input type="text" id="smtpUser" class="search-input" placeholder="user@example.com">
                                </div>
                                <div class="smtp-field">
                                    <label for="smtpPass">Password</label>
                                    <input type="password" id="smtpPass" class="search-input" placeholder="••••••••">
                                </div>
                                <div class="smtp-field">
                                    <label for="smtpFromName">From Name</label>
                                    <input type="text" id="smtpFromName" class="search-input" placeholder="ZAP-Hosting Team">
                                </div>
                                <div class="smtp-field">
                                    <label for="smtpFromEmail">From E-Mail</label>
                                    <input type="email" id="smtpFromEmail" class="search-input" placeholder="outreach@zap-hosting.com">
                                </div>
                                <div class="smtp-field">
                                    <label for="smtpMaxHour">Max E-Mails / Stunde</label>
                                    <input type="number" id="smtpMaxHour" class="search-input" value="10" min="1" max="500" title="Maximale Anzahl E-Mails pro rollendem 60-Minuten-Fenster">
                                </div>
                                <div class="smtp-field">
                                    <label for="smtpMaxDay">Max E-Mails / Tag</label>
                                    <input type="number" id="smtpMaxDay" class="search-input" value="50" min="1" max="2000" title="Maximale Anzahl E-Mails pro Kalendertag (Europe/Berlin)">
                                </div>
                            </div>
                            <div class="smtp-row-2">
                                <div class="smtp-field">
                                    <label for="smtpSubject">E-Mail Subject <small style="font-weight:400;color:var(--zap-text-muted)">(Variablen: {{domain}}, {{keyword}}, {{competitor}}, {{backlink_url}}, {{from_name}})</small></label>
                                    <input type="text" id="smtpSubject" class="search-input" placeholder="Linkpartnerschaft — {{domain}}">
                                </div>
                                <div class="smtp-field">
                                    <label for="smtpTemplate">E-Mail Template (HTML) <small style="font-weight:400;color:var(--zap-text-muted)">Gleiche Variablen wie Subject</small></label>
                                    <textarea id="smtpTemplate" class="search-input" rows="8" placeholder="Hallo,&#10;&#10;ich bin auf {{domain}} gestoßen und fand euren Artikel sehr interessant...&#10;&#10;Liebe Grüße,&#10;{{from_name}}"></textarea>
                                </div>
                            </div>
                            <div style="margin-top:14px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Einstellungen speichern</button>
                                <span id="smtpRateStatus" style="font-size:.82rem;color:var(--zap-text-muted);"></span>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- ── Send Controls ── -->
                <div class="card" style="padding:18px 20px;">
                    <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                        <div>
                            <h3 style="margin:0 0 4px;font-size:.95rem;"><i class="fas fa-paper-plane" style="color:var(--zap-green);margin-right:6px;"></i> E-Mail Versand</h3>
                            <div style="font-size:.82rem;color:var(--zap-text-muted);">
                                Heute: <strong id="sendStatDay">—</strong> gesendet ·
                                Letzte Stunde: <strong id="sendStatHour">—</strong> ·
                                Limit: <strong id="sendStatLimit">—/h · —/d</strong>
                            </div>
                        </div>
                        <div style="flex:1"></div>
                        <button class="btn btn-primary" id="sendBatchBtn" onclick="triggerSendBatch()">
                            <i class="fas fa-paper-plane"></i> Batch senden (max. Limit)
                        </button>
                        <span style="font-size:.8rem;color:var(--zap-text-muted);">
                            Sendet bis zum Stunden- und Tageslimit. Reihenfolge: höchster Domain-Rank zuerst.
                        </span>
                    </div>
                </div>

                <!-- ── Stats Row ── -->
                <div class="email-stats">
                    <div class="email-stat s-send">
                        <div class="email-stat-val" id="statToSend">—</div>
                        <div class="email-stat-lbl"><i class="fas fa-paper-plane"></i> To Send</div>
                    </div>
                    <div class="email-stat s-sent">
                        <div class="email-stat-val" id="statSent">—</div>
                        <div class="email-stat-lbl"><i class="fas fa-check"></i> Sent</div>
                    </div>
                    <div class="email-stat s-reply">
                        <div class="email-stat-val" id="statReplied">—</div>
                        <div class="email-stat-lbl"><i class="fas fa-reply"></i> Reply</div>
                    </div>
                    <div class="email-stat s-disc">
                        <div class="email-stat-val" id="statDiscussion">—</div>
                        <div class="email-stat-lbl"><i class="fas fa-comments"></i> Discussion</div>
                    </div>
                    <div class="email-stat s-cont">
                        <div class="email-stat-val" id="statContent">—</div>
                        <div class="email-stat-lbl"><i class="fas fa-file-alt"></i> Content</div>
                    </div>
                    <div class="email-stat s-done">
                        <div class="email-stat-val" id="statDone">—</div>
                        <div class="email-stat-lbl"><i class="fas fa-flag"></i> Done</div>
                    </div>
                </div>

                <!-- ── Kanban ── -->
                <div class="card" style="padding:20px;">
                    <div class="kanban-toolbar">
                        <input type="text" id="kbDomainFilter"  class="search-input" placeholder="Filter by domain..." style="max-width:220px;">
                        <input type="text" id="kbKeywordFilter" class="search-input" placeholder="Filter by keyword..." style="max-width:220px;">
                        <button class="btn btn-secondary" id="kbRefreshBtn"><i class="fas fa-sync-alt"></i> Refresh</button>
                        <span style="flex:1"></span>
                        <span style="font-size:.8rem;color:var(--zap-text-muted)">
                            Drag &amp; drop cards between columns — or use the <strong>Move to…</strong> dropdown on each card.
                        </span>
                    </div>

                    <div id="kanbanBoard">
                        <div style="color:var(--zap-text-muted);padding:30px;text-align:center;">Loading Kanban board...</div>
                    </div>
                </div>

            </div><!-- /email-grid -->
        </div>
    </div>

    <script src="assets/js/main.js?v=20260515a"></script>
    <script src="assets/js/emails_send.js?v=20260515a"></script>
</body>
</html>
