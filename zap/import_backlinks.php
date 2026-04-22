<?php
require_once '../config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$pdo    = db();
$brand  = $_POST['brand']  ?? 'ZAP';
$target = $_POST['target'] ?? '';   // target domain e.g. zap-hosting.com
$format = $_POST['format'] ?? 'ahrefs';

if (empty($_FILES['csv']['tmp_name'])) {
    echo json_encode(['success' => false, 'error' => 'No file uploaded']);
    exit;
}

$file = fopen($_FILES['csv']['tmp_name'], 'r');
if (!$file) {
    echo json_encode(['success' => false, 'error' => 'Cannot read file']);
    exit;
}

// Read first line to detect header
$header = fgetcsv($file, 0, ',') ?: fgetcsv($file, 0, "\t");
if (!$header) {
    echo json_encode(['success' => false, 'error' => 'Empty file']);
    exit;
}

// Normalize header keys
$header = array_map(fn($h) => strtolower(trim(str_replace(['"', "\xEF\xBB\xBF"], '', $h))), $header);

/**
 * Detect column indices for different export formats:
 *   Ahrefs:   referring page url / anchor / link url / link type / domain rating / url rating / traffic / first seen / last seen
 *   SEMrush:  page score / source url / anchor / target url / type / nofollow / form / frame / image
 *   Moz:      page authority / domain authority / external links / source url / target url / anchor text / rel / first found / last found
 *   Generic:  source_url / target_url / anchor_text / link_type / domain_rating / domain_authority / first_seen
 */
function findCol(array $header, array $candidates): int|false {
    foreach ($candidates as $c) {
        $idx = array_search($c, $header);
        if ($idx !== false) return $idx;
    }
    return false;
}

$colMap = [
    'source_url'  => findCol($header, ['referring page url', 'source url', 'page url', 'source', 'from', 'url from', 'source_url', 'backlink url']),
    'target_url'  => findCol($header, ['link url', 'target url', 'destination url', 'anchor url', 'to', 'url to', 'target_url']),
    'anchor'      => findCol($header, ['anchor', 'anchor text', 'link text', 'anchor_text', 'text']),
    'link_type'   => findCol($header, ['link type', 'type', 'follow', 'nofollow', 'link_type']),
    'dr'          => findCol($header, ['domain rating', 'dr', 'domain_rating', 'domain rating (dr)']),
    'da'          => findCol($header, ['domain authority', 'da', 'domain_authority', 'page authority', 'pa']),
    'first_seen'  => findCol($header, ['first seen', 'first_seen', 'first found', 'date found', 'discovered']),
];

$inserted = 0;
$updated  = 0;
$skipped  = 0;
$errors   = [];

// Detect delimiter (comma vs tab)
rewind($file);
$raw = fread($file, 2048);
$commas = substr_count($raw, ',');
$tabs   = substr_count($raw, "\t");
$delim  = $tabs > $commas ? "\t" : ',';
rewind($file);
fgetcsv($file, 0, $delim); // skip header again

while (($row = fgetcsv($file, 0, $delim)) !== false) {
    if (count($row) < 2) continue;

    $sourceUrl = $colMap['source_url'] !== false ? trim($row[$colMap['source_url']] ?? '') : '';
    $targetUrl = $colMap['target_url'] !== false ? trim($row[$colMap['target_url']] ?? '') : '';

    // Skip rows without URLs
    if (!$sourceUrl && !$targetUrl) { $skipped++; continue; }

    // If only source URL, use the configured target domain
    if (!$targetUrl && $target) {
        $targetUrl = 'https://' . ltrim($target, 'https://');
    }

    if (!filter_var($sourceUrl, FILTER_VALIDATE_URL)) { $skipped++; continue; }

    $anchor    = $colMap['anchor']   !== false ? substr(trim($row[$colMap['anchor']]   ?? ''), 0, 500) : null;
    $rawType   = $colMap['link_type'] !== false ? strtolower(trim($row[$colMap['link_type']] ?? '')) : '';
    $linkType  = str_contains($rawType, 'sponsored') ? 'sponsored'
               : (str_contains($rawType, 'ugc')       ? 'ugc'
               : (str_contains($rawType, 'nofollow') || str_contains($rawType, 'no') ? 'nofollow'
               : 'dofollow'));

    $dr = $colMap['dr'] !== false && isset($row[$colMap['dr']]) && is_numeric($row[$colMap['dr']])
          ? (float)$row[$colMap['dr']] : null;
    $da = $colMap['da'] !== false && isset($row[$colMap['da']]) && is_numeric($row[$colMap['da']])
          ? (float)$row[$colMap['da']] : null;

    $firstSeen = null;
    if ($colMap['first_seen'] !== false && !empty($row[$colMap['first_seen']])) {
        $ts = strtotime(trim($row[$colMap['first_seen']]));
        if ($ts) $firstSeen = date('Y-m-d', $ts);
    }

    try {
        $existing = $pdo->prepare("SELECT id FROM backlinks WHERE source_url = ? AND target_url = ?");
        $existing->execute([$sourceUrl, $targetUrl]);
        $existRow = $existing->fetch();

        if ($existRow) {
            $pdo->prepare("UPDATE backlinks SET
                brand = ?, anchor_text = COALESCE(?, anchor_text), domain_rating = COALESCE(?, domain_rating),
                domain_authority = COALESCE(?, domain_authority), link_type = ?,
                first_seen = COALESCE(first_seen, ?), updated_at = NOW()
                WHERE id = ?")
                ->execute([$brand, $anchor, $dr, $da, $linkType, $firstSeen, $existRow['id']]);
            $updated++;
        } else {
            $pdo->prepare("INSERT INTO backlinks
                (brand, source_url, target_url, anchor_text, domain_rating, domain_authority, link_type, first_seen)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$brand, $sourceUrl, $targetUrl, $anchor, $dr, $da, $linkType, $firstSeen]);
            $inserted++;
        }
    } catch (Exception $e) {
        $errors[] = substr($e->getMessage(), 0, 100);
        $skipped++;
    }
}

fclose($file);

echo json_encode([
    'success'  => true,
    'inserted' => $inserted,
    'updated'  => $updated,
    'skipped'  => $skipped,
    'errors'   => array_slice($errors, 0, 5),
    'detected_columns' => array_filter($colMap, fn($v) => $v !== false),
]);
