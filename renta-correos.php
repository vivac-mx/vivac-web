<?php
// Arma y envía los correos de "Reserva confirmada" (cliente y vivac.mx) con PDF adjunto.
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'renta-correos.php') { http_response_code(404); exit; }
require_once __DIR__ . '/renta-comun.php';
require_once __DIR__ . '/renta-pdf.php';

function h_esc($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }

function detalle_reserva($r) {
    return [
        ['Número de reserva', numero_reserva($r)], ['Vehículo', $r['vehiculo']], ['Sucursal', 'Cancún, Quintana Roo'],
        ['Recogida', fecha_larga($r['desde'])], ['Devolución', fecha_larga($r['hasta'])], ['Días de renta', (string) $r['dias']],
        ['Total de la renta', dinero($r['total'])], ['Garantía pagada (primer día)', dinero($r['garantia'])],
    ];
}

function detalle_cliente($r) {
    return [['Nombre', $r['nombre']], ['Correo', $r['correo']], ['Teléfono', $r['telefono']]];
}

function cuerpo_texto($filas) {
    $t = '';
    foreach ($filas as $f) { $t .= $f[0] . ': ' . $f[1] . "\n"; }
    return $t;
}

function cuerpo_html($titulo, $intro, $bloques, $pie) {
    $h = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:0 auto;color:#111">'
        . '<table width="100%" cellpadding="0" cellspacing="0"><tr><td height="6" width="33%" bgcolor="#1E7A02"></td><td bgcolor="#ffffff"></td><td width="33%" bgcolor="#880808"></td></tr></table>'
        . '<p style="font-size:20px;font-weight:bold;margin:18px 0 0"><span style="color:#880808">vi</span><span style="color:#1E7A02">vac</span>.mx</p>'
        . '<h1 style="color:#880808;font-size:22px;margin:16px 0 8px">' . h_esc($titulo) . '</h1>'
        . '<p style="font-size:15px;line-height:1.5">' . h_esc($intro) . '</p>';
    foreach ($bloques as $nombre => $filas) {
        $h .= '<h2 style="font-size:13px;color:#880808;text-transform:uppercase;margin:20px 0 4px">' . h_esc($nombre) . '</h2>'
            . '<table width="100%" cellpadding="7" cellspacing="0" style="border-collapse:collapse;font-size:15px">';
        foreach ($filas as $f) {
            $h .= '<tr><td width="42%" style="color:#555;border-bottom:1px solid #e5e5e5">' . h_esc($f[0])
                . '</td><td style="border-bottom:1px solid #e5e5e5"><b>' . h_esc($f[1]) . '</b></td></tr>';
        }
        $h .= '</table>';
    }
    return $h . '<p style="font-size:14px;color:#555;line-height:1.5;margin-top:22px">' . h_esc($pie) . '</p>'
        . '<p style="font-size:13px;color:#777">WhatsApp +52 998 339 4281 · info@vivac.mx · www.vivac.mx</p></div>';
}

// Envía la confirmación al cliente y a vivac.mx. Con $soloStaff = true solo se manda a vivac.mx (pruebas).
function enviar_confirmaciones($r, $soloStaff = false) {
    $auto = vehiculo();
    $num = numero_reserva($r);
    $staff = (string) (config()['correo_aviso'] ?? 'info@vivac.mx');
    $adj = [];
    try {
        $adj[] = ['nombre' => "Reserva-$num.pdf", 'tipo' => 'application/pdf', 'datos' => pdf_reserva($r, $auto)];
    } catch (Throwable $e) {
        error_log('PDF de reserva: ' . $e->getMessage());
    }
    $res = detalle_reserva($r);
    $cli = detalle_cliente($r);

    if (!$soloStaff) {
        $texto = "Hola {$r['nombre']},\n\n¡Tu reserva está confirmada!\n\n" . cuerpo_texto($res) . "\n"
            . ($adj ? "Adjuntamos tu comprobante de reserva en PDF.\n" : '')
            . "Un asesor de vivac.mx te contactará por WhatsApp para coordinar la entrega.\nWhatsApp +52 998 339 4281 · info@vivac.mx\n";
        $html = cuerpo_html('Reserva confirmada', 'Hola ' . $r['nombre'] . ', ¡tu reserva está confirmada!', ['Datos de la reserva' => $res],
            ($adj ? 'Adjuntamos tu comprobante de reserva en PDF. ' : '') . 'Un asesor de vivac.mx te contactará por WhatsApp para coordinar la entrega.');
        enviar_completo($r['correo'], "Reserva confirmada $num - vivac.mx", $texto, $html, $adj);
    }

    $adjStaff = $adj;
    $adjStaff[] = ['nombre' => "Reserva-$num.ics", 'tipo' => 'text/calendar; charset=UTF-8; method=PUBLISH', 'datos' => ics_calendario([ics_evento($r, $auto)])];
    $texto = "Se confirmó el pago de la garantía y las fechas quedaron bloqueadas.\n\n" . cuerpo_texto($res) . "\nCLIENTE\n" . cuerpo_texto($cli)
        . "\nSe adjunta el PDF y un archivo .ics para agregar la reserva a Google Calendar.\n";
    $html = cuerpo_html("Reserva confirmada $num", 'Se confirmó el pago de la garantía y las fechas quedaron bloqueadas.',
        ['Datos de la reserva' => $res, 'Datos del cliente' => $cli],
        'Se adjunta el PDF de la reserva y un archivo .ics para agregarla a Google Calendar.');
    enviar_completo($staff, "Reserva confirmada $num - {$r['nombre']}", $texto, $html, $adjStaff, $r['correo']);
}
