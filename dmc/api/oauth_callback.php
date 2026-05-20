<?php
/**
 * oauth_callback.php
 * Empfängt den Authorization Code von Google, tauscht ihn gegen Tokens
 * und speichert diese in google_oauth.json.
 *
 * Diese Seite wird von Google nach dem Login automatisch aufgerufen.
 * Danach ist kein weiterer Eingriff nötig.
 */

$oauthFile = __DIR__ . '/google_oauth.json';

// Fehler von Google?
if (!empty($_GET['error'])) {
    http_response_code(400);
    die('<p style="font-family:sans-serif;color:red">Google OAuth Fehler: ' . htmlspecialchars($_GET['error']) . '</p>');
}

$code = trim($_GET['code'] ?? '');
if ($code === '') {
    http_response_code(400);
    die('<p style="font-family:sans-serif;color:red">Kein Authorization Code erhalten.</p>');
}

if (!is_file($oauthFile)) {
    http_response_code(500);
    die('<p style="font-family:sans-serif;color:red">google_oauth.json nicht gefunden.</p>');
}

$data = json_decode(file_get_contents($oauthFile), true);

// Code gegen Tokens tauschen
$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_POSTFIELDS     => http_build_query([
        'code'          => $code,
        'client_id'     => $data['client_id'],
        'client_secret' => $data['client_secret'],
        'redirect_uri'  => $data['redirect_uri'],
        'grant_type'    => 'authorization_code',
    ]),
]);
$tokenResp = json_decode(curl_exec($ch), true);
curl_close($ch);

if (empty($tokenResp['refresh_token'])) {
    http_response_code(500);
    echo '<p style="font-family:sans-serif;color:red">Token-Austausch fehlgeschlagen:<br>';
    echo htmlspecialchars(json_encode($tokenResp));
    echo '</p>';
    exit;
}

// Tokens in google_oauth.json speichern
$data['access_token']  = $tokenResp['access_token'];
$data['refresh_token'] = $tokenResp['refresh_token'];
$data['expires_at']    = time() + (int) ($tokenResp['expires_in'] ?? 3600);
$data['token_type']    = $tokenResp['token_type'] ?? 'Bearer';

file_put_contents($oauthFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo '<html><body style="font-family:sans-serif;padding:40px">';
echo '<h2 style="color:green">✅ Google Drive erfolgreich verbunden!</h2>';
echo '<p>Der Refresh-Token wurde gespeichert. Alle zukünftigen Content-Uploads gehen direkt in deinen Google Drive-Ordner.</p>';
echo '<p>Du kannst dieses Fenster jetzt schließen.</p>';
echo '</body></html>';
