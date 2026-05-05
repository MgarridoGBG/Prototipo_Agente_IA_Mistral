/* ===================================================
   app.js  —  Chat con Mistral AI (streaming SSE)
   API key, System Prompt y contexto RAG están en
   proxy.php (servidor). Este archivo es público.
   =================================================== */

const PROXY_URL = 'api/proxy.php';

const form         = document.getElementById('formulario');
const promptEl     = document.getElementById('prompt');
const chatEl       = document.getElementById('chat');
const bienvenidaEl = document.getElementById('bienvenida');
const btnEnviar    = document.getElementById('btn-enviar');
const btnLimpiar   = document.getElementById('btn-limpiar');
const errorEl      = document.getElementById('error');
const loader       = document.getElementById('loader');

/* ─── Historial de conversación (solo user/assistant) ─── */
let historial = [];

/* ─── Crea una burbuja de mensaje en el chat ─── */
function crearBurbuja(role) {
  if (bienvenidaEl) bienvenidaEl.style.display = 'none';

  const wrapper = document.createElement('div');
  wrapper.className = `msg msg--${role}`;

  if (role === 'assistant') {
    wrapper.innerHTML = '<div class="msg__avatar">✦</div><div class="msg__text"></div>';
  } else {
    wrapper.innerHTML = '<div class="msg__text"></div>';
  }

  chatEl.appendChild(wrapper);
  chatEl.scrollTop = chatEl.scrollHeight;
  return wrapper.querySelector('.msg__text');
}

/* ─── Limpia el chat y reinicia la conversación ─── */
function limpiarChat() {
  Array.from(chatEl.children).forEach(el => {
    if (el.id !== 'bienvenida') el.remove();
  });
  if (bienvenidaEl) bienvenidaEl.style.display = '';
  historial = [];
  errorEl.textContent = '';
}

/* ─── Envío del formulario ─── */
form.addEventListener('submit', async (e) => {
  e.preventDefault();

  const texto = promptEl.value.trim();
  if (!texto) return;

  errorEl.textContent = '';
  promptEl.value = '';

  // Mostrar mensaje del usuario
  crearBurbuja('user').textContent = texto;
  historial.push({ role: 'user', content: texto });

  // Preparar burbuja del asistente
  btnEnviar.disabled = true;
  loader.classList.add('visible');

  const textEl = crearBurbuja('assistant');
  textEl.parentElement.classList.add('cargando');
  chatEl.scrollTop = chatEl.scrollHeight;

  const messages = [
    ...historial,
  ];

  let respuestaCompleta = '';

  try {
    const response = await fetch(PROXY_URL, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ messages }),
    });

    if (!response.ok) {
      const err = await response.json().catch(() => ({}));
      throw new Error(err?.message || `Error HTTP ${response.status}`);
    }

    const reader  = response.body.getReader();
    const decoder = new TextDecoder('utf-8');
    let buffer    = '';

    while (true) {
      const { done, value } = await reader.read();
      if (done) break;

      buffer += decoder.decode(value, { stream: true });

      // Procesar líneas SSE
      const lines = buffer.split('\n');
      buffer = lines.pop(); // línea incompleta → se acumula

      for (const line of lines) {
        const trimmed = line.trim();
        if (!trimmed || trimmed === 'data: [DONE]') continue;
        if (!trimmed.startsWith('data: '))         continue;

        try {
          const json  = JSON.parse(trimmed.slice(6));
          const delta = json.choices?.[0]?.delta?.content;
          if (delta) {
            respuestaCompleta += delta;
            textEl.textContent += delta;
            chatEl.scrollTop = chatEl.scrollHeight;
          }
        } catch {
          // fragmento JSON incompleto → se descarta
        }
      }
    }

    // Guardar respuesta completa en el historial de conversación
    if (respuestaCompleta) {
      historial.push({ role: 'assistant', content: respuestaCompleta });
    }

  } catch (err) {
    errorEl.textContent = '⚠ ' + err.message;
    historial.pop(); // quitar el mensaje de usuario fallido
    textEl.parentElement.remove(); // quitar burbuja vacía
  } finally {
    textEl.parentElement.classList.remove('cargando');
    btnEnviar.disabled = false;
    loader.classList.remove('visible');
  }
});

/* ─── Botón nueva conversación ─── */
btnLimpiar.addEventListener('click', limpiarChat);

/* ─── Ctrl + Enter para enviar ─── */
promptEl.addEventListener('keydown', (e) => {
  if (e.key === 'Enter' && e.ctrlKey) form.requestSubmit();
});
