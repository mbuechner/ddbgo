<?php

declare(strict_types=1);

namespace Drupal\ddbgo_cj;

use Drupal\content_lock\ContentLock\ContentLockInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Synchronizes KWE nodes with organization data from the DDB API.
 */
final class KweQueueWorker {

  private const QUEUE_NAME = 'kwe_queue_worker';

  private const ORGANIZATION_URL = 'https://www.deutsche-digitale-bibliothek.de/organization/';

  private const API_URL = 'https://api.deutsche-digitale-bibliothek.de/2/items/';

  /**
   * Prevents a synchronized save from enqueuing the same node again.
   */
  private bool $synchronizing = FALSE;

  /**
   * Constructs the KWE queue worker.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ContentLockInterface $contentLock,
    private readonly QueueFactory $queueFactory,
    private readonly AccountProxyInterface $currentUser,
    private readonly ClientInterface $httpClient,
    private readonly LoggerInterface $logger,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Adds every published KWE node that is not already queued.
   */
  public function enqueuePublishedNodes(): void {
    $node_storage = $this->entityTypeManager->getStorage('node');
    $node_ids = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('type', 'kwe')
      ->execute();

    $queued_ids = $this->getQueuedNodeIds();
    $queue = $this->getQueue();
    foreach ($node_ids as $node_id) {
      if (!in_array((int) $node_id, $queued_ids, TRUE)) {
        $queue->createItem((int) $node_id);
      }
    }
  }

  /**
   * Adds a KWE node to the queue unless it is already present.
   */
  public function enqueueNode(NodeInterface $node): void {
    if ($this->synchronizing) {
      return;
    }

    $node_id = (int) $node->id();
    if (!in_array($node_id, $this->getQueuedNodeIds(), TRUE)) {
      $this->getQueue()->createItem($node_id);
    }
  }

  /**
   * Processes all queue items that are currently available.
   */
  public function processQueue(): void {
    $queue = $this->getQueue();
    $items = [];
    while ($item = $queue->claimItem(600)) {
      $items[] = $item;
    }

    foreach ($items as $item) {
      try {
        if ($this->processItem((int) $item->data)) {
          $queue->deleteItem($item);
        }
        else {
          $queue->releaseItem($item);
        }
      }
      catch (\Throwable $exception) {
        $queue->releaseItem($item);
        $this->logger->error('Could not update KWE node @id: @message', [
          '@id' => $item->data,
          '@message' => $exception->getMessage(),
        ]);
      }
    }
  }

  /**
   * Updates one node. Returns FALSE when a lock requires a later retry.
   */
  public function processItem(int $node_id): bool {
    $node = $this->entityTypeManager->getStorage('node')->load($node_id);
    if (!$node instanceof NodeInterface) {
      return TRUE;
    }

    $ddb_uri = $node->hasField('field_ddburi') ? $node->get('field_ddburi')->value : NULL;
    if (!is_string($ddb_uri) || !str_starts_with($ddb_uri, self::ORGANIZATION_URL)) {
      $this->logger->warning('Node @id has no valid DDB organization URI.', ['@id' => $node_id]);
      return TRUE;
    }

    if ($this->contentLock->fetchLock($node)) {
      $this->logger->warning('Node @id is locked and will be retried later.', ['@id' => $node_id]);
      return FALSE;
    }

    $api_url = self::API_URL . substr($ddb_uri, strlen(self::ORGANIZATION_URL)) . '/source/record';
    $organization = $this->loadOrganization($node_id, $api_url);
    if ($organization === NULL) {
      return TRUE;
    }

    $this->updateNode($node, $organization, $api_url);
    return TRUE;
  }

  /**
   * Returns all node IDs currently present in the queue.
   *
   * Claimed items are released immediately so their original state is kept.
   *
   * @return int[]
   *   Queued node IDs.
   */
  private function getQueuedNodeIds(): array {
    $queue = $this->getQueue();
    $items = [];
    $node_ids = [];
    while ($item = $queue->claimItem(60)) {
      $items[] = $item;
      $node_ids[] = (int) $item->data;
    }

    foreach ($items as $item) {
      $queue->releaseItem($item);
    }

    return array_values(array_unique($node_ids));
  }

  /**
   * Returns the configured KWE queue.
   */
  private function getQueue(): QueueInterface {
    return $this->queueFactory->get(self::QUEUE_NAME);
  }

  /**
   * Downloads and parses organization data from the trusted DDB API endpoint.
   *
   * @return array<string, string>|null
   *   Parsed values, or NULL when the response cannot be processed.
   */
  private function loadOrganization(int $node_id, string $url): ?array {
    try {
      $response = $this->httpClient->request('GET', $url, [
        'connect_timeout' => 3.0,
        'headers' => ['Accept' => 'application/xml'],
        'timeout' => 5.0,
      ]);
      $contents = (string) $response->getBody();

      $previous = libxml_use_internal_errors(TRUE);
      try {
        $xml = simplexml_load_string($contents, \SimpleXMLElement::class, LIBXML_NONET);
      }
      finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
      }

      if (!$xml instanceof \SimpleXMLElement) {
        throw new \RuntimeException('The API response is not valid XML.');
      }

      return $this->parseOrganization($xml);
    }
    catch (GuzzleException | \RuntimeException $exception) {
      $this->logger->error('Could not load DDB data for node @id from @url: @message', [
        '@id' => $node_id,
        '@url' => $url,
        '@message' => $exception->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Applies changed API values and creates a revision when necessary.
   *
   * @param array<string, string> $values
   *   Parsed organization data.
   */
  private function updateNode(NodeInterface $node, array $values, string $url): void {
    $changed = FALSE;
    $field_map = [
      'title' => 'displayName',
      'field_ddb_id' => 'id',
      'field_isil' => 'pid',
      'field_kurzname' => 'abbreviation',
      'field_plz' => 'postalCode',
      'field_stadt' => 'city',
      'field_telefonnummer' => 'telephone',
      'field_email' => 'email',
    ];

    foreach ($field_map as $field_name => $value_key) {
      if (isset($values[$value_key]) && $node->hasField($field_name)
        && $node->get($field_name)->value !== $values[$value_key]) {
        $node->set($field_name, $values[$value_key]);
        $changed = TRUE;
      }
    }

    if (isset($values['url']) && $node->hasField('field_url')
      && $node->get('field_url')->uri !== $values['url']) {
      $node->set('field_url', ['uri' => $values['url']]);
      $changed = TRUE;
    }

    if (isset($values['street']) && $node->hasField('field_strasse')) {
      $street = trim($values['street'] . ' ' . ($values['houseIdentifier'] ?? ''));
      if ($node->get('field_strasse')->value !== $street) {
        $node->set('field_strasse', $street);
        $changed = TRUE;
      }
    }

    $references = [
      'field_bundesland' => ['bundesland', 'state'],
      'field_land' => ['land', 'country'],
      'field_sparte' => ['kultursparte_kwe', 'sector'],
      'field_untersparte' => ['kulturuntersparte_kwe', 'subsector'],
    ];
    foreach ($references as $field_name => [$vocabulary, $value_key]) {
      if (!isset($values[$value_key]) || !$node->hasField($field_name)) {
        continue;
      }
      $term_id = $this->findTermId($vocabulary, $values[$value_key]);
      if ($term_id === NULL) {
        $this->logger->warning('No taxonomy term in @vocabulary matches API value @value for node @id; @field remains unchanged.', [
          '@vocabulary' => $vocabulary,
          '@value' => $values[$value_key],
          '@id' => $node->id(),
          '@field' => $field_name,
        ]);
      }
      elseif ((int) $node->get($field_name)->target_id !== $term_id) {
        $node->set($field_name, ['target_id' => $term_id]);
        $changed = TRUE;
      }
    }

    if (!$changed) {
      $this->logger->info('Node @id is already up to date.', ['@id' => $node->id()]);
      return;
    }

    if ($node->getEntityType()->isRevisionable()) {
      $node->setNewRevision(TRUE);
      $node->setRevisionLogMessage("Automatic update from '$url'.");
      $node->setRevisionCreationTime($this->time->getRequestTime());
      $node->setRevisionUserId((int) $this->currentUser->id());
    }

    $this->synchronizing = TRUE;
    try {
      $node->save();
    }
    finally {
      $this->synchronizing = FALSE;
    }
    $this->logger->info('Node @id was updated from @url.', [
      '@id' => $node->id(),
      '@url' => $url,
    ]);
  }

  /**
   * Finds a taxonomy term by vocabulary and URI.
   */
  private function findTermId(string $vocabulary, string $uri): ?int {
    $ids = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', $vocabulary)
      ->condition('field_uri', $uri)
      ->range(0, 1)
      ->execute();

    $term_id = reset($ids);
    return $term_id === FALSE ? NULL : (int) $term_id;
  }

  /**
   * Extracts the supported organization fields from DDB XML.
   *
   * @return array<string, string>
   *   Parsed values keyed by DDB field name.
   */
  private function parseOrganization(\SimpleXMLElement $xml): array {
    $paths = [
      'id' => '/*[local-name()="organization"]/*[local-name()="id"]',
      'displayName' => '/*[local-name()="organization"]/*[local-name()="displayName"][@lang="deu"]',
      'pid' => '/*[local-name()="organization"]/*[local-name()="pid"]',
      'abbreviation' => '/*[local-name()="organization"]/*[local-name()="abbreviation"][@lang="deu"]',
      'street' => '/*[local-name()="organization"]/*[local-name()="address"]/*[local-name()="street"]',
      'houseIdentifier' => '/*[local-name()="organization"]/*[local-name()="address"]/*[local-name()="houseIdentifier"]',
      'postalCode' => '/*[local-name()="organization"]/*[local-name()="address"]/*[local-name()="postalCode"]',
      'city' => '/*[local-name()="organization"]/*[local-name()="address"]/*[local-name()="city"]/*[local-name()="label"][@lang="deu"]',
      'state' => '/*[local-name()="organization"]/*[local-name()="address"]/*[local-name()="state"]/@uri',
      'country' => '/*[local-name()="organization"]/*[local-name()="address"]/*[local-name()="country"]/@uri',
      'sector' => '/*[local-name()="organization"]/*[local-name()="sector"]',
      'subsector' => '/*[local-name()="organization"]/*[local-name()="subsector"]',
      'telephone' => '/*[local-name()="organization"]/*[local-name()="telephone"]',
      'email' => '/*[local-name()="organization"]/*[local-name()="email"]',
      'url' => '/*[local-name()="organization"]/*[local-name()="url"]',
    ];

    $values = [];
    foreach ($paths as $key => $path) {
      $matches = $xml->xpath($path);
      if ($matches !== FALSE && isset($matches[0])) {
        $values[$key] = trim((string) $matches[0]);
      }
    }
    return $values;
  }

}
