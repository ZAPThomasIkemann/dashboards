<?php
require_once __DIR__ . '/../config.php';

class DataForSEO {
    private string $base = 'https://api.dataforseo.com/v3';
    private string $auth;
    public bool $configured = false;

    public function __construct() {
        $login = DFS_LOGIN;
        $pass  = DFS_PASSWORD;
        if ($login && $pass) {
            $this->auth = base64_encode("{$login}:{$pass}");
            $this->configured = true;
        }
    }

    private function request(string $method, string $endpoint, ?array $body = null): array {
        if (!$this->configured) {
            return ['error' => 'DataForSEO login email not configured. Set DFS_LOGIN in /etc/environment.'];
        }

        $opts = [
            'http' => [
                'method'        => strtoupper($method),
                'header'        => "Authorization: Basic {$this->auth}\r\nContent-Type: application/json\r\n",
                'timeout'       => 60,
                'ignore_errors' => true,
            ],
        ];
        if ($body !== null) {
            $opts['http']['content'] = json_encode($body);
        }

        $ctx  = stream_context_create($opts);
        $raw  = @file_get_contents($this->base . $endpoint, false, $ctx);
        if ($raw === false) {
            return ['error' => 'Request failed: ' . error_get_last()['message'] ?? 'unknown'];
        }
        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['error' => 'Invalid JSON response'];
        }
        return $data ?? [];
    }

    // ── BACKLINKS ────────────────────────────────────────────────────────────

    /**
     * Fetch backlinks summary (domain rating etc.) for a target domain.
     * Returns ['error' => '...'] if subscription not active or request fails.
     */
    public function getBacklinksSummary(string $domain): array {
        $res  = $this->request('POST', '/backlinks/summary/live', [[
            'target'             => $domain,
            'include_subdomains' => true,
        ]]);
        $task = $res['tasks'][0] ?? [];
        if (($task['status_code'] ?? 0) === 40204) {
            return ['error' => 'Access denied: Backlinks subscription not active.'];
        }
        return $task['result'][0] ?? [];
    }

    /**
     * Detect whether the backlinks subscription is currently usable.
     *
     * @return array{available:bool, reason:string, raw:array}
     */
    public function getBacklinksSubscriptionState(string $domain = 'example.com'): array {
        $res = $this->request('POST', '/backlinks/summary/live', [[
            'target'             => $domain,
            'include_subdomains' => true,
        ]]);
        $task = $res['tasks'][0] ?? [];
        $statusCode = (int) ($task['status_code'] ?? 0);
        $message = strtolower((string) ($task['status_message'] ?? ''));

        if ($statusCode === 40204 || str_contains($message, 'denied') || str_contains($message, 'subscription')) {
            return ['available' => false, 'reason' => 'subscription_unavailable', 'raw' => $task];
        }

        if ($statusCode >= 20000 && $statusCode < 30000) {
            return ['available' => true, 'reason' => 'available', 'raw' => $task];
        }

        return ['available' => false, 'reason' => 'unknown', 'raw' => $task];
    }

    /**
     * Fetch backlinks list for a domain (up to $limit)
     */
    public function getBacklinks(string $domain, int $offset = 0, int $limit = 1000): array {
        $res = $this->request('POST', '/backlinks/backlinks/live', [[
            'target'             => $domain,
            'include_subdomains' => true,
            'mode'               => 'as_is',
            'filters'            => [['dofollow', '=', true]],
            'order_by'           => ['domain_from_rank,desc'],
            'offset'             => $offset,
            'limit'              => $limit,
        ]]);
        return $res['tasks'][0]['result'][0]['items'] ?? [];
    }

    /**
     * Fetch ALL backlinks (dofollow + nofollow) for a domain
     */
    public function getAllBacklinks(string $domain, int $limit = 1000): array {
        $res = $this->request('POST', '/backlinks/backlinks/live', [[
            'target'             => $domain,
            'include_subdomains' => true,
            'mode'               => 'as_is',
            'order_by'           => ['domain_from_rank,desc'],
            'offset'             => 0,
            'limit'              => $limit,
        ]]);
        return $res['tasks'][0]['result'][0]['items'] ?? [];
    }

    // ── SERP ─────────────────────────────────────────────────────────────────

    /**
     * Run a live SERP check for one keyword and return the top organic results.
     *
     * Returns an array of organic items with keys:
     *   rank_group, rank_absolute, domain, url, title, description
     *
     * @param  int $depth  Number of SERP positions to fetch (10 = first page)
     */
    public function getSerpOrganic(
        string $keyword,
        string $locationCode = '2276',
        string $languageCode = 'de',
        int    $depth        = 10
    ): array {
        $res = $this->request('POST', '/serp/google/organic/live/advanced', [[
            'keyword'       => $keyword,
            'location_code' => (int) $locationCode,
            'language_code' => $languageCode,
            'depth'         => max(10, min(100, $depth)),
            'se_domain'     => ($languageCode === 'en') ? 'google.com' : 'google.' . ($locationCode === '2276' ? 'de' : ($locationCode === '2040' ? 'at' : 'com')),
        ]]);

        $items  = $res['tasks'][0]['result'][0]['items'] ?? [];
        $organic = [];
        foreach ($items as $item) {
            if (($item['type'] ?? '') === 'organic') {
                $organic[] = $item;
            }
        }
        return $organic;
    }

    /**
     * Fetch backlinks pointing to a SPECIFIC URL (not a whole domain).
     * Use this instead of getBacklinks() when you only want backlinks
     * to a competitor's exact landing page, not their entire site.
     *
     * Returns the same item shape as getBacklinks():
     *   url_from, domain_from, url_to, anchor, dofollow,
     *   domain_from_rank, page_from_rank, etc.
     */
    public function getBacklinksForUrl(string $url, int $limit = 1000): array {
        $res = $this->request('POST', '/backlinks/backlinks/live', [[
            'target'             => $url,
            'include_subdomains' => false,
            'mode'               => 'as_is',
            'order_by'           => ['domain_from_rank,desc'],
            'offset'             => 0,
            'limit'              => max(1, min(1000, $limit)),
        ]]);
        return $res['tasks'][0]['result'][0]['items'] ?? [];
    }

    /**
     * Fetch backlinks for a domain but only those pointing to a specific URL path.
     * Falls back to getBacklinksForUrl() — kept for backwards-compat naming.
     */
    public function getBacklinksForLandingPage(string $targetUrl, int $limit = 1000): array {
        return $this->getBacklinksForUrl($targetUrl, $limit);
    }

    // ── RANKINGS ─────────────────────────────────────────────────────────────

    /**
     * Get ranked keywords for a domain via DataForSEO Labs
     */
    public function getRankedKeywords(string $domain, string $locationCode = '2040', string $languageCode = 'de', int $limit = 1000): array {
        $res = $this->request('POST', '/dataforseo_labs/google/ranked_keywords/live', [[
            'target'            => $domain,
            'location_code'     => (int)$locationCode,
            'language_code'     => $languageCode,
            'include_serp_info' => true,
            'ignore_synonyms'   => true,
            'limit'             => $limit,
        ]]);
        return $res['tasks'][0]['result'][0]['items'] ?? [];
    }

    /**
     * Get domain overview (organic traffic, authority etc.)
     */
    public function getDomainOverview(string $domain, string $locationCode = '2040', string $languageCode = 'de'): array {
        $res = $this->request('POST', '/dataforseo_labs/google/domain_rank_overview/live', [[
            'target'        => $domain,
            'location_code' => (int)$locationCode,
            'language_code' => $languageCode,
        ]]);
        return $res['tasks'][0]['result'][0]['items'][0] ?? [];
    }
}
