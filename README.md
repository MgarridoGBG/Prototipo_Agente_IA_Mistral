# Kino — Asistente IA de Marketing Kiné

Chatbot conversacional con IA que actúa como asistente virtual de Marketing Kiné. Usa **Mistral AI** como motor de lenguaje, **streaming SSE** para respuestas en tiempo real y un proxy PHP que mantiene la API key y el contexto RAG completamente en el servidor.

---

## Características

- Respuestas en **streaming** (Server-Sent Events) — el texto aparece palabra a palabra.
- **Proxy seguro**: la API key y el System Prompt nunca llegan al cliente.
- **Contexto RAG** editable sin tocar código (`assets/reglas.txt`).
- Historial de conversación multi-turno con límite de tokens.
- Validación y saneamiento estricto de los mensajes entrantes.
- Restricción de acceso por same-origin (protección CSRF básica).

---

## Estructura del proyecto

```
├── index.html           # Interfaz del chat
├── config.php           # API key (no incluida en el repo, ver config_example.php)
├── config_example.php   # Plantilla de configuración
├── api/
│   └── proxy.php        # Proxy seguro → Mistral AI
└── assets/
    ├── app.js           # Lógica del chat (SSE, historial)
    ├── styles.css       # Estilos
    └── reglas.txt       # Contexto RAG / conocimiento del negocio
```

---

## Requisitos

- PHP 8.1+ con extensión **cURL** habilitada.
- Servidor web compatible (Apache/XAMPP, Nginx…).
- Cuenta en [Mistral AI](https://mistral.ai) con una API key válida.

---

## Instalación

1. **Clona** o copia el proyecto en el directorio raíz de tu servidor web.

2. **Configura la API key**: copia `config_example.php` como `config.php` y añade tu clave:

   ```php
   define('MISTRAL_API_KEY', 'tu_api_key_aqui');
   ```

3. **Protege `config.php`**: asegúrate de que está en `.gitignore` para no exponer la clave.

4. **Personaliza el conocimiento**: edita `assets/reglas.txt` con la información del negocio (servicios, horarios, políticas, etc.).

5. Abre `index.html` en el navegador a través del servidor (p. ej. `http://localhost/ia/`).

---

## Personalización

### Cambiar el modelo o la temperatura

En `api/proxy.php`:

```php
define('MISTRAL_MODEL', 'mistral-small-latest'); // modelo a usar
define('TEMPERATURE',   0.4);                    // 0 = determinista, 1 = creativo
```

### Ajustar el comportamiento del asistente

Edita el `$SYSTEM_PROMPT` en `api/proxy.php` y el fichero `assets/reglas.txt`.  
Los cambios en `reglas.txt` se aplican sin reiniciar el servidor.

---

## Seguridad

| Medida | Dónde |
|--------|-------|
| API key solo en servidor | `config.php` (excluido del repo) |
| Validación de origen (same-origin) | `proxy.php` |
| Solo método POST permitido | `proxy.php` |
| Saneamiento de roles y longitud de mensajes | `proxy.php` |
| Límite de 40 mensajes por historial | `proxy.php` |
| Contenido de cada mensaje truncado a 8 000 caracteres | `proxy.php` |

---

## Licencia

Uso interno de Marketing Kiné. Todos los derechos reservados.
