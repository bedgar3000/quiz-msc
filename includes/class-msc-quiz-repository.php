<?php
/**
 * Acceso a datos y lógica de negocio de los quizzes.
 */

defined( 'ABSPATH' ) || exit;

class MSC_Quiz_Repository {

	const TYPES    = array( 'single', 'multiple' );
	const STATUSES = array( 'draft', 'published' );

	/* ---------------------------------------------------------------------
	 * Quizzes
	 * ------------------------------------------------------------------ */

	public static function list_quizzes() {
		global $wpdb;
		$quizzes     = MSC_Quiz_DB::table( 'quizzes' );
		$questions   = MSC_Quiz_DB::table( 'quiz_questions' );
		$submissions = MSC_Quiz_DB::table( 'quiz_submissions' );

		$rows = $wpdb->get_results(
			"SELECT q.*,
				(SELECT COUNT(*) FROM {$questions} qq WHERE qq.quiz_id = q.id) AS question_count,
				(SELECT COUNT(*) FROM {$submissions} s WHERE s.quiz_id = q.id) AS submission_count
			FROM {$quizzes} q
			ORDER BY q.id DESC",
			ARRAY_A
		);

		return array_map(
			function ( $row ) {
				$quiz                     = self::format_quiz( $row );
				$quiz['question_count']   = (int) $row['question_count'];
				$quiz['submission_count'] = (int) $row['submission_count'];
				return $quiz;
			},
			$rows
		);
	}

	public static function get_quiz( $id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . MSC_Quiz_DB::table( 'quizzes' ) . ' WHERE id = %d', $id ),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		$quiz              = self::format_quiz( $row );
		$quiz['questions'] = self::get_questions( $quiz['id'] );
		return $quiz;
	}

	public static function get_quiz_by_slug( $slug ) {
		global $wpdb;
		$id = $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM ' . MSC_Quiz_DB::table( 'quizzes' ) . ' WHERE slug = %s', $slug )
		);
		return $id ? self::get_quiz( (int) $id ) : null;
	}

	private static function get_questions( $quiz_id ) {
		global $wpdb;
		$questions_table = MSC_Quiz_DB::table( 'quiz_questions' );
		$answers_table   = MSC_Quiz_DB::table( 'quiz_answers' );

		$questions = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$questions_table} WHERE quiz_id = %d ORDER BY position, id", $quiz_id ),
			ARRAY_A
		);
		if ( ! $questions ) {
			return array();
		}

		$ids     = implode( ',', array_map( 'intval', wp_list_pluck( $questions, 'id' ) ) );
		$answers = $wpdb->get_results(
			"SELECT * FROM {$answers_table} WHERE question_id IN ({$ids}) ORDER BY position, id",
			ARRAY_A
		);

		$by_question = array();
		foreach ( $answers as $answer ) {
			$by_question[ $answer['question_id'] ][] = array(
				'id'         => (int) $answer['id'],
				'answer'     => $answer['answer'],
				'note'           => (string) $answer['note'],
				'selection_note' => (string) $answer['selection_note'],
				'is_correct'     => (bool) $answer['is_correct'],
			);
		}

		return array_map(
			function ( $q ) use ( $by_question ) {
				return array(
					'id'          => (int) $q['id'],
					'question'    => $q['question'],
					'instruction' => $q['instruction'],
					'note'        => (string) $q['note'],
					'type'        => $q['type'],
					'answers'     => isset( $by_question[ $q['id'] ] ) ? $by_question[ $q['id'] ] : array(),
				);
			},
			$questions
		);
	}

	private static function format_quiz( $row ) {
		$quiz = array(
			'id'           => (int) $row['id'],
			'title'        => $row['title'],
			'slug'         => $row['slug'],
			'eyebrow'      => $row['eyebrow'],
			'description'  => (string) $row['description'],
			'footer_text'  => $row['footer_text'],
			'status'       => $row['status'],
			'published_at' => self::to_input_date( $row['published_at'] ),
			'expires_at'   => self::to_input_date( $row['expires_at'] ),
			'created_at'   => $row['created_at'],
			'updated_at'   => $row['updated_at'],
		);

		$quiz['availability'] = self::availability( $quiz );
		$quiz['url']          = MSC_Quiz_Public::quiz_url( $quiz['slug'] );
		$quiz['shortcode']    = sprintf( '[msc_quiz id="%d"]', $quiz['id'] );
		return $quiz;
	}

	/**
	 * Valida y guarda un quiz completo (con preguntas y respuestas).
	 *
	 * Las preguntas y respuestas que llegan con `id` existente se actualizan en
	 * lugar de recrearse, de modo que las estadísticas no pierden la referencia.
	 *
	 * @return int|WP_Error ID del quiz.
	 */
	public static function save_quiz( array $data, $id = 0 ) {
		global $wpdb;

		$quiz = self::sanitize_quiz( $data );
		if ( is_wp_error( $quiz ) ) {
			return $quiz;
		}

		$quizzes_table   = MSC_Quiz_DB::table( 'quizzes' );
		$questions_table = MSC_Quiz_DB::table( 'quiz_questions' );
		$answers_table   = MSC_Quiz_DB::table( 'quiz_answers' );
		$now             = current_time( 'mysql' );

		if ( $id && ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$quizzes_table} WHERE id = %d", $id ) ) ) {
			return new WP_Error( 'msc_quiz_not_found', 'El quiz no existe.', array( 'status' => 404 ) );
		}

		$questions = $quiz['questions'];
		unset( $quiz['questions'] );
		$quiz['slug']       = self::unique_slug( $quiz['slug'] ? $quiz['slug'] : $quiz['title'], $id );
		$quiz['updated_at'] = $now;

		$wpdb->query( 'START TRANSACTION' );

		if ( $id ) {
			$ok = $wpdb->update( $quizzes_table, $quiz, array( 'id' => $id ) );
		} else {
			$quiz['created_at'] = $now;
			$quiz['created_by'] = get_current_user_id();
			$ok                 = $wpdb->insert( $quizzes_table, $quiz );
			$id                 = (int) $wpdb->insert_id;
		}
		if ( false === $ok ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'msc_quiz_db', 'No se pudo guardar el quiz: ' . $wpdb->last_error, array( 'status' => 500 ) );
		}

		$existing_questions = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$questions_table} WHERE quiz_id = %d", $id ) ) );
		$existing_answers   = array();
		if ( $existing_questions ) {
			$rows = $wpdb->get_results(
				"SELECT id, question_id FROM {$answers_table} WHERE question_id IN (" . implode( ',', $existing_questions ) . ')',
				ARRAY_A
			);
			foreach ( $rows as $row ) {
				$existing_answers[ (int) $row['id'] ] = (int) $row['question_id'];
			}
		}

		$kept_questions = array();
		$kept_answers   = array();

		foreach ( $questions as $q_pos => $question ) {
			$answers = $question['answers'];
			$q_id    = $question['id'];
			$q_row   = array(
				'quiz_id'     => $id,
				'position'    => $q_pos,
				'question'    => $question['question'],
				'instruction' => $question['instruction'],
				'note'        => $question['note'],
				'type'        => $question['type'],
			);

			if ( $q_id && in_array( $q_id, $existing_questions, true ) ) {
				$ok = $wpdb->update( $questions_table, $q_row, array( 'id' => $q_id ) );
			} else {
				$ok   = $wpdb->insert( $questions_table, $q_row );
				$q_id = (int) $wpdb->insert_id;
			}
			if ( false === $ok ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'msc_quiz_db', 'No se pudo guardar una pregunta: ' . $wpdb->last_error, array( 'status' => 500 ) );
			}
			$kept_questions[] = $q_id;

			foreach ( $answers as $a_pos => $answer ) {
				$a_id  = $answer['id'];
				$a_row = array(
					'question_id' => $q_id,
					'position'    => $a_pos,
					'answer'         => $answer['answer'],
					'note'           => $answer['note'],
					'selection_note' => $answer['selection_note'],
					'is_correct'     => $answer['is_correct'] ? 1 : 0,
				);
				if ( $a_id && isset( $existing_answers[ $a_id ] ) && $existing_answers[ $a_id ] === $q_id ) {
					$ok = $wpdb->update( $answers_table, $a_row, array( 'id' => $a_id ) );
				} else {
					$ok   = $wpdb->insert( $answers_table, $a_row );
					$a_id = (int) $wpdb->insert_id;
				}
				if ( false === $ok ) {
					$wpdb->query( 'ROLLBACK' );
					return new WP_Error( 'msc_quiz_db', 'No se pudo guardar una respuesta: ' . $wpdb->last_error, array( 'status' => 500 ) );
				}
				$kept_answers[] = $a_id;
			}
		}

		$removed_questions = array_diff( $existing_questions, $kept_questions );
		if ( $removed_questions ) {
			$wpdb->query( "DELETE FROM {$questions_table} WHERE id IN (" . implode( ',', $removed_questions ) . ')' );
		}
		$removed_answers = array_diff( array_keys( $existing_answers ), $kept_answers );
		if ( $removed_answers ) {
			$wpdb->query( "DELETE FROM {$answers_table} WHERE id IN (" . implode( ',', $removed_answers ) . ')' );
		}

		$wpdb->query( 'COMMIT' );
		MSC_Quiz_Public::purge_page_cache();
		return $id;
	}

	private static function sanitize_quiz( array $data ) {
		$title = isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '';
		if ( '' === $title ) {
			return self::invalid( 'El título del quiz es obligatorio.' );
		}

		$status = isset( $data['status'] ) && in_array( $data['status'], self::STATUSES, true ) ? $data['status'] : 'draft';

		$published_at = self::from_input_date( isset( $data['published_at'] ) ? $data['published_at'] : '' );
		$expires_at   = self::from_input_date( isset( $data['expires_at'] ) ? $data['expires_at'] : '' );
		if ( $published_at && $expires_at && $expires_at <= $published_at ) {
			return self::invalid( 'La fecha de expiración debe ser posterior a la fecha de publicación.' );
		}

		$raw_questions = isset( $data['questions'] ) && is_array( $data['questions'] ) ? array_values( $data['questions'] ) : array();
		if ( 'published' === $status && ! $raw_questions ) {
			return self::invalid( 'Agrega al menos una pregunta antes de publicar el quiz.' );
		}

		$questions = array();
		foreach ( $raw_questions as $i => $raw ) {
			$n    = $i + 1;
			$text = isset( $raw['question'] ) ? sanitize_textarea_field( $raw['question'] ) : '';
			if ( '' === $text ) {
				return self::invalid( "La pregunta {$n} no tiene texto." );
			}
			$type = isset( $raw['type'] ) && in_array( $raw['type'], self::TYPES, true ) ? $raw['type'] : 'single';

			$answers     = array();
			$raw_answers = isset( $raw['answers'] ) && is_array( $raw['answers'] ) ? array_values( $raw['answers'] ) : array();
			foreach ( $raw_answers as $j => $raw_answer ) {
				$answer_text = isset( $raw_answer['answer'] ) ? sanitize_textarea_field( $raw_answer['answer'] ) : '';
				if ( '' === $answer_text ) {
					return self::invalid( sprintf( 'La respuesta %s de la pregunta %d está vacía.', self::letter( $j ), $n ) );
				}
				$answers[] = array(
					'id'         => isset( $raw_answer['id'] ) ? absint( $raw_answer['id'] ) : 0,
					'answer'     => $answer_text,
					'note'           => isset( $raw_answer['note'] ) ? sanitize_textarea_field( $raw_answer['note'] ) : '',
					'selection_note' => isset( $raw_answer['selection_note'] ) ? sanitize_textarea_field( $raw_answer['selection_note'] ) : '',
					'is_correct'     => ! empty( $raw_answer['is_correct'] ),
				);
			}

			if ( count( $answers ) < 2 ) {
				return self::invalid( "La pregunta {$n} necesita al menos dos respuestas." );
			}
			$correct = count( array_filter( wp_list_pluck( $answers, 'is_correct' ) ) );
			if ( 'single' === $type && 1 !== $correct ) {
				return self::invalid( "La pregunta {$n} es de selección única: marca exactamente una respuesta correcta." );
			}
			if ( 'multiple' === $type && $correct < 1 ) {
				return self::invalid( "La pregunta {$n} es de selección múltiple: marca al menos una respuesta correcta." );
			}

			$questions[] = array(
				'id'          => isset( $raw['id'] ) ? absint( $raw['id'] ) : 0,
				'question'    => $text,
				'instruction' => isset( $raw['instruction'] ) ? sanitize_text_field( $raw['instruction'] ) : '',
				'note'        => isset( $raw['note'] ) ? sanitize_textarea_field( $raw['note'] ) : '',
				'type'        => $type,
				'answers'     => $answers,
			);
		}

		return array(
			'title'        => $title,
			'slug'         => isset( $data['slug'] ) ? sanitize_title( $data['slug'] ) : '',
			'eyebrow'      => isset( $data['eyebrow'] ) ? sanitize_text_field( $data['eyebrow'] ) : '',
			'description'  => isset( $data['description'] ) ? sanitize_textarea_field( $data['description'] ) : '',
			'footer_text'  => isset( $data['footer_text'] ) ? sanitize_text_field( $data['footer_text'] ) : '',
			'status'       => $status,
			'published_at' => $published_at,
			'expires_at'   => $expires_at,
			'questions'    => $questions,
		);
	}

	public static function delete_quiz( $id ) {
		global $wpdb;
		$question_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . MSC_Quiz_DB::table( 'quiz_questions' ) . ' WHERE quiz_id = %d', $id ) );
		if ( $question_ids ) {
			$wpdb->query( 'DELETE FROM ' . MSC_Quiz_DB::table( 'quiz_answers' ) . ' WHERE question_id IN (' . implode( ',', array_map( 'intval', $question_ids ) ) . ')' );
		}
		$wpdb->delete( MSC_Quiz_DB::table( 'quiz_questions' ), array( 'quiz_id' => $id ) );

		$submission_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . MSC_Quiz_DB::table( 'quiz_submissions' ) . ' WHERE quiz_id = %d', $id ) );
		if ( $submission_ids ) {
			$wpdb->query( 'DELETE FROM ' . MSC_Quiz_DB::table( 'quiz_submission_answers' ) . ' WHERE submission_id IN (' . implode( ',', array_map( 'intval', $submission_ids ) ) . ')' );
		}
		$wpdb->delete( MSC_Quiz_DB::table( 'quiz_submissions' ), array( 'quiz_id' => $id ) );

		$deleted = (bool) $wpdb->delete( MSC_Quiz_DB::table( 'quizzes' ), array( 'id' => $id ) );
		MSC_Quiz_Public::purge_page_cache();
		return $deleted;
	}

	public static function duplicate_quiz( $id ) {
		$quiz = self::get_quiz( $id );
		if ( ! $quiz ) {
			return new WP_Error( 'msc_quiz_not_found', 'El quiz no existe.', array( 'status' => 404 ) );
		}
		$quiz['title'] .= ' (copia)';
		$quiz['slug']   = $quiz['slug'] . '-copia';
		$quiz['status'] = 'draft';
		foreach ( $quiz['questions'] as &$question ) {
			unset( $question['id'] );
			foreach ( $question['answers'] as &$answer ) {
				unset( $answer['id'] );
			}
		}
		return self::save_quiz( $quiz );
	}

	public static function unique_slug( $source, $exclude_id = 0 ) {
		global $wpdb;
		$base = sanitize_title( $source );
		if ( '' === $base ) {
			$base = 'quiz';
		}
		$slug   = $base;
		$suffix = 2;
		while ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . MSC_Quiz_DB::table( 'quizzes' ) . ' WHERE slug = %s AND id != %d', $slug, $exclude_id ) ) ) {
			$slug = $base . '-' . $suffix++;
		}
		return $slug;
	}

	/**
	 * Estado público: draft | scheduled | expired | open.
	 */
	public static function availability( array $quiz ) {
		if ( 'published' !== $quiz['status'] ) {
			return 'draft';
		}
		$now          = current_time( 'mysql' );
		$published_at = self::from_input_date( $quiz['published_at'] );
		$expires_at   = self::from_input_date( $quiz['expires_at'] );
		if ( $published_at && $now < $published_at ) {
			return 'scheduled';
		}
		if ( $expires_at && $now >= $expires_at ) {
			return 'expired';
		}
		return 'open';
	}

	/**
	 * Datos que viajan al navegador del visitante: sin respuestas correctas ni
	 * retroalimentación, que se entregan sólo al corregir. La "nota al
	 * seleccionar" sí viaja, porque se muestra en cuanto se elige la respuesta.
	 */
	public static function public_payload( array $quiz ) {
		$availability = self::availability( $quiz );
		$messages     = array(
			'draft'     => 'Este quiz no está disponible.',
			'scheduled' => 'Este quiz estará disponible a partir del ' . self::human_date( $quiz['published_at'] ) . '.',
			'expired'   => 'Este quiz expiró el ' . self::human_date( $quiz['expires_at'] ) . ' y ya no acepta respuestas.',
			'open'      => '',
		);

		$questions = array();
		if ( 'open' === $availability ) {
			foreach ( $quiz['questions'] as $question ) {
				$questions[] = array(
					'id'          => $question['id'],
					'question'    => $question['question'],
					'instruction' => $question['instruction'],
					'type'        => $question['type'],
					'answers'     => array_map(
						function ( $answer ) {
							return array(
								'id'             => $answer['id'],
								'answer'         => $answer['answer'],
								'selection_note' => $answer['selection_note'],
							);
						},
						$question['answers']
					),
				);
			}
		}

		return array(
			'id'           => $quiz['id'],
			'title'        => $quiz['title'],
			'eyebrow'      => $quiz['eyebrow'],
			'description'  => $quiz['description'],
			'footer_text'  => $quiz['footer_text'],
			'availability' => $availability,
			'message'      => $messages[ $availability ],
			'questions'    => $questions,
		);
	}

	/* ---------------------------------------------------------------------
	 * Corrección y respuestas de usuarios
	 * ------------------------------------------------------------------ */

	/**
	 * @param array $quiz       Quiz completo (get_quiz).
	 * @param array $selections [question_id => [answer_id, ...]].
	 */
	public static function grade( array $quiz, array $selections ) {
		$results = array();
		$score   = 0;

		foreach ( $quiz['questions'] as $question ) {
			$valid_ids   = wp_list_pluck( $question['answers'], 'id' );
			$selected    = isset( $selections[ $question['id'] ] ) ? array_map( 'intval', (array) $selections[ $question['id'] ] ) : array();
			$selected    = array_values( array_intersect( $valid_ids, array_unique( $selected ) ) );
			$correct_ids = array();
			foreach ( $question['answers'] as $answer ) {
				if ( $answer['is_correct'] ) {
					$correct_ids[] = $answer['id'];
				}
			}
			if ( 'single' === $question['type'] ) {
				$selected = array_slice( $selected, 0, 1 );
			}

			$sorted_selected = $selected;
			$sorted_correct  = $correct_ids;
			sort( $sorted_selected );
			sort( $sorted_correct );
			$is_correct = $sorted_selected && $sorted_selected === $sorted_correct;
			if ( $is_correct ) {
				++$score;
			}

			$results[] = array(
				'question_id' => $question['id'],
				'question'    => $question['question'],
				'type'        => $question['type'],
				'selected'    => array_values(
					array_map(
						function ( $answer ) {
							return array(
								'id'     => $answer['id'],
								'answer' => $answer['answer'],
							);
						},
						array_filter(
							$question['answers'],
							function ( $answer ) use ( $selected ) {
								return in_array( $answer['id'], $selected, true );
							}
						)
					)
				),
				'correct'     => $is_correct,
				'feedback'    => self::feedback( $question, $selected, $is_correct ),
			);
		}

		$total = count( $quiz['questions'] );
		return array(
			'score'      => $score,
			'total'      => $total,
			'percentage' => $total ? round( $score / $total * 100 ) : 0,
			'results'    => $results,
		);
	}

	/**
	 * Construye la retroalimentación con las notas configuradas:
	 * - Nota de cada respuesta seleccionada (en múltiple incorrecta, sólo las erróneas).
	 * - En selección múltiple incorrecta, aviso por cada respuesta correcta no marcada.
	 * - La nota de la pregunta se añade cuando la respuesta es incorrecta.
	 */
	private static function feedback( array $question, array $selected, $is_correct ) {
		$messages = array();

		if ( ! $selected ) {
			$messages[] = 'No seleccionaste una respuesta. Revisa la pregunta e inténtalo de nuevo.';
		} elseif ( 'multiple' === $question['type'] && ! $is_correct ) {
			foreach ( $question['answers'] as $answer ) {
				$picked = in_array( $answer['id'], $selected, true );
				if ( $picked && ! $answer['is_correct'] && '' !== $answer['note'] ) {
					$messages[] = $answer['note'];
				}
			}
			foreach ( $question['answers'] as $answer ) {
				if ( $answer['is_correct'] && ! in_array( $answer['id'], $selected, true ) ) {
					$messages[] = 'Revisa la opción «' . $answer['answer'] . '», que forma parte de las respuestas esperadas.';
				}
			}
		} else {
			foreach ( $question['answers'] as $answer ) {
				if ( in_array( $answer['id'], $selected, true ) && '' !== $answer['note'] ) {
					$messages[] = $answer['note'];
				}
			}
		}

		if ( ! $is_correct && '' !== $question['note'] ) {
			$messages[] = $question['note'];
		}

		return ( $is_correct ? 'Respuesta correcta. ' : 'Revisa tu respuesta. ' ) . implode( ' ', $messages );
	}

	public static function save_submission( array $quiz, array $graded ) {
		global $wpdb;

		$detail = array_map(
			function ( $result ) {
				unset( $result['feedback'] );
				return $result;
			},
			$graded['results']
		);

		$wpdb->insert(
			MSC_Quiz_DB::table( 'quiz_submissions' ),
			array(
				'quiz_id'    => $quiz['id'],
				'user_id'    => get_current_user_id(),
				'score'      => $graded['score'],
				'total'      => $graded['total'],
				'percentage' => $graded['percentage'],
				'ip'         => self::client_ip(),
				'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '',
				'detail'     => wp_json_encode( $detail ),
				'created_at' => current_time( 'mysql' ),
			)
		);
		$submission_id = (int) $wpdb->insert_id;
		if ( ! $submission_id ) {
			return 0;
		}

		$values = array();
		foreach ( $graded['results'] as $result ) {
			foreach ( $result['selected'] as $answer ) {
				$values[] = $wpdb->prepare( '(%d,%d,%d)', $submission_id, $result['question_id'], $answer['id'] );
			}
		}
		if ( $values ) {
			$wpdb->query( 'INSERT INTO ' . MSC_Quiz_DB::table( 'quiz_submission_answers' ) . ' (submission_id, question_id, answer_id) VALUES ' . implode( ',', $values ) );
		}

		return $submission_id;
	}

	public static function list_submissions( $quiz_id, $page = 1, $per_page = 20 ) {
		global $wpdb;
		$table  = MSC_Quiz_DB::table( 'quiz_submissions' );
		$offset = ( max( 1, $page ) - 1 ) * $per_page;

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE quiz_id = %d", $quiz_id ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, u.display_name, u.user_email
				FROM {$table} s
				LEFT JOIN {$wpdb->users} u ON u.ID = s.user_id
				WHERE s.quiz_id = %d
				ORDER BY s.created_at DESC, s.id DESC
				LIMIT %d OFFSET %d",
				$quiz_id,
				$per_page,
				$offset
			),
			ARRAY_A
		);

		return array(
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
			'items'       => array_map( array( __CLASS__, 'format_submission' ), $rows ),
		);
	}

	public static function format_submission( array $row ) {
		return array(
			'id'         => (int) $row['id'],
			'user_id'    => (int) $row['user_id'],
			'user'       => $row['user_id'] ? ( $row['display_name'] ? $row['display_name'] : 'Usuario #' . $row['user_id'] ) : 'Anónimo',
			'email'      => isset( $row['user_email'] ) ? (string) $row['user_email'] : '',
			'score'      => (int) $row['score'],
			'total'      => (int) $row['total'],
			'percentage' => (float) $row['percentage'],
			'ip'         => $row['ip'],
			'created_at' => $row['created_at'],
			'detail'     => json_decode( (string) $row['detail'], true ),
		);
	}

	public static function delete_submission( $id ) {
		global $wpdb;
		$wpdb->delete( MSC_Quiz_DB::table( 'quiz_submission_answers' ), array( 'submission_id' => $id ) );
		return (bool) $wpdb->delete( MSC_Quiz_DB::table( 'quiz_submissions' ), array( 'id' => $id ) );
	}

	/**
	 * Estadísticas: promedio, % de acierto por pregunta y veces elegida cada respuesta.
	 */
	public static function stats( array $quiz ) {
		global $wpdb;
		$submissions = MSC_Quiz_DB::table( 'quiz_submissions' );
		$picks       = MSC_Quiz_DB::table( 'quiz_submission_answers' );

		$summary = $wpdb->get_row(
			$wpdb->prepare( "SELECT COUNT(*) AS total, AVG(percentage) AS average FROM {$submissions} WHERE quiz_id = %d", $quiz['id'] ),
			ARRAY_A
		);

		$counts = array();
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.answer_id, COUNT(*) AS picks
				FROM {$picks} p INNER JOIN {$submissions} s ON s.id = p.submission_id
				WHERE s.quiz_id = %d GROUP BY p.answer_id",
				$quiz['id']
			),
			ARRAY_A
		);
		foreach ( $rows as $row ) {
			$counts[ (int) $row['answer_id'] ] = (int) $row['picks'];
		}

		$correct_by_question = array();
		$details             = $wpdb->get_col( $wpdb->prepare( "SELECT detail FROM {$submissions} WHERE quiz_id = %d", $quiz['id'] ) );
		foreach ( $details as $json ) {
			foreach ( (array) json_decode( (string) $json, true ) as $result ) {
				if ( ! empty( $result['correct'] ) && isset( $result['question_id'] ) ) {
					$qid                         = (int) $result['question_id'];
					$correct_by_question[ $qid ] = ( isset( $correct_by_question[ $qid ] ) ? $correct_by_question[ $qid ] : 0 ) + 1;
				}
			}
		}

		$total     = (int) $summary['total'];
		$questions = array();
		foreach ( $quiz['questions'] as $question ) {
			$questions[] = array(
				'id'       => $question['id'],
				'question' => $question['question'],
				'correct'  => isset( $correct_by_question[ $question['id'] ] ) ? $correct_by_question[ $question['id'] ] : 0,
				'answers'  => array_map(
					function ( $answer ) use ( $counts ) {
						return array(
							'id'         => $answer['id'],
							'answer'     => $answer['answer'],
							'is_correct' => $answer['is_correct'],
							'picks'      => isset( $counts[ $answer['id'] ] ) ? $counts[ $answer['id'] ] : 0,
						);
					},
					$question['answers']
				),
			);
		}

		return array(
			'total'     => $total,
			'average'   => $total ? round( (float) $summary['average'], 1 ) : 0,
			'questions' => $questions,
		);
	}

	/* ---------------------------------------------------------------------
	 * Utilidades
	 * ------------------------------------------------------------------ */

	public static function letter( $index ) {
		$letters = '';
		for ( $n = $index + 1; $n > 0; $n = intdiv( $n - 1, 26 ) ) {
			$letters = chr( 65 + ( $n - 1 ) % 26 ) . $letters;
		}
		return $letters;
	}

	private static function invalid( $message ) {
		return new WP_Error( 'msc_quiz_invalid', $message, array( 'status' => 400 ) );
	}

	/** 'Y-m-d H:i:s' → 'Y-m-d\TH:i' (formato de <input type="datetime-local">). */
	private static function to_input_date( $value ) {
		if ( ! $value || '0000-00-00 00:00:00' === $value ) {
			return '';
		}
		return str_replace( ' ', 'T', substr( $value, 0, 16 ) );
	}

	/** Acepta 'Y-m-d\TH:i' o 'Y-m-d H:i[:s]'; devuelve 'Y-m-d H:i:s' o null. */
	private static function from_input_date( $value ) {
		$value = trim( str_replace( 'T', ' ', (string) $value ) );
		if ( '' === $value ) {
			return null;
		}
		$date = DateTime::createFromFormat( 'Y-m-d H:i:s', strlen( $value ) === 16 ? $value . ':00' : $value );
		return $date ? $date->format( 'Y-m-d H:i:s' ) : null;
	}

	private static function human_date( $input_date ) {
		$mysql = self::from_input_date( $input_date );
		return $mysql ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $mysql ) ) : '';
	}

	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
