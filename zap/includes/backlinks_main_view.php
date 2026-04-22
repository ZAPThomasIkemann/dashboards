            <div class="page-header" style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                <div>
                    <h1><i class="fas fa-link"></i> Backlinks</h1>
                    <span class="page-subtitle">Backlinks je Projekt (Ziel-Domain), Verteilung &amp; Top-Anker</span>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                    <button class="btn btn-secondary" onclick="openModal('importModal')"><i class="fas fa-file-csv"></i> CSV Import</button>
                    <button class="btn btn-secondary" onclick="checkAllLinks()"><i class="fas fa-sync-alt"></i> Links prüfen</button>
                    <button type="button" class="btn btn-secondary" onclick="syncBacklinks()" id="syncBLBtn"><i class="fas fa-link"></i> Sync Backlinks</button>
                    <button class="btn btn-primary" onclick="openAddModal()"><i class="fas fa-plus"></i> Backlink hinzufügen</button>
                </div>
            </div>

            <!-- Stats + kompakte Projekt-Kacheln -->
            <div id="blStats" class="bl-stats-container" style="margin-bottom:20px;"></div>

            <h2 class="bl-section-title"><i class="fas fa-layer-group"></i> Detailübersicht je Projekt</h2>
            <p class="bl-section-hint">Oben: Kennzahlen &amp; Dofollow/Nofollow je Ziel-Projekt (ZAP &amp; DMC einzeln). Hier: DR-Verteilung &amp; Top-Anker. Zuordnung über die <strong>Ziel-URL</strong> (Host = Monitoring-Domain). Ohne Treffer nur in der Tabelle.</p>
            <div id="blProjectsWrap" class="bl-projects-wrap"></div>

            <div id="blSyncProgress" class="sync-progress-panel" style="display:none;margin-bottom:16px;">
                <div class="progress-bar"><div class="progress-fill cyan" id="blProgressFill" style="width:0%"></div></div>
                <div class="sync-progress-meta" id="blProgressMeta"></div>
            </div>

            <h2 class="bl-section-title" style="margin-top:8px;"><i class="fas fa-table"></i> Alle Backlinks</h2>

            <div class="card">
                <div class="table-controls" style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;">
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <span id="syncStatus" style="font-size:0.75rem;color:var(--zap-text-muted);max-width:260px;"></span>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;flex:1;flex-wrap:wrap;min-width:min(100%,280px);">
                        <input type="text" id="globalSearch" class="search-input" placeholder="Alle Spalten durchsuchen..." oninput="filterTable()">
                        <select id="domainFilter" class="search-input" onchange="filterTable()">
                            <option value="">Alle Domains</option>
                            <?php foreach (MONITORED_DOMAINS as $d => $m): ?>
                            <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($m['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="brandFilter" class="search-input" onchange="filterTable()">
                            <option value="">Alle Brands</option>
                            <option value="ZAP">ZAP</option>
                            <option value="DMC">DMC</option>
                        </select>
                        <div class="bl-toolbar-filter">
                            <span class="bl-toolbar-filter-label">Status</span>
                            <div id="statusFilterRoot" class="bl-status-pills" role="group" aria-label="Status filtern"></div>
                        </div>
                        <select id="typeFilter" class="search-input" onchange="filterTable()">
                            <option value="">Alle Typen</option>
                            <option value="dofollow">Dofollow</option>
                            <option value="nofollow">Nofollow</option>
                            <option value="sponsored">Sponsored</option>
                            <option value="ugc">UGC</option>
                        </select>
                        <div class="bl-toolbar-filter">
                            <span class="bl-toolbar-filter-label">HTTP</span>
                            <div id="httpFilterRoot" class="bl-status-pills" role="group" aria-label="HTTP-Status filtern"></div>
                        </div>
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
                                <th class="sortable" data-col="4">Typ <span class="sort-icon"></span></th>
                                <th class="sortable" data-col="5">DR <span class="sort-icon"></span></th>
                                <th class="sortable" data-col="6">DA <span class="sort-icon"></span></th>
                                <th class="sortable" data-col="7">Status <span class="sort-icon"></span></th>
                                <th class="sortable" data-col="8">HTTP <span class="sort-icon"></span></th>
                                <th class="sortable" data-col="9">First Seen <span class="sort-icon"></span></th>
                                <th class="sortable" data-col="10">Zuletzt geprüft <span class="sort-icon"></span></th>
                                <th class="sortable" data-col="11">Notizen <span class="sort-icon"></span></th>
                                <th>Aktionen</th>
                            </tr>
                            <tr class="col-search-row">
                                <th><input class="col-search-input" placeholder="Brand" data-col="0" oninput="filterTable()"></th>
                                <th><input class="col-search-input" placeholder="Source URL" data-col="1" oninput="filterTable()"></th>
                                <th><input class="col-search-input" placeholder="Target URL" data-col="2" oninput="filterTable()"></th>
                                <th><input class="col-search-input" placeholder="Anchor" data-col="3" oninput="filterTable()"></th>
                                <th><input class="col-search-input" placeholder="Typ" data-col="4" oninput="filterTable()"></th>
                                <th><input class="col-search-input" placeholder="DR" data-col="5" oninput="filterTable()"></th>
                                <th><input class="col-search-input" placeholder="DA" data-col="6" oninput="filterTable()"></th>
                                <th class="th-status-codes-filter"><div class="bl-status-codes-filter" id="linkStatusFilterRoot" title="Status (Online/Offline/Unbekannt) aus der Liste filtern"></div></th>
                                <th><input class="col-search-input" placeholder="HTTP" data-col="8" oninput="filterTable()"></th>
                                <th><input class="col-search-input" placeholder="First Seen" data-col="9" oninput="filterTable()"></th>
                                <th><input class="col-search-input" placeholder="Geprüft" data-col="10" oninput="filterTable()"></th>
                                <th><input class="col-search-input" placeholder="Notizen" data-col="11" oninput="filterTable()"></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="tableBody">
                            <tr class="loading-row"><td colspan="13"><div class="spinner"></div></td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="table-footer">
                    <span id="tableInfo">Lade...</span>
                    <div class="pagination" id="pagination"></div>
                </div>
            </div>
