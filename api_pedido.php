<?php
/**
 * api_pedido.php
 * -----------------------------------------------------------------------------
 * API REST que consulta en SAP HANA la cabecera (EKKO) de una LISTA de pedidos
 * (DCOs) recibida desde n8n en una sola llamada. NO filtra por fecha.
 *
 * ENTRADA (body JSON):
 *   { "pedidos": ["4700012659","4500001234", ...] }
 *   { "pedidos": "4700012659,4500001234" }   (string separado por comas)
 *
 * *** MODO DIAGNOSTICO ACTIVO ***
 * Muestra los errores PHP en la respuesta para localizar el 500.
 * Una vez funcione, poner $DEBUG = false.
 * -----------------------------------------------------------------------------
 */

// ---------- DIAGNOSTICO ----------
$DEBUG = true;   // <-- cambiar a false cuando ya funcione
if ($DEBUG) {
    error_reporting(E_ALL);
    ini_set("display_errors", "1");
}

header("Content-Type: application/json");

// Captura errores fatales que ocurren fuera del try (ej. en el require)
register_shutdown_function(function () use ($DEBUG) {
    $err = error_get_last();
    if ($err && in_array($err["type"], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        echo json_encode([
            "error"   => "Error fatal PHP",
            "detalle" => $DEBUG ? $err : "activar DEBUG para ver detalle"
        ]);
    }
});

require_once "conexion_pdo.php"; // clase de conexión PDO existente

class ApiPedido
{
    public function extraerJson()
    {
        // ---------- ENTRADA ----------
        $raw = file_get_contents("php://input");
        $input = json_decode($raw, true) ?? [];
        $pedidos = $input["pedidos"] ?? null;

        if (is_string($pedidos)) {
            $pedidos = explode(",", $pedidos);
        }

        if (empty($pedidos) || !is_array($pedidos)) {
            http_response_code(400);
            return json_encode([
                "error"      => "Falta el parametro 'pedidos'",
                "body_recibido" => $raw
            ]);
        }

        // Limpieza
        $limpios = [];
        foreach ($pedidos as $p) {
            $p = trim($p);
            if ($p !== "" && preg_match('/^[A-Za-z0-9]+$/', $p)) {
                $limpios[] = $p;
            }
        }
        $limpios = array_values(array_unique($limpios));

        if (empty($limpios)) {
            http_response_code(400);
            return json_encode(["error" => "No hay numeros de pedido validos"]);
        }

        // ---------- CONSULTA ----------
        try {
            $stdlog = new stdClass();
            $hana = new conexion_pdo("Sap", "CalidadHana", $stdlog);

            $listaIn = "'" . implode("','", $limpios) . "'";

            $consulta = "SELECT
                    EKKO.BUKRS, EKKO.EBELN, EKKO.ERNAM, EKKO.STATU,
                    EKKO.BSART, EKKO.EKGRP, EKKO.LIFNR, EKKO.BEDAT,
                    EKKO.AEDAT, EKKO.WAERS
                FROM EKKO
                WHERE EKKO.EBELN IN ($listaIn)
                ORDER BY EKKO.BUKRS, EKKO.EBELN";

            $rconsulta = $hana->ejecutar_consulta($consulta);

            $encontrados = array_map(function ($r) { return $r["EBELN"]; }, $rconsulta ?: []);
            $noEncontrados = array_values(array_diff($limpios, $encontrados));

            return json_encode([
                "success"        => true,
                "solicitados"    => count($limpios),
                "encontrados"    => count($rconsulta ?: []),
                "no_encontrados" => $noEncontrados,
                "data"           => $rconsulta ?: []
            ]);

        } catch (Throwable $e) {
            http_response_code(500);
            return json_encode([
                "error"   => "Error en consulta SAP",
                "mensaje" => $e->getMessage(),
                "archivo" => $e->getFile(),
                "linea"   => $e->getLine()
            ]);
        }
    }
}

// ---------- EJECUCION ----------
$api = new ApiPedido();
echo $api->extraerJson();
