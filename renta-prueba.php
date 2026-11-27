<?php
// Prueba del PDF y del correo de confirmación SIN hacer un pago. Requiere la clave_admin en la dirección (?k=...).
require __DIR__ . '/renta-comun.php';
require __DIR__ . '/renta-correos.php';
$clave = (string) (config()['clave_admin'] ?? '');
if ($clave === '' || !hash_equals($clave, (string) ($_GET['k'] ?? ''))) { http_response_code(404); exit; }
$auto = vehiculo();
$r = [
    'id' => 'prueba000000', 'seq' => 0, 'estado' => 'confirmada',
    'desde' => date('Y-m-d', strtotime('+10 days')), 'hasta' => date('Y-m-d', strtotime('+13 days')), 'dias' => 3,
    'garantia' => $auto['precio_dia'], 'total' => $auto['precio_dia'] * 3, 'vehiculo' => $auto['nombre'],
    'nombre' => 'Cliente de Prueba', 'correo' => 'cliente@ejemplo.com', 'telefono' => '+52 9981234567',
];
if (isset($_GET['enviar'])) {
    enviar_confirmaciones($r, true);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Correo de prueba enviado a ' . (config()['correo_aviso'] ?? 'info@vivac.mx') . '. Revisa también spam.';
    exit;
}
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="Reserva-prueba.pdf"');
echo pdf_reserva($r, $auto);
