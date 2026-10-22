# MSC Quiz

Plugin de WordPress para crear quizzes/encuestas desde **wp-admin → Quiz MSC** y publicarlos en la web. Las dos interfaces (el panel admin y el quiz público) están hechas con Vue 3.

## Cómo mostrar un quiz

| Forma | Ejemplo | Header/footer del tema |
|---|---|---|
| URL propia (automática por slug) | `https://msc.test/quiz/mi-quiz/` | **No**: es una plantilla en blanco con el HTML/CSS/JS de `template.html` |
| Shortcode por ID | `[msc_quiz id="1"]` | Sí (lo que tenga la página) |
| Shortcode por slug | `[msc_quiz slug="mi-quiz"]` | Sí |

- El estado del quiz es **Borrador** o **Publicado**. Un borrador devuelve 404 a los visitantes.
- **Fecha de publicación** (opcional): antes de esa fecha el quiz muestra "aún no está disponible".
- **Fecha de expiración** (opcional): después de esa fecha muestra "expiró" y no acepta respuestas.
- Los administradores pueden ver la vista previa de cualquier quiz (borrador, programado o expirado). Sus respuestas en la vista previa se corrigen pero no se guardan.
- La base `/quiz/` se puede cambiar con el filtro `msc_quiz_url_base`. Después hay que ir a *Ajustes → Enlaces permanentes → Guardar*.

## Base de datos

Se usan **tablas relacionadas** en lugar de un campo JSON para las preguntas. Así se puede editar una pregunta sin perder la referencia de las estadísticas (los IDs se mantienen al guardar), ordenar por índice y contar con SQL cuántas veces se eligió cada respuesta.

```
{prefix}msc_quizzes                  id, title, slug (único), eyebrow, description, footer_text,
                                     status, published_at, expires_at, created_by, created_at, updated_at
{prefix}msc_quiz_questions           id, quiz_id, position, question, instruction, note, type (single|multiple)
{prefix}msc_quiz_answers             id, question_id, position, answer, note, selection_note, is_correct (tinyint)
{prefix}msc_quiz_submissions         id, quiz_id, user_id, score, total, percentage, ip, user_agent,
                                     detail (JSON snapshot), created_at
{prefix}msc_quiz_submission_answers  id, submission_id, question_id, answer_id
```

- El **tipo** (selección única o múltiple) se guarda en la pregunta, no en cada respuesta, porque define si toda la pregunta usa radios o checkboxes.
- Cada respuesta enviada guarda una copia JSON (`detail`) de lo que vio el usuario. El historial sigue legible aunque el quiz se edite o se borren preguntas más tarde.

## Retroalimentación

La corrección se hace en el servidor. El navegador no recibe las respuestas correctas ni las notas hasta que el usuario envía el quiz.

- **Nota al seleccionar** (opcional, por respuesta): aparece de inmediato en un recuadro amarillo bajo la respuesta en cuanto el usuario la elige, antes de corregir.
- **Retroalimentación de cada respuesta**: al corregir, se muestra la de la respuesta que eligió el usuario.
- **Selección múltiple incorrecta**: se muestran las notas de las opciones erróneas elegidas y el aviso "Revisa la opción «…»" por cada opción correcta que no marcó.
- **Nota de la pregunta**: se añade cuando la respuesta es incorrecta.

## Respuestas de usuarios

En *Quiz MSC → Respuestas* se ven el total de respuestas, el promedio, el % de acierto por pregunta y cuántas veces se eligió cada respuesta. También se puede ver el detalle de cada envío y exportarlo a CSV.

## Desarrollo

```bash
npm install
npm run build          # genera assets/dist/{admin,public}.{js,css}
npm run watch:admin    # recompila el admin al guardar
npm run watch:public
```

- `src/admin/`: aplicación Vue del panel (listado, editor y respuestas).
- `src/public/Quiz.vue` y `src/public/quiz.css`: el quiz público. Es el HTML de `template.html` llevado a Vue, con el CSS limitado a `.msc-quiz` para que no choque con el tema.
- `templates/standalone.php`: la plantilla en blanco de la URL propia.
- `includes/`: tablas (`class-msc-quiz-db.php`), lógica (`-repository`), API REST `msc-quiz/v1` (`-rest`), menú admin (`-admin`) y shortcode/rutas (`-public`).

`assets/dist/` ya va compilado. Para usar el plugin no hace falta Node: basta con subir la carpeta sin `node_modules/`.

## Filtros

- `msc_quiz_capability`: permiso para administrar (por defecto `manage_options`).
- `msc_quiz_url_base`: base de la URL pública (por defecto `quiz`).
- `msc_quiz_rate_limit`: envíos máximos por IP cada 10 minutos (por defecto 30; `0` lo desactiva).

Al **borrar** el plugin se eliminan sus tablas. Para conservar los datos, define `MSC_QUIZ_KEEP_DATA` como `true` en `wp-config.php`.
