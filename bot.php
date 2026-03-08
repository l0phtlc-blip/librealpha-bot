<?php
/**
 * LibreAlpha Bot — Worker independiente
 * Corre cada 4h, no necesita Pantheon
 */

define('TELEGRAM_TOKEN', '8686964599:AAH4MVGNxjVFgiw6y9cOAuHKUlbCXbifDrw');
define('TELEGRAM_CANAL', '-1003786820006');

$posts = array(
    array('symbol' => 'BTCUSDT',  'rangoVelas' => 40),
    array('symbol' => 'ETHUSDT',  'rangoVelas' => 40),
    array('symbol' => 'LINKUSDT', 'rangoVelas' => 30),
    array('symbol' => 'SOLUSDT',  'rangoVelas' => 30),
    array('symbol' => 'BNBUSDT',  'rangoVelas' => 35),
    array('symbol' => 'XRPUSDT',  'rangoVelas' => 85),
    array('symbol' => 'AVAXUSDT', 'rangoVelas' => 30),
    array('symbol' => 'ADAUSDT',  'rangoVelas' => 35),
    array('symbol' => 'DOTUSDT',  'rangoVelas' => 35),
    array('symbol' => 'PEPEUSDT', 'rangoVelas' => 15),
);

$estadoFile = __DIR__ . '/estado.json';
$estadoPrev = array();
if (file_exists($estadoFile)) {
    $estadoPrev = json_decode(file_get_contents($estadoFile), true);
    if (!is_array($estadoPrev)) $estadoPrev = array();
}
$estadoNuevo = $estadoPrev;
$publicados  = 0;

echo date('Y-m-d H:i:s') . " — Iniciando análisis — " . count($posts) . " activos\n";

foreach ($posts as $activo) {
    $symbol = $activo['symbol'];
    echo date('Y-m-d H:i:s') . " — Analizando {$symbol}...\n";

    $resultado = analizarActivo($activo, $estadoPrev);

    if (!$resultado) {
        echo date('Y-m-d H:i:s') . " — {$symbol} — sin estructura válida, saltando\n";
        continue;
    }

    $senalActual = null;
    if ($resultado['spring'] && $resultado['lps']) $senalActual = 'spring_lps';
    elseif ($resultado['spring'])                  $senalActual = 'spring';
    elseif ($resultado['upthrust'])                $senalActual = 'upthrust';
    elseif ($resultado['estructuraNueva'])         $senalActual = 'estructura_nueva';

    if (!$senalActual) {
        echo date('Y-m-d H:i:s') . " — {$symbol} — estructura válida pero sin señal\n";
        continue;
    }

    $prevSenal = isset($estadoPrev[$symbol]['senal']) ? $estadoPrev[$symbol]['senal'] : '';
    $prevSC    = isset($estadoPrev[$symbol]['sc'])    ? floatval($estadoPrev[$symbol]['sc']) : null;

    $debePublicar = ($senalActual !== $prevSenal)
        || ($senalActual === 'estructura_nueva' && $prevSC !== null && abs($resultado['sc'] - $prevSC) / max($resultado['sc'], 0.0000001) > 0.005);

    if (!$debePublicar) {
        echo date('Y-m-d H:i:s') . " — {$symbol} — señal ya publicada, saltando\n";
        continue;
    }

    $mensaje = buildMensaje($resultado, $senalActual);
    if (!$mensaje) continue;

    $ok = telegramSend($mensaje, TELEGRAM_CANAL);
    if ($ok) {
        echo date('Y-m-d H:i:s') . " — {$symbol} — publicado: {$senalActual}\n";
        $estadoNuevo[$symbol] = array(
            'senal'     => $senalActual,
            'sc'        => $resultado['sc'],
            'ar'        => $resultado['ar'],
            'publicado' => date('Y-m-d H:i:s')
        );
        $publicados++;
        sleep(2);
    }
}

file_put_contents($estadoFile, json_encode($estadoNuevo));
echo date('Y-m-d H:i:s') . " — Finalizado — {$publicados} señales publicadas\n";

// --- FUNCIONES ---

function binanceGet($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res ? json_decode($res, true) : null;
}

function telegramSend($texto, $chatId) {
    $url = "https://api.telegram.org/bot" . TELEGRAM_TOKEN . "/sendMessage";
    $ch  = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array(
        'chat_id'    => $chatId,
        'text'       => $texto,
        'parse_mode' => 'Markdown'
    )));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code === 200;
}

function formatPrecio($valor) {
    if ($valor == 0) return "0.00";
    if ($valor < 0.00001) return number_format($valor, 10);
    if ($valor < 0.0001)  return number_format($valor, 8);
    if ($valor < 0.01)    return number_format($valor, 6);
    if ($valor < 1)       return number_format($valor, 4);
    return number_format($valor, 2);
}

function analizarActivo($activo, $estadoPrev) {
    $symbol     = $activo['symbol'];
    $rangoVelas = $activo['rangoVelas'];

    $data4h = binanceGet("https://api.binance.com/api/v3/klines?symbol={$symbol}&interval=4h&limit=200");
    $dataD  = binanceGet("https://api.binance.com/api/v3/klines?symbol={$symbol}&interval=1d&limit=30");

    if (!$data4h || !$dataD) {
        echo date('Y-m-d H:i:s') . " — {$symbol} — ERROR: fetch Binance fallido\n";
        return null;
    }

    $cierres   = array_map(function($k) { return floatval($k[4]); }, $data4h);
    $maximos   = array_map(function($k) { return floatval($k[2]); }, $data4h);
    $minimos   = array_map(function($k) { return floatval($k[3]); }, $data4h);
    $volumenes = array_map(function($k) { return floatval($k[5]); }, $data4h);
    $cierresD  = array_map(function($k) { return floatval($k[4]); }, $dataD);
    $maximosD  = array_map(function($k) { return floatval($k[2]); }, $dataD);
    $minimosDi = array_map(function($k) { return floatval($k[3]); }, $dataD);

    $precio = end($cierres);
    $inicio = -($rangoVelas + 5);

    $ar = max(array_slice($maximos, $inicio, -5));
    $sc = min(array_slice($minimos, $inicio, -5));

    $maxD          = max($maximosD);
    $minD          = min($minimosDi);
    $precioActual  = end($cierresD);
    $caidaDesdeMax = ($maxD - $precioActual) / $maxD;
    $subaDesdMin   = ($precioActual - $minD) / $minD;

    if ($caidaDesdeMax > 0.15) { echo date('Y-m-d H:i:s') . " — {$symbol} — rechazado: caída " . round($caidaDesdeMax*100) . "% desde máximo\n"; return null; }
    if ($subaDesdMin   > 0.20) { echo date('Y-m-d H:i:s') . " — {$symbol} — rechazado: suba "  . round($subaDesdMin*100)   . "% desde mínimo\n";  return null; }

    $rango       = $ar - $sc;
    $precioMedio = ($ar + $sc) / 2;
    if ($precioMedio == 0 || $rango / $precioMedio < 0.03) { echo date('Y-m-d H:i:s') . " — {$symbol} — rechazado: rango estrecho\n"; return null; }

    $velasRango   = array_slice($minimos, $inicio, -5);
    $maxsRango    = array_slice($maximos, $inicio, -5);
    $cierresRango = array_slice($cierres, $inicio, -5);

    $tocasSC = count(array_filter($velasRango, function($m) use ($sc) { return $m <= $sc * 1.02; }));
    $tocasAR = count(array_filter($maxsRango,  function($m) use ($ar) { return $m >= $ar * 0.98; }));
    if ($tocasSC < 2 || $tocasAR < 2) { echo date('Y-m-d H:i:s') . " — {$symbol} — rechazado: toques SC={$tocasSC} AR={$tocasAR}\n"; return null; }

    $pctDentro = count(array_filter($cierresRango, function($c) use ($sc, $ar) { return $c >= $sc && $c <= $ar; })) / count($cierresRango);
    if ($pctDentro < 0.60) { echo date('Y-m-d H:i:s') . " — {$symbol} — rechazado: " . round($pctDentro*100) . "% dentro del rango\n"; return null; }

    $tendencia4h = abs($cierresRango[0] - end($cierresRango)) / $rango;
    if ($tendencia4h > 0.40) { echo date('Y-m-d H:i:s') . " — {$symbol} — rechazado: tendencia 4h " . round($tendencia4h*100) . "%\n"; return null; }

    $ventana     = array_slice($data4h, -6, 5);
    $springIdx   = -1;
    $upthrustIdx = -1;

    foreach ($ventana as $i => $vela) {
        $min    = floatval($vela[3]);
        $max    = floatval($vela[2]);
        $cierre = floatval($vela[4]);
        if ($min < $sc && $cierre > $sc) $springIdx   = $i;
        if ($max > $ar && $cierre < $ar) $upthrustIdx = $i;
    }

    $spring   = $springIdx   !== -1;
    $upthrust = $upthrustIdx !== -1;
    $lps      = false;

    if ($spring) {
        $springMin = floatval($ventana[$springIdx][3]);
        $springVol = floatval($ventana[$springIdx][5]);
        for ($i = $springIdx + 2; $i < count($ventana); $i++) {
            $min        = floatval($ventana[$i][3]);
            $cierreLps  = floatval($ventana[$i][4]);
            $volLps     = floatval($ventana[$i][5]);
            $velaPost   = isset($ventana[$i+1]) ? $ventana[$i+1] : end($data4h);
            $cierrePost = floatval($velaPost[4]);
            if ($min > $sc && $min > $springMin && $volLps < $springVol && $cierrePost > $cierreLps) {
                $lps = true;
                break;
            }
        }
    }

    $scPrev = isset($estadoPrev[$symbol]['sc']) ? floatval($estadoPrev[$symbol]['sc']) : null;
    $arPrev = isset($estadoPrev[$symbol]['ar']) ? floatval($estadoPrev[$symbol]['ar']) : null;
    $estructuraNueva = ($scPrev === null
        || abs($sc - $scPrev) / max($sc, 0.0000001) > 0.005
        || abs($ar - $arPrev) / max($ar, 0.0000001) > 0.005);

    $volActual   = end($volumenes);
    $volSlice    = array_slice($volumenes, -21, 20);
    $volPromedio = array_sum($volSlice) / count($volSlice);
    $volumenAlto = $volActual > $volPromedio * 1.3;

    return array(
        'symbol'          => $symbol,
        'precio'          => $precio,
        'sc'              => $sc,
        'ar'              => $ar,
        'spring'          => $spring,
        'upthrust'        => $upthrust,
        'lps'             => $lps,
        'estructuraNueva' => $estructuraNueva,
        'volumenAlto'     => $volumenAlto,
    );
}

function buildMensaje($r, $senal) {
    $symbol = $r['symbol'];
    $moneda = str_replace('USDT', '', $symbol);
    $precio = formatPrecio($r['precio']);
    $sc     = formatPrecio($r['sc']);
    $ar     = formatPrecio($r['ar']);
    $vol    = $r['volumenAlto'] ? '📈 Alto' : '➖ Normal';
    $rango  = round((($r['ar'] - $r['sc']) / $r['sc']) * 100, 1);

    if ($senal === 'spring_lps') {
        $emoji  = '🟢';
        $titulo = "Spring + LPS confirmado";
        $detalle = "Señal institucional de alta calidad. Acumulación completa detectada.";
        $sesgo   = "LONG";
    } elseif ($senal === 'spring') {
        $emoji  = '🟡';
        $titulo = "Spring detectado";
        $detalle = "El precio perforó el SC y cerró por encima. Posible acumulación institucional.";
        $sesgo   = "LONG";
    } elseif ($senal === 'upthrust') {
        $emoji  = '🔴';
        $titulo = "Upthrust detectado";
        $detalle = "El precio superó el AR y cerró por debajo. Posible distribución institucional.";
        $sesgo   = "SHORT";
    } elseif ($senal === 'estructura_nueva') {
        $emoji  = '🔵';
        $titulo = "Nueva estructura institucional";
        $detalle = "Nuevos niveles SC/AR detectados. El mercado está definiendo un nuevo rango.";
        $sesgo   = "NEUTRAL";
    } else {
        return null;
    }

    return "{$emoji} *{$moneda} — {$titulo}*\n\n"
         . "💰 Precio: \${$precio}\n"
         . "🟩 SC (Soporte): \${$sc}\n"
         . "🟥 AR (Resistencia): \${$ar}\n"
         . "📊 Rango: {$rango}%\n"
         . "📉 Volumen: {$vol}\n"
         . "📌 Sesgo: *{$sesgo}*\n\n"
         . "_{$detalle}_\n\n"
         . "🔗 [Ver análisis](https://dev-librealpha.pantheonsite.io)\n"
         . "#LibreAlpha #{$moneda}";
}
