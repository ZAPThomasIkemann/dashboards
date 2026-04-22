<?php
require_once 'config.php';
/** Offline-Ansicht: Query ?offline=1 oder eigene Datei backlinks_offline.php (setzt BL_OFFLINE_MODE). */
$blOfflineMode = (defined('BL_OFFLINE_MODE') && BL_OFFLINE_MODE)
    || (isset($_GET['offline']) && (string) $_GET['offline'] === '1');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $blOfflineMode ? 'Offline Backlinks – ZAP Dashboard' : 'Backlinks – ZAP Dashboard' ?></title>
    <?php include 'includes/favicon.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=Rubik:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/main.css">
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include 'includes/topbar.php'; ?>
        <div class="page-content">
            <?php if ($blOfflineMode): ?>
                <?php include 'includes/backlinks_offline_view.php'; ?>
            <?php else: ?>
                <?php include 'includes/backlinks_main_view.php'; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add/Edit Modal -->
    <div class="modal-overlay" id="backlinkModal">
        <div class="modal">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-link"></i> Backlink hinzufügen</h3>
                <button class="modal-close" onclick="closeModal('backlinkModal')"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="backlinkForm">
                    <input type="hidden" id="editId" value="">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Brand *</label>
                            <select class="form-input" id="fBrand" required>
                                <option value="ZAP">ZAP</option>
                                <option value="DMC">DMC</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Link Typ</label>
                            <select class="form-input" id="fLinkType">
                                <option value="dofollow">Dofollow</option>
                                <option value="nofollow">Nofollow</option>
                                <option value="sponsored">Sponsored</option>
                                <option value="ugc">UGC</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Source URL (Seite mit dem Backlink) *</label>
                        <input type="url" class="form-input" id="fSourceUrl" placeholder="https://example.com/seite-mit-backlink" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Target URL (Wohin verlinkt) *</label>
                        <input type="url" class="form-input" id="fTargetUrl" placeholder="https://zap-hosting.com/..." required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Anchor Text</label>
                        <input type="text" class="form-input" id="fAnchor" placeholder="z.B. ZAP-Hosting">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Domain Rating (DR)</label>
                            <input type="number" class="form-input" id="fDR" min="0" max="100" step="0.1" placeholder="0–100">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Domain Authority (DA)</label>
                            <input type="number" class="form-input" id="fDA" min="0" max="100" step="0.1" placeholder="0–100">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">First Seen (Seit wann)</label>
                            <input type="date" class="form-input" id="fFirstSeen">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Notizen</label>
                            <input type="text" class="form-input" id="fNotes" placeholder="Optional">
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('backlinkModal')">Abbrechen</button>
                <button class="btn btn-primary" onclick="saveBacklink()"><i class="fas fa-save"></i> Speichern</button>
            </div>
        </div>
    </div>

    <?php if (!$blOfflineMode): ?>
    <!-- CSV Import Modal -->
    <div class="modal-overlay" id="importModal">
        <div class="modal" style="max-width:600px;">
            <div class="modal-header">
                <h3><i class="fas fa-file-csv"></i> CSV Import</h3>
                <button class="modal-close" onclick="closeModal('importModal')"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div class="import-formats">
                    <div class="import-format-info">
                        <i class="fas fa-check-circle" style="color:var(--zap-green)"></i>
                        Unterstützte Formate: <strong>Ahrefs</strong>, <strong>SEMrush</strong>, <strong>Moz</strong> (CSV/TSV Export)
                    </div>
                    <div class="import-format-info" style="margin-top:8px;">
                        <i class="fas fa-info-circle" style="color:var(--zap-cyan)"></i>
                        Mindest-Spalten: <code>source_url</code> + <code>target_url</code> (oder nur source_url + Target-Domain angeben)
                    </div>
                </div>
                <form id="importForm" style="margin-top:16px;">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Brand *</label>
                            <select class="form-input" id="importBrand" required>
                                <option value="ZAP">ZAP</option>
                                <option value="DMC">DMC</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Target Domain (falls nicht in CSV)</label>
                            <select class="form-input" id="importTarget">
                                <option value="">— aus CSV —</option>
                                <?php foreach (MONITORED_DOMAINS as $d => $m): ?>
                                <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($m['name']) ?> (<?= $d ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">CSV / TSV Datei *</label>
                        <div class="file-drop" id="fileDrop" onclick="document.getElementById('csvFile').click()">
                            <i class="fas fa-cloud-upload-alt"></i>
                            <span id="fileDropText">Datei hier ablegen oder klicken</span>
                        </div>
                        <input type="file" id="csvFile" accept=".csv,.tsv,.txt" style="display:none" onchange="handleFileSelect(this)">
                    </div>
                    <div id="importPreview" style="display:none;" class="import-preview"></div>
                </form>
                <div id="importResult" style="display:none;" class="import-result"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('importModal')">Schließen</button>
                <button class="btn btn-primary" id="importBtn" onclick="runImport()" disabled><i class="fas fa-upload"></i> Importieren</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Delete Confirm Modal -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal" style="max-width:400px;">
            <div class="modal-header">
                <h3><i class="fas fa-trash"></i> Backlink löschen</h3>
                <button class="modal-close" onclick="closeModal('deleteModal')"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <p style="color:var(--zap-text-muted);font-size:0.875rem;">Diesen Backlink wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('deleteModal')">Abbrechen</button>
                <button class="btn btn-danger" onclick="confirmDelete()"><i class="fas fa-trash"></i> Löschen</button>
            </div>
        </div>
    </div>

    <script src="assets/js/main.js"></script>
<?php if ($blOfflineMode): ?>
    <script src="assets/js/backlinks_offline.js"></script>
<?php else: ?>
    <script src="assets/js/sync_stats.js"></script>
    <script src="assets/js/backlinks.js"></script>
<?php endif; ?>
</body>
</html>
