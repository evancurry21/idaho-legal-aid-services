<?php

declare(strict_types=1);

namespace Drupal\ilas_voyage_ai_provider\Plugin\AiProvider;

use Drupal\Component\Serialization\Json;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\AiProviderClientBase;
use Drupal\ai\Exception\AiResponseErrorException;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInterface;
use Drupal\ai\OperationType\Embeddings\EmbeddingsOutput;

/**
 * Plugin implementation of the 'ilas_voyage' provider.
 */
#[AiProvider(
  id: 'ilas_voyage',
  label: new TranslatableMarkup('Voyage AI'),
)]
final class VoyageAiProvider extends AiProviderClientBase implements EmbeddingsInterface {

  /**
   * Voyage embeddings endpoint.
   */
  private const API_ENDPOINT = 'https://api.voyageai.com/v1/embeddings';

  /**
   * Supported Voyage embeddings model.
   */
  private const MODEL_ID = 'voyage-law-2';

  /**
   * Supported Voyage embeddings model label.
   */
  private const MODEL_LABEL = 'Voyage Law 2';

  /**
   * Supported max input size in tokens.
   */
  private const MAX_INPUT_TOKENS = 16000;

  /**
   * Fixed vector size for voyage-law-2.
   */
  private const VECTOR_SIZE = 1024;

  /**
   * {@inheritdoc}
   */
  public function getConfiguredModels(?string $operation_type = NULL, array $capabilities = []): array {
    if ($operation_type !== NULL && $operation_type !== 'embeddings') {
      return [];
    }

    return [
      self::MODEL_ID => self::MODEL_LABEL,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isUsable(?string $operation_type = NULL, array $capabilities = []): bool {
    if (!$this->getConfig()->get('api_key')) {
      return FALSE;
    }

    if ($operation_type !== NULL) {
      return in_array($operation_type, $this->getSupportedOperationTypes(), TRUE);
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedOperationTypes(): array {
    return ['embeddings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getModelSettings(string $model_id, array $generalConfig = []): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function setAuthentication(mixed $authentication): void {
    // Search API AI uses the configured Key entity rather than runtime auth.
  }

  /**
   * {@inheritdoc}
   */
  public function embeddings(string|EmbeddingsInput $input, string $model_id, array $tags = []): EmbeddingsOutput {
    if ($input instanceof EmbeddingsInput) {
      $input = $input->getPrompt();
    }

    $response = $this->httpClient->request('POST', self::API_ENDPOINT, [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->loadApiKey(),
        'Content-Type' => 'application/json',
      ],
      'json' => [
        'model' => $model_id,
        'input' => [$input],
      ] + $this->configuration,
    ]);

    $payload = Json::decode((string) $response->getBody());
    if (!is_array($payload)) {
      throw new AiResponseErrorException('Voyage embeddings response was not valid JSON.');
    }

    $embedding = $payload['data'][0]['embedding'] ?? $payload['embeddings'][0] ?? NULL;
    if (!is_array($embedding)) {
      throw new AiResponseErrorException('Voyage embeddings response did not contain an embedding vector.');
    }

    return new EmbeddingsOutput($embedding, $payload, [
      'model_id' => $model_id,
      'tags' => $tags,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function maxEmbeddingsInput($model_id = ''): int {
    return self::MAX_INPUT_TOKENS;
  }

  /**
   * {@inheritdoc}
   */
  public function embeddingsVectorSize(string $model_id): int {
    return self::VECTOR_SIZE;
  }

  /**
   * {@inheritdoc}
   */
  public function getSetupData(): array {
    return [
      'key_config_name' => 'api_key',
      'default_models' => [
        'embeddings' => self::MODEL_ID,
      ],
    ];
  }

}
