<?php
// Valida la solicitud, aparta las fechas de forma temporal y crea el link de pago en Clip.
require __DIR__ . '/renta-comun.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { responder(['ok' => false, 'error' => 'Método no permitido.'], 405); }
if (!empty($_POST['web'])) { responder(['ok' => true, 'url' => '/']); } // campo trampa para bots
if (!clip_configurado()) {
    responder(['ok' => false, 'error' => 'El pago en línea aún no está disponible. Escríbenos por WhatsApp y apartamos tu auto.'], 503);
}

function campo($k, $max) {
    $v = isset($_POST[$k]) ? trim((string) $_POST[$k]) : '';
    return mb_substr(preg_replace('/[\r\n]+/', ' ', $v), 0, $max);
}

$desde = campo('desde', 10);
$hasta = campo('hasta', 10);
$nombre = campo('nombre', 100);
$correo = campo('correo', 150);
$clave = preg_replace('/\D/', '', campo('clave', 4));
$tel = preg_replace('/\D/', '', campo('telefono', 20));

if ($nombre === '') { responder(['ok' => false, 'error' => 'Escribe tu nombre.'], 422); }
if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) { responder(['ok' => false, 'error' => 'Escribe un correo electrónico válido.'], 422); }
if ($clave === '') { $clave = '52'; }
$largo = strlen($tel);
if ($largo < 6 || $largo > 10 || ($clave === '52' && $largo !== 10)) {
    responder(['ok' => false, 'error' => 'Revisa tu número de teléfono.'], 422);
}
if (!fecha_ok($desde) || !fecha_ok($hasta)) { responder(['ok' => false, 'error' => 'Elige fechas válidas.'], 422); }
if ($desde < date('Y-m-d')) { responder(['ok' => false, 'error' => 'La fecha de recogida no puede ser un día pasado.'], 422); }
if ($hasta < $desde) { responder(['ok' => false, 'error' => 'La devolución no puede ser anterior a la recogida.'], 422); }

$dias = max(1, (int) round((strtotime($hasta) - strtotime($desde)) / 86400));
if ($dias > 60) { responder(['ok' => false, 'error' => 'Para rentas de más de 60 días escríbenos por WhatsApp.'], 422); }

$auto = vehiculo();
$precio = $auto['precio_dia'];
if ($precio <= 0) { responder(['ok' => false, 'error' => 'Este auto no está disponible por ahora.'], 503); }
$total = $precio * $dias;

$cfg = config();
$sitio = rtrim((string) ($cfg['sitio'] ?? 'https://www.vivac.mx'), '/');
$retencion = max(10, min(120, (int) ($cfg['retencion_min'] ?? 30)));
$ip = substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . 'vivac'), 0, 12);

$res = con_reservas(function (&$lista) use ($desde, $hasta, $nombre, $correo, $clave, $tel, $dias, $precio, $total, $auto, $sitio, $retencion, $ip) {
    $activas = 0;
    foreach ($lista as $r) {
        if ($r['estado'] === 'pendiente' && ($r['ip'] ?? '') === $ip) { $activas++; }
    }
    if ($activas >= 3) {
        return ['error' => 'Tienes varios pagos pendientes. Espera unos minutos o escríbenos por WhatsApp.', 'codigo' => 429];
    }
    if (traslapa($lista, $desde, $hasta)) {
        return ['error' => 'Esas fechas ya no están disponibles. Elige otras.', 'codigo' => 409, 'ocupado' => true];
    }
    $id = bin2hex(random_bytes(6));
    $pago = [
        'amount' => (float) $precio,
        'currency' => 'MXN',
        'purchase_description' => 'Garantia renta ' . $auto['nombre'] . ' del ' . $desde . ' al ' . $hasta . ' (primer dia)',
        'redirection_url' => [
            'success' => $sitio . '/?renta=ok#renta',
            'error' => $sitio . '/?renta=error#renta',
            'default' => $sitio . '/#renta',
        ],
        'webhook_url' => $sitio . '/renta-webhook.php',
        'metadata' => ['reserva' => $id],
    ];
    list($codigo, $j) = clip('POST', '/v2/checkout', $pago);
    if ($codigo < 200 || $codigo >= 300 || empty($j['payment_request_url']) || empty($j['payment_request_id'])) {
        error_log('Clip: no se pudo crear el link de pago. HTTP ' . $codigo . ' ' . substr(json_encode($j), 0, 300));
        return ['error' => 'No pudimos iniciar el pago. Intenta de nuevo o escríbenos por WhatsApp.', 'codigo' => 502];
    }
    $lista[] = [
        'id' => $id, 'estado' => 'pendiente', 'desde' => $desde, 'hasta' => $hasta, 'dias' => $dias,
        'garantia' => $precio, 'total' => $total, 'vehiculo' => $auto['nombre'],
        'nombre' => $nombre, 'correo' => $correo, 'telefono' => '+' . $clave . ' ' . $tel,
        'clip_id' => $j['payment_request_id'], 'creado' => time(), 'expira' => time() + $retencion * 60, 'ip' => $ip,
    ];
    return ['url' => $j['payment_request_url']];
});

if (isset($res['error'])) {
    responder(['ok' => false, 'error' => $res['error'], 'ocupado' => !empty($res['ocupado'])], $res['codigo']);
}
responder(['ok' => true, 'url' => $res['url']]);
