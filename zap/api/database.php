<?php
require_once '../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function db_tables(PDO $pdo, string $database): array {
    $sql = "
        SELECT
            t.TABLE_NAME,
            t.TABLE_ROWS,
            t.UPDATE_TIME,
            (
                SELECT COUNT(*)
                FROM information_schema.COLUMNS c
                WHERE c.TABLE_SCHEMA = t.TABLE_SCHEMA
                  AND c.TABLE_NAME = t.TABLE_NAME
            ) AS COLUMN_COUNT
        FROM information_schema.TABLES t
        WHERE t.TABLE_SCHEMA = ?
        ORDER BY t.TABLE_NAME ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$database]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function db_columns(PDO $pdo, string $database, string $table): array {
    $sql = "
        SELECT COLUMN_NAME, COLUMN_KEY, ORDINAL_POSITION
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = ?
          AND TABLE_NAME = ?
        ORDER BY ORDINAL_POSITION ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$database, $table]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function db_safe_table(array $tables, string $table): ?string {
    foreach ($tables as $row) {
        if ((string) ($row['TABLE_NAME'] ?? '') === $table) {
            return $table;
        }
    }
    return null;
}

function db_rows(PDO $pdo, string $table, array $columns, int $limit, int $offset): array {
    if (!$columns) {
        return ['rows' => [], 'total' => 0, 'order_by' => null];
    }

    $columnNames = array_map(static fn(array $c): string => (string) $c['COLUMN_NAME'], $columns);
    $quotedColumns = implode(', ', array_map(static fn(string $c): string => '`' . str_replace('`', '``', $c) . '`', $columnNames));

    $pk = null;
    foreach ($columns as $column) {
        if ((string) ($column['COLUMN_KEY'] ?? '') === 'PRI') {
            $pk = (string) $column['COLUMN_NAME'];
            break;
        }
    }
    if ($pk === null) {
        $pk = (string) $columns[0]['COLUMN_NAME'];
    }

    $countSql = "SELECT COUNT(*) FROM `" . str_replace('`', '``', $table) . "`";
    $total = (int) $pdo->query($countSql)->fetchColumn();

    $sql = "
        SELECT $quotedColumns
        FROM `" . str_replace('`', '``', $table) . "`
        ORDER BY `" . str_replace('`', '``', $pk) . "` DESC
        LIMIT $limit OFFSET $offset
    ";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        foreach ($row as $key => $value) {
            if (is_string($value) && strlen($value) > 1000) {
                $row[$key] = substr($value, 0, 1000) . '...';
            }
        }
    }

    return [
        'rows' => $rows,
        'total' => $total,
        'order_by' => $pk,
    ];
}

$pdo = db();
$database = DB_NAME;
$tables = db_tables($pdo, $database);
$mode = (string) ($_GET['mode'] ?? 'overview');

if ($mode === 'rows') {
    $table = (string) ($_GET['table'] ?? '');
    $safeTable = db_safe_table($tables, $table);
    if ($safeTable === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Tabelle nicht gefunden']);
        exit;
    }

    $limit = min(max((int) ($_GET['limit'] ?? 100), 1), 300);
    $offset = max((int) ($_GET['offset'] ?? 0), 0);
    $columns = db_columns($pdo, $database, $safeTable);
    $rowData = db_rows($pdo, $safeTable, $columns, $limit, $offset);

    echo json_encode([
        'success' => true,
        'server_time' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d H:i:s'),
        'current_database' => $database,
        'table' => $safeTable,
        'columns' => array_map(static fn(array $c): array => [
            'name' => (string) $c['COLUMN_NAME'],
            'key' => (string) ($c['COLUMN_KEY'] ?? ''),
            'ordinal_position' => (int) ($c['ORDINAL_POSITION'] ?? 0),
        ], $columns),
        'rows' => $rowData['rows'],
        'total' => $rowData['total'],
        'limit' => $limit,
        'offset' => $offset,
        'order_by' => $rowData['order_by'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$tableSummaries = array_map(static fn(array $row): array => [
    'table_name' => (string) ($row['TABLE_NAME'] ?? ''),
    'table_rows_estimate' => $row['TABLE_ROWS'] !== null ? (int) $row['TABLE_ROWS'] : null,
    'update_time' => $row['UPDATE_TIME'] ?? null,
    'column_count' => $row['COLUMN_COUNT'] !== null ? (int) $row['COLUMN_COUNT'] : 0,
], $tables);

echo json_encode([
    'success' => true,
    'server_time' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d H:i:s'),
    'current_database' => $database,
    'tables' => $tableSummaries,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
