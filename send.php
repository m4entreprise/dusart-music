<?php
// Minimal mail endpoint without external dependencies
// Place this file at the web root alongside index.html

// Basic CORS/headers if fetched via JS
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['ok' => true, 'message' => 'send.php reachable']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée']);
    exit;
}

// SMTP configuration (no external deps)
$SMTP_ENABLED = true; // set false to fallback to mail()
$SMTP_HOST = 'mail.dusart-music.be'; // per provider settings
$SMTP_PORT = 465; // 465 (SSL) as recommended
$SMTP_SECURE = 'ssl'; // 'ssl' per provider, 'tls' alternative on 587
$SMTP_USER = 'press@dusart-music.be';
$SMTP_PASS = 'nD7*QjK!Cnaudz4';

function smtp_expect($conn, $expectedPrefixes, $context = '') {
    $response = '';
    while (!feof($conn)) {
        $line = fgets($conn, 515);
        if ($line === false) break;
        $response .= $line;
        // Multi-line responses end when char 4 != '-'
        if (strlen($line) >= 4 && $line[3] !== '-') break;
    }
    foreach ((array)$expectedPrefixes as $prefix) {
        if (strpos($response, $prefix) === 0) return $response;
    }
    throw new Exception("SMTP unexpected reply ($context): " . trim($response));
}

function smtp_send_mail($host, $port, $secure, $user, $pass, $fromEmail, $fromName, $toEmail, $subject, $headersLines, $body) {
    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $conn = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
    if (!$conn) throw new Exception("SMTP connection failed: $errstr ($errno)");
    stream_set_timeout($conn, 15);

    smtp_expect($conn, '220', 'connect');
    fwrite($conn, "EHLO dusart-music.be\r\n");
    $ehlo = smtp_expect($conn, '250', 'EHLO');

    if ($secure === 'tls') {
        fwrite($conn, "STARTTLS\r\n");
        smtp_expect($conn, '220', 'STARTTLS');
        if (!@stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new Exception('SMTP STARTTLS negotiation failed');
        }
        // Re-EHLO after STARTTLS
        fwrite($conn, "EHLO dusart-music.be\r\n");
        smtp_expect($conn, '250', 'EHLO after STARTTLS');
    }

    if ($user !== '' && $pass !== '') {
        fwrite($conn, "AUTH LOGIN\r\n");
        smtp_expect($conn, '334', 'AUTH LOGIN');
        fwrite($conn, base64_encode($user) . "\r\n");
        smtp_expect($conn, '334', 'AUTH username');
        fwrite($conn, base64_encode($pass) . "\r\n");
        smtp_expect($conn, '235', 'AUTH password');
    }

    // Envelope
    fwrite($conn, "MAIL FROM:<$fromEmail>\r\n");
    smtp_expect($conn, ['250', '251'], 'MAIL FROM');
    fwrite($conn, "RCPT TO:<$toEmail>\r\n");
    smtp_expect($conn, ['250', '251'], 'RCPT TO');
    fwrite($conn, "DATA\r\n");
    smtp_expect($conn, '354', 'DATA');

    // Build headers + body
    $headers = implode("\r\n", $headersLines);
    $data = $headers . "\r\n\r\n" . $body . "\r\n.\r\n";
    fwrite($conn, $data);
    smtp_expect($conn, '250', 'message body');
    fwrite($conn, "QUIT\r\n");
    fclose($conn);
    return true;
}

// Simple rate limit (per IP, 30s)
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dusart_mail_rl';
if (!is_dir($rateDir)) @mkdir($rateDir, 0700, true);
$rateFile = $rateDir . DIRECTORY_SEPARATOR . md5($ip);
$now = time();
if (file_exists($rateFile)) {
    $last = (int) @file_get_contents($rateFile);
    if ($now - $last < 30) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Trop de requêtes, réessayez dans quelques secondes.']);
        exit;
    }
}
@file_put_contents($rateFile, (string) $now);

// Honeypot
$hp = trim($_POST['website'] ?? '');
if ($hp !== '') {
    // Silently accept
    echo json_encode(['ok' => true, 'message' => 'Merci']);
    exit;
}

// Fields
$name    = trim($_POST['name']    ?? '');
$email   = trim($_POST['email']   ?? '');
$subject = trim($_POST['subject'] ?? '');
$message = trim($_POST['message'] ?? '');
$company = trim($_POST['company'] ?? '');
$context = trim($_POST['context'] ?? 'general'); // e.g. 'press' or 'general'

if ($name === '' || $email === '' || $message === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Champs requis manquants.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => "Email invalide."]);
    exit;
}

// Configure destinataire (fixe) et expéditeur (fixe)
$to = 'booking@dusart-music.com';
$from = 'form@dusart-music.be';
$envelopeFrom = 'press@dusart-music.be'; // use authenticated identity for SMTP MAIL FROM
$subjectLine = ($subject !== '' ? $subject : 'Nouveau message du site') . " [" . ucfirst($context) . "]";

$body = "Nouvelle demande depuis le site DUSART\n\n" .
        "Contexte: $context\n" .
        ($company !== '' ? "Organisation: $company\n" : '') .
        "Nom: $name\n" .
        "Email: $email\n\n" .
        "Message:\n$message\n";

$headers = [];
$headers[] = 'MIME-Version: 1.0';
$headers[] = 'Content-Type: text/plain; charset=UTF-8';
$headers[] = 'Content-Transfer-Encoding: 8bit';
$headers[] = 'From: DUSART <' . $from . '>';
$headers[] = 'Reply-To: ' . $name . ' <' . $email . '>';
$headers[] = 'X-Mailer: PHP/' . phpversion();

try {
    if ($SMTP_ENABLED) {
        $ok = smtp_send_mail(
            $SMTP_HOST,
            $SMTP_PORT,
            $SMTP_SECURE,
            $SMTP_USER,
            $SMTP_PASS,
            $envelopeFrom,
            'DUSART',
            $to,
            '=?UTF-8?B?' . base64_encode($subjectLine) . '?=',
            $headers,
            $body
        );
    } else {
        $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subjectLine) . '?=', $body, implode("\r\n", $headers));
    }
} catch (Exception $ex) {
    $ok = false;
}

if ($ok) {
    echo json_encode(['ok' => true, 'message' => 'Votre message a bien été envoyé.']);
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => "L'envoi a échoué. Veuillez réessayer plus tard."]);
}
