<?php
// Devuelve las fechas ya apartadas (reservas confirmadas y pagos en curso).
require __DIR__ . '/renta-comun.php';
$rangos = con_reservas(function (&$lista) {
    $salida = [];
    foreach ($lista as $r) {
        if (ocupa($r)) { $salida[] = ['desde' => $r['desde'], 'hasta' => $r['hasta']]; }
    }
    return $salida;
});
responder(['ok' => true, 'rangos' => $rangos, 'pagos' => clip_configurado()]);
