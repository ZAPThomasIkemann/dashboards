<?php
/**
 * oauth_setup.php
 * Einmaliger Google OAuth2-Login für Drive-Upload-Zugriff.
 * Aufruf: https://thomas-dev.zap-srv.com/dashboards/dmc/api/oauth_setup.php
 *
 * Nach erfolgreichem Login wird google_oauth.json mit den Tokens ergänzt
 * und alle zukünftigen Workflow-Uploads laufen über deinen Google-Account.
 */

$oauthFile = __DIR__ . '/google_oauth.json';

if (!is_file($oauthFile)) {
    http_response_code(500);
    die('<p style="font-family:sans-serif;color:red">google_oauth.json nicht gefunden. Bitte Datei mit Client-ID und Client-Secret anlegen.</p>');
}

$data = json_decode(file_get_contents($oauthFile), true);

if (empty($data['client_id']) || empty($data['client_secret'])) {
    http_response_code(500);
    die('<p style="font-family:sans-serif;color:red">google_oauth.json enthält keine gültigen Credentials.</p>');
}

// Bereits autorisiert?
if (!empty($data['refresh_token'])) {
    echo '<html><body style="font-family:sans-serif;padding:40px">';
    echo '<h2 style="color:green">✅ Google Drive bereits verbunden</h2>';
    echo '<p>Ein gültiges Refresh-Token ist hinterlegt. Der Drive-Upload ist aktiv.</p>';
    echo '<p><a href="oauth_setup.php?reauth=1">Neu autorisieren</a> (nur nötig, wenn Berechtigungen geändert wurden)</p>';
    echo '</body></html>';
    if (empty($_GET['reauth'])) exit;
}

$authUrl = 'https://accounts.google.com/o/oauth2/auth?' . http_build_query([
    'client_id'     => $data['client_id'],
    'redirect_uri'  => $data['redirect_uri'],
    'scope'         => 'https://www.googleapis.com/auth/drive https://www.googleapis.com/auth/documents',
    'response_type' => 'code',
    'access_type'   => 'offline',
    'prompt'        => 'consent',
]);

header('Location: ' . $authUrl);
exit;
