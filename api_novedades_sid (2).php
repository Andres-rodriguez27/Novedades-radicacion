<?php
/**
 * =============================================================================
 *  API - Novedades de Radicación SID (Informix 12.4)
 * =============================================================================
 *  Objetivo:
 *    Extraer desde SID los radicados (nume_rela) con su ÚLTIMA novedad
 *    (infa_nove_sap.fech_nove) dentro del período consultado.
 *    Por defecto el período es el MES CORRIDO: desde el primer día del mes
 *    actual hasta el día de hoy. Devuelve TODOS los radicados con novedad,
 *    tengan o no pedido SAP.
 *
 *  Consumo desde n8n:
 *    GET .../api_novedades_sid.php                          (mes corrido, por defecto)
 *    GET .../api_novedades_sid.php?estado=RAD               (mes corrido + estado)
 *    GET .../api_novedades_sid.php?fecha_desde=2026-03-01&fecha_hasta=2026-03-31
 *                                                           (rango puntual, ej. pruebas)
 *    Header: X-API-Key: <clave>
 *
 *  Salida: JSON  { "ok": true, "total": N, "data": [ ... ] }
 * =============================================================================
 */

/* -------------------------------------------------------------------------- *
 *  CONTROL DE ERRORES DE PHP
 * -------------------------------------------------------------------------- *
 *  Se evita que warnings/notices de PHP se impriman y contaminen el JSON
 *  (causa típica del error "Invalid JSON in response body" en n8n).
 *  Los errores se capturan y se devuelven SIEMPRE como JSON válido.
 * -------------------------------------------------------------------------- */
error_reporting(E_ALL);
ini_set('display_errors', '0'); // no imprimir errores crudos en la salida

/* -------------------------------------------------------------------------- *
 *  CONFIGURACIÓN
 * -------------------------------------------------------------------------- */

// API Key esperada en el header X-API-Key.
define('API_KEY', 'sid_sap_7RNVLR3GQEEJDAA0MNc6qe8A6Tr2eF2cK0k8tvIWWsLAjCUM');

// Ambiente de conexión: "ProduccionColombia" o "DesarrolloColombia"
define('AMBIENTE', 'DesarrolloColombia');

// Estados válidos de acti_esta (whitelist, ya que NO hay parámetros preparados)
$ESTADOS_VALIDOS = array('RAD', 'CTB', 'PAG', 'ANU', 'REC', 'SPS');

/* -------------------------------------------------------------------------- *
 *  CABECERAS Y LOCALE
 * -------------------------------------------------------------------------- */

header('Content-Type: application/json; charset=utf-8');
// Locale para acentos correctos en español (nombres, observaciones de novedad)
setlocale(LC_ALL, 'es_CO.UTF-8', 'es_ES.UTF-8', 'es_CO', 'es_ES');
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

/* -------------------------------------------------------------------------- *
 *  RESPUESTA JSON ESTÁNDAR
 * -------------------------------------------------------------------------- */

function responder($codigoHttp, $payload) {
    http_response_code($codigoHttp);
    // JSON_INVALID_UTF8_SUBSTITUTE: reemplaza bytes no-UTF8 (acentos mal codificados
    // desde Informix) en vez de fallar y devolver false -> evita "Invalid JSON".
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($payload, $flags);
    if ($json === false) {
        // Fallback: si aun así falla, devolver un JSON mínimo con el motivo.
        $json = json_encode(array(
            'ok'    => false,
            'error' => 'Fallo al serializar JSON: ' . json_last_error_msg()
        ));
    }
    echo $json;
    exit;
}

function error($codigoHttp, $mensaje) {
    responder($codigoHttp, array('ok' => false, 'error' => $mensaje));
}

/**
 * Convierte un texto a UTF-8 válido.
 * Informix suele entregar los datos en ISO-8859-1 (Latin1); si esos bytes se
 * serializan directamente, json_encode() falla y devuelve false ("Invalid JSON").
 * Esta función detecta si el texto ya es UTF-8 válido y, si no lo es, lo convierte.
 * Compatible con cualquier versión de PHP (no depende de flags de PHP 7.2+).
 */
function aUtf8($texto) {
    if ($texto === null || $texto === '') {
        return $texto;
    }
    // Si ya es UTF-8 válido, se deja igual.
    if (function_exists('mb_check_encoding') && mb_check_encoding($texto, 'UTF-8')) {
        return $texto;
    }
    // Convertir desde ISO-8859-1 / Windows-1252 (lo típico de Informix) a UTF-8.
    if (function_exists('mb_convert_encoding')) {
        return mb_convert_encoding($texto, 'UTF-8', 'ISO-8859-1, Windows-1252');
    }
    // Último recurso si mbstring no está disponible.
    return utf8_encode($texto);
}

/* -------------------------------------------------------------------------- *
 *  SEGURIDAD - VALIDACIÓN DE API KEY
 * -------------------------------------------------------------------------- */

$headers = function_exists('getallheaders') ? getallheaders() : array();
// Normalizar claves de headers a minúsculas para búsqueda robusta
$headersLower = array();
foreach ($headers as $k => $v) {
    $headersLower[strtolower($k)] = $v;
}
$apiKeyRecibida = isset($headersLower['x-api-key']) ? $headersLower['x-api-key'] : '';

if (!hash_equals(API_KEY, (string)$apiKeyRecibida)) {
    error(401, 'No autorizado: X-API-Key inválida o ausente.');
}

/* -------------------------------------------------------------------------- *
 *  LECTURA Y SANITIZACIÓN DE PARÁMETROS
 * -------------------------------------------------------------------------- */

// --- estado (opcional) ---
$estado = isset($_GET['estado']) ? strtoupper(trim($_GET['estado'])) : '';
if ($estado !== '' && !in_array($estado, $ESTADOS_VALIDOS, true)) {
    error(400, 'Parámetro "estado" inválido. Valores permitidos: ' . implode(', ', $ESTADOS_VALIDOS));
}

// --- fecha_desde / fecha_hasta (opcionales, rango sobre la fecha de novedad) ---
// Si se envían, reemplazan el rango por defecto (mes corrido). Formato: YYYY-MM-DD.
// Uso típico: pruebas por rango (ej. marzo) o consultas puntuales de otro período.
function validarFecha($valor) {
    // Acepta exactamente YYYY-MM-DD y valida que sea una fecha real
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
        return false;
    }
    $partes = explode('-', $valor);
    if (!checkdate((int)$partes[1], (int)$partes[2], (int)$partes[0])) {
        return false;
    }
    return $valor;
}

$fechaDesde = isset($_GET['fecha_desde']) ? trim($_GET['fecha_desde']) : '';
$fechaHasta = isset($_GET['fecha_hasta']) ? trim($_GET['fecha_hasta']) : '';

// Ambas deben venir juntas si se usa el modo rango
if (($fechaDesde !== '' && $fechaHasta === '') || ($fechaDesde === '' && $fechaHasta !== '')) {
    error(400, 'Para filtrar por rango debe enviar AMBOS parámetros: fecha_desde y fecha_hasta (formato YYYY-MM-DD).');
}

$usarRango = false;
if ($fechaDesde !== '' && $fechaHasta !== '') {
    if (validarFecha($fechaDesde) === false || validarFecha($fechaHasta) === false) {
        error(400, 'Formato de fecha inválido. Use YYYY-MM-DD (ej. 2026-03-01).');
    }
    if ($fechaDesde > $fechaHasta) {
        error(400, 'fecha_desde no puede ser mayor que fecha_hasta.');
    }
    $usarRango = true;
}

/* -------------------------------------------------------------------------- *
 *  RANGO EFECTIVO DE CONSULTA
 * -------------------------------------------------------------------------- *
 *  Por defecto (sin parámetros): desde el PRIMER DÍA DEL MES ACTUAL hasta HOY.
 *  Si se envían fecha_desde/fecha_hasta, se usan esos (modo prueba/rango).
 * -------------------------------------------------------------------------- */

if ($usarRango) {
    $rangoDesde = $fechaDesde;   // YYYY-MM-DD provisto
    $rangoHasta = $fechaHasta;   // YYYY-MM-DD provisto
    $modoFiltro = 'rango_fechas';
} else {
    // Mes corrido: día 1 del mes actual .. día de hoy
    $rangoDesde = date('Y-m-01');
    $rangoHasta = date('Y-m-d');
    $modoFiltro = 'mes_corrido';
}

// Literales de fecha en formato MM/DD/YYYY (el que espera este Informix)
// para comparar contra fech_nove casteada a DATE (compara sólo por día).
$rangoDesdeD = "'" . date('m/d/Y', strtotime($rangoDesde)) . "'";
$rangoHastaD = "'" . date('m/d/Y', strtotime($rangoHasta)) . "'";

/* -------------------------------------------------------------------------- *
 *  CONSTRUCCIÓN DE LA CONSULTA SQL
 * -------------------------------------------------------------------------- *
 *  - Tablas SIN owner: Informix las resuelve con el owner del usuario conectado.
 *  - LEFT JOIN sobre infa_deta: incluye radicados SIN pedido SAP.
 *  - fech_nove se castea a DATE (::DATE) para filtrar y emparejar por día.
 *  - Subconsulta ult: la última FECHA (por día) de novedad por radicado en rango.
 * -------------------------------------------------------------------------- */

$sql = "
SELECT
    TRIM(g.nume_rela)                   AS numero_radicado,
    TRIM(g.acti_usua)                   AS usuario_radica,
    g.fech_rece                         AS fecha_radicacion,
    TRIM(g.acti_esta)                   AS estado_radicado,
    d.nume_orde                         AS numero_pedido_sap,
    n.obse_nove                         AS novedad,
    n.fech_nove                         AS fecha_novedad
FROM infa_glob g
JOIN infa_nove_sap n
       ON TRIM(g.nume_rela) = TRIM(n.nume_rela)
JOIN (
        SELECT TRIM(nume_rela) AS nume_rela, MAX(DATE(fech_nove)) AS max_fech
        FROM infa_nove_sap
        WHERE DATE(fech_nove) >= $rangoDesdeD
          AND DATE(fech_nove) <= $rangoHastaD
        GROUP BY nume_rela
     ) ult
       ON TRIM(n.nume_rela) = ult.nume_rela
      AND DATE(n.fech_nove) = ult.max_fech
LEFT JOIN infa_deta d
       ON g.cons_infa = d.cons_infa
      AND g.cons_tira = d.cons_tira
WHERE DATE(n.fech_nove) >= $rangoDesdeD
  AND DATE(n.fech_nove) <= $rangoHastaD
";

// Filtro opcional por estado (valor ya validado contra whitelist)
if ($estado !== '') {
    $sql .= " AND TRIM(g.acti_esta) = '$estado' ";
}

$sql .= " ORDER BY g.nume_rela, n.fech_nove DESC ";

/* -------------------------------------------------------------------------- *
 *  CONEXIÓN Y EJECUCIÓN (usa tu capa PDO propia - Patrón B)
 * -------------------------------------------------------------------------- */

try {
    include("Conexion_Pdo.php");
    include('dupreeDB.php');

    // $file proviene de dupreeDB.php (mismo patrón que usas actualmente)
    $conexion_info = new conexion_pdo("Informix", AMBIENTE, $file);

    // Patrón B: ejecutar_consulta devuelve las filas. No acepta parámetros preparados.
    $resul = $conexion_info->ejecutar_consulta($sql);

    if ($resul === false || $resul === null) {
        error(500, 'La consulta no devolvió un resultado válido desde SID.');
    }

    // Normalizar a arreglo indexado de filas asociativas
    $filas = array();
    foreach ($resul as $row) {
        $r = (array) $row;

        // numero_pedido_sap puede venir nulo/0 cuando el radicado no tiene pedido.
        $pedido = isset($r['numero_pedido_sap']) ? $r['numero_pedido_sap'] : null;
        $tienePedido = !($pedido === null || $pedido === '' || (string)$pedido === '0');

        $filas[] = array(
            'numero_radicado'   => isset($r['numero_radicado']) ? aUtf8(trim((string)$r['numero_radicado'])) : null,
            'usuario_radica'    => isset($r['usuario_radica']) ? aUtf8(trim((string)$r['usuario_radica'])) : null,
            'fecha_radicacion'  => isset($r['fecha_radicacion']) ? aUtf8((string)$r['fecha_radicacion']) : null,
            'estado_radicado'   => isset($r['estado_radicado']) ? aUtf8(trim((string)$r['estado_radicado'])) : null,
            'numero_pedido_sap' => $tienePedido ? (string)$pedido : null,
            'tiene_pedido'      => $tienePedido,
            'novedad'           => isset($r['novedad']) ? aUtf8((string)$r['novedad']) : null,
            'fecha_novedad'     => isset($r['fecha_novedad']) ? aUtf8((string)$r['fecha_novedad']) : null,
        );
    }

    responder(200, array(
        'ok'        => true,
        'ambiente'  => AMBIENTE,
        'filtros'   => array(
            'estado'      => $estado !== '' ? $estado : 'todos',
            'modo'        => $modoFiltro,
            'fecha_desde' => $rangoDesde,
            'fecha_hasta' => $rangoHasta,
        ),
        'total'     => count($filas),
        'data'      => $filas,
    ));

} catch (Throwable $e) {
    // Throwable cubre tanto Exception como Error (PHP 7+),
    // asi cualquier fallo de la clase de conexion o del driver
    // se devuelve como JSON valido y no rompe la respuesta.
    error(500, 'Error al consultar SID: ' . $e->getMessage());
}
