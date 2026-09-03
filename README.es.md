# Moodle Question Bank Cleaner — guía paso a paso en español

Esta es la guía «sin dar nada por supuesto».

**Idea fundamental:** el programa no es un plugin. Es un script temporal de administración para ejecutar desde la terminal del servidor.

## Antes de empezar

Necesitas:

- acceso a la terminal/SSH del servidor;
- una copia reciente de la base de datos completa;
- saber cuál es la **raíz real de Moodle**;
- Moodle 5.2.x para usar `--apply` con esta versión del script.

La raíz de Moodle es la carpeta que contiene, entre otras cosas:

```text
config.php
admin/
course/
lib/
```

Si tu instalación moderna usa una carpeta `public/` como raíz web, no confundas la raíz que sirve Apache/Nginx con la raíz del código de Moodle. Para este script importa la carpeta donde están `config.php` y `admin/`.

---

## 1. Subir el archivo

Sube:

```text
questionbank_cleanup.php
```

hasta:

```text
<raíz_de_moodle>/admin/cli/questionbank_cleanup.php
```

Puedes hacerlo por SFTP (por ejemplo, FileZilla).

---

## 2. Comprobar que el archivo PHP no tiene errores de sintaxis

Sitúate en la raíz de Moodle:

```bash
cd /ruta/real/de/moodle
```

Comprueba la sintaxis:

```bash
php -l admin/cli/questionbank_cleanup.php
```

Debes obtener algo equivalente a:

```text
No syntax errors detected in admin/cli/questionbank_cleanup.php
```

Si no sale eso, **no sigas**.

---

## 3. Ver la ayuda

```bash
runuser -u www-data -- php admin/cli/questionbank_cleanup.php --help
```

En Debian/Ubuntu el usuario web suele ser `www-data`. Si tu servidor usa otro usuario, tendrás que adaptarlo.

---

## 4. Hacer primero una simulación (DRY-RUN)

Este comando **no borra nada**:

```bash
runuser -u www-data -- php admin/cli/questionbank_cleanup.php
```

El script recorrerá todo el sitio Moodle y terminará con un resumen.

Fíjate especialmente en:

```text
Mode: DRY-RUN (NO CHANGES)
```

Y en si aparece algún aviso como:

```text
WARNING: Found ... quiz slot(s) that are random or do not have exactly one normal reference.
```

Si aparece ese aviso, **no ejecutes el borrado real**. Mira el archivo `quiz_slot_anomalies.csv` que indica el propio programa.

---

## 5. Entender los números del DRY-RUN

El programa habla de **versiones de preguntas**, no necesariamente de preguntas distintas.

Por ejemplo, una misma pregunta puede tener:

- versión 1: antigua;
- versión 2: antigua;
- versión 3: actual y usada por el cuestionario.

En ese caso el informe podría proponer borrar 2 versiones y conservar 1, pero la pregunta actual seguiría existiendo.

Las decisiones principales de `question_versions.csv` son:

```text
KEEP_IN_USE
WOULD_DELETE
```

`KEEP_IN_USE` significa que Moodle considera que esa versión todavía se necesita.

`WOULD_DELETE` significa que el `--apply` la borraría.

---

## 6. Revisar los informes

El programa muestra una ruta parecida a:

```text
<moodledata>/temp/moodle_qbank_cleanup_20260903_120000/
```

Dentro encontrarás, entre otros:

```text
summary.csv
question_versions.csv
categories.csv
quiz_slots_before.csv
quiz_slots_after.csv
```

Y, si detectó alguna rareza:

```text
quiz_slot_anomalies.csv
```

Antes del borrado real revisa al menos `summary.csv` y cualquier archivo de anomalías.

---

## 7. Hacer una copia de seguridad de la base de datos

**No uses `--apply` sin una copia completa reciente de la base de datos.**

La forma concreta de hacerla depende de tu hosting y de si usas MariaDB/MySQL/PostgreSQL. Si no sabes hacer una copia completa y restaurable de la base de datos, detente aquí y hazla desde tu panel de hosting o pide ayuda al administrador.

No subas nunca esa copia de base de datos a GitHub.

---

## 8. Poner Moodle en modo mantenimiento

```bash
runuser -u www-data -- php admin/cli/maintenance.php --enable
```

Comprueba que Moodle queda en mantenimiento antes de seguir.

---

## 9. Ejecutar el borrado real

Este comando **sí borra**:

```bash
runuser -u www-data -- php admin/cli/questionbank_cleanup.php --apply --confirm=DELETE-UNUSED-QUESTIONS
```

Tiene dos seguros deliberados:

- `--apply`
- `--confirm=DELETE-UNUSED-QUESTIONS`

Además, el programa se niega a borrar si no detecta Moodle en mantenimiento o si el pre-flight de los slots de cuestionario no está limpio.

No cierres la terminal mientras trabaja.

---

## 10. NO quitar todavía el modo mantenimiento

Cuando acabe, busca:

```text
Exact quiz-slot mapping unchanged: YES
```

Si dice `NO` o aparece un error `CRITICAL`, deja Moodle en mantenimiento e investiga/restaura antes de abrirlo.

---

## 11. Comparar por segunda vía los slots de los cuestionarios

El script ya hace la comparación internamente, pero puedes comprobar también los dos CSV:

```bash
cmp -s /ruta/del/informe/quiz_slots_before.csv /ruta/del/informe/quiz_slots_after.csv \
  && echo "QUIZ SLOTS IDENTICOS" \
  || echo "ATENCION: HAY DIFERENCIAS"
```

Queremos ver:

```text
QUIZ SLOTS IDENTICOS
```

---

## 12. Quitar el modo mantenimiento

Solo después de que las comprobaciones sean correctas:

```bash
runuser -u www-data -- php admin/cli/maintenance.php --disable
```

Entra en Moodle y abre varios cuestionarios representativos para comprobar visualmente que funcionan.

---

## 13. Retirar el script de producción

Cuando hayas terminado:

```bash
rm admin/cli/questionbank_cleanup.php
```

Conserva una copia del script en tu ordenador o descárgala desde este repositorio si vuelves a necesitarla.

---

# Qué elimina exactamente

En modo real, el programa intenta eliminar:

1. versiones de preguntas que la API de Moodle considera no utilizadas;
2. entradas del banco que se quedan sin ninguna versión;
3. categorías vacías, empezando por las hijas y subiendo por la jerarquía.

Respeta las categorías que Moodle obliga a conservar.

# Qué NO toca

No pretende cambiar:

- preguntas que Moodle considera en uso;
- contenido de las preguntas;
- respuestas;
- feedback;
- puntuaciones;
- archivos/imágenes;
- ajustes de los cuestionarios;
- el conjunto de preguntas colocado en los slots de los cuestionarios.

# Preguntas aleatorias

Esta versión adopta una postura deliberadamente conservadora: si detecta referencias de conjunto (`question_set_references`) en slots de cuestionarios —por ejemplo preguntas aleatorias—, el DRY-RUN las informa y `--apply` se niega a empezar.

# Regla sencilla para no liarla

**DRY-RUN → revisar → backup → mantenimiento → APPLY → comprobar → quitar mantenimiento.**

Nunca empieces por `--apply`.
