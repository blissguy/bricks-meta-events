<?php
/**
 * Works out which submitted fields carry identity data.
 *
 * Bricks field IDs are auto-generated and invisible to the author, so asking
 * for them by ID would be unusable. Detection is by field type, which is
 * reliable, with a narrow label-based heuristic for names and an explicit
 * per-slot override for everything else.
 *
 * The guiding rule is that a wrong identifier is worse than a missing one:
 * Meta counts a garbage hash as an attempted match key, so every ambiguous
 * case resolves to "send nothing and say so" rather than to a guess.
 *
 * @package BricksMetaEvents
 */

namespace BricksMetaEvents;

defined( 'ABSPATH' ) || exit;

/**
 * Extracts hashable identity values from a submitted Bricks form.
 */
class Field_Mapper {

	/**
	 * Field types that can never carry a usable identifier.
	 */
	private const IGNORED_TYPES = array( 'html', 'password', 'rememberme', 'file', 'image', 'gallery' );

	/**
	 * Reserved override value meaning "never send this identity".
	 */
	private const NEVER = 'none';

	/**
	 * Notes describing anything ambiguous, for the health log.
	 *
	 * @var array<int, string>
	 */
	private array $notes = array();

	/**
	 * Candidate fields, in panel order.
	 *
	 * @var array<int, array{position: int, id: string, type: string, label: string, name: string, value: string}>
	 */
	private array $candidates = array();

	/**
	 * @param object $form Bricks form object from bricks/form/response.
	 */
	public function __construct( private $form ) {
		$this->collect_candidates();
	}

	/**
	 * Build the identity payload for ServerEventFactory::safe_create_event().
	 *
	 * Keys here are the ones the host plugin routes into hashed user data;
	 * anything else it would silently reclassify as custom data.
	 *
	 * @return array<string, string>
	 */
	public function identity(): array {
		// A site-wide choice to send nothing that could identify anyone. Meta
		// still counts the enquiry, it just cannot attribute it.
		if ( Settings::never_send_details() ) {
			return array();
		}

		$settings = (array) $this->form->get_settings();
		$identity = array();

		$email = $this->resolve_slot( $settings['bmeEmailField'] ?? '', array( $this, 'detect_email' ) );
		if ( '' !== $email ) {
			$identity['email'] = $email;
		}

		$phone = $this->resolve_slot( $settings['bmePhoneField'] ?? '', array( $this, 'detect_phone' ) );
		if ( '' !== $phone ) {
			$identity['phone'] = $phone;
		}

		$name = $this->resolve_name( $settings['bmeNameField'] ?? '' );
		$identity = array_merge( $identity, $name );

		// A logged-in visitor is the strongest match signal available, and
		// unlike the rest it cannot be mistyped or spoofed.
		if ( is_user_logged_in() ) {
			$identity['external_id'] = (string) get_current_user_id();
		}

		return $identity;
	}

	/**
	 * Ambiguities worth surfacing on the health screen.
	 *
	 * @return array<int, string>
	 */
	public function notes(): array {
		return $this->notes;
	}

	/* ---------------------------------------------------------------------
	 * Detection
	 * ------------------------------------------------------------------ */

	/**
	 * Email: an `email` field, else exactly one `text` field holding an address.
	 */
	private function detect_email(): string {
		$typed = $this->by_type( 'email' );

		if ( 1 === count( $typed ) ) {
			return $typed[0]['value'];
		}

		if ( count( $typed ) > 1 ) {
			$values = array_unique( array_column( $typed, 'value' ) );

			// A confirm-email pair: same address twice, so either is correct.
			if ( 1 === count( $values ) ) {
				return $typed[0]['value'];
			}

			// Two different addresses. Picking one would send the wrong
			// person's hashed email to Meta, so send neither.
			$this->notes[] = sprintf(
				/* translators: %d: number of email fields. */
				__( 'This form has %d email fields with different addresses, so no email was sent. Pick one using the Email field setting.', 'bricks-meta-events' ),
				count( $typed )
			);

			return '';
		}

		// Authors regularly build email inputs as plain text fields. Accepting
		// that is pragmatic; doing it silently is not.
		$inferred = array();

		foreach ( $this->by_type( 'text' ) as $candidate ) {
			if ( $this->is_valid_email( $candidate['value'] ) ) {
				$inferred[] = $candidate;
			}
		}

		if ( 1 === count( $inferred ) ) {
			$this->notes[] = sprintf(
				/* translators: %s: field label. */
				__( 'No email field found, so the address was taken from the text field "%s". Change that field to the Email type to be sure.', 'bricks-meta-events' ),
				$inferred[0]['label']
			);

			return $inferred[0]['value'];
		}

		return '';
	}

	/**
	 * Phone: `tel` fields only.
	 *
	 * Deliberately no inference from text or number fields. Postcodes, order
	 * numbers and years all look like phone numbers to a digit heuristic, and
	 * a hashed wrong number actively degrades match quality.
	 */
	private function detect_phone(): string {
		$typed = $this->by_type( 'tel' );

		return $typed ? $typed[0]['value'] : '';
	}

	/**
	 * Name: label-matched single field, or a first/last pair.
	 *
	 * @return array<string, string>
	 */
	private function resolve_name( string $override ): array {
		if ( self::NEVER === strtolower( trim( $override ) ) ) {
			return array();
		}

		if ( '' !== trim( $override ) ) {
			$field = $this->find_by_reference( $override );

			return $field ? $this->split_name( $field['value'] ) : array();
		}

		$first = null;
		$last  = null;
		$full  = null;

		foreach ( $this->candidates as $candidate ) {
			if ( 'text' !== $candidate['type'] || '' === $candidate['value'] ) {
				continue;
			}

			$haystack = $candidate['label'] . ' ' . $candidate['name'];

			if ( null === $first && preg_match( '/\b(first|given)\b/i', $haystack ) ) {
				$first = $candidate['value'];
				continue;
			}

			if ( null === $last && preg_match( '/\b(last|sur|family)\b/i', $haystack ) ) {
				$last = $candidate['value'];
				continue;
			}

			if ( null === $full && preg_match( '/\b(full[\s-]?name|your\s+name|name)\b/i', $haystack ) ) {
				$full = $candidate['value'];
			}
		}

		if ( null !== $first || null !== $last ) {
			return array_filter(
				array(
					'first_name' => (string) $first,
					'last_name'  => (string) $last,
				),
				static fn( $value ) => '' !== $value
			);
		}

		return null !== $full ? $this->split_name( $full ) : array();
	}

	/* ---------------------------------------------------------------------
	 * Plumbing
	 * ------------------------------------------------------------------ */

	/**
	 * Apply an override if one is set, otherwise fall back to detection.
	 *
	 * @param string   $override Raw override value from the panel.
	 * @param callable $detector Detection callback.
	 */
	private function resolve_slot( string $override, callable $detector ): string {
		$override = trim( $override );

		if ( self::NEVER === strtolower( $override ) ) {
			return '';
		}

		if ( '' === $override ) {
			return (string) call_user_func( $detector );
		}

		$field = $this->find_by_reference( $override );

		if ( null === $field ) {
			$this->notes[] = sprintf(
				/* translators: %s: the override value the author typed. */
				__( 'The field "%s" is not on this form, so that detail was not sent.', 'bricks-meta-events' ),
				$override
			);

			return '';
		}

		return $field['value'];
	}

	/**
	 * Resolve an override string to a field.
	 *
	 * The Bricks field ID is the intended reference: it is shown with a copy
	 * button on every field in the builder, and it survives reordering,
	 * relabelling and dynamic-data changes. Label and POST name are accepted
	 * as conveniences, but both can be edited out from under the override.
	 *
	 * @return array{position: int, id: string, type: string, label: string, name: string, value: string}|null
	 */
	private function find_by_reference( string $reference ) {
		$reference = trim( $reference );

		if ( '' === $reference ) {
			return null;
		}

		foreach ( array( 'id', 'label', 'name' ) as $key ) {
			foreach ( $this->candidates as $candidate ) {
				if ( '' !== $candidate[ $key ] && 0 === strcasecmp( $candidate[ $key ], $reference ) ) {
					return $candidate;
				}
			}
		}

		return null;
	}

	/**
	 * Build the candidate list from the form's own field definitions.
	 *
	 * Bricks normalizes custom field names into form-field-{id} before any
	 * action runs, so get_field_value() is complete by the time this runs.
	 * The raw POST name is only a fallback for loop-context drift.
	 */
	private function collect_candidates(): void {
		$settings = (array) $this->form->get_settings();
		$fields   = isset( $settings['fields'] ) && is_array( $settings['fields'] ) ? $settings['fields'] : array();
		$posted   = (array) $this->form->get_fields();
		$position = 0;

		foreach ( $fields as $field ) {
			++$position;

			$type = (string) ( $field['type'] ?? '' );

			if ( in_array( $type, self::IGNORED_TYPES, true ) ) {
				continue;
			}

			// A honeypot only ever holds bot input.
			if ( ! empty( $field['isHoneypot'] ) ) {
				continue;
			}

			$id = (string) ( $field['id'] ?? '' );

			if ( '' === $id ) {
				continue;
			}

			$name  = (string) ( $field['name'] ?? '' );
			$value = (string) $this->form->get_field_value( $id );

			if ( '' === $value && '' !== $name && isset( $posted[ $name ] ) ) {
				$value = (string) $posted[ $name ];
			}

			// Fields the visitor left blank stay in the list so that an
			// override pointing at one resolves to "empty" rather than to
			// "no such field", which would be a misleading thing to report.
			$this->candidates[] = array(
				'position' => $position,
				'id'       => $id,
				'type'     => $type,
				'label'    => (string) ( $field['label'] ?? '' ),
				'name'     => $name,
				'value'    => trim( $value ),
			);
		}
	}

	/**
	 * Candidates of a given field type that the visitor actually filled in.
	 *
	 * @return array<int, array{position: int, id: string, type: string, label: string, name: string, value: string}>
	 */
	private function by_type( string $type ): array {
		return array_values(
			array_filter(
				$this->candidates,
				static fn( $candidate ) => $candidate['type'] === $type && '' !== $candidate['value']
			)
		);
	}

	/**
	 * Prefer Bricks' own validator, which is stricter than is_email().
	 */
	private function is_valid_email( string $value ): bool {
		$validator = array( '\\Bricks\\Integrations\\Form\\Init', 'is_valid_email' );

		if ( is_callable( $validator ) ) {
			return (bool) call_user_func( $validator, $value );
		}

		return (bool) is_email( $value );
	}

	/**
	 * Split a full name, reusing the host plugin's own logic where possible so
	 * this plugin cannot disagree with its other integrations.
	 *
	 * @return array<string, string>
	 */
	private function split_name( string $value ): array {
		$value = trim( $value );

		if ( '' === $value ) {
			return array();
		}

		$splitter = array( Host_Adapter::CLS_EVENT_FACTORY, 'split_name' );

		if ( is_callable( $splitter ) ) {
			[ $first, $last ] = (array) call_user_func( $splitter, $value );
		} else {
			$index = strpos( $value, ' ' );
			$first = false === $index ? $value : substr( $value, 0, $index );
			$last  = false === $index ? null : substr( $value, $index + 1 );
		}

		return array_filter(
			array(
				'first_name' => (string) $first,
				'last_name'  => (string) $last,
			),
			static fn( $part ) => '' !== $part
		);
	}
}
