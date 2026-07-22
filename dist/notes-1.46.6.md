## anpa-socios 1.46.6

Melloras do asistente de configuración inicial e corrección do sementado de
estrutura. Validado da prerelease 1.46.6-beta.1.

### Corrixido

- **Niveis duplicados ao instalar**: se o sitio xa tiña niveis (instalación
  parcial previa ou restauración), o asistente sementaba os niveis por defecto
  (1º–6º) **enriba** dos existentes, creando duplicados. Agora só se sementa a
  estrutura cando **non existe ningún nivel**; se xa hai catálogo, non se toca.

### Novo no asistente

- **Email do administrador raíz**: agora pódese establecer na instalación (antes
  quedaba no valor por defecto e só se podía cambiar despois en Axustes). É a conta
  protexida; non controla o remitente dos correos (iso é WP Mail SMTP).
- **Firma dos correos**: recóllese na instalación cunha plantilla neutra por defecto
  (`— / nome da asociación`), editable. Antes quedaba baleira ata configurala en Axustes.
- **Enderezo (RGPD)**: aclárase que se amosa no aviso de protección de datos do
  formulario de alta, cun placeholder de exemplo.
- **Código postal**: placeholder neutro de exemplo (`00000`). O valor real
  escríbeo cada asociación (o plugin é reutilizable e non fixa datos concretos).

### Notas técnicas

- Sen cambios de esquema (DB 1.37.0).
- Cambios en `render_setup_wizard` / `process_setup_inline` e
  `ANPA_Socios_Admin_Estrutura_Handler::seed_default_structure` (guarda de catálogo baleiro).
- Suite PHPUnit en verde (948 tests, 2729 assertions).
