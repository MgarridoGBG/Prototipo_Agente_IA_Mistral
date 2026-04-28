<?php
/* =============================================================
   proxy.php  —  Proxy seguro para Mistral AI
   El acceso a API key, el System Prompt y el contexto RAG viven aquí.
   El frontend solo envía el historial usuario/asistente.
   ============================================================= */

/* ── Configuración ── */
require_once __DIR__ . '/../config.php'; // API key cargada desde archivo protegido
define('MISTRAL_API_URL', 'https://api.mistral.ai/v1/chat/completions');
define('MISTRAL_MODEL',   'mistral-small-latest');
define('TEMPERATURE',     0.4);

$SYSTEM_PROMPT = <<<'PROMPT'
Eres Kino, asistente virtual de Marketing Kiné.
Tu principal fuente de información es el contexto delimitado entre <contexto></contexto>.

REGLAS (en orden de prioridad):
1. Responde con naturalidad y amabilidad. Puedes deducir e inferir información razonable a partir del contexto aunque no esté escrita de forma literal.
2. Si el contexto no ofrece ninguna base para responder, di: "Lo siento, no tengo esa información específica."
3. No inventes datos concretos como precios exactos, teléfonos, direcciones o fechas que no aparezcan en el contexto.
4. Utiliza lenguaje cercano pero profesional. Puedes tutear al usuario.
5. Termina siempre con algo similar a: "¿Deseas saber más?"

--- EJEMPLOS (few-shot) ---
Pregunta: "¿Cuál es el teléfono de contacto?"
Contexto: [no contiene datos de teléfono]
Respuesta correcta: "Lo siento, no tengo esa información específica. ¿Deseas saber más?"

Pregunta: "¿A qué hora abren?"
Contexto: "Horario: Lunes y martes de 10 a 14"
Respuesta correcta: "Abrimos los lunes y martes de 10:00 a 14:00. ¿Deseas saber más?"

Pregunta: "¿Son buenos para redes sociales?"
Contexto: "Servicios: gestión de redes sociales, community management, campañas de Instagram y TikTok"
Respuesta correcta: "Sí, la gestión de redes sociales es una de nuestras especialidades, incluyendo community management y campañas en Instagram y TikTok. ¿Deseas saber más?"

Pregunta: "¿Ofrecen diseño web?"
Contexto: [no menciona diseño web]
Respuesta correcta: "Lo siento, no tengo esa información específica. ¿Deseas saber más?"
--- FIN EJEMPLOS ---
PROMPT;

/* ── Cargar contexto RAG desde reglas.txt ── */
$contextoPath = __DIR__ . '/../assets/reglas.txt';
$contexto = file_exists($contextoPath) ? trim(file_get_contents($contextoPath)) : '';

$systemContent = $contexto
    ? $SYSTEM_PROMPT . "\n\n<contexto>\n" . $contexto . "\n</contexto>"
    : $SYSTEM_PROMPT;

/* ── Solo aceptar POST ── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

/* ── Solo aceptar peticiones del mismo origen (same-origin) ── */
$host    = $_SERVER['HTTP_HOST']    ?? '';
$origin  = $_SERVER['HTTP_ORIGIN']  ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';

$originOk  = $origin !== '' && in_array($origin, [
    'http://'  . $host,
    'https://' . $host,
], true);

$refererOk = $referer !== '' && (
    str_starts_with($referer, 'http://'  . $host . '/') ||
    str_starts_with($referer, 'https://' . $host . '/')
);

if (!$originOk && !$refererOk) {
    http_response_code(403);
    exit;
}

/* ── Leer y validar el cuerpo JSON ── */
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!isset($body['messages']) || !is_array($body['messages'])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Petición inválida']);
    exit;
}

/* ── Limitar el número de mensajes para evitar abuso de tokens ── */
if (count($body['messages']) > 40) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Historial demasiado largo']);
    exit;
}

/* ── Sanear mensajes: solo roles user/assistant (system lo pone el servidor) ── */
$allowedRoles = ['user', 'assistant'];
$messages = [];

foreach ($body['messages'] as $msg) {
    if (
        !isset($msg['role'], $msg['content']) ||
        !in_array($msg['role'], $allowedRoles, true) ||
        !is_string($msg['content'])
    ) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Mensaje inválido']);
        exit;
    }
    $messages[] = [
        'role'    => $msg['role'],
        'content' => mb_substr($msg['content'], 0, 8000),
    ];
}

/* ── Anteponer el system prompt (nunca viene del cliente) ── */
array_unshift($messages, ['role' => 'system', 'content' => $systemContent]);

/* ── Payload para Mistral ── */
$payload = json_encode([
    'model'       => MISTRAL_MODEL,
    'stream'      => true,
    'temperature' => TEMPERATURE,
    'messages'    => $messages,
]);

/* ── Cabeceras SSE ── */
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
header('X-Content-Type-Options: nosniff');

/* ── Llamada a Mistral mediante cURL con streaming ── */
$ch = curl_init(MISTRAL_API_URL);

curl_setopt($ch, CURLOPT_POST,        true);
curl_setopt($ch, CURLOPT_POSTFIELDS,  $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER,  [
    'Content-Type: application/json',
    'Authorization: Bearer ' . MISTRAL_API_KEY,
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($curl, $data) {
    echo $data;
    if (ob_get_level() > 0) ob_flush();
    flush();
    return strlen($data);
});

curl_exec($ch);

if (curl_errno($ch)) {
    // El stream ya empezó, solo podemos cerrar limpiamente
    error_log('proxy.php cURL error: ' . curl_error($ch));
}

curl_close($ch);
