<?php
// Recibe los avisos de Clip. No se confía en el contenido del aviso: se consulta a Clip
// el estado real del pago y solo entonces se confirma la reservación y se bloquean las fechas.
require __DIR__ . '/renta-comun.php';

$entrada = json_decode((string) file_get_contents('php://input'), true);
$pid = is_array($entrada) ? (string) ($entrada['payment_request_id'] ?? '') : '';
if (!preg_match('/^[0-9a-fA-F-]{20,40}$/', $pid)) { responder(['ok' => true]); }

list($codigo, $j) = clip('GET', '/v2/checkout/' . $pid);
$pagado = $codigo === 200 && is_array($j) && in_array((string) ($j['status'] ?? ''), ['CHECKOUT_COMPLETED', 'COMPLETED'], true);
if (!$pagado) { responder(['ok' => true]); }

$aviso = con_reservas(function (&$lista) use ($pid) {
    foreach ($lista as $i => $r) {
        if (($r['clip_id'] ?? '') !== $pid) { continue; }
        if ($r['estado'] === 'confirmada' || $r['estado'] === 'pago_sin_fechas') { return null; }
        if ($r['estado'] === 'expirada') {
            // El pago llegó después de vencer el apartado: se revisa que las fechas sigan libres.
            $otras = $lista;
            unset($otras[$i]);
            if (traslapa($otras, $r['desde'], $r['hasta'])) {
                $lista[$i]['estado'] = 'pago_sin_fechas';
                return ['tipo' => 'conflicto', 'r' => $lista[$i]];
            }
        }
        $lista[$i]['estado'] = 'confirmada';
        $lista[$i]['pagado'] = time();
        return ['tipo' => 'ok', 'r' => $lista[$i]];
    }
    return null;
});

if ($aviso) {
    $r = $aviso['r'];
    $folio = strtoupper($r['id']);
    $f1 = date('d/m/Y', strtotime($r['desde']));
    $f2 = date('d/m/Y', strtotime($r['hasta']));
    $resumen = "Folio: $folio\nVehículo: {$r['vehiculo']}\nRecogida: $f1\nDevolución: $f2 ({$r['dias']} días)\n"
        . "Total de la renta: $" . number_format($r['total'], 2) . " MXN\nGarantía pagada (primer día): $" . number_format($r['garantia'], 2) . " MXN\n"
        . "Cliente: {$r['nombre']}\nCorreo: {$r['correo']}\nTeléfono: {$r['telefono']}\nPago Clip: {$r['clip_id']}\n";
    $staff = (string) (config()['correo_aviso'] ?? 'info@vivac.mx');
    if ($aviso['tipo'] === 'ok') {
        enviar($staff, "Reserva de auto confirmada $folio", "Se confirmó el pago de una reservación de auto. Las fechas quedaron bloqueadas.\n\n" . $resumen, $r['correo']);
        enviar($r['correo'], "Tu reservación de auto en Cancún $folio",
            "Hola {$r['nombre']},\n\nRecibimos tu pago de garantía y tus fechas quedaron apartadas.\n\n" . $resumen
            . "\nUn asesor de vivac.mx te contactará por WhatsApp para coordinar la entrega.\nWhatsApp +52 998 339 4281 · info@vivac.mx\n");
    } else {
        enviar($staff, "URGENTE: pago recibido sin fechas disponibles $folio",
            "Clip confirmó un pago, pero las fechas ya fueron tomadas por otra reservación. Hay que contactar al cliente y resolver (otras fechas o reembolso desde tu cuenta de Clip).\n\n" . $resumen, $r['correo']);
    }
}
responder(['ok' => true]);
