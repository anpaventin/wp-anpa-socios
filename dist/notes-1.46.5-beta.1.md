## anpa-socios 1.46.5-beta.1 (prerelease)

Corrección dun erro na páxina **Grupos e horarios**.

### Corrixido

- **Grupos e horarios non cargaban** ("Erro ao cargar grupos: Non se puido cargar
  a estrutura escolar."). O endpoint `GET /admin/grupos-horarios` aínda consultaba
  as columnas `niveis.curso_escolar` e `niveis.horario_comedor_id`, eliminadas pola
  migración de fase31 (modelo de niveis global 1.35.0 + comedor por curso no pivot
  `wp_anpa_niveis_curso` 1.36.0/1.37.0). A consulta fallaba con erro SQL en cada
  carga, deixando a páxina inutilizable.

  Agora cárganse os niveis globais activos e resólvese o horario de comedor de cada
  nivel para o curso seleccionado desde o pivot, igual que fan o editor de Estrutura
  escolar e o gate de comedor. É a mesma clase de erro que xa se corrixira na
  creación de grupos (1.46.0-beta.2), nun camiño de lectura distinto que non se
  exercitara ata agora.

### Notas técnicas

- Sen cambios de esquema (DB 1.37.0).
- Só cambia `ANPA_Socios_Admin_Grupos_Handler::list_grupos_horarios`; a proxección
  pura `ANPA_Socios_Grupos_Horarios::build` non se toca (mesma forma de datos).
- Suite PHPUnit en verde (948 tests, 2723 assertions).
