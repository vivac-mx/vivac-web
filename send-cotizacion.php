<?php
// Recibe el formulario de cotización de vivac.mx y lo envía por correo.
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Cancun');

const DESTINO = 'info@vivac.mx';
const REMITENTE = 'no-reply@vivac.mx';

function responder($ok, $error = null) {
    echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

function linea($clave, $max = 200) {
    $v = isset($_POST[$clave]) ? trim((string) $_POST[$clave]) : '';
    $v = preg_replace('/[\r\n]+/', ' ', $v);
    return mb_substr($v, 0, $max);
}

function fecha_valida($texto) {
    $d = DateTime::createFromFormat('!Y-m-d', $texto);
    return ($d && $d->format('Y-m-d') === $texto) ? $d : null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    responder(false, 'Método no permitido.');
}

// Campo trampa para bots: si viene lleno, se responde "ok" sin enviar nada.
if (!empty($_POST['web'])) {
    responder(true);
}

$nombre = linea('nombre', 100);
$correo = linea('correo', 150);
$telefono = linea('telefono', 40);
$origen = linea('origen', 100);
$destino = linea('destino', 60);
$presupuesto = linea('presupuesto', 60);
$salida = linea('salida', 10);
$regreso = linea('regreso', 10);
$adultos = (int) linea('adultos', 3);
$menores = (int) linea('menores', 3);
$notas = isset($_POST['notas']) ? trim((string) $_POST['notas']) : '';
$notas = mb_substr(str_replace("\r", '', $notas), 0, 1000);

if ($nombre === '' || $origen === '') {
    responder(false, 'Escribe tu nombre y tu ciudad de salida.');
}
if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    responder(false, 'Escribe un correo electrónico válido para poder responderte.');
}

$hoy = new DateTime('today');
$dSalida = fecha_valida($salida);
$dRegreso = fecha_valida($regreso);
if (!$dSalida || !$dRegreso) {
    responder(false, 'Elige fechas de salida y de regreso válidas.');
}
if ($dSalida < $hoy) {
    responder(false, 'La fecha de salida no puede ser un día pasado.');
}
if ($dRegreso < $dSalida) {
    responder(false, 'La fecha de regreso no puede ser anterior a la de salida.');
}
if ($adultos < 1 || $adultos > 50 || $menores < 0 || $menores > 50) {
    responder(false, 'Revisa el número de viajeros.');
}

$cuerpo = "Nueva solicitud de cotización desde vivac.mx\n\n"
    . "Nombre: $nombre\n"
    . "Correo: $correo\n"
    . "WhatsApp o teléfono: " . ($telefono !== '' ? $telefono : 'no indicado') . "\n"
    . "Ciudad de salida: $origen\n"
    . "Destino: $destino\n"
    . "Fechas: $salida al $regreso\n"
    . "Viajeros: $adultos adultos, $menores menores\n"
    . "Presupuesto aproximado: " . ($presupuesto !== '' ? $presupuesto : 'por definir') . "\n"
    . "Comentarios: " . ($notas !== '' ? $notas : 'ninguno') . "\n";

$asunto = mb_encode_mimeheader('Nueva cotización de ' . $nombre, 'UTF-8', 'B', "\r\n");
$cabeceras = "From: vivac.mx <" . REMITENTE . ">\r\n"
    . "Reply-To: $correo\r\n"
    . "MIME-Version: 1.0\r\n"
    . "Content-Type: text/plain; charset=UTF-8\r\n";

if (!mail(DESTINO, $asunto, $cuerpo, $cabeceras)) {
    http_response_code(500);
    responder(false, 'No pudimos enviar tu solicitud. Escríbenos por WhatsApp y te atendemos de inmediato.');
}

responder(true);
