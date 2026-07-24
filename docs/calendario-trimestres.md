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

## Modelo de datos

- `wp_anpa_cursos`: `+t1_peche_operativo DATE NULL`, `+t2_peche_operativo DATE NULL`.
- `wp_anpa_curso_trimestres`: estado lectivo + estado de ventá por
  `(curso_escolar, trimestre)`.
- `wp_anpa_transicions`: rexistro append-only de transicións.

A migración (`DB_VERSION 1.38.0`) é aditiva e idempotente; sementa os
trimestres do curso activo (T1 `activo`, T2/T3 `pendente`, ventás `pechada`).
