<?php
// Recibe los avisos de Clip. No se confía en el contenido del aviso: se consulta a Clip
// el estado real del pago y solo entonces se confirma la reservación y se bloquean las fechas.
require __DIR__ . '/renta-comun.php';
require __DIR__ . '/renta-correos.php';

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
        $max = 0;
        foreach ($lista as $otro) {
            if (!empty($otro['seq'])) { $max = max($max, (int) $otro['seq']); }
        }
        $lista[$i]['estado'] = 'confirmada';
        $lista[$i]['pagado'] = time();
        $lista[$i]['seq'] = $max + 1;
        return ['tipo' => 'ok', 'r' => $lista[$i]];
    }
    return null;
});

if ($aviso) {
    if ($aviso['tipo'] === 'ok') {
        enviar_confirmaciones($aviso['r']);
    } else {
        $r = $aviso['r'];
        $staff = (string) (config()['correo_aviso'] ?? 'info@vivac.mx');
        enviar($staff, 'URGENTE: pago recibido sin fechas disponibles ' . strtoupper($r['id']),
            "Clip confirmó un pago, pero las fechas ya fueron tomadas por otra reservación. Hay que contactar al cliente y resolver (otras fechas o reembolso desde Clip).\n\n"
            . cuerpo_texto(detalle_cliente($r)) . 'Fechas: ' . fecha_larga($r['desde']) . ' al ' . fecha_larga($r['hasta']) . "\nPago Clip: " . $r['clip_id'] . "\n", $r['correo']);
    }
}
responder(['ok' => true]);
