<?php
require_once 'vendor/autoload.php';
use Picqer\Barcode\BarcodeGeneratorPNG;

header('Content-Type: application/json');

// Función para logging
function logMessage($message, $data = null) {
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message";
    if ($data !== null) {
        $logEntry .= " - Data: " . json_encode($data);
    }
    error_log($logEntry);
    echo $logEntry . "\n"; // También lo envía a stdout para Railway logs
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    logMessage("📥 RECEIVED RAW INPUT", $rawInput);
    
    $input = json_decode($rawInput, true);
    logMessage("📥 PARSED INPUT", $input);
    
    if (isset($input['action']) && $input['action'] === 'print_ticket') {
        
        // Datos hardcodeados para el ticket
        $rs = [
            'impresionmembrete' => '<strong>EMPRESA DE PRUEBA S.A.</strong><br/>RUT: 12.345.678-9<br/>Dirección de Prueba 123',
            'patente' => 'ABC123',
            'ingreso' => date('d/m/Y H:i:s')
        ];
        
        // Generar el HTML del ticket
        logMessage("🎫 GENERATING TICKET HTML");
        $html = ingreso_html_80mm($rs);
        logMessage("✅ HTML GENERATED", strlen($html) . " chars");
        
        // Datos para enviar al servicio de impresión
        $data = [
            'action' => 'print_html',
            'html' => $html
        ];
        logMessage("📤 SENDING TO PRINT SERVICE", [
            'url' => 'http://localhost:5160/print',
            'action' => $data['action'],
            'html_length' => strlen($html)
        ]);

        // Configurar cURL
        $ch = curl_init('http://localhost:5160/print');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlInfo = curl_getinfo($ch);
        curl_close($ch);
        
        logMessage("📡 CURL RESPONSE", [
            'http_code' => $httpCode,
            'curl_error' => $curlError,
            'response' => $response,
            'connect_time' => $curlInfo['connect_time'] ?? 'unknown',
            'total_time' => $curlInfo['total_time'] ?? 'unknown'
        ]);
        
        if ($response !== false && $httpCode === 200) {
            logMessage("✅ SUCCESS - Ticket sent successfully");
            echo json_encode(['success' => true, 'message' => 'Ticket enviado correctamente']);
        } else {
            $errorMsg = "Error conectando con el servicio de impresión. HTTP: $httpCode";
            if ($curlError) {
                $errorMsg .= ", cURL Error: $curlError";
            }
            logMessage("❌ ERROR", $errorMsg);
            echo json_encode(['success' => false, 'error' => $errorMsg]);
        }
    } else {
        logMessage("❌ INVALID ACTION", $input);
        echo json_encode(['success' => false, 'error' => 'Acción no válida']);
    }
} else {
    logMessage("❌ INVALID METHOD", $_SERVER['REQUEST_METHOD']);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
}

function ingreso_html_80mm($rs) {
    ob_start();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title></title>
        <style type="text/css">
            *{
                color:#000 !important;
                font-family:"Helvetica"; 
                font-size:9pt;
            }

            body{
                margin: 0;
                padding: 0; 
            }

            .page{
                padding:7px;
                width:8cm; 
            }
            .dest{
                color:#000; 
                font-weight:bold; 
                font-size:10pt;
            }
            .sep_10px{
                height:5px;
            }

            .sep_2px{
                height:2px;
            }

            .div_block{
                clear:both;
            }

            .tbl_detalle th{
                border-bottom:1px solid #000;
            }

            .tbl_detalle th, .tbl_detalle td{
                text-align:right;
            }

            .ted_td{
                text-align:center;
            }

            .ted_img{
                width: 7.5cm; 
                height: 2.5cm;
                margin-bottom:3px;
            }

            .totales{
                padding-top:0.7cm;
                font-weight:bold;
            }

            .totales td{
                font-weight:bold;
                text-align:right;
                white-space: nowrap;
            }

            .new_page{
                page-break-after: always;
                border: 0;
                margin: 0;
                padding: 0;
            }

            .tbl_detalle th.txtl, .tbl_detalle td.txtl{
                text-align:left;
            }

            .cls{
                clear:both;
            }
        </style>
        <script language="javascript">
            window.onload = function(e){ 
                var is_chrome = Boolean(window.chrome);
                var x = (typeof w == "undefined") ? window : w;
                w = x;
                if (is_chrome) {
                    setTimeout(function () {
                        w.print();
                        w.close();
                    }, 200);
                }
                else {
                    w.print();
                    w.close();
                }
            }
        </script>
    </head>
    <body>
        <div class="page" style="padding:7px 0 !important; width:7.2cm !important; font-size:8pt;">
            <div>
                <table width="100%" cellpadding="0" cellspacing="0">
                    <tbody>
                        <tr>
                            <td align="center" valign="middle"><?php echo $rs['impresionmembrete']; ?></td>
                        </tr>
                        <tr>
                            <td align="center" valign="middle">
                                <div style="margin:5px 0;border-top: 1px solid #000;text-align:center;border-bottom: 1px solid #000;text-align:center;">
                                    <div class="sep_10px"></div>
                                    <div class="dest">Ticket Ingreso</div>
                                    <div class="sep_10px"></div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="div_block">
                <table style="width: 100%;">
                    <tbody>
                        <tr valign="top">
                            <td style="text-align: center !important; font-size: 35px; font-weight: bold;" align="center">
                                <?php echo $rs['patente']; ?>
                            </td>
                        </tr>

                        <tr>
                            <td style=" text-align: center;">
                                <?php
                                $generator = new BarcodeGeneratorPNG();
                                $barcodePNG = $generator->getBarcode($rs['patente'], $generator::TYPE_CODE_128, 2, 40);
                                ?>
                                <img src="data:image/png;base64,<?php echo base64_encode($barcodePNG); ?>">                        
                            </td>
                        </tr>
                        <tr align="center x" valign="top">
                            <td style="text-align:center;"><?php echo $rs['ingreso']; ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="new_page"></div>
    </body>
    </html>
    <?php
    return ob_get_clean();
}
?>