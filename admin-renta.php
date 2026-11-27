<?php
// Panel privado de vivac.mx para gestionar las reservas de renta de auto.
require __DIR__ . '/renta-comun.php';
require __DIR__ . '/renta-correos.php';

$nonce = base64_encode(random_bytes(12));
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'nonce-$nonce'; img-src data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

// ---------- Límite de intentos de contraseña ----------
function intentos($modo) {
    $f = dirname(ruta_datos()) . '/panel-intentos.json';
    $fh = @fopen($f, 'c+');
    if (!$fh) { return 0; }
    flock($fh, LOCK_EX);
    $d = json_decode((string) stream_get_contents($fh), true);
    if (!is_array($d)) { $d = []; }
    $ih = substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . 'panel'), 0, 16);
    $ahora = time();
    foreach ($d as $k => $v) {
        if ($v['t'] < $ahora - 900) { unset($d[$k]); }
    }
    if ($modo === 'fallo') { $d[$ih] = ['n' => ($d[$ih]['n'] ?? 0) + 1, 't' => $ahora]; }
    if ($modo === 'ok') { unset($d[$ih]); }
    $n = isset($d[$ih]) ? (int) $d[$ih]['n'] : 0;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($d));
    flock($fh, LOCK_UN);
    fclose($fh);
    return $n;
}

// ---------- Sesión ----------
session_name('vivac_panel');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Strict']);
session_start();

if (isset($_GET['salir'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: admin-renta.php');
    exit;
}

$clave = (string) (config()['panel_clave'] ?? '');
$logueado = !empty($_SESSION['ok']) && (time() - (int) ($_SESSION['t'] ?? 0)) < 7200;

if (!$logueado) {
    $error = '';
    if ($clave === '' || $clave === 'ESCRIBE_AQUI_LA_CONTRASENA_DEL_PANEL') {
        $error = 'El panel aún no está configurado. Agrega panel_clave en renta-config.php.';
    } elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['clave'])) {
        if (intentos('leer') >= 5) {
            $error = 'Demasiados intentos. Espera 15 minutos.';
        } elseif (hash_equals($clave, (string) $_POST['clave'])) {
            intentos('ok');
            session_regenerate_id(true);
            $_SESSION['ok'] = true;
            $_SESSION['t'] = time();
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
            header('Location: admin-renta.php');
            exit;
        } else {
            intentos('fallo');
            $error = 'Contraseña incorrecta.';
        }
    }
    ?><!DOCTYPE html>
<html lang="es-MX"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Panel vivac.mx</title>
<style>body{font:16px/1.5 system-ui,sans-serif;background:#EAF3E6;display:grid;place-items:center;min-height:100vh;margin:0}form{background:#fff;padding:32px;border-radius:18px;width:min(360px,92vw);display:grid;gap:14px;border-top:6px solid #880808}h1{font-size:22px;margin:0}input{font:inherit;padding:11px 12px;border:1.5px solid #999;border-radius:10px}button{font:600 16px system-ui;background:#880808;color:#fff;border:0;border-radius:999px;padding:12px;cursor:pointer}.e{color:#880808;font-weight:600}</style></head>
<body><form method="post" autocomplete="off"><h1>Panel de renta · vivac.mx</h1>
<?php if ($error !== '') { ?><p class="e"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php } ?>
<label>Contraseña<br><input type="password" name="clave" autofocus required style="width:100%;box-sizing:border-box"></label><button type="submit">Entrar</button></form></body></html>
<?php
    exit;
}
$_SESSION['t'] = time();
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }

// ---------- Ayudas ----------
function csrf_ok() { return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string) $_POST['csrf']); }
function aviso($t, $tipo = 'ok') { $_SESSION['aviso'] = [$t, $tipo]; }
function volver($q = '') { header('Location: admin-renta.php' . $q); exit; }
function todas() { return con_reservas(function (&$l) { return $l; }); }
function es_bloqueo($r) { return ($r['tipo'] ?? '') === 'bloqueo'; }
function actualizar($id, $fn) {
    return con_reservas(function (&$lista) use ($id, $fn) {
        foreach ($lista as $i => $r) {
            if ($r['id'] === $id) { return $fn($lista, $i); }
        }
        return 'No se encontró la reservación.';
    });
}
function estado_info($r) {
    if (es_bloqueo($r)) { return ['Bloqueo', 'c-blq']; }
    switch ($r['estado']) {
        case 'confirmada':
            $op = $r['operacion'] ?? 'por_entregar';
            if ($op === 'entregado') { return ['En renta', 'c-ren']; }
            if ($op === 'devuelto') { return ['Devuelto', 'c-dev']; }
            return ['Por entregar', 'c-ent'];
        case 'pendiente': return ['Pago pendiente', 'c-pen'];
        case 'cancelada': return ['Cancelada', 'c-can'];
        case 'expirada': return ['Expirada', 'c-can'];
        case 'pago_sin_fechas': return ['Pago sin fechas', 'c-err'];
    }
    return [(string) $r['estado'], 'c-dev'];
}
function h($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function corto($t, $n) { return mb_strlen($t, 'UTF-8') > $n ? mb_substr($t, 0, $n - 1, 'UTF-8') . '…' : $t; }
function fh($ts) { return $ts ? date('d/m/Y H:i', (int) $ts) : '—'; }

$hoy = date('Y-m-d');

// ---------- Descarga de PDF ----------
if (isset($_GET['pdf'])) {
    $idp = preg_replace('/[^0-9a-f]/', '', (string) $_GET['pdf']);
    $rp = null;
    foreach (todas() as $x) { if ($x['id'] === $idp) { $rp = $x; } }
    if (!$rp || es_bloqueo($rp) || $rp['estado'] !== 'confirmada') { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'No disponible.'; exit; }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="Reserva-' . numero_reserva($rp) . '.pdf"');
    echo pdf_reserva($rp, vehiculo());
    exit;
}

// ---------- Acciones ----------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_ok()) { aviso('La sesión expiró. Intenta de nuevo.', 'err'); volver(); }
    $accion = (string) ($_POST['accion'] ?? '');
    $id = preg_replace('/[^0-9a-f]/', '', (string) ($_POST['id'] ?? ''));
    $q = $id !== '' ? '?ver=' . $id : '';

    if ($accion === 'cancelar') {
        $nota = trim(mb_substr((string) ($_POST['nota'] ?? ''), 0, 300, 'UTF-8'));
        $res = actualizar($id, function (&$lista, $i) use ($nota) {
            if ($lista[$i]['estado'] !== 'confirmada' && $lista[$i]['estado'] !== 'pendiente') { return 'Esa reservación ya no está activa.'; }
            $lista[$i]['estado'] = 'cancelada';
            $lista[$i]['cancelada'] = time();
            if ($nota !== '') { $lista[$i]['nota_cancelacion'] = $nota; }
            return 'ok';
        });
        aviso($res === 'ok' ? 'Listo: las fechas quedaron libres. Si el cliente pagó, recuerda reembolsar desde tu cuenta de Clip.' : $res, $res === 'ok' ? 'ok' : 'err');
        volver($q);
    }
    if ($accion === 'operacion') {
        $op = (string) ($_POST['op'] ?? '');
        if (!in_array($op, ['por_entregar', 'entregado', 'devuelto'], true)) { volver($q); }
        $res = actualizar($id, function (&$lista, $i) use ($op) { $lista[$i]['operacion'] = $op; $lista[$i]['op_fecha'] = time(); return 'ok'; });
        aviso($res === 'ok' ? 'Estado actualizado.' : $res, $res === 'ok' ? 'ok' : 'err');
        volver($q);
    }
    if ($accion === 'nota') {
        $nota = trim(mb_substr((string) ($_POST['nota'] ?? ''), 0, 500, 'UTF-8'));
        $res = actualizar($id, function (&$lista, $i) use ($nota) { $lista[$i]['nota'] = $nota; return 'ok'; });
        aviso($res === 'ok' ? 'Nota guardada.' : $res, $res === 'ok' ? 'ok' : 'err');
        volver($q);
    }
    if ($accion === 'reenviar') {
        $rr = null;
        foreach (todas() as $x) { if ($x['id'] === $id) { $rr = $x; } }
        if ($rr && !es_bloqueo($rr) && $rr['estado'] === 'confirmada') {
            enviar_confirmaciones($rr);
            aviso('Correos de confirmación reenviados al cliente y a vivac.mx.');
        } else {
            aviso('No se puede reenviar esta reservación.', 'err');
        }
        volver($q);
    }
    if ($accion === 'bloquear') {
        $d = (string) ($_POST['desde'] ?? '');
        $hh = (string) ($_POST['hasta'] ?? '');
        $motivo = trim(mb_substr((string) ($_POST['motivo'] ?? ''), 0, 80, 'UTF-8'));
        if ($motivo === '') { $motivo = 'Bloqueo manual'; }
        if (!fecha_ok($d) || !fecha_ok($hh) || $hh < $d) { aviso('Revisa las fechas del bloqueo.', 'err'); volver(); }
        $auto = vehiculo();
        $res = con_reservas(function (&$lista) use ($d, $hh, $motivo, $auto) {
            if (traslapa($lista, $d, $hh)) { return 'Esas fechas ya tienen una reservación o un bloqueo.'; }
            $dias = max(1, (int) round((strtotime($hh) - strtotime($d)) / 86400));
            $lista[] = [
                'id' => bin2hex(random_bytes(6)), 'estado' => 'confirmada', 'tipo' => 'bloqueo', 'desde' => $d, 'hasta' => $hh,
                'dias' => $dias, 'garantia' => 0, 'total' => 0, 'vehiculo' => $auto['nombre'], 'nombre' => $motivo,
                'correo' => '', 'telefono' => '', 'creado' => time(),
            ];
            return 'ok';
        });
        aviso($res === 'ok' ? 'Fechas bloqueadas: nadie podrá reservarlas en el sitio.' : $res, $res === 'ok' ? 'ok' : 'err');
        volver();
    }
    volver();
}

// ---------- Datos para mostrar ----------
$lista = todas();
$flash = $_SESSION['aviso'] ?? null;
unset($_SESSION['aviso']);

$mes = (isset($_GET['mes']) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $_GET['mes'])) ? (string) $_GET['mes'] : date('Y-m');
$filtro = (string) ($_GET['f'] ?? 'proximas');
if (!in_array($filtro, ['proximas', 'encurso', 'pasadas', 'pendientes', 'canceladas', 'todas'], true)) { $filtro = 'proximas'; }
$busca = trim((string) ($_GET['q'] ?? ''));
$ver = preg_replace('/[^0-9a-f]/', '', (string) ($_GET['ver'] ?? ''));
$det = null;
foreach ($lista as $x) { if ($ver !== '' && $x['id'] === $ver) { $det = $x; } }

$conf = [];
$cal = [];
foreach ($lista as $r) {
    if ($r['estado'] === 'confirmada') { $conf[] = $r; }
    if ($r['estado'] === 'confirmada' || $r['estado'] === 'pendiente') { $cal[] = $r; }
}
$en7 = date('Y-m-d', strtotime('+7 days'));
$k_ent = $k_dev = $k_ren = $k_prox = $k_pen = 0;
foreach ($conf as $r) {
    if (es_bloqueo($r)) { continue; }
    $op = $r['operacion'] ?? 'por_entregar';
    if ($r['desde'] === $hoy && $op === 'por_entregar') { $k_ent++; }
    if ($r['hasta'] === $hoy && $op !== 'devuelto') { $k_dev++; }
    if ($r['desde'] <= $hoy && $r['hasta'] >= $hoy && $op !== 'devuelto') { $k_ren++; }
    if ($r['desde'] > $hoy && $r['desde'] <= $en7) { $k_prox++; }
}
foreach ($lista as $r) { if ($r['estado'] === 'pendiente') { $k_pen++; } }

$filas = [];
foreach ($lista as $r) {
    $est = $r['estado'];
    $ok = false;
    if ($filtro === 'todas') { $ok = true; }
    elseif ($filtro === 'proximas') { $ok = $est === 'confirmada' && $r['hasta'] >= $hoy; }
    elseif ($filtro === 'encurso') { $ok = $est === 'confirmada' && $r['desde'] <= $hoy && $r['hasta'] >= $hoy; }
    elseif ($filtro === 'pasadas') { $ok = $est === 'confirmada' && $r['hasta'] < $hoy; }
    elseif ($filtro === 'pendientes') { $ok = $est === 'pendiente'; }
    elseif ($filtro === 'canceladas') { $ok = in_array($est, ['cancelada', 'expirada', 'pago_sin_fechas'], true); }
    if ($ok && $busca !== '') {
        $pajar = mb_strtolower(implode(' ', [$r['nombre'], $r['correo'], $r['telefono'], numero_reserva($r)]), 'UTF-8');
        $ok = mb_strpos($pajar, mb_strtolower($busca, 'UTF-8'), 0, 'UTF-8') !== false;
    }
    if ($ok) { $filas[] = $r; }
}
usort($filas, function ($a, $b) use ($filtro) {
    return $filtro === 'pasadas' || $filtro === 'canceladas' || $filtro === 'todas' ? strcmp($b['desde'], $a['desde']) : strcmp($a['desde'], $b['desde']);
});

$p = strtotime($mes . '-01');
$diasMes = (int) date('t', $p);
$off = (int) date('N', $p) - 1;
$mAnt = date('Y-m', strtotime('-1 month', $p));
$mSig = date('Y-m', strtotime('+1 month', $p));
$meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$tituloMes = $meses[(int) date('n', $p) - 1] . ' ' . date('Y', $p);
$csrf = $_SESSION['csrf'];
$auto = vehiculo();
?><!DOCTYPE html>
<html lang="es-MX"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Panel de renta · vivac.mx</title>
<style>
*{box-sizing:border-box}body{margin:0;font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f7f2;color:#111}
header{background:#fff;border-bottom:1px solid #ddd;padding:10px 20px;display:flex;gap:16px;align-items:center;flex-wrap:wrap;border-top:6px solid #880808}
header b{font-size:18px}header span.v{color:#1E7A02}header a{color:#111;font-weight:600;text-decoration:none}header .sp{flex:1}
main{max-width:1180px;margin:0 auto;padding:20px}
h2{font-size:18px;margin:26px 0 10px}
.aviso{padding:12px 16px;border-radius:10px;background:#fff;border-left:6px solid #1E7A02;margin-bottom:16px;font-weight:600}.aviso.err{border-color:#880808;color:#880808}
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}
.kpi{background:#fff;border-radius:14px;padding:14px 16px;border:1px solid #e3e3e3}.kpi b{display:block;font-size:28px;line-height:1.1}.kpi span{color:#555;font-size:13px}
.card{background:#fff;border:1px solid #e3e3e3;border-radius:14px;padding:16px}
.nav{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}.nav a{color:#880808;font-weight:700;text-decoration:none;padding:4px 10px}
.calw{overflow-x:auto}.calg{display:grid;grid-template-columns:repeat(7,minmax(92px,1fr));gap:4px;min-width:660px}
.dow{font-size:12px;font-weight:700;color:#555;text-align:center}
.d{min-height:84px;background:#fbfbfb;border:1px solid #e6e6e6;border-radius:8px;padding:4px;font-size:12px}.d.hoy{outline:2px solid #1E7A02;background:#fff}.d i{font-style:normal;color:#777}
.chip{display:block;margin-top:3px;padding:2px 5px;border-radius:5px;color:#fff;text-decoration:none;font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.c-ent{background:#1E7A02}.c-ren{background:#880808}.c-dev{background:#777}.c-pen{background:#b45309}.c-can{background:#bbb;text-decoration:line-through}.c-blq{background:#111}.c-err{background:#fff;color:#880808;border:1px solid #880808}
.tag{display:inline-block;padding:2px 9px;border-radius:999px;color:#fff;font-size:12px;font-weight:600}
.tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}.tabs a{padding:6px 12px;border-radius:999px;background:#fff;border:1px solid #d5d5d5;color:#111;text-decoration:none;font-size:14px}.tabs a.on{background:#880808;color:#fff;border-color:#880808}
table{width:100%;border-collapse:collapse;background:#fff;border-radius:12px;overflow:hidden;font-size:14px}th,td{padding:9px 10px;text-align:left;border-bottom:1px solid #eee;vertical-align:top}th{background:#EAF3E6;font-size:12px;text-transform:uppercase;letter-spacing:.03em}.tw{overflow-x:auto}
input,select,textarea,button{font:inherit}input,textarea,select{padding:8px 10px;border:1.5px solid #aaa;border-radius:8px;width:100%}textarea{min-height:70px}
.btn{display:inline-block;background:#880808;color:#fff;border:0;border-radius:999px;padding:9px 16px;font-weight:600;cursor:pointer;text-decoration:none}.btn.v{background:#1E7A02}.btn.g{background:#fff;color:#111;border:1.5px solid #bbb}.btn.n{background:#111}
.fila{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;align-items:end}
.det{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:6px 24px;margin:12px 0}.det div{border-bottom:1px solid #eee;padding:4px 0}.det small{color:#666;display:block}
.acc{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
details{background:#fff;border:1px solid #e3e3e3;border-radius:14px;padding:12px 16px}summary{cursor:pointer;font-weight:700}
</style></head>
<body>
<header><b><span class="v">vivac</span>.mx · Panel de renta</b><a href="admin-renta.php">Inicio</a><span class="sp"></span><span style="color:#555"><?= h($auto['nombre']) ?></span><a href="admin-renta.php?salir=1">Cerrar sesión</a></header>
<main>
<?php if ($flash) { ?><div class="aviso <?= $flash[1] === 'err' ? 'err' : '' ?>"><?= h($flash[0]) ?></div><?php } ?>

<div class="kpis">
<div class="kpi"><b><?= $k_ent ?></b><span>Entregas hoy</span></div>
<div class="kpi"><b><?= $k_dev ?></b><span>Devoluciones hoy</span></div>
<div class="kpi"><b><?= $k_ren ?></b><span>En renta ahora</span></div>
<div class="kpi"><b><?= $k_prox ?></b><span>Entregas en 7 días</span></div>
<div class="kpi"><b><?= $k_pen ?></b><span>Pagos pendientes</span></div>
</div>

<?php if ($det) {
    list($etq, $cls) = estado_info($det);
    $bl = es_bloqueo($det);
    $tel = preg_replace('/\D/', '', (string) ($det['telefono'] ?? ''));
    $activa = $det['estado'] === 'confirmada' || $det['estado'] === 'pendiente';
?>
<h2>Detalle · <?= h(numero_reserva($det)) ?></h2>
<div class="card">
<span class="tag <?= $cls ?>"><?= h($etq) ?></span>
<div class="det">
<div><small>Cliente / motivo</small><b><?= h($det['nombre']) ?></b></div>
<?php if (!$bl) { ?>
<div><small>Correo</small><?= h($det['correo']) ?></div>
<div><small>Teléfono</small><?= h($det['telefono']) ?></div>
<?php } ?>
<div><small>Vehículo</small><?= h($det['vehiculo']) ?></div>
<div><small>Recogida</small><?= h(fecha_larga($det['desde'])) ?></div>
<div><small>Devolución</small><?= h(fecha_larga($det['hasta'])) ?></div>
<div><small>Días</small><?= h($det['dias']) ?></div>
<?php if (!$bl) { ?>
<div><small>Total de la renta</small><?= h(dinero($det['total'])) ?></div>
<div><small>Garantía (primer día)</small><?= h(dinero($det['garantia'])) ?></div>
<div><small>Pago en Clip (para reembolsos)</small><?= h($det['clip_id'] ?? '—') ?></div>
<?php } ?>
<div><small>Creada</small><?= h(fh($det['creado'] ?? 0)) ?></div>
<?php if (!empty($det['pagado'])) { ?><div><small>Pagada</small><?= h(fh($det['pagado'])) ?></div><?php } ?>
<?php if (!empty($det['cancelada'])) { ?><div><small>Cancelada</small><?= h(fh($det['cancelada'])) ?><?= !empty($det['nota_cancelacion']) ? ' · ' . h($det['nota_cancelacion']) : '' ?></div><?php } ?>
</div>

<div class="acc">
<?php if (!$bl && $det['estado'] === 'confirmada') { ?><a class="btn n" href="admin-renta.php?pdf=<?= h($det['id']) ?>">Descargar PDF</a><?php } ?>
<?php if (!$bl && $tel !== '') { ?><a class="btn v" href="https://wa.me/<?= h($tel) ?>" target="_blank" rel="noopener noreferrer">Escribir por WhatsApp</a><?php } ?>
<?php if (!$bl && $det['estado'] === 'confirmada') { ?>
<form method="post" data-confirmar="¿Reenviar los correos de confirmación al cliente y a vivac.mx?"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="accion" value="reenviar"><input type="hidden" name="id" value="<?= h($det['id']) ?>"><button class="btn g" type="submit">Reenviar correos</button></form>
<?php } ?>
</div>

<?php if (!$bl && $det['estado'] === 'confirmada') { ?>
<h2 style="margin-top:20px">Entrega y devolución</h2>
<div class="acc" style="margin-top:0">
<?php foreach (['por_entregar' => 'Por entregar', 'entregado' => 'Marcar como entregado', 'devuelto' => 'Marcar como devuelto'] as $v => $t) { ?>
<form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="accion" value="operacion"><input type="hidden" name="id" value="<?= h($det['id']) ?>"><input type="hidden" name="op" value="<?= $v ?>"><button class="btn <?= ($det['operacion'] ?? 'por_entregar') === $v ? 'v' : 'g' ?>" type="submit"><?= $t ?></button></form>
<?php } ?>
</div>
<?php } ?>

<?php if (!$bl) { ?>
<form method="post" style="margin-top:16px"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="accion" value="nota"><input type="hidden" name="id" value="<?= h($det['id']) ?>">
<label>Nota interna (solo la ves tú)<textarea name="nota" maxlength="500"><?= h($det['nota'] ?? '') ?></textarea></label><div class="acc"><button class="btn g" type="submit">Guardar nota</button></div></form>
<?php } ?>

<?php if ($activa) { ?>
<form method="post" style="margin-top:16px" data-confirmar="<?= $bl ? '¿Quitar este bloqueo y liberar las fechas?' : '¿Cancelar esta reservación y liberar las fechas? El reembolso, si corresponde, se hace desde Clip.' ?>">
<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="accion" value="cancelar"><input type="hidden" name="id" value="<?= h($det['id']) ?>">
<?php if (!$bl) { ?><label>Motivo de la cancelación (opcional)<input name="nota" maxlength="300"></label><?php } ?>
<div class="acc"><button class="btn" type="submit"><?= $bl ? 'Quitar bloqueo y liberar fechas' : 'Cancelar y liberar fechas' ?></button><a class="btn g" href="admin-renta.php">Cerrar detalle</a></div></form>
<?php } else { ?><div class="acc"><a class="btn g" href="admin-renta.php">Cerrar detalle</a></div><?php } ?>
</div>
<?php } ?>

<h2>Calendario</h2>
<div class="card">
<div class="nav"><a href="?mes=<?= $mAnt ?>">‹ <?= h($meses[(int) date('n', strtotime($mAnt . '-01')) - 1]) ?></a><b><?= h(ucfirst($tituloMes)) ?></b><a href="?mes=<?= $mSig ?>"><?= h($meses[(int) date('n', strtotime($mSig . '-01')) - 1]) ?> ›</a></div>
<div class="calw"><div class="calg">
<?php foreach (['L', 'M', 'M', 'J', 'V', 'S', 'D'] as $dw) { ?><div class="dow"><?= $dw ?></div><?php } ?>
<?php for ($i = 0; $i < $off; $i++) { ?><div></div><?php } ?>
<?php for ($dd = 1; $dd <= $diasMes; $dd++) {
    $f = sprintf('%s-%02d', $mes, $dd); ?>
<div class="d<?= $f === $hoy ? ' hoy' : '' ?>"><i><?= $dd ?></i>
<?php foreach ($cal as $r) {
    if ($f < $r['desde'] || $f > $r['hasta']) { continue; }
    list($etq, $cls) = estado_info($r);
    $marca = $f === $r['desde'] ? '▶ ' : ($f === $r['hasta'] ? '◀ ' : '');
    ?><a class="chip <?= $cls ?>" href="?ver=<?= h($r['id']) ?>&amp;mes=<?= h($mes) ?>" title="<?= h($etq . ' · ' . $r['nombre']) ?>"><?= h($marca . corto($r['nombre'], 16)) ?></a>
<?php } ?>
</div>
<?php } ?>
</div></div>
<p style="color:#555;font-size:13px;margin:10px 0 0">▶ entrega · ◀ devolución · <span class="tag c-ent">Por entregar</span> <span class="tag c-ren">En renta</span> <span class="tag c-dev">Devuelto</span> <span class="tag c-pen">Pago pendiente</span> <span class="tag c-blq">Bloqueo</span></p>
</div>

<h2>Bloquear fechas a mano</h2>
<details><summary>Mantenimiento, renta en mostrador u otro motivo</summary>
<form method="post" style="margin-top:12px"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="accion" value="bloquear">
<div class="fila"><label>Desde<input type="date" name="desde" required></label><label>Hasta (incluido)<input type="date" name="hasta" required></label><label>Motivo<input name="motivo" maxlength="80" placeholder="Ej. Mantenimiento"></label><button class="btn" type="submit">Bloquear fechas</button></div>
<p style="color:#555;font-size:13px">Mientras estén bloqueadas, nadie podrá elegirlas en el sitio. Se quitan desde el detalle del bloqueo.</p></form></details>

<h2>Reservaciones</h2>
<div class="tabs">
<?php foreach (['proximas' => 'Próximas', 'encurso' => 'En curso', 'pasadas' => 'Pasadas', 'pendientes' => 'Pagos pendientes', 'canceladas' => 'Canceladas', 'todas' => 'Todas'] as $k => $t) { ?><a class="<?= $filtro === $k ? 'on' : '' ?>" href="?f=<?= $k ?>&amp;mes=<?= h($mes) ?>"><?= $t ?></a><?php } ?>
</div>
<form method="get" style="margin-bottom:10px;display:flex;gap:8px"><input type="hidden" name="f" value="<?= h($filtro) ?>"><input name="q" value="<?= h($busca) ?>" placeholder="Buscar por nombre, correo, teléfono o número" maxlength="60"><button class="btn g" type="submit">Buscar</button></form>
<div class="tw"><table>
<tr><th>Número</th><th>Cliente</th><th>Fechas</th><th>Días</th><th>Total</th><th>Estado</th><th></th></tr>
<?php if (!$filas) { ?><tr><td colspan="7" style="color:#555">No hay reservaciones en esta vista.</td></tr><?php } ?>
<?php foreach ($filas as $r) { list($etq, $cls) = estado_info($r); ?>
<tr><td><?= h(numero_reserva($r)) ?></td><td><b><?= h($r['nombre']) ?></b><br><small><?= h($r['telefono']) ?></small></td>
<td><?= h(date('d/m/Y', strtotime($r['desde']))) ?> – <?= h(date('d/m/Y', strtotime($r['hasta']))) ?></td><td><?= h($r['dias']) ?></td>
<td><?= es_bloqueo($r) ? '—' : h(dinero($r['total'])) ?></td><td><span class="tag <?= $cls ?>"><?= h($etq) ?></span></td>
<td><a class="btn g" href="?ver=<?= h($r['id']) ?>&amp;mes=<?= h($mes) ?>">Ver</a></td></tr>
<?php } ?>
</table></div>
</main>
<script nonce="<?= $nonce ?>">
document.querySelectorAll("form[data-confirmar]").forEach(function (f) {
  f.addEventListener("submit", function (e) { if (!confirm(f.getAttribute("data-confirmar"))) { e.preventDefault(); } });
});
</script>
</body></html>
