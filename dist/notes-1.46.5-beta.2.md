## anpa-socios 1.46.5-beta.2 (prerelease)

Corrección de dous erros derivados de columnas eliminadas por fase31 (modelo de
niveis global + comedor por curso no pivot `wp_anpa_niveis_curso`). Ambos camiños
seguían consultando columnas que xa non existen en `niveis`
(`curso_escolar`, `horario_comedor_id`).

### Corrixido

1. **Grupos e horarios non cargaban** ("Erro ao cargar grupos: Non se puido cargar
   a estrutura escolar."). O endpoint `GET /admin/grupos-horarios` consultaba
   `niveis.curso_escolar` e `niveis.horario_comedor_id`. Agora carga os niveis
   globais activos e resolve o comedor de cada nivel para o curso desde o pivot.

2. **Eliminar un horario de comedor** en *Estrutura escolar* fallaría ("Non se
   puideron bloquear os niveis asociados." / "Non se puideron comprobar os niveis
   asociados."). A comprobación de referencias consultaba `niveis.horario_comedor_id`;
   agora consulta o pivot `wp_anpa_niveis_curso`.

### Notas técnicas

- Sen cambios de esquema (DB 1.37.0).
- Cambios en `ANPA_Socios_Admin_Grupos_Handler::list_grupos_horarios` e
  `ANPA_Socios_Admin_Estrutura_Handler::eliminar_horario_comedor`. As proxeccións
  puras non se tocan.
- Suite PHPUnit en verde (948 tests, 2723 assertions).
