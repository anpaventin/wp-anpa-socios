## anpa-socios 1.47.0-beta.4 (prerelease)

### Cambiado

- **Pretemporada (curso aínda non aberto)**: en `/socios/`, cando o curso está pendente, xa **non se mostra o formulario de acceso por email** — só a mensaxe informativa de que o curso aínda non comezou. O equipo administrador accede pola administración de WordPress (`wp-admin`), non por esta páxina. As sesións xa iniciadas séguense restaurando con normalidade.
- **Cores de botón da área tokenizadas**: os botóns da área de socios usan agora tokens de cor (`--anpa-accent`, `--anpa-danger`, …) cos mesmos valores accesibles por defecto, de xeito que o tema activo pode axustar as cores sen editar o plugin. Sen cambio visual por defecto.

### Notas técnicas

- Só presentación/UX; sen cambios de esquema (DB 1.37.0).
- O ensanche visual da área e outros axustes de estilo do sitio ANPA viven no tema fillo `anpa-ventin-child`.
- Suite en verde (959 tests, 2761 assertions).
