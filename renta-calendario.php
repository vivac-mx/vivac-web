<?php
// Calendario en formato iCalendar con las reservas confirmadas, para suscribirse desde Google Calendar.
require __DIR__ . '/renta-comun.php';
$clave = (string) (config()['clave_admin'] ?? '');
if ($clave === '' || !hash_equals($clave, (string) ($_GET['k'] ?? ''))) { http_response_code(404); exit; }
$auto = vehiculo();
$eventos = con_reservas(function (&$lista) use ($auto) {
    $s = [];
    foreach ($lista as $r) {
        if ($r['estado'] === 'confirmada') { $s[] = ics_evento($r, $auto); }
    }
    return $s;
});
header('Content-Type: text/calendar; charset=utf-8');
header('Cache-Control: no-store');
echo ics_calendario($eventos);
