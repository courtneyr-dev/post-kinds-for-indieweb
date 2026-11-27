<?php
/**
 * Offline text provider for the WP AI Client.
 *
 * Returns whatever PKIW_Fake_Text_Provider::$response holds, or throws
 * $throw, and records the output schema each generation asked for. It never
 * touches the network.
 *
 * @package PKIW
 */

declare(strict_types=1);

use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Providers\AbstractProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

/**
 * Fake provider with one JSON-capable text model.
 */
final class PKIW_Fake_Text_Provider extends AbstractProvider {

	/**
	 * Text the model returns.
	 *
	 * @var string
	 */
	public static string $response = '';

	/**
	 * Message of an exception the model throws instead of answering.
	 *
	 * @var string
	 */
	public static string $throw = '';

	/**
	 * Output schemas the model was asked for, one per generation.
	 *
	 * @var array<int, mixed>
	 */
	public static array $calls = [];

	/**
	 * Reset the shared state.
	 */
	public static function reset(): void {
		self::$response = '';
		self::$throw    = '';
		self::$calls    = [];
	}

	protected static function createProviderMetadata(): ProviderMetadata {
		return new ProviderMetadata( 'pkiw-fake-text', 'PKIW fake text', ProviderTypeEnum::cloud() );
	}

	protected static function createProviderAvailability(): ProviderAvailabilityInterface {
		return new class() implements ProviderAvailabilityInterface {
			public function isConfigured(): bool {
				return true;
			}
		};
	}

	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new class() implements ModelMetadataDirectoryInterface {
			public function listModelMetadata(): array {
				return [ $this->getModelMetadata( 'fixture-text' ) ];
			}

			public function hasModelMetadata( string $id ): bool {
				return 'fixture-text' === $id;
			}

			public function getModelMetadata( string $id ): ModelMetadata {
				return new ModelMetadata(
					$id,
					'Fixture text',
					[ CapabilityEnum::textGeneration() ],
					[
						new SupportedOption( OptionEnum::outputMimeType(), [ 'application/json' ] ),
						new SupportedOption( OptionEnum::outputSchema() ),
						new SupportedOption( OptionEnum::inputModalities() ),
						new SupportedOption( OptionEnum::outputModalities() ),
					]
				);
			}
		};
	}

	protected static function createModel( ModelMetadata $model, ProviderMetadata $provider ): ModelInterface {
		return new class( $model, $provider ) implements ModelInterface, TextGenerationModelInterface {

			/**
			 * Model config.
			 *
			 * @var ModelConfig|null
			 */
			private ?ModelConfig $config = null;

			public function __construct( private ModelMetadata $model, private ProviderMetadata $provider ) {}

			public function metadata(): ModelMetadata {
				return $this->model;
			}

			public function providerMetadata(): ProviderMetadata {
				return $this->provider;
			}

			public function setConfig( ModelConfig $config ): void {
				$this->config = $config;
			}

			public function getConfig(): ModelConfig {
				if ( null === $this->config ) {
					$this->config = new ModelConfig();
				}
				return $this->config;
			}

			public function generateTextResult( array $prompt ): GenerativeAiResult {
				PKIW_Fake_Text_Provider::$calls[] = $this->getConfig()->getOutputSchema();
				if ( '' !== PKIW_Fake_Text_Provider::$throw ) {
					throw new RuntimeException( PKIW_Fake_Text_Provider::$throw ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				}

				return new GenerativeAiResult(
					'pkiw-fake-text',
					[ new Candidate( new ModelMessage( [ new MessagePart( PKIW_Fake_Text_Provider::$response ) ] ), FinishReasonEnum::stop() ) ],
					new TokenUsage( 0, 0, 0 ),
					$this->provider,
					$this->model
				);
			}

			public function streamGenerateTextResult( array $prompt ): Generator {
				yield $this->generateTextResult( $prompt );
			}
		};
	}
}
