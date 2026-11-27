<?php
// Utilidades compartidas de la renta de autos. No se abre directamente desde el navegador.
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'renta-comun.php') { http_response_code(404); exit; }
date_default_timezone_set('America/Cancun');

function config() {
    static $c = null;
    if ($c === null) {
        // El archivo de claves vive FUERA de public_html: nunca se publica ni se sube a GitHub.
        $ruta = __DIR__ . '/../renta-config.php';
        $c = is_file($ruta) ? include $ruta : [];
        if (!is_array($c)) { $c = []; }
    }
    return $c;
}

function responder($datos, $codigo = 200) {
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

function vehiculo() {
    $j = json_decode((string) @file_get_contents(__DIR__ . '/datos/renta.json'), true);
    $v = (is_array($j) && isset($j['vehiculo']) && is_array($j['vehiculo'])) ? $j['vehiculo'] : [];
    $v['precio_dia'] = isset($v['precio_dia']) ? (float) $v['precio_dia'] : 0.0;
    $v['nombre'] = isset($v['nombre']) ? (string) $v['nombre'] : 'Auto';
    return $v;
}

function ruta_datos() {
    $dir = __DIR__ . '/../renta-datos';
    if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
    return $dir . '/reservas.json';
}

// Abre la lista de reservas con acceso exclusivo, marca las pendientes vencidas
// como expiradas, ejecuta $fn y guarda los cambios.
function con_reservas($fn) {
    $fh = @fopen(ruta_datos(), 'c+');
    if (!$fh) { responder(['ok' => false, 'error' => 'No se pudo abrir el almacenamiento de reservas.'], 500); }
    flock($fh, LOCK_EX);
    $txt = stream_get_contents($fh);
    $lista = json_decode(($txt === false || $txt === '') ? '[]' : $txt, true);
    if (!is_array($lista)) { $lista = []; }
    $ahora = time();
    foreach ($lista as $i => $r) {
        if ($r['estado'] === 'pendiente' && $r['expira'] < $ahora) { $lista[$i]['estado'] = 'expirada'; }
    }
    $resultado = $fn($lista);
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($lista, JSON_UNESCAPED_UNICODE));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $resultado;
}

function ocupa($r) { return $r['estado'] === 'confirmada' || $r['estado'] === 'pendiente'; }

function traslapa($lista, $desde, $hasta) {
    foreach ($lista as $r) {
        if (ocupa($r) && $desde <= $r['hasta'] && $hasta >= $r['desde']) { return true; }
    }
    return false;
}

function fecha_ok($f) {
    $d = DateTime::createFromFormat('!Y-m-d', (string) $f);
    return $d && $d->format('Y-m-d') === $f;
}

function clip_token() {
    $c = config();
    $t = trim((string) ($c['clip_token'] ?? ''));
    if ($t === '' && !empty($c['clip_api_key']) && !empty($c['clip_api_secret'])) {
        $t = base64_encode($c['clip_api_key'] . ':' . $c['clip_api_secret']);
    }
    if (stripos($t, 'basic ') === 0) { $t = trim(substr($t, 6)); }
    if ($t === 'PEGA_AQUI_EL_TOKEN_DE_CLIP') { $t = ''; }
    return $t;
}

function clip_configurado() { return clip_token() !== ''; }

// Llama a la API de Clip. Devuelve [codigo_http, respuesta_decodificada].
function clip($metodo, $ruta, $cuerpo = null) {
    $token = clip_token();
    if ($token === '') { return [0, null]; }
    $ch = curl_init('https://api.payclip.com' . $ruta);
    $op = [
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Authorization: Basic ' . $token, 'Content-Type: application/json', 'Accept: application/json'],
    ];
    if ($cuerpo !== null) { $op[CURLOPT_POSTFIELDS] = json_encode($cuerpo, JSON_UNESCAPED_UNICODE); }
    curl_setopt_array($ch, $op);
    $resp = curl_exec($ch);
    $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$codigo, json_decode((string) $resp, true)];
}

function enviar($para, $asunto, $texto, $responder = '') {
    $h = "From: vivac.mx <no-reply@vivac.mx>\r\n"
        . ($responder !== '' ? "Reply-To: $responder\r\n" : '')
        . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    return @mail($para, mb_encode_mimeheader($asunto, 'UTF-8', 'B', "\r\n"), $texto, $h);
}

// ---------- Formato, número de reserva, correo con adjuntos y calendario ----------
function fecha_larga($f) {
    $m = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $t = strtotime($f);
    return date('j', $t) . ' de ' . $m[(int) date('n', $t) - 1] . ' de ' . date('Y', $t);
}

function dinero($n) { return '$' . number_format((float) $n, 2) . ' MXN'; }

function numero_reserva($r) {
    if (($r['tipo'] ?? '') === 'bloqueo') { return 'BLOQUEO'; }
    return isset($r['seq']) ? sprintf('RES-%04d', (int) $r['seq']) : strtoupper((string) $r['id']);
}

// Correo con texto, HTML y adjuntos. $adjuntos: [['nombre' => , 'tipo' => , 'datos' => ]]
function enviar_completo($para, $asunto, $texto, $html, $adjuntos = [], $responder = '') {
    $b1 = 'mix_' . bin2hex(random_bytes(8));
    $b2 = 'alt_' . bin2hex(random_bytes(8));
    $h = "From: vivac.mx <no-reply@vivac.mx>\r\n"
        . ($responder !== '' ? "Reply-To: $responder\r\n" : '')
        . "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"$b1\"\r\n";
    $c = "--$b1\r\nContent-Type: multipart/alternative; boundary=\"$b2\"\r\n\r\n"
        . "--$b2\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($texto))
        . "--$b2\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html))
        . "--$b2--\r\n";
    foreach ($adjuntos as $a) {
        $c .= "--$b1\r\nContent-Type: {$a['tipo']}; name=\"{$a['nombre']}\"\r\nContent-Transfer-Encoding: base64\r\n"
            . "Content-Disposition: attachment; filename=\"{$a['nombre']}\"\r\n\r\n" . chunk_split(base64_encode($a['datos']));
    }
    $c .= "--$b1--\r\n";
    return @mail($para, mb_encode_mimeheader($asunto, 'UTF-8', 'B', "\r\n"), $c, $h);
}

function ics_texto($t) {
    return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\\;', '\\,', '\\n', '\\n'], (string) $t);
}

// Evento de día completo: del día de recogida al de devolución (ambos incluidos).
function ics_evento($r, $auto) {
    $ini = date('Ymd', strtotime($r['desde']));
    $fin = date('Ymd', strtotime($r['hasta'] . ' +1 day'));
    $desc = 'Reserva ' . numero_reserva($r) . "\n" . 'Cliente: ' . $r['nombre'] . "\n" . 'Teléfono: ' . $r['telefono'] . "\n"
        . 'Correo: ' . $r['correo'] . "\n" . 'Total de la renta: ' . dinero($r['total']) . "\n" . 'Garantía pagada: ' . dinero($r['garantia']);
    return "BEGIN:VEVENT\r\nUID:" . $r['id'] . "@vivac.mx\r\nDTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n"
        . "DTSTART;VALUE=DATE:$ini\r\nDTEND;VALUE=DATE:$fin\r\n"
        . 'SUMMARY:' . ics_texto((($r['tipo'] ?? '') === 'bloqueo') ? 'Auto no disponible - ' . $r['nombre'] : 'Renta ' . $r['vehiculo'] . ' - ' . $r['nombre'] . ' (' . numero_reserva($r) . ')') . "\r\n"
        . 'DESCRIPTION:' . ics_texto($desc) . "\r\nLOCATION:Cancún\\, Quintana Roo\r\nEND:VEVENT\r\n";
}

function ics_calendario($eventos) {
    return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//vivac.mx//Renta de autos//ES\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\n"
        . "X-WR-CALNAME:Renta de autos vivac.mx\r\nX-WR-TIMEZONE:America/Cancun\r\n" . implode('', $eventos) . "END:VCALENDAR\r\n";
}
