## anpa-socios 1.46.4

Mellora do asistente de configuración inicial (primeira execución en instalación limpa).

### Novidades

O asistente de posta en marcha (que aparece só nunha instalación nova, cando aínda
non se configurou a clave bancaria) agora recolle nun único paso moito máis contexto
da asociación, para deixar o plugin listo para usar sen ter que ir despois a Axustes:

- **Identidade da asociación**: nome, cota anual, email de contacto, enderezo,
  nome do menú e se as altas requiren aprobación manual.
- **Localización**: país, provincia, poboación e código postal por defecto (que se
  prefillan despois no formulario de alta de socios).
- **Curso escolar**: alta e activación do curso, con opción de **abrir matrículas**
  directamente desde o asistente.
- **Estrutura escolar por defecto**: unha grella editable cos niveis 1º–6º (idades
  8–13) e a última aula por nivel (A–D), que se sementa de forma transaccional e
  idempotente ao rematar o asistente.

Os horarios de comedor NON se configuran no asistente: fáiselo despois no editor de
**Estrutura escolar**, onde hai un aviso que o indica.

### Notas técnicas

- Sen cambios de esquema de base de datos (DB 1.37.0).
- A activación do curso pasa polo escritor canónico do ciclo de vida
  (`ANPA_Socios_Admin_Cursos_Handler::update_curso`), reutilizando toda a lóxica
  existente.
- A sementeira de estrutura reutiliza `sync_aulas_nivel` e é idempotente por `codigo`.
- Suite PHPUnit en verde (948 tests, 2723 assertions).
