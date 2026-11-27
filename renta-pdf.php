<?php
// Genera el PDF de la reservación sin librerías externas.
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'renta-pdf.php') { http_response_code(404); exit; }

class PdfReserva {
    private $w = 595.28;
    private $h = 841.89;
    private $c = '';
    private $img = null;
    private $anchos;

    function __construct() { $this->anchos = array_map('intval', explode(',', '278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584')); }

    private function cp($t) {
        $x = @mb_convert_encoding((string) $t, 'Windows-1252', 'UTF-8');
        return $x === false ? (string) $t : $x;
    }
    private function esc($t) { return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $this->cp($t)); }

    function ancho($t, $s, $b = false) {
        $t = $this->cp($t);
        $n = 0;
        for ($i = 0, $l = strlen($t); $i < $l; $i++) {
            $o = ord($t[$i]);
            $n += ($o >= 32 && $o <= 126) ? $this->anchos[$o - 32] : 556;
        }
        return $n * $s / 1000 * ($b ? 1.06 : 1);
    }
    function texto($x, $y, $t, $s = 10, $b = false, $col = [17, 17, 17]) {
        $this->c .= sprintf("BT /%s %.2F Tf %.3F %.3F %.3F rg %.2F %.2F Td (%s) Tj ET\n",
            $b ? 'F2' : 'F1', $s, $col[0] / 255, $col[1] / 255, $col[2] / 255, $x, $this->h - $y, $this->esc($t));
    }
    function rect($x, $y, $w, $h, $col) {
        $this->c .= sprintf("%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n",
            $col[0] / 255, $col[1] / 255, $col[2] / 255, $x, $this->h - $y - $h, $w, $h);
    }
    function linea($x1, $y1, $x2, $y2, $col, $g = 1) {
        $this->c .= sprintf("%.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S\n",
            $col[0] / 255, $col[1] / 255, $col[2] / 255, $g, $x1, $this->h - $y1, $x2, $this->h - $y2);
    }
    function parrafo($x, $y, $max, $t, $s = 10, $col = [85, 85, 85], $inter = 1.35) {
        foreach (preg_split('/\R/', (string) $t) as $linea) {
            $actual = '';
            foreach (preg_split('/\s+/', trim($linea)) as $pal) {
                $prueba = $actual === '' ? $pal : $actual . ' ' . $pal;
                if ($actual !== '' && $this->ancho($prueba, $s) > $max) {
                    $this->texto($x, $y, $actual, $s, false, $col);
                    $y += $s * $inter;
                    $actual = $pal;
                } else {
                    $actual = $prueba;
                }
            }
            $this->texto($x, $y, $actual, $s, false, $col);
            $y += $s * $inter;
        }
        return $y;
    }
    function logo($x, $y, $ancho) {
        $jpeg = base64_decode('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');
        $iw = 300;
        $ih = 137;
        $this->img = [$jpeg, $iw, $ih];
        $alto = $ancho * $ih / $iw;
        $this->c .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /Im1 Do Q\n", $ancho, $alto, $x, $this->h - $y - $alto);
        return $alto;
    }
    function salida() {
        $o = [];
        $o[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $o[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $xo = $this->img ? ' /XObject << /Im1 7 0 R >>' : '';
        $o[3] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Contents 4 0 R /Resources << /Font << /F1 5 0 R /F2 6 0 R >>%s >> >>', $this->w, $this->h, $xo);
        $o[4] = '<< /Length ' . strlen($this->c) . " >>\nstream\n" . $this->c . 'endstream';
        $o[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $o[6] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        if ($this->img) {
            $o[7] = sprintf('<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>',
                $this->img[1], $this->img[2], strlen($this->img[0])) . "\nstream\n" . $this->img[0] . "\nendstream";
        }
        $pdf = "%PDF-1.4\n";
        $pos = [];
        foreach ($o as $n => $cuerpo) {
            $pos[$n] = strlen($pdf);
            $pdf .= "$n 0 obj\n$cuerpo\nendobj\n";
        }
        $xref = strlen($pdf);
        $max = max(array_keys($o));
        $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($n = 1; $n <= $max; $n++) { $pdf .= sprintf("%010d 00000 n \n", $pos[$n]); }
        $pdf .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
        return $pdf;
    }
}

// Devuelve el contenido (bytes) del PDF de una reservación confirmada.
function pdf_reserva($r, $auto) {
    $granate = [136, 8, 8]; $verde = [30, 122, 2]; $gris = [85, 85, 85]; $claro = [234, 243, 230]; $negro = [17, 17, 17];
    $p = new PdfReserva();
    $p->rect(0, 0, 198.43, 8, $verde);
    $p->rect(396.85, 0, 198.43, 8, $granate);
    $alto = $p->logo(40, 30, 120);
    $num = numero_reserva($r);
    $t = 'Reserva confirmada';
    $p->texto(555 - $p->ancho($t, 18, true), 52, $t, 18, true, $granate);
    $t = 'No. ' . $num;
    $p->texto(555 - $p->ancho($t, 13, true), 72, $t, 13, true, $negro);
    $t = 'Emitida el ' . fecha_larga(date('Y-m-d'));
    $p->texto(555 - $p->ancho($t, 10), 88, $t, 10, false, $gris);
    $p->linea(40, 30 + $alto + 12, 555, 30 + $alto + 12, $granate, 2);
    $y = 30 + $alto + 50;
    $p->texto(40, $y, 'Renta de auto en Cancún', 20, true, $negro);
    $y += 20;
    $p->texto(40, $y, 'Sucursal Cancún, Quintana Roo', 11, false, $gris);
    $y += 38;
    $bloques = [
        'Datos de la reserva' => [
            ['Número de reserva', $num], ['Vehículo', $r['vehiculo']],
            ['Recogida', fecha_larga($r['desde'])], ['Devolución', fecha_larga($r['hasta'])],
            ['Días de renta', (string) $r['dias']], ['Precio por día', dinero($r['garantia'])],
            ['Total de la renta', dinero($r['total'])],
        ],
        'Datos del cliente' => [
            ['Nombre', $r['nombre']], ['Correo', $r['correo']], ['Teléfono', $r['telefono']],
        ],
    ];
    foreach ($bloques as $titulo => $filas) {
        $p->texto(40, $y, mb_strtoupper($titulo, 'UTF-8'), 11, true, $granate);
        $y += 8;
        $p->linea(40, $y, 555, $y, [217, 217, 217], 1);
        $y += 20;
        foreach ($filas as $f) {
            $p->texto(40, $y, $f[0], 11, false, $gris);
            $p->texto(220, $y, $f[1], 11, true, $negro);
            $y += 21;
        }
        $y += 14;
    }
    $p->rect(40, $y, 515, 58, $claro);
    $p->texto(56, $y + 24, 'Garantía pagada (primer día de renta)', 11, false, $gris);
    $t = dinero($r['garantia']);
    $p->texto(539 - $p->ancho($t, 20, true), $y + 40, $t, 20, true, $verde);
    $y += 58 + 28;
    if (!empty($auto['condiciones'])) {
        $p->texto(40, $y, 'CONDICIONES', 11, true, $granate);
        $y += 16;
        $p->parrafo(40, $y, 515, mb_substr((string) $auto['condiciones'], 0, 1200, 'UTF-8'), 10);
    }
    $p->linea(40, 790, 555, 790, [217, 217, 217], 1);
    $p->texto(40, 806, 'Un asesor de vivac.mx te contactará por WhatsApp para coordinar la entrega.', 10, false, $gris);
    $p->texto(40, 820, 'WhatsApp +52 998 339 4281 · info@vivac.mx · www.vivac.mx', 10, false, $gris);
    return $p->salida();
}
