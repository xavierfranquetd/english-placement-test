<?php
declare(strict_types=1);

/**
 * Placement Test — English for Hotel Operations | EstudieMás
 * Archivo único: index.php
 * Hosting: SiteGround (PHP 8.x, hosting compartido)
 * Sin base de datos — persistencia en CSV y JSON
 */

// ---------------------------------------------------------------------------
// CONFIGURACIÓN GLOBAL
// ---------------------------------------------------------------------------

define('DATA_DIR',       __DIR__ . '/data/');
define('CSV_FILE',       DATA_DIR . 'codigos.csv');
define('JSON_FILE',      DATA_DIR . 'tests.json');
define('SESSION_MAX',    3600);  // 60 minutos
define('MAIL_TO',        'TEST_Hospitality@estudiemas.com');
define('MAIL_FROM',      'placement@estudiemas.net');
define('MAIL_FROM_NAME', 'EstudieMás Placement Test');

// ---------------------------------------------------------------------------
// SESIÓN
// ---------------------------------------------------------------------------

ini_set('session.gc_maxlifetime', (string) SESSION_MAX);
ini_set('session.cookie_lifetime', (string) SESSION_MAX);

session_set_cookie_params([
    'lifetime' => SESSION_MAX,
    'path'     => '/',
    'secure'   => isset($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Strict',
]);

session_start();

// Verificar expiración por inactividad
if (isset($_SESSION['ultima_actividad'])) {
    if ((time() - $_SESSION['ultima_actividad']) > SESSION_MAX) {
        session_unset();
        session_destroy();
        session_start();
    }
}
$_SESSION['ultima_actividad'] = time();

// ---------------------------------------------------------------------------
// ENRUTADOR — ¿es una petición AJAX?
// ---------------------------------------------------------------------------

$es_ajax = (
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
) || isset($_POST['accion']) || (
    isset($_SERVER['CONTENT_TYPE']) &&
    str_contains($_SERVER['CONTENT_TYPE'], 'application/json')
);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $es_ajax) {
    manejar_ajax();
    exit;
}

// ---------------------------------------------------------------------------
// HTML DE LA SPA
// ---------------------------------------------------------------------------

mostrar_html();
exit;

// ===========================================================================
// FUNCIONES AJAX
// ===========================================================================

function manejar_ajax(): void
{
    header('Content-Type: application/json; charset=utf-8');

    // Leer cuerpo — puede venir como JSON o como form-data
    $cuerpo = file_get_contents('php://input');
    $datos  = [];

    if (!empty($cuerpo)) {
        $datos = json_decode($cuerpo, true) ?? [];
    }

    // Mezclar con POST convencional (fallback)
    if (empty($datos)) {
        $datos = $_POST;
    }

    $accion = trim((string) ($datos['accion'] ?? ''));

    // Validar CSRF en todas las peticiones POST
    $token_recibido = trim((string) ($datos['csrf_token'] ?? ''));
    $token_esperado = $_SESSION['csrf_token'] ?? '';

    if (empty($token_esperado) || !hash_equals($token_esperado, $token_recibido)) {
        json_error('Token de seguridad inválido. Recargue la página.');
    }

    match ($accion) {
        'validar'   => accion_validar($datos),
        'resultado' => accion_resultado($datos),
        default     => json_error('Acción no reconocida.'),
    };
}

// ---------------------------------------------------------------------------
// ACCIÓN: validar código y devolver preguntas (sin respuestas correctas)
// ---------------------------------------------------------------------------

function accion_validar(array $datos): void
{
    $codigo = trim((string) ($datos['codigo'] ?? ''));

    if (empty($codigo)) {
        json_error('Debe ingresar un código de acceso.');
    }

    // Localizar el código en el CSV
    $fila = buscar_fila_csv($codigo);

    if ($fila === null) {
        json_error('Código inválido. Verifique e intente de nuevo.');
    }

    if ((int) ($fila['usado'] ?? 0) === 1) {
        json_error('Este código ya fue utilizado. Solicite uno nuevo.');
    }

    // Cargar banco de preguntas
    $json_raw = @file_get_contents(JSON_FILE);
    if ($json_raw === false) {
        json_error('Error interno al cargar el examen. Intente más tarde.');
    }

    $todas = json_decode($json_raw, true);
    if (!is_array($todas)) {
        json_error('Error interno: banco de preguntas no disponible.');
    }

    // Asignar versión aleatoria (1–3)
    $version = (string) random_int(1, 3);

    if (!isset($todas[$version]) || !is_array($todas[$version])) {
        json_error('Error interno: versión de examen no disponible.');
    }

    // Guardar versión en sesión — nunca confiar en el cliente
    $_SESSION['version']       = $version;
    $_SESSION['codigo']        = $codigo;
    $_SESSION['examen_activo'] = true;

    // Eliminar respuestas correctas antes de enviar al frontend
    $preguntas_sin_ans = array_map(function (array $p): array {
        return [
            't'    => $p['t'],
            'opts' => $p['opts'],
        ];
    }, $todas[$version]);

    echo json_encode([
        'ok'        => true,
        'version'   => $version,
        'preguntas' => $preguntas_sin_ans,
    ], JSON_UNESCAPED_UNICODE);
}

// ---------------------------------------------------------------------------
// ACCIÓN: recibir respuestas, corregir en servidor y guardar resultado
// ---------------------------------------------------------------------------

function accion_resultado(array $datos): void
{
    // Verificar sesión activa
    if (
        empty($_SESSION['examen_activo']) ||
        empty($_SESSION['version']) ||
        empty($_SESSION['codigo'])
    ) {
        json_error('Su sesión ha expirado. Por favor solicite un nuevo código de acceso.');
    }

    $version       = (string) $_SESSION['version'];  // siempre del servidor
    $codigo_sesion = (string) $_SESSION['codigo'];

    $codigo         = trim((string) ($datos['codigo'] ?? ''));
    $nombre         = trim((string) ($datos['nombre'] ?? ''));
    $correo         = trim((string) ($datos['correo'] ?? ''));
    $respuestas_raw = $datos['respuestas'] ?? [];

    // Validaciones
    if (empty($codigo) || $codigo !== $codigo_sesion) {
        json_error('Código de acceso no coincide con la sesión activa.');
    }

    if (empty($nombre)) {
        json_error('El nombre es obligatorio.');
    }

    if (strlen($nombre) > 200) {
        json_error('El nombre es demasiado largo.');
    }

    if (empty($correo) || filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
        json_error('Debe ingresar un correo electrónico válido.');
    }

    if (!is_array($respuestas_raw)) {
        json_error('Formato de respuestas inválido.');
    }

    // Sanitizar entradas
    $nombre_limpio = htmlspecialchars($nombre, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $correo_limpio = (string) filter_var($correo, FILTER_SANITIZE_EMAIL);

    // Validar cada respuesta: sólo se aceptan "0", "1", "2", "3"
    $respuestas = [];
    foreach ($respuestas_raw as $r) {
        if (in_array((string) $r, ['0', '1', '2', '3'], true)) {
            $respuestas[] = (int) $r;
        } else {
            $respuestas[] = -1; // inválido → incorrecto
        }
    }

    // Cargar preguntas con respuestas correctas (solo en servidor)
    $json_raw = @file_get_contents(JSON_FILE);
    if ($json_raw === false) {
        json_error('Error interno al leer el banco de preguntas.');
    }

    $todas = json_decode($json_raw, true);
    if (!is_array($todas) || !isset($todas[$version])) {
        json_error('Error interno: versión de examen no encontrada.');
    }

    $preguntas = $todas[$version];
    $total     = count($preguntas);

    // Completar respuestas faltantes con -1
    while (count($respuestas) < $total) {
        $respuestas[] = -1;
    }

    // CORRECCIÓN EN EL SERVIDOR — las respuestas correctas nunca salieron al cliente
    $puntaje = 0;
    foreach ($preguntas as $i => $p) {
        if (isset($respuestas[$i]) && (int) $respuestas[$i] === (int) $p['ans']) {
            $puntaje++;
        }
    }

    $pct = ($total > 0) ? round(($puntaje / $total) * 100, 2) : 0.0;

    [$nivel, $decision] = calcular_nivel($puntaje);

    $fecha = date('Y-m-d H:i:s');

    // Bloqueo con flock para evitar doble uso del mismo código
    $lock_file = sys_get_temp_dir() . '/ema_placement_' . md5(CSV_FILE) . '.lock';
    $lock_fp   = @fopen($lock_file, 'c');

    if ($lock_fp === false) {
        json_error('Error interno al procesar el resultado. Intente más tarde.');
    }

    if (!flock($lock_fp, LOCK_EX)) {
        fclose($lock_fp);
        json_error('El sistema está procesando otra solicitud. Intente de nuevo.');
    }

    // Dentro del bloqueo: segunda verificación del código
    $fila = buscar_fila_csv($codigo);

    if ($fila === null) {
        flock($lock_fp, LOCK_UN);
        fclose($lock_fp);
        json_error('Código no encontrado.');
    }

    if ((int) ($fila['usado'] ?? 0) === 1) {
        flock($lock_fp, LOCK_UN);
        fclose($lock_fp);
        json_error('Este código ya fue utilizado.');
    }

    // Marcar código como usado y guardar datos del estudiante
    $ok_csv = actualizar_csv($codigo, $nombre_limpio, $correo_limpio, $fecha);

    flock($lock_fp, LOCK_UN);
    fclose($lock_fp);

    if (!$ok_csv) {
        json_error('Error al guardar el resultado. Contacte al administrador.');
    }

    // Limpiar sesión del examen
    unset($_SESSION['examen_activo'], $_SESSION['version'], $_SESSION['codigo']);

    // Enviar correo de notificación (mail() nativo — fallo no invalida el resultado)
    $enviado = enviar_correo(
        $nombre_limpio,
        $correo_limpio,
        $codigo,
        $version,
        $puntaje,
        $total,
        $pct,
        $nivel,
        $decision,
        $fecha
    );

    echo json_encode([
        'ok'       => true,
        'puntaje'  => $puntaje,
        'total'    => $total,
        'pct'      => $pct,
        'nivel'    => $nivel,
        'decision' => $decision,
        'enviado'  => $enviado,
    ], JSON_UNESCAPED_UNICODE);
}

// ===========================================================================
// FUNCIONES AUXILIARES
// ===========================================================================

/**
 * Retorna [nivel, decision] según el puntaje obtenido.
 */
function calcular_nivel(int $puntaje): array
{
    if ($puntaje >= 20) {
        return ['A2 confirmado', 'ADMITIDO'];
    }
    if ($puntaje >= 15) {
        return ['A2 límite', 'REQUIERE ENTREVISTA'];
    }
    return ['A1 o inferior', 'NO ADMITIDO'];
}

/**
 * Busca una fila en el CSV por código.
 * Detecta la estructura de columnas desde el encabezado.
 * Retorna array asociativo o null si no existe.
 */
function buscar_fila_csv(string $codigo): ?array
{
    $fp = @fopen(CSV_FILE, 'r');
    if ($fp === false) {
        return null;
    }

    $cabecera  = null;
    $resultado = null;

    while (($linea = fgetcsv($fp)) !== false) {
        if ($cabecera === null) {
            $cabecera = array_map('trim', $linea);
            continue;
        }

        // Rellenar celdas faltantes para evitar advertencias
        while (count($linea) < count($cabecera)) {
            $linea[] = '';
        }

        $fila = array_combine($cabecera, array_slice($linea, 0, count($cabecera)));

        if ($fila === false) {
            continue;
        }

        // Comparar con trim para neutralizar finales de línea Windows
        if (trim((string) ($fila['codigo'] ?? '')) === $codigo) {
            $resultado = $fila;
            break;
        }
    }

    fclose($fp);
    return $resultado;
}

/**
 * Lee el CSV completo, actualiza la fila del código y reescribe el archivo.
 * Detecta automáticamente si existe la columna 'correo'.
 */
function actualizar_csv(string $codigo, string $nombre, string $correo, string $fecha): bool
{
    $fp = @fopen(CSV_FILE, 'r');
    if ($fp === false) {
        return false;
    }

    $filas    = [];
    $cabecera = null;
    $tiene_correo = false;

    while (($linea = fgetcsv($fp)) !== false) {
        if ($cabecera === null) {
            $cabecera = array_map('trim', $linea);
            $tiene_correo = in_array('correo', $cabecera, true);
            $filas[] = $linea;
            continue;
        }
        $filas[] = $linea;
    }

    fclose($fp);

    if ($cabecera === null) {
        return false;
    }

    // Índices de columnas obligatorias
    $idx_codigo = array_search('codigo', $cabecera, true);
    $idx_nombre = array_search('nombre_estudiante', $cabecera, true);
    $idx_correo = $tiene_correo ? array_search('correo', $cabecera, true) : false;
    $idx_usado  = array_search('usado', $cabecera, true);
    $idx_fecha  = array_search('fecha_uso', $cabecera, true);

    if ($idx_codigo === false || $idx_nombre === false || $idx_usado === false || $idx_fecha === false) {
        return false;
    }

    $actualizado = false;

    // filas[0] es el encabezado; las filas de datos empiezan en índice 1
    for ($i = 1; $i < count($filas); $i++) {
        while (count($filas[$i]) < count($cabecera)) {
            $filas[$i][] = '';
        }

        if (trim((string) $filas[$i][$idx_codigo]) === $codigo) {
            $filas[$i][$idx_nombre] = $nombre;
            $filas[$i][$idx_usado]  = '1';
            $filas[$i][$idx_fecha]  = $fecha;

            if ($idx_correo !== false) {
                $filas[$i][$idx_correo] = $correo;
            }

            $actualizado = true;
            break;
        }
    }

    if (!$actualizado) {
        return false;
    }

    $fp_w = @fopen(CSV_FILE, 'w');
    if ($fp_w === false) {
        return false;
    }

    foreach ($filas as $fila) {
        fputcsv($fp_w, $fila);
    }

    fclose($fp_w);
    return true;
}

/**
 * Genera y envía el correo de notificación en HTML.
 * Si mail() falla, retorna false — el resultado ya quedó guardado.
 */
function enviar_correo(
    string $nombre,
    string $correo,
    string $codigo,
    string $version,
    int    $puntaje,
    int    $total,
    float  $pct,
    string $nivel,
    string $decision,
    string $fecha
): bool {
    $colores = match ($decision) {
        'ADMITIDO'            => ['fondo' => '#eef3c2', 'texto' => '#29337f'],
        'REQUIERE ENTREVISTA' => ['fondo' => '#f0f1fa', 'texto' => '#29337f'],
        default               => ['fondo' => '#FAECE7', 'texto' => '#712B13'],
    };

    $asunto = 'Placement Test EFH | ' . $nombre . ' | ' . $decision;

    $cuerpo  = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"></head>';
    $cuerpo .= '<body style="font-family:Arial,sans-serif;background:#f5f5f3;margin:0;padding:20px;">';
    $cuerpo .= '<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:10px;border:1px solid #e0ddd6;overflow:hidden;">';

    // Cabecera del correo
    $cuerpo .= '<div style="background:#29337f;padding:24px 32px;">';
    $cuerpo .= '<h1 style="color:#b8ce24;font-family:Georgia,serif;margin:0;font-size:22px;">EstudieMás</h1>';
    $cuerpo .= '<p style="color:#eef3c2;margin:6px 0 0;font-size:14px;">English for Hotel Operations &mdash; Placement Test</p>';
    $cuerpo .= '</div>';

    $cuerpo .= '<div style="padding:32px;">';
    $cuerpo .= '<h2 style="font-family:Georgia,serif;color:#29337f;margin-top:0;">Resultado del Examen</h2>';
    $cuerpo .= '<table style="width:100%;border-collapse:collapse;font-size:14px;">';

    $filas_tabla = [
        ['Nombre',     $nombre],
        ['Correo',     $correo],
        ['Código',     $codigo],
        ['Versión',    $version],
        ['Puntaje',    $puntaje . ' / ' . $total],
        ['Porcentaje', number_format($pct, 2) . '%'],
        ['Nivel',      $nivel],
        ['Fecha',      $fecha],
    ];

    foreach ($filas_tabla as [$etiqueta, $valor]) {
        $cuerpo .= '<tr>';
        $cuerpo .= '<td style="padding:8px 12px;background:#f5f5f3;font-weight:bold;color:#29337f;width:35%;border-bottom:1px solid #e0ddd6;">'
                 . htmlspecialchars($etiqueta, ENT_QUOTES, 'UTF-8') . '</td>';
        $cuerpo .= '<td style="padding:8px 12px;border-bottom:1px solid #e0ddd6;">'
                 . htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8') . '</td>';
        $cuerpo .= '</tr>';
    }

    $cuerpo .= '</table>';

    // Badge de decisión
    $cuerpo .= '<div style="margin-top:24px;text-align:center;">';
    $cuerpo .= '<span style="display:inline-block;padding:12px 28px;border-radius:8px;font-weight:bold;font-size:18px;';
    $cuerpo .= 'background:' . $colores['fondo'] . ';color:' . $colores['texto'] . ';">';
    $cuerpo .= htmlspecialchars($decision, ENT_QUOTES, 'UTF-8');
    $cuerpo .= '</span></div>';

    $cuerpo .= '</div>';
    $cuerpo .= '<div style="padding:16px 32px;background:#f5f5f3;text-align:center;font-size:12px;color:#888;">';
    $cuerpo .= 'EstudieMás &middot; placement@estudiemas.net';
    $cuerpo .= '</div></div></body></html>';

    $cabeceras  = 'MIME-Version: 1.0' . "\r\n";
    $cabeceras .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
    $cabeceras .= 'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>' . "\r\n";
    $cabeceras .= 'Reply-To: ' . MAIL_FROM . "\r\n";
    $cabeceras .= 'X-Mailer: PHP/' . phpversion() . "\r\n";

    return @mail(MAIL_TO, $asunto, $cuerpo, $cabeceras);
}

/**
 * Genera el token CSRF si no existe y lo retorna.
 */
function obtener_csrf(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Termina la ejecución devolviendo un error JSON estandarizado.
 */
function json_error(string $mensaje): never
{
    echo json_encode(['ok' => false, 'mensaje' => $mensaje], JSON_UNESCAPED_UNICODE);
    exit;
}

// ===========================================================================
// HTML — SINGLE PAGE APPLICATION
// ===========================================================================

function mostrar_html(): void
{
    $csrf = obtener_csrf();
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Placement Test &mdash; English for Hotel Operations | EstudieMás</title>
    <style>
        /* ------------------------------------------------------------------ */
        /* Reset y base                                                         */
        /* ------------------------------------------------------------------ */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background: #f5f5f3;
            color: #333;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 24px 16px 48px;
        }

        /* ------------------------------------------------------------------ */
        /* Cabecera                                                             */
        /* ------------------------------------------------------------------ */
        .header {
            width: 100%;
            max-width: 680px;
            background: #29337f;
            border-radius: 10px 10px 0 0;
            padding: 24px 32px;
        }

        .header h1 {
            font-family: Georgia, 'Times New Roman', serif;
            color: #b8ce24;
            font-size: 26px;
            letter-spacing: 0.5px;
        }

        .header p {
            color: #eef3c2;
            font-size: 13px;
            margin-top: 6px;
        }

        /* ------------------------------------------------------------------ */
        /* Tarjeta principal                                                    */
        /* ------------------------------------------------------------------ */
        .card {
            width: 100%;
            max-width: 680px;
            background: #fff;
            border: 1px solid #e0ddd6;
            border-top: none;
            border-radius: 0 0 10px 10px;
            padding: 32px;
        }

        /* ------------------------------------------------------------------ */
        /* Pantallas SPA                                                        */
        /* ------------------------------------------------------------------ */
        .pantalla { display: none; }
        .pantalla.activa { display: block; }

        /* ------------------------------------------------------------------ */
        /* Formulario de entrada                                                */
        /* ------------------------------------------------------------------ */
        .form-group { margin-bottom: 20px; }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: bold;
            color: #29337f;
            margin-bottom: 6px;
        }

        .form-group input {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #c8c5be;
            border-radius: 6px;
            font-size: 15px;
            font-family: Arial, sans-serif;
            transition: border-color 0.2s;
            background: #fff;
        }

        .form-group input:focus {
            outline: none;
            border-color: #29337f;
            box-shadow: 0 0 0 3px rgba(41,51,127,0.1);
        }

        /* ------------------------------------------------------------------ */
        /* Botones                                                              */
        /* ------------------------------------------------------------------ */
        .btn-primario {
            display: inline-block;
            background: #29337f;
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 11px 28px;
            font-size: 15px;
            font-family: Arial, sans-serif;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s;
        }

        .btn-primario:hover:not(:disabled) { background: #1e2563; }
        .btn-primario:active:not(:disabled) { transform: scale(0.98); }
        .btn-primario:disabled { background: #9199bf; cursor: not-allowed; }

        .btn-secundario {
            display: inline-block;
            background: #eef3c2;
            color: #29337f;
            border: 1px solid #b8ce24;
            border-radius: 6px;
            padding: 10px 24px;
            font-size: 14px;
            font-family: Arial, sans-serif;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.2s;
        }

        .btn-secundario:hover { background: #dce87a; }

        .nav-botones {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 24px;
            gap: 12px;
        }

        /* ------------------------------------------------------------------ */
        /* Barra de progreso animada                                            */
        /* ------------------------------------------------------------------ */
        .progreso-wrap {
            background: #e0ddd6;
            border-radius: 8px;
            height: 10px;
            margin-bottom: 20px;
            overflow: hidden;
        }

        .progreso-barra {
            height: 100%;
            background: #b8ce24;
            border-radius: 8px;
            width: 0%;
            transition: width 0.4s ease;
        }

        .progreso-label {
            font-size: 13px;
            color: #666;
            margin-bottom: 8px;
            text-align: right;
        }

        /* ------------------------------------------------------------------ */
        /* Pregunta                                                             */
        /* ------------------------------------------------------------------ */
        .pregunta-texto {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 18px;
            color: #29337f;
            margin-bottom: 20px;
            line-height: 1.55;
        }

        /* ------------------------------------------------------------------ */
        /* Opciones de respuesta                                                */
        /* ------------------------------------------------------------------ */
        .opciones { list-style: none; display: flex; flex-direction: column; gap: 10px; }

        .opciones li {
            border: 1px solid #e0ddd6;
            border-radius: 8px;
            overflow: hidden;
            transition: border-color 0.15s;
        }

        .opciones label {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            cursor: pointer;
            font-size: 15px;
            transition: background 0.15s;
            user-select: none;
        }

        .opciones label:hover { background: #eef3c2; }

        .opciones input[type="radio"] { display: none; }

        .opciones li.seleccionada {
            border-color: #b8ce24;
        }

        .opciones li.seleccionada label {
            background: #eef3c2;
            font-weight: bold;
            color: #29337f;
        }

        .opcion-letra {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #f5f5f3;
            border: 1px solid #c8c5be;
            font-weight: bold;
            font-size: 13px;
            flex-shrink: 0;
            color: #29337f;
        }

        /* ------------------------------------------------------------------ */
        /* Spinner global                                                       */
        /* ------------------------------------------------------------------ */
        .spinner-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(245,245,243,0.85);
            z-index: 999;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 16px;
        }

        .spinner-overlay.visible { display: flex; }

        .spinner {
            width: 44px;
            height: 44px;
            border: 4px solid #e0ddd6;
            border-top-color: #29337f;
            border-radius: 50%;
            animation: girar 0.8s linear infinite;
        }

        @keyframes girar { to { transform: rotate(360deg); } }

        .spinner-texto { color: #29337f; font-size: 14px; font-weight: bold; }

        /* ------------------------------------------------------------------ */
        /* Pantalla de resultado                                                */
        /* ------------------------------------------------------------------ */
        .resultado-puntaje { text-align: center; margin-bottom: 28px; }

        .resultado-numero {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 64px;
            font-weight: bold;
            color: #29337f;
            line-height: 1;
        }

        .resultado-total { font-size: 16px; color: #666; margin-top: 6px; }

        .resultado-pct {
            font-size: 24px;
            color: #29337f;
            font-weight: bold;
            margin-top: 10px;
        }

        .badge-decision {
            display: block;
            text-align: center;
            padding: 14px 28px;
            border-radius: 8px;
            font-size: 20px;
            font-weight: bold;
            margin: 20px 0;
            letter-spacing: 0.5px;
        }

        .badge-admitido      { background: #eef3c2; color: #29337f; }
        .badge-entrevista    { background: #f0f1fa; color: #29337f; }
        .badge-no-admitido   { background: #FAECE7; color: #712B13; }

        .resultado-nivel {
            text-align: center;
            color: #555;
            font-size: 15px;
            margin-bottom: 20px;
        }

        /* ------------------------------------------------------------------ */
        /* Alertas de error                                                     */
        /* ------------------------------------------------------------------ */
        .alerta {
            background: #FAECE7;
            border-left: 4px solid #c0392b;
            color: #712B13;
            border-radius: 6px;
            padding: 12px 16px;
            font-size: 14px;
            margin-bottom: 16px;
            display: none;
        }

        .alerta.visible { display: block; }

        /* ------------------------------------------------------------------ */
        /* Separador                                                            */
        /* ------------------------------------------------------------------ */
        .separador { border: none; border-top: 1px solid #e0ddd6; margin: 24px 0; }

        /* ------------------------------------------------------------------ */
        /* Responsive                                                           */
        /* ------------------------------------------------------------------ */
        @media (max-width: 480px) {
            .card { padding: 20px 16px; }
            .header { padding: 18px 16px; }
            .resultado-numero { font-size: 48px; }
            .nav-botones { flex-wrap: wrap; }
            .nav-botones button { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>

<!-- SPINNER GLOBAL -->
<div class="spinner-overlay" id="spinnerOverlay" role="status" aria-live="polite" aria-label="Procesando">
    <div class="spinner"></div>
    <p class="spinner-texto">Procesando&hellip;</p>
</div>

<!-- CABECERA -->
<header class="header">
    <h1>EstudieMás</h1>
    <p>English for Hotel Operations &mdash; Placement Test</p>
</header>

<!-- TARJETA PRINCIPAL -->
<main class="card">

    <!-- ============================================================ -->
    <!-- PANTALLA 1 — ENTRADA                                          -->
    <!-- ============================================================ -->
    <section class="pantalla activa" id="pantalla-entrada" aria-label="Registro">

        <h2 style="font-family:Georgia,serif;color:#29337f;margin-bottom:6px;">Bienvenido</h2>
        <p style="color:#666;font-size:14px;margin-bottom:24px;">
            Complete los campos a continuación para iniciar su examen de ubicación.
        </p>

        <div class="alerta" id="error-entrada" role="alert"></div>

        <form id="form-entrada" novalidate>
            <div class="form-group">
                <label for="campo-codigo">Código de acceso</label>
                <input
                    type="text"
                    id="campo-codigo"
                    name="codigo"
                    placeholder="Ej. EMA-2026-001"
                    autocomplete="off"
                    maxlength="40"
                    spellcheck="false"
                    required
                >
            </div>

            <div class="form-group">
                <label for="campo-nombre">Nombre completo</label>
                <input
                    type="text"
                    id="campo-nombre"
                    name="nombre"
                    placeholder="Ingrese su nombre y apellido"
                    autocomplete="name"
                    maxlength="200"
                    required
                >
            </div>

            <div class="form-group">
                <label for="campo-correo">Correo electrónico</label>
                <input
                    type="email"
                    id="campo-correo"
                    name="correo"
                    placeholder="nombre@ejemplo.com"
                    autocomplete="email"
                    maxlength="200"
                    required
                >
            </div>

            <button type="submit" class="btn-primario" id="btn-comenzar">
                Comenzar examen
            </button>
        </form>
    </section>

    <!-- ============================================================ -->
    <!-- PANTALLA 2 — TEST                                             -->
    <!-- ============================================================ -->
    <section class="pantalla" id="pantalla-test" aria-label="Examen">

        <p class="progreso-label" id="progreso-label">Pregunta 1 de 30</p>
        <div
            class="progreso-wrap"
            role="progressbar"
            aria-valuemin="0"
            aria-valuemax="100"
            aria-valuenow="0"
            id="progreso-wrap"
        >
            <div class="progreso-barra" id="progreso-barra"></div>
        </div>

        <div class="alerta" id="error-test" role="alert"></div>

        <p class="pregunta-texto" id="pregunta-texto"></p>

        <ul class="opciones" id="lista-opciones" role="radiogroup" aria-label="Opciones de respuesta"></ul>

        <div class="nav-botones">
            <button class="btn-secundario" id="btn-anterior"  type="button">&#8592; Anterior</button>
            <button class="btn-primario"   id="btn-siguiente" type="button">Siguiente &#8594;</button>
            <button class="btn-primario"   id="btn-finalizar" type="button" style="display:none;">Ver resultado</button>
        </div>
    </section>

    <!-- ============================================================ -->
    <!-- PANTALLA 3 — RESULTADO                                        -->
    <!-- ============================================================ -->
    <section class="pantalla" id="pantalla-resultado" aria-label="Resultado del examen">

        <h2 style="font-family:Georgia,serif;color:#29337f;margin-bottom:20px;">Su resultado</h2>

        <div class="alerta" id="error-resultado" role="alert"></div>

        <div class="resultado-puntaje">
            <div class="resultado-numero" id="res-puntaje">—</div>
            <div class="resultado-total"  id="res-total">de 30 respuestas correctas</div>
            <div class="resultado-pct"    id="res-pct"></div>
        </div>

        <span class="badge-decision" id="res-decision" aria-live="polite"></span>

        <p class="resultado-nivel" id="res-nivel"></p>

        <hr class="separador">

        <p style="font-size:13px;color:#888;text-align:center;">
            Su resultado ha sido registrado. Recibirá confirmación por correo electrónico.
        </p>

    </section>

</main>

<script>
(function () {
    'use strict';

    // ------------------------------------------------------------------
    // Estado de la aplicación (no expone respuestas correctas)
    // ------------------------------------------------------------------
    const estado = {
        csrfToken:      '<?= $csrf ?>',
        codigo:         '',
        nombre:         '',
        correo:         '',
        version:        '',
        preguntas:      [],
        respuestas:     [],   // índice seleccionado (-1 = sin responder)
        preguntaActual: 0,
    };

    // ------------------------------------------------------------------
    // Referencias DOM
    // ------------------------------------------------------------------
    const $pantallaEntrada   = document.getElementById('pantalla-entrada');
    const $pantallaTest      = document.getElementById('pantalla-test');
    const $pantallaResultado = document.getElementById('pantalla-resultado');

    const $formEntrada   = document.getElementById('form-entrada');
    const $campoCodigo   = document.getElementById('campo-codigo');
    const $campoNombre   = document.getElementById('campo-nombre');
    const $campoCorreo   = document.getElementById('campo-correo');
    const $btnComenzar   = document.getElementById('btn-comenzar');
    const $errorEntrada  = document.getElementById('error-entrada');

    const $progresoLabel = document.getElementById('progreso-label');
    const $progresoBarra = document.getElementById('progreso-barra');
    const $progresoWrap  = document.getElementById('progreso-wrap');
    const $preguntaTexto = document.getElementById('pregunta-texto');
    const $listaOpciones = document.getElementById('lista-opciones');
    const $btnAnterior   = document.getElementById('btn-anterior');
    const $btnSiguiente  = document.getElementById('btn-siguiente');
    const $btnFinalizar  = document.getElementById('btn-finalizar');
    const $errorTest     = document.getElementById('error-test');

    const $resPuntaje     = document.getElementById('res-puntaje');
    const $resTotal       = document.getElementById('res-total');
    const $resPct         = document.getElementById('res-pct');
    const $resDecision    = document.getElementById('res-decision');
    const $resNivel       = document.getElementById('res-nivel');
    const $errorResultado = document.getElementById('error-resultado');

    const $spinnerOverlay = document.getElementById('spinnerOverlay');

    // ------------------------------------------------------------------
    // Helpers de UI
    // ------------------------------------------------------------------

    function mostrarPantalla(id) {
        [$pantallaEntrada, $pantallaTest, $pantallaResultado].forEach(function (p) {
            p.classList.remove('activa');
        });
        document.getElementById(id).classList.add('activa');
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function mostrarSpinner() { $spinnerOverlay.classList.add('visible'); }
    function ocultarSpinner() { $spinnerOverlay.classList.remove('visible'); }

    function mostrarError(el, msg) {
        el.textContent = msg;
        el.classList.add('visible');
        el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function ocultarError(el) {
        el.textContent = '';
        el.classList.remove('visible');
    }

    // ------------------------------------------------------------------
    // AJAX — todas las peticiones pasan por aquí
    // ------------------------------------------------------------------

    async function postJSON(payload) {
        const resp = await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type':     'application/json; charset=utf-8',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(Object.assign({ csrf_token: estado.csrfToken }, payload)),
        });

        if (!resp.ok) {
            throw new Error('Error HTTP: ' + resp.status);
        }

        return resp.json();
    }

    // ------------------------------------------------------------------
    // PANTALLA 1 — Validar código
    // ------------------------------------------------------------------

    $formEntrada.addEventListener('submit', async function (e) {
        e.preventDefault();
        ocultarError($errorEntrada);

        const codigo = $campoCodigo.value.trim();
        const nombre = $campoNombre.value.trim();
        const correo = $campoCorreo.value.trim();

        if (!codigo) {
            mostrarError($errorEntrada, 'Debe ingresar el código de acceso.');
            $campoCodigo.focus();
            return;
        }
        if (!nombre) {
            mostrarError($errorEntrada, 'Debe ingresar su nombre completo.');
            $campoNombre.focus();
            return;
        }
        if (!correo || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(correo)) {
            mostrarError($errorEntrada, 'Debe ingresar un correo electrónico válido.');
            $campoCorreo.focus();
            return;
        }

        $btnComenzar.disabled = true;
        mostrarSpinner();

        try {
            const res = await postJSON({ accion: 'validar', codigo: codigo });

            if (!res.ok) {
                mostrarError($errorEntrada, res.mensaje || 'Error al validar el código.');
                return;
            }

            // Guardar datos en estado local (no las respuestas correctas, que nunca llegan)
            estado.codigo         = codigo;
            estado.nombre         = nombre;
            estado.correo         = correo;
            estado.version        = res.version;
            estado.preguntas      = res.preguntas;
            estado.respuestas     = new Array(res.preguntas.length).fill(-1);
            estado.preguntaActual = 0;

            mostrarPantalla('pantalla-test');
            renderizarPregunta();

        } catch (err) {
            mostrarError($errorEntrada, 'Error de conexión. Verifique su internet e intente de nuevo.');
            console.error(err);
        } finally {
            $btnComenzar.disabled = false;
            ocultarSpinner();
        }
    });

    // ------------------------------------------------------------------
    // PANTALLA 2 — Renderizar pregunta actual
    // ------------------------------------------------------------------

    const LETRAS = ['A', 'B', 'C', 'D'];

    function renderizarPregunta() {
        const i     = estado.preguntaActual;
        const p     = estado.preguntas[i];
        const total = estado.preguntas.length;

        // Actualizar progreso
        const pct = Math.round(((i + 1) / total) * 100);
        $progresoLabel.textContent = 'Pregunta ' + (i + 1) + ' de ' + total;
        $progresoBarra.style.width = pct + '%';
        $progresoWrap.setAttribute('aria-valuenow', String(pct));

        // Texto de la pregunta
        $preguntaTexto.textContent = p.t;

        // Construir lista de opciones
        $listaOpciones.innerHTML = '';

        p.opts.forEach(function (opcion, j) {
            const li    = document.createElement('li');
            const radio = document.createElement('input');
            const lbl   = document.createElement('label');
            const span  = document.createElement('span');
            const idR   = 'opcion-' + i + '-' + j;

            radio.type  = 'radio';
            radio.name  = 'respuesta';
            radio.value = String(j);
            radio.id    = idR;

            // Restaurar selección previa
            if (estado.respuestas[i] === j) {
                radio.checked = true;
                li.classList.add('seleccionada');
            }

            radio.addEventListener('change', function () {
                estado.respuestas[i] = j;
                // Actualizar clases de selección
                $listaOpciones.querySelectorAll('li').forEach(function (item, idx) {
                    item.classList.toggle('seleccionada', idx === j);
                });
            });

            span.className   = 'opcion-letra';
            span.textContent = LETRAS[j];
            span.setAttribute('aria-hidden', 'true');

            lbl.setAttribute('for', idR);
            lbl.appendChild(span);
            lbl.appendChild(document.createTextNode(' ' + opcion));

            li.appendChild(radio);
            li.appendChild(lbl);
            $listaOpciones.appendChild(li);
        });

        // Visibilidad de botones de navegación
        $btnAnterior.style.display  = i === 0 ? 'none' : 'inline-block';
        $btnSiguiente.style.display = i === total - 1 ? 'none' : 'inline-block';
        $btnFinalizar.style.display = i === total - 1 ? 'inline-block' : 'none';

        ocultarError($errorTest);
    }

    $btnAnterior.addEventListener('click', function () {
        if (estado.preguntaActual > 0) {
            estado.preguntaActual--;
            renderizarPregunta();
        }
    });

    $btnSiguiente.addEventListener('click', function () {
        if (estado.preguntaActual < estado.preguntas.length - 1) {
            estado.preguntaActual++;
            renderizarPregunta();
        }
    });

    $btnFinalizar.addEventListener('click', function () {
        const sinResponder = estado.respuestas.filter(function (r) { return r === -1; }).length;

        if (sinResponder > 0) {
            const msg = sinResponder === 1
                ? 'Hay 1 pregunta sin responder. ¿Desea enviar el examen de todas formas?'
                : 'Hay ' + sinResponder + ' preguntas sin responder. ¿Desea enviar el examen de todas formas?';
            if (!window.confirm(msg)) return;
        }

        enviarResultado();
    });

    // ------------------------------------------------------------------
    // PANTALLA 3 — Enviar resultado al servidor
    // ------------------------------------------------------------------

    async function enviarResultado() {
        ocultarError($errorResultado);
        mostrarSpinner();

        // Mapear -1 (sin responder) a 99 para que el servidor lo invalide
        const respuestasEnvio = estado.respuestas.map(function (r) {
            return r === -1 ? 99 : r;
        });

        try {
            const res = await postJSON({
                accion:     'resultado',
                codigo:     estado.codigo,
                nombre:     estado.nombre,
                correo:     estado.correo,
                respuestas: respuestasEnvio,
            });

            mostrarPantalla('pantalla-resultado');

            if (!res.ok) {
                mostrarError($errorResultado, res.mensaje || 'Error al procesar el resultado.');
                return;
            }

            // Mostrar resultado
            $resPuntaje.textContent  = String(res.puntaje);
            $resTotal.textContent    = 'de ' + res.total + ' respuestas correctas';
            $resPct.textContent      = parseFloat(res.pct).toFixed(2) + '%';
            $resNivel.textContent    = 'Nivel estimado: ' + res.nivel;
            $resDecision.textContent = res.decision;

            // Badge con color según decisión
            $resDecision.className = 'badge-decision';
            if (res.decision === 'ADMITIDO') {
                $resDecision.classList.add('badge-admitido');
            } else if (res.decision === 'REQUIERE ENTREVISTA') {
                $resDecision.classList.add('badge-entrevista');
            } else {
                $resDecision.classList.add('badge-no-admitido');
            }

        } catch (err) {
            mostrarPantalla('pantalla-resultado');
            mostrarError($errorResultado,
                'Error de conexión al enviar el resultado. Contacte al administrador.');
            console.error(err);
        } finally {
            ocultarSpinner();
        }
    }

})();
</script>

</body>
</html>
<?php
}
