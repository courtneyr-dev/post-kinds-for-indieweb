<?php
/**
 * Attachment duration helper coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * @group integration
 */
final class AttachmentDurationTest extends WP_UnitTestCase {

	/**
	 * Create an attachment with controlled metadata.
	 *
	 * @param string               $mime     Attachment MIME type.
	 * @param array<string, mixed> $metadata Attachment metadata.
	 * @param string               $title    Attachment title.
	 * @return int Attachment ID.
	 */
	private function attachment_with_metadata( string $mime, array $metadata, string $title = 'Duration fixture' ): int {
		$attachment_id = self::factory()->attachment->create(
			[
				'post_mime_type' => $mime,
				'post_title'     => $title,
			]
		);
		wp_update_attachment_metadata( $attachment_id, $metadata );

		return $attachment_id;
	}

	/**
	 * @return array<string, array{0: int|string, 1: int, 2: string, 3: string}>
	 */
	public function valid_lengths(): array {
		return [
			'integer'          => [ 134, 134, 'PT2M14S', '2:14' ],
			'digit string'     => [ '134', 134, 'PT2M14S', '2:14' ],
			'hours'            => [ 3725, 3725, 'PT1H2M5S', '1:02:05' ],
			'under one minute' => [ 45, 45, 'PT45S', '0:45' ],
			'exact hour'       => [ 3600, 3600, 'PT1H', '1:00:00' ],
			'exact minute'     => [ 60, 60, 'PT1M', '1:00' ],
		];
	}

	/**
	 * @dataProvider valid_lengths
	 *
	 * @param int|string $stored  Stored metadata value.
	 * @param int        $seconds Expected seconds.
	 * @param string     $iso     Expected ISO duration.
	 * @param string     $clock   Expected clock duration.
	 */
	public function test_reads_strict_positive_lengths( int|string $stored, int $seconds, string $iso, string $clock ): void {
		$attachment_id = $this->attachment_with_metadata( 'audio/mpeg', [ 'length' => $stored ] );

		$this->assertSame(
			[
				'seconds' => $seconds,
				'iso'     => $iso,
				'clock'   => $clock,
				'label'   => human_readable_duration( $clock ),
			],
			\PKIW\attachment_duration( $attachment_id )
		);
	}

	public function test_ignores_length_formatted_in_favor_of_length(): void {
		$attachment_id = $this->attachment_with_metadata(
			'audio/mpeg',
			[
				'length'           => 1674,
				'length_formatted' => '27:53',
			]
		);

		$duration = \PKIW\attachment_duration( $attachment_id );

		$this->assertIsArray( $duration );
		$this->assertSame( '27:54', $duration['clock'] );
		$this->assertSame( human_readable_duration( '27:54' ), $duration['label'] );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public function invalid_lengths(): array {
		return [
			'missing'          => [ null ],
			'zero'             => [ 0 ],
			'negative'         => [ -5 ],
			'float 12.5'       => [ 12.5 ],
			'float 134.7'      => [ 134.7 ],
			'decimal string'   => [ '12.5' ],
			'mixed string'     => [ '12abc' ],
			'empty string'     => [ '' ],
			'array'            => [ [ 134 ] ],
			'too many digits'  => [ '1234567890' ],
		];
	}

	/**
	 * @dataProvider invalid_lengths
	 *
	 * @param mixed $stored Stored metadata value, or null for no key.
	 */
	public function test_rejects_invalid_lengths( mixed $stored ): void {
		$metadata = null === $stored ? [] : [ 'length' => $stored ];
		$id       = $this->attachment_with_metadata( 'video/mp4', $metadata );

		$this->assertNull( \PKIW\attachment_duration( $id ) );
	}

	public function test_rejects_non_media_attachments_and_unknown_ids(): void {
		$image_id = $this->attachment_with_metadata( 'image/jpeg', [ 'length' => 134 ] );

		$this->assertNull( \PKIW\attachment_duration( $image_id ) );
		$this->assertNull( \PKIW\attachment_duration( PHP_INT_MAX ) );
	}

	public function test_result_contains_no_title_or_id3_metadata(): void {
		$attachment_id = $this->attachment_with_metadata(
			'audio/mpeg',
			[
				'length' => 134,
				'title'  => 'Maple Street recording',
				'artist' => 'Maple Street ensemble',
			],
			'Voice memo at Maple Street Cafe'
		);

		$this->assertStringNotContainsString( 'Maple', (string) wp_json_encode( \PKIW\attachment_duration( $attachment_id ) ) );
	}

	public function test_duration_time_html_escapes_each_value(): void {
		$this->assertSame(
			'<time class="pk-duration" datetime="PT2M14S"><span aria-hidden="true">2:14</span><span class="pk-sr-only">2 minutes, 14 seconds</span></time>',
			\PKIW\duration_time_html(
				[
					'iso'   => 'PT2M14S',
					'clock' => '2:14',
					'label' => '2 minutes, 14 seconds',
				]
			)
		);
		$this->assertSame( '', \PKIW\duration_time_html( [ 'iso' => 'PT2M14S', 'clock' => '2:14' ] ) );
	}
}
