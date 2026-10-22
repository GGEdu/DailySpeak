# Rediseño del flujo de debate · análisis (2026-10-22)

**Pedido del dueño.** Que «Debate this» muestre primero un resumen amplio de la noticia con una estructura siempre
igual (título, qué ocurre, por qué, contexto). Que el debate arranque con una explicación breve y una pregunta de la
IA. Que la IA anote las frases incorrectas del alumno y explique palabras cuando el alumno lo pida. Y que cada turno
de la IA corrija, resuma lo que ha entendido, conteste con su opinión y termine preguntando.

**Método.** Consejo de cuatro voces (arquitecto, escéptico, pragmático y crítico), cada una con solo la pregunta y el
contexto verificado en el código.

## Cómo funciona hoy (verificado)

| Pieza | Hoy |
|---|---|
| Resumen | 3 párrafos libres en C1 y 5 palabras, una vez por artículo (`NewsSummarizer`); hay ~650 hechos así |
| Arranque | El alumno habla primero: no hay pregunta de la IA |
| Tutor | Modelo rápido sin razonamiento; 2-3 frases; «push back»; **regla 4 de la especificación: no corregir durante la conversación** |
| Correcciones | Solo en el informe final (muletillas, errores con corrección, vocabulario recomendado) |
| Palabras | Tocar cualquier palabra de la conversación, el resumen o el informe la traduce y la guarda |
| Voz | STT → LLM → TTS, unos 2 s por turno: cada frase de más es latencia y coste |

## Posiciones

- **Arquitecto:** sí al flujo, separando canales. Por voz va solo lo conversacional. Las correcciones van como texto
  bajo la intervención del alumno, generadas por una llamada en paralelo que no retrasa la voz.
- **Escéptico:** no tal cual. Sí al resumen fijo con «las posturas» y a la pregunta de apertura. Las correcciones,
  fuera del turno, como ahora. El STT normaliza la gramática (oculta errores reales) y oye mal (inventa otros).
- **Pragmático:** sí con dos recortes: nada de corregir y opinar en cada turno. Como mucho una corrección, y solo si
  cambia el sentido. La pregunta de apertura se genera y guarda con el resumen, así que el arranque no espera a nadie.
- **Crítico:** no tal cual. Una corrección explícita como máximo, y solo de alta confianza. La opinión de la IA en cada
  turno ancla al alumno: debe llegar después de que el alumno se posicione. El resumen en C1 no sirve a un B1. Un
  «contexto» obligatorio invita a rellenar con datos que el artículo no trae.

## Veredicto

- **Consenso:**
  - Las cuatro voces aceptan el resumen de estructura fija y la pregunta de apertura.
  - Las cuatro rechazan el turno hablado de cuatro partes (corregir, resumir, opinar y preguntar): rompe el
    formato de voz, sube la latencia y el coste, y deja al alumno hablando menos.
  - Las cuatro añaden al resumen una sección que la propuesta no tenía: **las posturas**. Sin dos lados, el alumno no
    sabe qué defender y el tutor no tiene contra qué empujar.
- **Disenso más fuerte:** el escéptico quiere cero correcciones durante el debate, porque la transcripción no es
  fiable. Se acepta en parte: **ninguna corrección se dice en voz alta**. Van por escrito, solo las de alta confianza,
  con un «no dije eso» para los errores del STT.
- **Premisa:** el escéptico cuestiona que el alumno necesite corrección en cada turno para aprender. La alternativa
  barata es la **reformulación**: repetir bien lo que dijo el alumno corrige sin cortar, y además deja oír si el STT
  entendió mal (lo aporta el crítico).
- **Lo que cambió mi posición:**
  - Del crítico: la IA no da su veredicto pronto; hace de contrapunto. Y el resumen va en el nivel del alumno.
  - Del pragmático: la pregunta de apertura se guarda con el resumen.

## Flujo recomendado

1. **Antes de hablar: «Debate brief»** con estructura fija, generado en el nivel del alumno la primera vez que alguien
   pulsa «Debate this» en ese artículo y nivel. Queda en caché; no se regeneran los 650 artículos.
   - **Headline:** el titular en lenguaje claro, en una frase.
   - **What happened:** quién, qué, cuándo y dónde, en 2-3 frases.
   - **Why it matters:** la causa o la tensión de fondo.
   - **Background:** solo lo que dice el artículo. Si el artículo no trae contexto, la sección es corta o no aparece:
     nunca se rellena con datos de fuera.
   - **Two sides:** argumentos a favor y en contra, 1-2 por lado.
   - **The debate question:** la pregunta con la que arranca el tutor.
   - **Key words:** 3-5 palabras del texto, con el toque para traducir que ya existe.
   - **Fuente, fecha y enlace** (ya se muestran).
2. **Arranque:** el primer mensaje es del tutor. Dice en voz y por escrito el encuadre (1-2 frases) y la pregunta del
   debate, que sale guardada en el resumen, así que no hay llamada de LLM extra.
3. **Cada turno del tutor, hablado, 3 frases como mucho:**
   - una frase que reformula bien lo que dijo el alumno («So you're saying…»);
   - un contrapunto o una pregunta que le obligue a justificar, sin dar todavía su propia postura;
   - una pregunta al final, siempre.
   - Si el alumno pregunta qué significa algo, se lo explica en 1-2 frases sencillas y vuelve al debate.
4. **Bajo cada intervención del alumno, solo texto y sin retrasar la voz:** «Better: …» con hasta 2 correcciones de
   alta confianza. Las genera una llamada corta en paralelo. Se pueden descartar («no dije eso») y alimentan el
   informe final.
5. **Al terminar:** el informe de siempre, que reúne las correcciones de los turnos. Más adelante, los errores que se
   repiten entre sesiones.

## Coste y latencia

- **Latencia de voz:** la misma de hoy. El turno hablado sigue en 3 frases; las correcciones llegan aparte, un
  instante después.
- **Una llamada corta más por turno:** las correcciones.
- **Resumen de debate:** una llamada por cada par artículo y nivel que se use de verdad.
- **Apertura:** una síntesis de voz por debate.
- **Topes:** todo pasa por el tope diario de IA, que ya gestiona el admin.

## Riesgos y cómo se evitan

| Riesgo | Cómo se evita |
|---|---|
| Corregir sobre una transcripción mal oída | Correcciones solo por escrito y de alta confianza, con botón para descartarlas; la reformulación hablada deja oír el malentendido |
| «Contexto» inventado | Solo hechos del texto, validados; secciones que pueden ser cortas |
| El alumno da la razón a la IA | La IA hace de contrapunto y no da su postura hasta que el alumno se pronuncia |
| Más contenido derivado de fuentes que prohíben su uso con IA (20 de 22 dan aviso) | Decisión pendiente del dueño sobre qué fuentes conservar; el resumen se genera solo bajo demanda |
| Turnos que se alargan sin control | Límite de frases en el prompt, validación del texto y medición de la latencia p95 y de la proporción de habla del alumno |

## Despliegue propuesto

Detrás de un ajuste del panel de administración, primero en B1. Medir unas 20 sesiones: latencia p95 por turno,
llamadas por turno, correcciones por sesión, descartes por «no dije eso» y proporción de tiempo que habla el alumno.
El flujo antiguo se retira solo si la proporción de habla no empeora.
