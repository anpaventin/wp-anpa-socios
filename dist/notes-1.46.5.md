## anpa-socios 1.46.5

Correccións de erros derivados do modelo de niveis global + comedor por curso
(fase31) e un axuste visual do asistente inicial. Valida das prerelease
1.46.5-beta.1/beta.2.

### Corrixido

1. **Grupos e horarios non cargaban** ("Erro ao cargar grupos: Non se puido cargar
   a estrutura escolar."). O endpoint `GET /admin/grupos-horarios` consultaba as
   columnas `niveis.curso_escolar` e `niveis.horario_comedor_id`, eliminadas por
   fase31. Agora carga os niveis globais activos e resolve o horario de comedor de
   cada nivel para o curso desde o pivot `wp_anpa_niveis_curso`.

2. **Eliminar un horario de comedor** en *Estrutura escolar* fallaría ("Non se
   puideron bloquear/comprobar os niveis asociados."). A comprobación de referencias
   consultaba `niveis.horario_comedor_id`; agora consulta o pivot.

3. **Asistente inicial en móbil**: a frase da clave e a clave privada amosábanse moi
   comprimidas (case en vertical) por usar unha táboa de columna estreita. Agora
   apílanse en bloques a ancho completo e léense ben en móbil.

### Notas técnicas

- Sen cambios de esquema (DB 1.37.0).
- Cambios en `ANPA_Socios_Admin_Grupos_Handler::list_grupos_horarios`,
  `ANPA_Socios_Admin_Estrutura_Handler::eliminar_horario_comedor` e
  `ANPA_Socios_Admin_Settings::render_setup_result`. As proxeccións puras non se tocan.
- Suite PHPUnit en verde (948 tests, 2723 assertions).
