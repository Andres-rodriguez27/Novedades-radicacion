{
  "name": "Novedades Radicación api",
  "nodes": [
    {
      "parameters": {
        "url": "https://servicioweb2col.azzorti.co/api_radicacion_novedades/api_novedades_sid.php",
        "sendQuery": true,
        "queryParameters": {
          "parameters": [
            {
              "name": "fecha_desde",
              "value": "2026-07-01"
            },
            {
              "name": "fecha_hasta",
              "value": "2026-08-30"
            }
          ]
        },
        "sendHeaders": true,
        "headerParameters": {
          "parameters": [
            {
              "name": "X-API-Key",
              "value": ""
            }
          ]
        },
        "options": {}
      },
      "type": "n8n-nodes-base.httpRequest",
      "typeVersion": 4.4,
      "position": [
        -304,
        32
      ],
      "id": "9289165c-96ab-4e14-8fa0-cbd89b868800",
      "name": "Extracción DCO calidad",
      "alwaysOutputData": false
    },
    {
      "parameters": {
        "method": "POST",
        "url": "https://servicioweb2col.azzorti.co/api_radicacion_novedades/api_pedido.php",
        "sendBody": true,
        "specifyBody": "json",
        "jsonBody": "={{ JSON.stringify({ pedidos: $json.pedidos }) }}",
        "options": {}
      },
      "type": "n8n-nodes-base.httpRequest",
      "typeVersion": 4.4,
      "position": [
        64,
        32
      ],
      "id": "e789c1de-a79c-4830-a65e-fbaa132d270e",
      "name": "Extracción EBELN SAP"
    },
    {
      "parameters": {},
      "type": "n8n-nodes-base.manualTrigger",
      "typeVersion": 1,
      "position": [
        -512,
        32
      ],
      "id": "2149931a-588e-42c5-a38f-6fbc3aa63c82",
      "name": "When clicking ‘Execute workflow’"
    },
    {
      "parameters": {
        "jsCode": "// Todos los DCOs vienen dentro de data[] del nodo anterior\nconst dcos = $input.first().json.data;\n\n// 1) Lista de números de pedido (solo esto va a la API) — sin vacíos ni duplicados\nconst pedidos = [...new Set(\n  dcos\n    .map(d => d.numero_pedido_sap)\n    .filter(p => p && p.toString().trim() !== \"\")\n)];\n\n// 2) Devuelve la lista para la API + todos los DCOs completos para cruzar después\nreturn [{\n  json: {\n    pedidos: pedidos,   // -> esto se manda a la API HANA\n    dcos: dcos          // -> todos los datos originales, se conservan\n  }\n}];"
      },
      "type": "n8n-nodes-base.code",
      "typeVersion": 2,
      "position": [
        -112,
        32
      ],
      "id": "d22a3c0f-82a0-482f-8651-be4dd97d01b8",
      "name": "Code: separa pedidos + dcos"
    }
  ]
}