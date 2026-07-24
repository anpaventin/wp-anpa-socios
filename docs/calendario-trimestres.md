# Calendario académico e trimestres (fase34)

Esta funcionalidade engade un calendario operativo por curso e o estado
separado de cada trimestre e da súa ventá de solicitudes. Non xestiona
pagamentos nin cambia estados de forma automática: as transicións críticas
son sempre manuais e quedan rexistradas.

## Conceptos

- **Datas operativas do curso** (en *Axustes → Cursos → Curso escolar*):
  - `Comeza` (data de inicio) e `Pecha` (data de fin) do curso.
  - `Peche operativo do 1º trimestre` e `Peche operativo do 2º trimestre`.
    Son datas de xestión (recoméndase fixalas un pouco antes do remate lectivo
    real). Son **opcionais**: se se deixan baleiras, o cálculo do trimestre
    volve ao modelo mensual histórico.
  - Regra de orde: `Comeza < peche T1 < peche T2 < Pecha`, todas dentro do curso.
    Se non se cumpre, a garda de validación rexeita o gardado.

- **Trimestre derivado da data**: a partir das datas operativas, calcúlase a
  que trimestre pertence unha data:
  - T1 = `[Comeza, peche T1]`
  - T2 = `(peche T1, peche T2]`
  - T3 = `(peche T2, Pecha]`

- **Estado lectivo do trimestre**: `pendente → activo → pechado`
  (reabrir `pechado → activo` é posible, sempre manual e auditado).

- **Estado da ventá de solicitudes** (por trimestre): `pechada ↔ aberta`.
  É un concepto separado do estado lectivo; controla se se poden presentar
  solicitudes para o seguinte período.

## Copiar datas do curso anterior

O botón **«Copiar datas do curso anterior»** trae as datas do curso previo
desprazadas +1 ano ao curso seleccionado. As datas quedan editables no
formulario; hai que gardar o curso para confirmalas. Se o curso anterior non
ten datas gardadas, amósase un aviso e non se garda nada.

## Panel de estado dos trimestres

Amosa, para cada trimestre, o estado lectivo e o estado da ventá con texto
(non só cor) e botóns de transición manual. Cada acción está protexida con
nonce e capacidade `manage_options`, é idempotente (se xa está no estado
destino non fai nada) e queda rexistrada en `wp_anpa_transicions` (quen, cando,
de/a estado, orixe manual/cron).

## Aviso automático de fin de trimestre (cron)

A comprobación diaria de temporada detecta cando `hoxe` chega á data de peche
operativo dun trimestre que segue `activo` no curso activo e crea un aviso
persistente no panel de administración. **Nunca cambia o estado**: só avisa
para que a xunta xestione a transición cando queira. Ao pechar o trimestre
correspondente, o aviso desaparece.

## Catro conceptos distintos (importante)

Non hai que confundilos:
- **Trimestre lectivo activo**: o período lectivo está en curso.
- **Ventá de solicitudes aberta**: pódense presentar solicitudes para o seguinte período.
- **Grupo aberto**: un grupo de actividade admite matrículas (estado propio do grupo).
- **Matrícula aceptada**: unha matrícula concreta está activa.

Sementar T1 como `activo` ao iniciar/activar un curso **non** abre automaticamente
as ventás nin os grupos, nin acepta matrículas. Alcanzar unha data operativa **non**
cambia estados: só xera un aviso para xestión manual.

## Comportamento seguro (fail-closed)

- A lectura do estado dos trimestres **non** escribe nin inventa un estado
  «activo»: se falta a configuración dun curso, amósase como **«Sen configurar»**
  e a ventá considérase **pechada** (nunca aberta por un erro de lectura).
- As transicións **bloquéanse** se non se pode determinar o estado (o curso non
  está inicializado); non se asume ningún estado por defecto.
- A inicialización (sementeira) faise só en momentos inequívocos: instalación/
  migración, **activación do curso**, ou unha **reparación manual explícita** desde
  o panel. É idempotente e queda rexistrada; non oculta incoherencias.

## Modelo de datos

- `wp_anpa_cursos`: `+t1_peche_operativo DATE NULL`, `+t2_peche_operativo DATE NULL`.
- `wp_anpa_curso_trimestres`: estado lectivo + estado de ventá por
  `(curso_escolar, trimestre)`.
- `wp_anpa_transicions`: rexistro append-only de transicións (curso, ámbito,
  referencia, estado anterior, estado novo, actor, orixe
  `manual|cron|migracion|activacion|reparacion`, identificador de correlación,
  motivo opcional e data/hora). Non garda datos persoais innecesarios.

As migracións (`DB_VERSION 1.38.1`) son aditivas e idempotentes.

## Rollback

A migración é aditiva e non elimina datos, pero iso **non** garante un rollback
de código automático: unha versión anterior non entende os novos estados/columnas
nin o cálculo por datas. Antes de reverter, fai unha copia; despois reverte o
código (as táboas/columnas novas quedan inertes) e verifica a compatibilidade cos
datos. Reaplicar a versión nova recupera a funcionalidade sen perda.
