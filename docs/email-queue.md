# Cola de comunicacións por correo

Esta páxina explica como funciona o envío de correos do plugin, que significa cada
estado, como intervir cando algo se atasca e canto tempo se garda a información.

Está escrita para quen mantén a web da asociación, non fai falta ser técnico/a.

## Idea xeral

O plugin **non envía os correos no momento** en que ocorre a acción que os provoca.
En vez diso apúntaos nunha **cola** gardada na base de datos e vaios enviando **por
lotes** cada poucos minutos. Iso ten tres vantaxes:

- Se o servidor de correo falla, non se perde nada: reintentarase.
- Un envío a moitas familias non bloquea a persoa que estaba usando a web.
- Queda **rexistro** de que se enviou, a quen e cando.

Cada envío agrúpase nunha **campaña**. Unha campaña ten moitos **destinatarios**, e
cada destinatario ten un ou varios **intentos**.

## "Aceptado" non é "entregado"

Isto é importante e non é un detalle.

Cando o plugin entrega unha mensaxe ao sistema de correo, o único que sabe é se ese
sistema **aceptou** encargarse dela. Non sabe se chegou á bandexa de entrada, se caeu
en correo lixo ou se a rexeitaron despois.

Por iso a pantalla di sempre **"Aceptado"** e nunca "Entregado". Se precisas
confirmación real de entrega, iso require un servizo de correo transaccional con
seguimento, que hoxe non forma parte deste plugin.

## Pode haber un correo repetido (e sabémolo)

O sistema de correo non dá un identificador que permita saber se unha mensaxe xa se
enviou. Se unha execución se corta **xusto despois** de enviar e **antes** de gardar o
resultado, non hai forma de distinguilo de cortarse antes de enviar.

Ante esa dúbida o plugin **reintenta**, porque é peor que unha familia non reciba unha
comunicación importante que recibila dúas veces. Eses casos márcanse como **"incerto"**
e a pantalla avisa de que puido haber duplicado. O modelo é, dito con propiedade,
*polo menos unha vez*, nunca *exactamente unha vez*.

## Onde se ve todo

**Menú do plugin → Comunicacións.**

Na lista de campañas vese o estado, os contadores e as accións dispoñibles. Ao premer
nunha campaña vese cada destinatario, o seu estado, o último erro e o historial de
intentos.

### Estados dunha campaña

| Estado | Que significa |
|---|---|
| Pendente | Creada, aínda non empezou a enviarse. |
| En curso | Estase a enviar por lotes. |
| Pausada | Detida a propósito. Non se colle traballo novo. |
| Rematada | Non queda ningún destinatario por procesar. |
| Cancelada | Detida definitivamente. Os xa aceptados quedan como están. |

"Rematada" e "Cancelada" son estados **finais**: non se poden reabrir. Para volver
enviar algo hai que xerar unha **campaña nova**.

### Estados dun destinatario

| Estado | Que significa |
|---|---|
| Pendente | Agarda quenda. |
| A procesar | Un lote colleuno agora mesmo. |
| Aceptado | O sistema de correo aceptou a mensaxe (non implica entrega). |
| Fallo (reintentarase) | Fallou e hai outro intento programado. |
| Fallo definitivo | Esgotou os intentos. Non se reintentará só. |
| Cancelado | Cancelouse antes de enviarse. |

### Accións

- **Procesar agora**: envía un lote nese momento. Útil se o cron non vai.
- **Pausar / Continuar**: detén e retoma unha campaña.
- **Cancelar**: detén definitivamente o que queda pendente. **Non desfai** o xa aceptado.
- **Reintentar fallos**: volve poñer en cola só os destinatarios fallidos. Non toca os
  aceptados. **Se a campaña xa está rematada ou cancelada, non se pode**: hai que crear
  unha campaña nova.

## Cron: o punto que máis falla

WordPress non ten un temporizador propio. O seu "cron" dispárase **cando alguén visita
a web**. Nunha web de ANPA con pouco tráfico iso significa que a cola pode quedar
parada horas.

A pantalla de Comunicacións avisa cando detecta un destes tres problemas:

1. **A tarefa está desactivada** (`DISABLE_WP_CRON` no `wp-config.php`).
2. **O evento non está programado** (perdeuse do rexistro de tarefas).
3. **Levamos moito sen procesar** (non houbo execucións recentes).

### Solución recomendada: cron real do servidor

O correcto nunha instalación de produción é desactivar o cron por visitas e chamar o
temporizador desde o servidor. No `wp-config.php`:

```php
define( 'DISABLE_WP_CRON', true );
```

E no cron do servidor (por exemplo cada 5 minutos):

```
*/5 * * * * curl -s https://exemplo.gal/wp-cron.php?doing_wp_cron > /dev/null 2>&1
```

Substitúe o dominio polo real. Se o aloxamento ten un panel con "tarefas programadas",
serve igual: só ten que pedir esa URL periodicamente.

Mentres non haxa cron real, sempre se pode usar **Procesar agora** a man.

### Cada canto se procesa

Por defecto, cada **5 minutos**. Non se cambia desde a interface a propósito (un
intervalo demasiado curto castiga o servidor).

O seguinte só se pode aplicar tocando código: pídeo a quen manteña a web. Entre 60 e
3600 segundos:

```php
add_filter( 'anpa_socios_email_cron_interval', fn() => 600 ); // 10 minutos
```

Cada execución está limitada **por número de mensaxes** e **por tempo**, para non
solapar coa seguinte. Se se esgota o tempo, o que quede volve á cola de inmediato.

## Canto tempo se garda a información

Unha tarefa diaria aplica dúas limpezas, sempre nesta orde:

1. **Contido enviado** (asunto, corpo e texto do erro): bórrase pasada a ventá de
   diagnóstico, **30 días por defecto** desde que a campaña remata ou se cancela.
   Consérvanse o estado, os contadores e a **pegada (hash)** do contido, así que segue
   acreditado que se enviou e que se enviou.
2. **Metadatos mínimos** (as propias filas de campañas, destinatarios e intentos):
   bórranse moito máis tarde, **365 días por defecto**.

Configúrase en **Axustes → Xeral → Mantemento → Rexistro de comunicacións**. Os valores
teñen topes: a ventá de metadatos nunca pode ser menor que a do contido, porque senón
desaparecerían as filas antes de limpar a parte sensible.

Unha campaña que **non** está rematada ou cancelada nunca se toca: aínda precisa o seu
contido para enviar.

## Desactivar e desinstalar

- **Desactivar o plugin**: cancélanse as tarefas programadas. **Non se borra ningún dato.**
- **Desinstalar**: por defecto **consérvase** o rexistro de comunicacións, porque pode
  ser necesario para diagnosticar incidencias e acreditar actuacións da xunta directiva.
  Só se borra se antes se activou a opción **"Eliminar o rexistro de comunicacións ao
  desinstalar"**. Esa opción afecta **só** ás comunicacións: nin socios/as, nin fillos/as,
  nin actividades, nin matrículas.

## Se algo vai mal: por onde empezar

| Síntoma | Onde mirar |
|---|---|
| Non sae ningún correo | Aviso de cron na pantalla de Comunicacións. Proba "Procesar agora". |
| Sae todo como "Fallo" | O erro amosado no detalle: normalmente é configuración SMTP. |
| Unha familia recibiu dous correos | Busca a advertencia de "incerto" nesa campaña. |
| Hai unha campaña "En curso" que non avanza | Comproba o cron e mira se os destinatarios teñen "seguinte intento" no futuro (backoff). |
| Quero parar un envío agora | "Pausar" (temporal) ou "Cancelar" (definitivo). |

## Notas técnicas

- Todas as datas gárdanse en **UTC** (columnas con sufixo `_utc`). Non se mesturan coa
  hora local de WordPress nin coa da sesión da base de datos.
- Cada mensaxe ten unha **identidade lóxica** (campaña + enderezo normalizado + tipo de
  destinatario + clave de mensaxe). Iso é o que evita duplicados: se o pai e a nai
  comparten enderezo, a **mesma** mensaxe colapsa nun só envío, pero dúas mensaxes
  distintas ao mesmo enderezo consérvanse.
- Repetir a **mesma operación** (mesma clave de idempotencia) non crea unha campaña nova
  nin duplica destinatarios.
- Os lotes reclámanse cunha **reserva atómica** con caducidade, así que dúas execucións
  simultáneas non poden coller a mesma fila. Se unha execución morre, a reserva caduca e
  a fila recupérase deixando un intento "incerto".
- O contido de cada mensaxe **conxélase ao encolar**. Cambiar unha plantilla despois non
  altera os envíos xa pendentes.
- Puntos de extensión: `anpa_socios_email_render_provider` (contido),
  `anpa_socios_email_cron_interval`, `anpa_socios_email_run_max_seconds`,
  `anpa_socios_email_payload_retention_days`,
  `anpa_socios_email_metadata_retention_days`, e a acción de auditoría
  `anpa_socios_email_admin_action`.
