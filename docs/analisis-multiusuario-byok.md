# Multiusuario con clave propia (BYOK) · análisis (2026-10-22)

**Propuesta del dueño.** Cada usuario del mismo servidor pone sus claves y modelos de IA, sus límites, y añade, quita
y edita sus fuentes. Ve todo lo que ve el admin, pero solo sus datos. El sistema guarda registros. El dueño da la
plataforma y cada usuario paga su uso.

**Método.** Consejo de cuatro voces, cada una con solo la pregunta y el contexto verificado:
- Hoy las fuentes y los artículos son comunes: sin dueño por fila, cada artículo se resume una vez con la clave de
  la plataforma y es único por URL.
- Debates, transcripciones y vocabulario ya son privados de cada usuario.
- Hay una sola clave de IA.

## Primero, la premisa: hay dos modelos distintos

| | A. Una instalación por persona | B. Una plataforma, muchos usuarios con su clave |
|---|---|---|
| Quién paga la IA | Cada dueño, con su clave | Cada usuario, con su clave |
| Quién guarda claves y transcripciones ajenas | Nadie: cada uno las suyas | **El dueño de la plataforma** (RGPD, custodia de secretos de terceros) |
| Fuentes y resúmenes | De cada instalación | Hay que decidir qué es común y qué es de cada uno |
| Trabajo | Lo que ya estaba en marcha (dueño único) | Multiusuario de verdad: aislamiento, claves por usuario, uso, registros |

Los dos consiguen «cada uno paga su uso». A es mucho más simple; B es lo que describe este mensaje.

## Posiciones

- **Arquitecto:** sí a B, mixto. Catálogo común curado por el dueño, cada usuario elige qué fuentes sigue y puede
  añadir fuentes privadas resueltas con su clave. Clave propia (BYOK) para todo lo que dispara él. Topes personales y
  topes de plataforma. Proveedores de una lista cerrada.
- **Escéptico:** no con este alcance. Con 2 usuarios la demanda no está demostrada. Terminar el modelo de dueño
  único y, a quien quiera usarlo, darle su instancia o una invitación con cuota. Lo que lo hace barato es el resumen
  único por artículo y el resumen de debate compartido. Las fuentes de cada usuario saltan la revisión y amplían la
  superficie de ataque. La licencia de las fuentes es una decisión previa a la arquitectura.
- **Pragmático:** sí a BYOK para claves y modelos, no al modelo completo. Fuentes, recogida y resúmenes siguen
  siendo comunes y los paga la plataforma. Fuera del MVP quedan las fuentes propias, la «visibilidad total» y los
  registros con contenido. Los jobs llevan el id del usuario, nunca la clave.
- **Crítico:** solo por fases y con las salvaguardas cerradas antes de abrir el registro. Riesgos:
  - **Una URL de proveedor libre es un canal de salida fuera del guard de SSRF.** Podría apuntar a la pasarela
    interna y gastar las claves del dueño, o sondear la red.
  - **Sin respaldo silencioso a la clave del dueño.**
  - **Un cliente de IA por job:** los workers son procesos largos, y una configuración global podría aplicar la clave
    de un usuario a otro.

## Veredicto

- **Consenso:**
  - Si se hace B, la clave y los modelos van por usuario.
  - Catálogo de fuentes, recogida, resúmenes y resúmenes de debate siguen siendo comunes y los paga la plataforma.
  - Proveedores de una lista cerrada, nunca una URL libre.
  - Un registro de uso sin contenido.
- **Disenso más fuerte:** el escéptico, no construir multiusuario ahora. Quien quiera usarla, su propia instalación.
- **Premisa:** «ve todo lo que ve el admin, pero solo lo suyo» no define un rol. El catálogo, los topes de plataforma
  y los registros contienen datos de todos. Hay que separar **«Mis ajustes»** (lo de cada usuario) de **«Plataforma»**
  (lo del dueño).
- **Me hicieron cambiar:**
  - Las fuentes privadas por usuario salen del MVP (fase 2, con topes del servidor).
  - Ningún respaldo a la clave del dueño salvo que este lo active explícitamente.
  - La configuración de IA no se aplica de forma global al proceso, sino por llamada.

## Si se elige B: MVP en este orden

1. **Mis ajustes → IA**, por usuario:
   - proveedor de una lista cerrada (OpenAI, OpenRouter, Gemini, Anthropic, Groq…);
   - clave cifrada, que nunca se devuelve (solo los 4 últimos caracteres) y se prueba al guardarla;
   - modelo por tarea.
2. **Resolución por usuario y tarea en cada llamada:**
   - Los jobs llevan el id del usuario y crean su propio cliente; sin estado global.
   - Sin clave: error claro. El respaldo a la clave de la plataforma solo existe si el dueño lo activa para ese usuario.
3. **Lo común lo paga la plataforma**, con la clave del dueño: recogida, resúmenes de noticias y resumen del debate,
   que se comparte por artículo y nivel. Lo de cada usuario lo paga su clave: turno de voz (STT, LLM, TTS),
   traducciones, revisión del turno e informe.
4. **Fuentes:** catálogo del dueño; cada usuario elige cuáles sigue y su feed solo enseña esas. Las fuentes propias,
   en fase 2.
5. **Uso y topes:**
   - Registro de uso por usuario (tarea, tokens, coste estimado, fecha), sin contenido.
   - Tope mensual que fija cada usuario.
   - Topes de plataforma del dueño contra abusos.
6. **Plataforma (dueño):** catálogo, usuarios con sus agregados de uso y errores (sin transcripciones ni claves),
   topes de plataforma y respaldo opcional.
7. **Antes de abrir el registro a desconocidos:**
   - aviso de privacidad actualizado, porque el dueño custodia claves y transcripciones de terceros;
   - decisión sobre las licencias de las fuentes;
   - revisión de la exposición a internet.

**Qué se reutiliza de lo empezado:** el almacén cifrado de opciones y las pantallas del panel. **Qué no:** aplicar
la configuración de IA de forma global al proceso. Con claves por usuario, eso puede mezclar las claves de dos
usuarios en el mismo worker.
