# Acta de Entrega - GLPI 11

Plugin institucional de Songa para emitir actas de entrega de equipos desde GLPI.

## Reglas funcionales acordadas
- Datos del colaborador tomados de GLPI: categoría = departamento, título = cargo, supervisor = jefe, ubicación = área.
- Gerente de área desde Fields: contenedor 10, campo 14, columna users_id_gerentefield en la tabla del contenedor de ubicaciones. Si no hay gerente, se usa el supervisor.
- Fecha automática al emitir.
- Activos con asignación actual al usuario, seleccionables individualmente. No incluir relaciones históricas de conexiones.
- Tabla PDF dinámica de cinco columnas: Ítem, Tipo, Número de Serie, Descripción del equipo, Activo Fijo. Una fila por activo, filas ilimitadas, salto de página automático. Sin activo fijo: N/A.
- Descripción incluye nombre, fabricante, modelo, especificaciones disponibles y comentario GLPI.
- Técnico emisor identificado por sesión GLPI; reutiliza la firma personal del plugin Informe Técnico (glpi_plugin_informeglopi_user_signatures) y exige PIN para autorizarla.
- Validación obligatoria de todos los datos. Si falta algo, no se emite el acta y se informa qué debe corregirse en GLPI.
- Guardar snapshot de usuario y activos, PDF privado e historial.

## Integración existente
Repositorio de Informe Técnico: https://github.com/fmosquera01/InformeGLPI
Versión consultada: 0.5.67 (rama main).

## Instalación
Copiar actadeentrega a GLPI_ROOT/plugins/, instalar y activar desde Configuración > Complementos. Requiere GLPI 11.x y PHP 8.2+.

## Estado
Desarrollo inicial. Validar en una instancia de pruebas antes de producción.