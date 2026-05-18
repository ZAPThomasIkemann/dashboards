            <div class="page-header" style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                <div>
                    <h1><i class="fas fa-unlink" style="color:#ff5050;"></i> Offline Backlinks</h1>
                    <span class="page-subtitle">Only backlinks with <strong>Offline</strong> status. Sorted by <strong>Domain Rating</strong> (highest first). Default filter: <strong>Dofollow only</strong>.</span>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                    <span id="offlineCountBadge" class="btn btn-secondary" style="pointer-events:none;opacity:0.95;">...</span>
                    <a href="index.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Overview</a>
                    <a href="backlinks.php" class="btn btn-primary"><i class="fas fa-link"></i> All Backlinks</a>
                </div>
            </div>

            <div class="card" style="margin-bottom:20px;">
                <div class="table-controls" style="display:flex;flex-wrap:wrap;align-items:center;gap:12px;justify-content:space-between;">
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <label for="linkTypeFilter" class="form-label" style="margin:0;font-size:0.85rem;">Link Type</label>
                        <select id="linkTypeFilter" class="search-input" style="min-width:220px;" title="Default: Dofollow only">
                            <option value="">All (Dofollow &amp; Nofollow)</option>
                            <option value="dofollow" selected>Dofollow Only</option>
                            <option value="nofollow">Nofollow Only</option>
                        </select>
                        <label for="httpFilter" class="form-label" style="margin:0;font-size:0.85rem;">HTTP</label>
                        <select id="httpFilter" class="search-input" style="min-width:200px;" title="HTTP status">
                            <option value="">All HTTP Statuses</option>
                        </select>
                    </div>
                    <p style="margin:0;font-size:0.78rem;color:var(--zap-text-muted);max-width:560px;line-height:1.4;">
                        <strong style="color:var(--zap-text);font-weight:600;">Active filter:</strong> Dofollow only by default. The HTTP dropdown only shows status codes that exist in the current result set.
                    </p>
                </div>
            </div>

            <div class="card">
                <div class="table-wrap">
                    <table class="data-table" id="backlinksTable">
                        <thead>
                            <tr>
                                <th>Brand</th>
                                <th>Source URL</th>
                                <th>Target URL</th>
                                <th>Anchor Text</th>
                                <th>Link Type</th>
                                <th>DR</th>
                                <th>DA</th>
                                <th>Status</th>
                                <th>HTTP</th>
                                <th>First Seen</th>
                                <th>Last Checked</th>
                                <th>Notes</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="tableBody">
                            <tr class="loading-row"><td colspan="13"><div class="spinner"></div></td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="table-footer">
                    <span id="tableInfo">Loading...</span>
                    <div class="pagination" id="pagination"></div>
                </div>
            </div>
