<?php
// ─── CONFIGURACIÓN ───────────────────────────────────────────────────────────
define('CORREO_DESTINO', 'xfranquet@estudiemas.net');
define('RUTA_CODIGOS',   __DIR__ . '/data/codigos.csv');
define('RUTA_TESTS',     __DIR__ . '/data/tests.json');
define('RUTA_LOCK',      __DIR__ . '/data/.lock');

// ─── FUNCIONES DE CÓDIGOS ────────────────────────────────────────────────────
function leerCodigos() {
    $codigos = [];
    if (!file_exists(RUTA_CODIGOS)) return $codigos;
    $f = fopen(RUTA_CODIGOS, 'r');
    fgetcsv($f); // cabecera
    while (($fila = fgetcsv($f)) !== false) {
        if (count($fila) >= 4) {
            $codigos[] = [
                'codigo'            => trim($fila[0]),
                'nombre_estudiante' => trim($fila[1]),
                'usado'             => trim($fila[2]),
                'fecha_uso'         => trim($fila[3]),
            ];
        }
    }
    fclose($f);
    return $codigos;
}

function guardarCodigos($codigos) {
    $f = fopen(RUTA_CODIGOS, 'w');
    fputcsv($f, ['codigo','nombre_estudiante','usado','fecha_uso']);
    foreach ($codigos as $c) {
        fputcsv($f, [$c['codigo'], $c['nombre_estudiante'], $c['usado'], $c['fecha_uso']]);
    }
    fclose($f);
}

function validarCodigo($codigo) {
    $codigos = leerCodigos();
    foreach ($codigos as $c) {
        if (strtoupper($c['codigo']) === strtoupper(trim($codigo))) {
            if ($c['usado'] === '1') return ['ok' => false, 'msg' => 'Este codigo ya fue utilizado.'];
            return ['ok' => true, 'msg' => 'valido'];
        }
    }
    return ['ok' => false, 'msg' => 'Codigo no reconocido. Contacta a EstudieMas.'];
}

function marcarUsado($codigo, $nombre) {
    $lock = fopen(RUTA_LOCK, 'w');
    flock($lock, LOCK_EX);
    $codigos = leerCodigos();
    foreach ($codigos as &$c) {
        if (strtoupper($c['codigo']) === strtoupper(trim($codigo))) {
            $c['usado']             = '1';
            $c['nombre_estudiante'] = $nombre;
            $c['fecha_uso']         = date('Y-m-d H:i:s');
        }
    }
    guardarCodigos($codigos);
    flock($lock, LOCK_UN);
    fclose($lock);
}

// ─── AJAX ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    header('Content-Type: application/json');

    // Validar código
    if ($_POST['accion'] === 'validar') {
        $codigo = trim($_POST['codigo'] ?? '');
        if (!$codigo) { echo json_encode(['ok'=>false,'msg'=>'Ingresa un codigo.']); exit; }
        $resultado = validarCodigo($codigo);
        if ($resultado['ok']) {
            // Asignar versión aleatoria y devolver preguntas SIN respuestas
            $version  = strval(rand(1, 3));
            $tests    = json_decode(file_get_contents(RUTA_TESTS), true);
            $preguntas = $tests[$version];
            $frontend = array_map(function($q) {
                return ['t' => $q['t'], 'opts' => $q['opts']];
            }, $preguntas);
            echo json_encode(['ok' => true, 'version' => $version, 'preguntas' => $frontend]);
        } else {
            echo json_encode($resultado);
        }
        exit;
    }

    // Corregir y enviar resultado
    if ($_POST['accion'] === 'resultado') {
        $codigo    = trim($_POST['codigo']  ?? '');
        $nombre    = htmlspecialchars(trim($_POST['nombre']  ?? ''), ENT_QUOTES);
        $correo    = filter_var(trim($_POST['correo'] ?? ''), FILTER_VALIDATE_EMAIL);
        $version   = trim($_POST['version'] ?? '');
        $respuestas = json_decode($_POST['respuestas'] ?? '[]', true);

        if (!$nombre || !$correo || !$codigo || !$version || !is_array($respuestas)) {
            echo json_encode(['ok'=>false,'msg'=>'Datos incompletos.']); exit;
        }

        $val = validarCodigo($codigo);
        if (!$val['ok']) { echo json_encode($val); exit; }

        // Corregir en el servidor
        $tests     = json_decode(file_get_contents(RUTA_TESTS), true);
        $preguntas = $tests[$version];
        $total     = count($preguntas);
        $puntaje   = 0;
        foreach ($preguntas as $i => $q) {
            if (isset($respuestas[$i]) && intval($respuestas[$i]) === intval($q['ans'])) {
                $puntaje++;
            }
        }
        $pct = round(($puntaje / $total) * 100);

        // Nivel
        if ($puntaje >= 20) {
            $nivel    = 'A2 confirmado';
            $decision = 'ADMITIDO';
            $color    = '#27500A'; $bg = '#EAF3DE';
        } elseif ($puntaje >= 15) {
            $nivel    = 'A2 limite';
            $decision = 'REQUIERE ENTREVISTA';
            $color    = '#633806'; $bg = '#FAEEDA';
        } else {
            $nivel    = 'A1 o inferior';
            $decision = 'NO ADMITIDO';
            $color    = '#712B13'; $bg = '#FAECE7';
        }

        marcarUsado($codigo, $nombre);

        // Correo
        $asunto  = "Placement Test EFH | $nombre | $decision";
        $mensaje = "
<!DOCTYPE html><html><body style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;'>
<h2 style='color:#0F6E56;border-bottom:2px solid #1D9E75;padding-bottom:8px;'>
  Resultado — English for Hotel Operations Placement Test
</h2>
<table style='width:100%;border-collapse:collapse;margin:20px 0;'>
  <tr><td style='padding:8px;color:#555;width:160px;'>Nombre</td><td style='padding:8px;font-weight:bold;'>$nombre</td></tr>
  <tr style='background:#f9f9f9;'><td style='padding:8px;color:#555;'>Correo</td><td style='padding:8px;'>$correo</td></tr>
  <tr><td style='padding:8px;color:#555;'>Codigo</td><td style='padding:8px;'>$codigo</td></tr>
  <tr style='background:#f9f9f9;'><td style='padding:8px;color:#555;'>Version del test</td><td style='padding:8px;'>$version</td></tr>
  <tr><td style='padding:8px;color:#555;'>Puntaje</td><td style='padding:8px;font-weight:bold;'>$puntaje / $total ($pct%)</td></tr>
  <tr style='background:#f9f9f9;'><td style='padding:8px;color:#555;'>Nivel estimado</td><td style='padding:8px;'>$nivel</td></tr>
  <tr><td style='padding:8px;color:#555;'>Decision</td>
    <td style='padding:8px;'><span style='background:$bg;color:$color;padding:4px 12px;border-radius:12px;font-weight:bold;'>$decision</span></td>
  </tr>
  <tr style='background:#f9f9f9;'><td style='padding:8px;color:#555;'>Fecha</td><td style='padding:8px;'>" . date('d/m/Y H:i') . " (Caracas)</td></tr>
</table>
<p style='color:#888;font-size:12px;margin-top:20px;'>
  Generado automaticamente por el sistema de placement de EstudieMas.<br>
  El codigo $codigo ha sido marcado como utilizado.
</p>
</body></html>";

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: placement@estudiemas.com\r\n";
        $headers .= "Reply-To: $correo\r\n";

        $enviado = mail(CORREO_DESTINO, $asunto, $mensaje, $headers);

        echo json_encode([
            'ok'       => true,
            'puntaje'  => $puntaje,
            'total'    => $total,
            'pct'      => $pct,
            'nivel'    => $nivel,
            'decision' => $decision,
            'enviado'  => $enviado
        ]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Placement Test — English for Hotel Operations | EstudieMas</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --verde: #1D9E75; --verde-oscuro: #0F6E56; --verde-claro: #E1F5EE;
  --gris-bg: #f5f5f3; --gris-borde: #e0ddd6;
  --texto: #1a1a18; --texto-2: #666; --radio: 10px;
}
body { font-family: Georgia, 'Times New Roman', serif; background: var(--gris-bg); color: var(--texto); min-height: 100vh; }
.shell { max-width: 680px; margin: 0 auto; padding: 2rem 1rem 4rem; }

.brand { text-align: center; margin-bottom: 2rem; }
.brand h1 { font-size: 20px; font-weight: normal; color: var(--verde-oscuro); letter-spacing: .02em; }
.brand h2 { font-size: 14px; font-weight: normal; color: var(--texto-2); font-family: Arial, sans-serif; margin-top: 4px; }

.screen { display: none; }
.screen.activa { display: block; }

.card { background: #fff; border: 1px solid var(--gris-borde); border-radius: var(--radio); padding: 1.5rem; margin-bottom: 1rem; }

label { font-size: 13px; color: var(--texto-2); font-family: Arial, sans-serif; display: block; margin-bottom: 6px; }
input[type=text], input[type=email] {
  width: 100%; padding: 10px 12px; border: 1px solid var(--gris-borde);
  border-radius: 8px; font-size: 15px; font-family: Arial, sans-serif;
  background: #fafaf8; transition: border-color .2s;
}
input:focus { outline: none; border-color: var(--verde); }
.campo { margin-bottom: 1rem; }

.btn { display: inline-block; padding: 10px 24px; border-radius: 8px; font-size: 14px; font-family: Arial, sans-serif; cursor: pointer; border: none; transition: background .2s; }
.btn-primary { background: var(--verde); color: #fff; }
.btn-primary:hover { background: var(--verde-oscuro); }
.btn-secondary { background: transparent; color: var(--texto); border: 1px solid var(--gris-borde); }
.btn-secondary:hover { background: var(--gris-bg); }
.btn:disabled { opacity: .4; cursor: not-allowed; }

.prog-wrap { background: #e8e8e4; border-radius: 99px; height: 5px; margin-bottom: 1.5rem; }
.prog-bar  { background: var(--verde); height: 5px; border-radius: 99px; transition: width .3s; }

.q-num  { font-size: 11px; color: var(--texto-2); font-family: Arial, sans-serif; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 8px; }
.q-text { font-size: 16px; line-height: 1.6; margin-bottom: 1.25rem; }
.opciones { display: flex; flex-direction: column; gap: 8px; }
.opcion {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 14px; border: 1px solid var(--gris-borde);
  border-radius: 8px; cursor: pointer; font-family: Arial, sans-serif;
  font-size: 14px; transition: all .15s; background: #fafaf8; user-select: none;
}
.opcion:hover { border-color: var(--verde); background: var(--verde-claro); }
.opcion.seleccionada { border-color: var(--verde); background: var(--verde-claro); font-weight: bold; }
.letra { color: var(--texto-2); min-width: 20px; font-size: 13px; }

.nav { display: flex; justify-content: space-between; align-items: center; margin-top: 1rem; }
.contador { font-size: 13px; color: var(--texto-2); font-family: Arial, sans-serif; }

.resultado-centro { text-align: center; padding: 1rem 0; }
.score-big { font-size: 52px; color: var(--verde-oscuro); margin: .5rem 0; font-family: Arial, sans-serif; }
.nivel-txt { font-size: 20px; margin-bottom: .5rem; }
.badge { display: inline-block; padding: 5px 16px; border-radius: 99px; font-size: 13px; font-family: Arial, sans-serif; font-weight: bold; margin-bottom: 1.5rem; }
.badge-pass   { background: #EAF3DE; color: #27500A; }
.badge-border { background: #FAEEDA; color: #633806; }
.badge-fail   { background: #FAECE7; color: #712B13; }
.desc-resultado { font-size: 14px; color: var(--texto-2); line-height: 1.7; font-family: Arial, sans-serif; max-width: 440px; margin: 0 auto 1.5rem; }
.metricas { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin: 1.25rem 0; }
.metrica { background: var(--gris-bg); border-radius: 8px; padding: .9rem; text-align: center; }
.metrica-val { font-size: 22px; font-weight: bold; font-family: Arial, sans-serif; }
.metrica-lbl { font-size: 12px; color: var(--texto-2); font-family: Arial, sans-serif; margin-top: 2px; }

.error-msg { color: #993C1D; font-size: 13px; font-family: Arial, sans-serif; margin-top: 6px; }
.info-msg  { color: var(--texto-2); font-size: 13px; font-family: Arial, sans-serif; margin-top: 8px; }

.spinner { display: inline-block; width: 14px; height: 14px; border: 2px solid #ccc; border-top-color: var(--verde); border-radius: 50%; animation: spin .7s linear infinite; vertical-align: middle; margin-right: 6px; }
@keyframes spin { to { transform: rotate(360deg); } }

@media (max-width: 480px) {
  .metricas { grid-template-columns: 1fr 1fr; }
  .score-big { font-size: 40px; }
}
</style>
</head>
<body>
<div class="shell">

  <div class="brand">
    <h1>EstudieMas</h1>
    <h2>English for Hotel Operations — Placement Test</h2>
  </div>

  <!-- PANTALLA 1: ENTRADA -->
  <div class="screen activa" id="s-entrada">
    <div class="card">
      <div class="campo">
        <label>Codigo de acceso</label>
        <input type="text" id="inp-codigo" placeholder="Ej: EMA-2026-001" autocomplete="off" style="text-transform:uppercase;" onkeydown="if(event.key==='Enter') entrar()">
        <div id="msg-codigo" class="error-msg" style="display:none;"></div>
      </div>
      <div class="campo">
        <label>Tu nombre completo</label>
        <input type="text" id="inp-nombre" placeholder="Nombre y apellido">
      </div>
      <div class="campo">
        <label>Tu correo electronico</label>
        <input type="email" id="inp-correo" placeholder="tu@correo.com">
      </div>
      <button class="btn btn-primary" id="btn-entrar" onclick="entrar()">Comenzar test →</button>
    </div>
  </div>

  <!-- PANTALLA 2: TEST -->
  <div class="screen" id="s-test">
    <div class="prog-wrap"><div class="prog-bar" id="pbar" style="width:0%"></div></div>
    <div id="pregunta-container"></div>
    <div class="nav">
      <button class="btn btn-secondary" id="btn-prev" onclick="navegar(-1)">← Anterior</button>
      <span class="contador" id="contador">1 / 30</span>
      <button class="btn btn-primary"   id="btn-next" onclick="navegar(1)">Siguiente →</button>
    </div>
  </div>

  <!-- PANTALLA 3: RESULTADO -->
  <div class="screen" id="s-resultado">
    <div class="card resultado-centro">
      <div style="font-size:12px;color:var(--texto-2);font-family:Arial,sans-serif;text-transform:uppercase;letter-spacing:.06em;">Resultado</div>
      <div class="score-big" id="res-score">…</div>
      <div class="nivel-txt" id="res-nivel"></div>
      <span class="badge" id="res-badge"></span>
      <div class="metricas">
        <div class="metrica"><div class="metrica-val" id="m-correctas">—</div><div class="metrica-lbl">correctas</div></div>
        <div class="metrica"><div class="metrica-val" id="m-incorrectas">—</div><div class="metrica-lbl">incorrectas</div></div>
        <div class="metrica"><div class="metrica-val" id="m-pct">—</div><div class="metrica-lbl">porcentaje</div></div>
      </div>
      <p class="desc-resultado" id="res-desc"></p>
      <div id="msg-envio" class="info-msg"></div>
    </div>
  </div>

</div>

<script>
let estado = {
  codigo: '', nombre: '', correo: '',
  version: '', preguntas: [],
  actual: 0, respuestas: []
};

async function entrar() {
  const codigo = document.getElementById('inp-codigo').value.trim().toUpperCase();
  const nombre = document.getElementById('inp-nombre').value.trim();
  const correo = document.getElementById('inp-correo').value.trim();
  const msgEl  = document.getElementById('msg-codigo');
  const btn    = document.getElementById('btn-entrar');

  if (!codigo || !nombre || !correo) {
    msgEl.textContent = 'Completa todos los campos.';
    msgEl.style.display = 'block'; return;
  }
  if (!/\S+@\S+\.\S+/.test(correo)) {
    msgEl.textContent = 'Ingresa un correo valido.';
    msgEl.style.display = 'block'; return;
  }

  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Verificando…';
  msgEl.style.display = 'none';

  const fd = new FormData();
  fd.append('accion', 'validar');
  fd.append('codigo', codigo);

  try {
    const res  = await fetch('', { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.ok) {
      msgEl.textContent = data.msg;
      msgEl.style.display = 'block';
      btn.disabled = false;
      btn.textContent = 'Comenzar test →';
      return;
    }
    estado = {
      codigo, nombre, correo,
      version: data.version,
      preguntas: data.preguntas,
      actual: 0,
      respuestas: new Array(data.preguntas.length).fill(null)
    };
    mostrar('s-test');
    renderPregunta(0);
  } catch(e) {
    msgEl.textContent = 'Error de conexion. Intenta de nuevo.';
    msgEl.style.display = 'block';
    btn.disabled = false;
    btn.textContent = 'Comenzar test →';
  }
}

function renderPregunta(i) {
  const q      = estado.preguntas[i];
  const letras = ['a','b','c','d'];
  const total  = estado.preguntas.length;
  const resp   = estado.respuestas[i];

  const optsHtml = q.opts.map((o, oi) => {
    const sel = resp === oi ? ' seleccionada' : '';
    return `<div class="opcion${sel}" onclick="seleccionar(${i},${oi})">
      <span class="letra">${letras[oi]})</span> ${o}
    </div>`;
  }).join('');

  document.getElementById('pregunta-container').innerHTML = `
    <div class="card">
      <div class="q-num">Pregunta ${i+1} de ${total}</div>
      <div class="q-text">${q.t}</div>
      <div class="opciones">${optsHtml}</div>
    </div>`;

  document.getElementById('contador').textContent = `${i+1} / ${total}`;
  document.getElementById('pbar').style.width = Math.round(((i+1)/total)*100) + '%';
  document.getElementById('btn-prev').disabled = (i === 0);

  const btnNext = document.getElementById('btn-next');
  if (i === total - 1) {
    btnNext.textContent = 'Ver resultado →';
    btnNext.onclick = enviarResultado;
  } else {
    btnNext.textContent = 'Siguiente →';
    btnNext.onclick = () => navegar(1);
  }
}

function seleccionar(qi, oi) {
  estado.respuestas[qi] = oi;
  renderPregunta(qi);
}

function navegar(dir) {
  estado.actual = Math.max(0, Math.min(estado.preguntas.length - 1, estado.actual + dir));
  renderPregunta(estado.actual);
}

async function enviarResultado() {
  const sinResponder = estado.respuestas.filter(r => r === null).length;
  if (sinResponder > 0) {
    if (!confirm(`Quedan ${sinResponder} preguntas sin responder. ¿Continuar de todas formas?`)) return;
  }

  mostrar('s-resultado');
  document.getElementById('msg-envio').innerHTML = '<span class="spinner"></span> Calculando resultado…';

  const fd = new FormData();
  fd.append('accion',     'resultado');
  fd.append('codigo',     estado.codigo);
  fd.append('nombre',     estado.nombre);
  fd.append('correo',     estado.correo);
  fd.append('version',    estado.version);
  fd.append('respuestas', JSON.stringify(estado.respuestas));

  try {
    const res  = await fetch('', { method: 'POST', body: fd });
    const data = await res.json();

    if (!data.ok) {
      document.getElementById('msg-envio').textContent = 'Error: ' + data.msg;
      return;
    }

    const { puntaje, total, pct, nivel, decision } = data;

    document.getElementById('res-score').textContent      = `${puntaje}/${total}`;
    document.getElementById('res-nivel').textContent      = nivel;
    document.getElementById('m-correctas').textContent    = puntaje;
    document.getElementById('m-incorrectas').textContent  = total - puntaje;
    document.getElementById('m-pct').textContent          = pct + '%';

    let badgeCls, desc;
    if (decision === 'ADMITIDO') {
      badgeCls = 'badge-pass';
      desc = 'Demuestras dominio suficiente del nivel A2 para ingresar al curso English for Hotel Operations. EstudieMas se pondra en contacto contigo para confirmar tu inscripcion.';
    } else if (decision === 'REQUIERE ENTREVISTA') {
      badgeCls = 'badge-border';
      desc = 'Tu resultado esta en la frontera A1/A2. EstudieMas realizara una breve entrevista oral de 10 minutos para confirmar tu nivel antes de la admision.';
    } else {
      badgeCls = 'badge-fail';
      desc = 'Tu nivel actual no alcanza el minimo A2 requerido para el curso. Te recomendamos reforzar ingles basico antes de intentar el test nuevamente.';
    }

    const badgeEl = document.getElementById('res-badge');
    badgeEl.textContent = decision;
    badgeEl.className   = `badge ${badgeCls}`;
    document.getElementById('res-desc').textContent = desc;
    document.getElementById('msg-envio').textContent =
      data.enviado ? '✓ Resultado enviado a EstudieMas.' : 'Resultado registrado. Toma una captura de pantalla.';

  } catch(e) {
    document.getElementById('msg-envio').textContent = 'Error de conexion. Toma una captura de pantalla de esta pagina.';
  }
}

function mostrar(id) {
  document.querySelectorAll('.screen').forEach(s => s.classList.remove('activa'));
  document.getElementById(id).classList.add('activa');
}
</script>
</body>
</html>
