<?php
require_once 'config.php';
$dashboardBrand = dashboard_scope_brand();
$dashboardDomains = dashboard_scope_domains();
/** Offline-Ansicht: Query ?offline=1 oder eigene Datei backlinks_offline.php (setzt BL_OFFLINE_MODE). */
$blOfflineMode = (defined('BL_OFFLINE_MODE') && BL_OFFLINE_MODE)
    || (isset($_GET['offline']) && (string) $_GET['offline'] === '1');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $blOfflineMode ? 'Offline Backlinks - ZAP Dashboard' : 'Backlinks - ZAP Dashboard' ?></title>
    <?php include 'includes/favicon.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=Rubik:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/main.css?v=20260506a">
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
                <h3 id="modalTitle"><i class="fas fa-link"></i> Add Backlink</h3>
                <button class="modal-close" onclick="closeModal('backlinkModal')"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="backlinkForm">
                    <input type="hidden" id="editId" value="">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Brand *</label>
                            <select class="form-input" id="fBrand" required>
                                <option value="<?= htmlspecialchars((string) $dashboardBrand) ?>"><?= htmlspecialchars((string) $dashboardBrand) ?></option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Link Type</label>
                            <select class="form-input" id="fLinkType">
                                <option value="dofollow">Dofollow</option>
                                <option value="nofollow">Nofollow</option>
                                <option value="sponsored">Sponsored</option>
                                <option value="ugc">UGC</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Source URL (page containing the backlink) *</label>
                        <input type="url" class="form-input" id="fSourceUrl" placeholder="https://example.com/page-with-backlink" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Target URL (linked destination) *</label>
                        <input type="url" class="form-input" id="fTargetUrl" placeholder="https://zap-hosting.com/..." required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Anchor Text</label>
                        <input type="text" class="form-input" id="fAnchor" placeholder="e.g. ZAP-Hosting">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Domain Rating (DR)</label>
                            <input type="number" class="form-input" id="fDR" min="0" max="100" step="0.1" placeholder="0-100">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Domain Authority (DA)</label>
                            <input type="number" class="form-input" id="fDA" min="0" max="100" step="0.1" placeholder="0-100">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">First Seen</label>
                            <input type="date" class="form-input" id="fFirstSeen">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Notes</label>
                            <input type="text" class="form-input" id="fNotes" placeholder="Optional">
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('backlinkModal')">Cancel</button>
                <button class="btn btn-primary" onclick="saveBacklink()"><i class="fas fa-save"></i> Save</button>
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
                        Supported formats: <strong>Ahrefs</strong>, <strong>SEMrush</strong>, <strong>Moz</strong> (CSV/TSV export)
                    </div>
                    <div class="import-format-info" style="margin-top:8px;">
                        <i class="fas fa-info-circle" style="color:var(--zap-cyan)"></i>
                        Minimum columns: <code>source_url</code> + <code>target_url</code> (or provide <code>source_url</code> and set the target domain below)
                    </div>
                </div>
                <form id="importForm" style="margin-top:16px;">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Brand *</label>
                            <select class="form-input" id="importBrand" required>
                                <option value="<?= htmlspecialchars((string) $dashboardBrand) ?>"><?= htmlspecialchars((string) $dashboardBrand) ?></option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Target Domain (if not included in CSV)</label>
                            <select class="form-input" id="importTarget">
                                <option value="">- from CSV -</option>
                                <?php foreach ($dashboardDomains as $d => $m): ?>
                                <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($m['name']) ?> (<?= $d ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">CSV / TSV File *</label>
                        <div class="file-drop" id="fileDrop" onclick="document.getElementById('csvFile').click()">
                            <i class="fas fa-cloud-upload-alt"></i>
                            <span id="fileDropText">Drop file here or click to choose</span>
                        </div>
                        <input type="file" id="csvFile" accept=".csv,.tsv,.txt" style="display:none" onchange="handleFileSelect(this)">
                    </div>
                    <div id="importPreview" style="display:none;" class="import-preview"></div>
                </form>
                <div id="importResult" style="display:none;" class="import-result"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('importModal')">Close</button>
                <button class="btn btn-primary" id="importBtn" onclick="runImport()" disabled><i class="fas fa-upload"></i> Import</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Delete Confirm Modal -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal" style="max-width:400px;">
            <div class="modal-header">
                <h3><i class="fas fa-trash"></i> Delete Backlink</h3>
                <button class="modal-close" onclick="closeModal('deleteModal')"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <p style="color:var(--zap-text-muted);font-size:0.875rem;">Delete this backlink? This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('deleteModal')">Cancel</button>
                <button class="btn btn-danger" onclick="confirmDelete()"><i class="fas fa-trash"></i> Delete</button>
            </div>
        </div>
    </div>

    <script src="assets/js/main.js?v=20260506a"></script>
    <script>
        window.DASHBOARD_BRAND_SCOPE = <?= json_encode($dashboardBrand, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        window.DASHBOARD_DOMAIN_SCOPE = <?= json_encode(array_keys($dashboardDomains), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    </script>
<?php if ($blOfflineMode): ?>
    <script src="assets/js/backlinks_offline.js?v=20260505a"></script>
<?php else: ?>
    <script src="assets/js/sync_stats.js"></script>
    <script src="assets/js/backlinks.js?v=20260505a"></script>
<?php endif; ?>
</body>
</html>


