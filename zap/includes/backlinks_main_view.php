<?php
$dashboardDomains = $dashboardDomains ?? dashboard_scope_domains();
$dashboardBrand = $dashboardBrand ?? dashboard_scope_brand();
?>
            <div class="page-header" style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                <div>
                    <h1><i class="fas fa-link"></i> Backlinks</h1>
                    <span class="page-subtitle">Backlinks for <?= htmlspecialchars((string) $dashboardBrand) ?> projects, distribution and top anchors</span>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                    <button class="btn btn-secondary" onclick="openModal('importModal')"><i class="fas fa-file-csv"></i> CSV Import</button>
                    <button class="btn btn-secondary" onclick="checkAllLinks()"><i class="fas fa-sync-alt"></i> Check Links</button>
                    <button type="button" class="btn btn-secondary" onclick="syncBacklinks()" id="syncBLBtn"><i class="fas fa-link"></i> Sync Backlinks</button>
                    <button class="btn btn-primary" onclick="openAddModal()"><i class="fas fa-plus"></i> Add Backlink</button>
                </div>
            </div>
            <div id="blSyncProgress" class="sync-progress-panel" style="display:none;margin-bottom:16px;">
                <div class="progress-bar"><div class="progress-fill cyan" id="blProgressFill" style="width:0%"></div></div>
                <div class="sync-progress-meta" id="blProgressMeta"></div>
            </div>

            <div class="card">
                <div class="table-controls" style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;">
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <span id="syncStatus" style="font-size:0.75rem;color:var(--zap-text-muted);max-width:260px;"></span>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;flex:1;flex-wrap:wrap;min-width:min(100%,280px);justify-content:flex-end;">
                        <input type="text" id="globalSearch" class="search-input" placeholder="Search all columns..." oninput="filterTable()">
                    </div>
                </div>

                <div class="table-wrap">
                    <table class="data-table" id="backlinksTable">
                        <thead>
                            <tr>
                                <th class="sortable" data-col="0">Brand <span class="sort-icon"></span></th>
                                <th class="sortable" data-col="1">Source URL <span class="sort-icon"></span></th>
                                <th class="sortable" data-col="2">Target URL <span class="sort-icon"></span></th>
                                <th class="sortable" data-col="3">Anchor Text <span class="sort-icon"></span></th>
                                <th class="sortable" data-col="4">Link Type <span class="sort-icon"></span></th>
                                <th class="sortable" data-col="5">DR <span class="sort-icon"></span></th>
                                <th class="sortable" data-col="6">DA <span class="sort-icon"></span></th>
                                <th class="sortable" data-col="7">Status <span class="sort-icon"></span></th>
                                <th class="sortable" data-col="8">HTTP <span class="sort-icon"></span></th>
                                <th class="sortable" data-col="9">First Seen <span class="sort-icon"></span></th>
                                <th class="sortable" data-col="10">Last Checked <span class="sort-icon"></span></th>
                                <th class="sortable" data-col="11">Notes <span class="sort-icon"></span></th>
                                <th>Actions</th>
                            </tr>
                            <tr class="col-search-row">
                                <th></th>
                                <th>
                                    <select class="col-search-input" id="sourceDomainFilter" data-domain-filter="1" onchange="filterTable()">
                                        <option value="">All Domains</option>
                                    </select>
                                </th>
                                <th><input class="col-search-input" placeholder="Target URL" data-col="2" oninput="filterTable()"></th>
                                <th>
                                    <div class="col-filter-group">
                                        <select class="col-filter-op" data-col-op="3" onchange="filterTable()">
                                            <option value="contains">contains</option>
                                            <option value="not_contains">does not contain</option>
                                        </select>
                                        <input class="col-search-input" placeholder="Anchor" data-col="3" oninput="filterTable()">
                                    </div>
                                </th>
                                <th>
                                    <select class="col-search-input" data-col="4" onchange="filterTable()">
                                        <option value="">All Link Types</option>
                                        <option value="dofollow">Dofollow</option>
                                        <option value="nofollow">Nofollow</option>
                                        <option value="sponsored">Sponsored</option>
                                        <option value="ugc">UGC</option>
                                    </select>
                                </th>
                                <th><input class="col-search-input" placeholder="DR" data-col="5" oninput="filterTable()"></th>
                                <th><input class="col-search-input" placeholder="DA" data-col="6" oninput="filterTable()"></th>
                                <th class="th-status-codes-filter"><div class="bl-status-codes-filter" id="linkStatusFilterRoot" title="Filter statuses from the current list"></div></th>
                                <th class="th-status-codes-filter"><div class="bl-status-codes-filter" id="httpFilterRoot" title="Filter HTTP statuses from the current list"></div></th>
                                <th></th>
                                <th></th>
                                <th></th>
                                <th></th>
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
