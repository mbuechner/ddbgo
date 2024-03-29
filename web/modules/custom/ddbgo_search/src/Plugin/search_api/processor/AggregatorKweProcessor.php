<?php

namespace Drupal\ddbgo_search\Plugin\search_api\processor;

use Drupal;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ddbgo_search\Plugin\search_api\processor\Property\EntityProcessorProperty;
use Drupal\node\Entity\Node;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Utility\FieldsHelperInterface;
use Drupal\search_api\Utility\Utility;
use Symfony\Component\DependencyInjection\ContainerInterface;


/**
 * Adds the user's soul mate node for indexing.
 *
 * @SearchApiProcessor(
 *   id = "ddbgo_aggregator_kwe",
 *   label = @Translation("DDBgo Aggregator KWE nodes"),
 *   description = @Translation("DDBgo KWEs which are linked to Aggregator, used in Aggregator's search"),
 *   stages = {
 *     "add_properties" = 20,
 *   },
 *   locked = FALSE,
 *   hidden = FALSE,
 * )
 */
class AggregatorKweProcessor extends ProcessorPluginBase
{

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|null
   */
  protected $entityTypeManager;

  /**
   * The fields helper.
   *
   * @var \Drupal\search_api\Utility\FieldsHelperInterface|null
   */
  protected $fieldsHelper;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
  {
    /** @var static $processor */
    $processor = parent::create($container, $configuration, $plugin_id, $plugin_definition);

    $processor->setEntityTypeManager($container->get('entity_type.manager'));
    $processor->setFieldsHelper($container->get('search_api.fields_helper'));

    return $processor;
  }

  /**
   * Retrieves the entity type manager.
   *
   * @return \Drupal\Core\Entity\EntityTypeManagerInterface
   *   The entity type manager.
   */
  public function getEntityTypeManager()
  {
    return $this->entityTypeManager ?: Drupal::entityTypeManager();
  }

  /**
   * Sets the entity type manager.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The new entity type manager.
   *
   * @return $this
   */
  public function setEntityTypeManager(EntityTypeManagerInterface $entity_type_manager)
  {
    $this->entityTypeManager = $entity_type_manager;
    return $this;
  }

  /**
   * Retrieves the fields helper.
   *
   * @return \Drupal\search_api\Utility\FieldsHelperInterface
   *   The fields helper.
   */
  public function getFieldsHelper()
  {
    return $this->fieldsHelper ?: Drupal::service('search_api.fields_helper');
  }

  /**
   * Sets the fields helper.
   *
   * @param \Drupal\search_api\Utility\FieldsHelperInterface $fields_helper
   *   The new fields helper.
   *
   * @return $this
   */
  public function setFieldsHelper(FieldsHelperInterface $fields_helper)
  {
    $this->fieldsHelper = $fields_helper;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public static function supportsIndex(IndexInterface $index)
  {
    foreach ($index->getDatasources() as $datasource) {
      if ($datasource->getEntityTypeId() === 'node') {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL)
  {
    $properties = [];

    if ($datasource && $datasource->getEntityTypeId() === 'node') {
      $definition = [
        'label' => $this->t('DDBgo Aggregator KWE nodes'),
        'description' => $this->t("DDBgo KWEs which are linked to Aggregator, used in Aggregator's search"),
        'type' => 'entity:node',
        'processor_id' => $this->getPluginId(),
        'is_list' => TRUE,
      ];
      $properties['ddbgo_aggregator_kwe'] = new EntityProcessorProperty($definition);
      $properties['ddbgo_aggregator_kwe']->setEntityTypeId('node');
    }

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item)
  {
    $original_entity = $item->getOriginalObject()->getValue();

    if (!($original_entity instanceof Node)) {
      return;
    }

    /** @var \Drupal\search_api\Item\FieldInterface[][] $to_extract */
    $to_extract = [];
    foreach ($item->getFields() as $field) {
      $datasource = $field->getDatasource();
      $property_path = $field->getPropertyPath();
      list($direct, $nested) = Utility::splitPropertyPath($property_path, FALSE);
      if ($datasource
        && $datasource->getEntityTypeId() === 'node'
        && $direct === 'ddbgo_aggregator_kwe') {
        $to_extract[$nested][] = $field;
      }
    }

    if (!$to_extract) {
      return;
    }

    $query = Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('type', 'bestand')
      ->condition('field_aggregator.target_id', $original_entity->id())
      ->exists('field_kwe')
      ->sort('field_kwe.entity.title', 'ASC')
      ->execute();

    $nids = [];
    foreach (\Drupal::entityTypeManager()->getStorage('node')->loadMultiple($query) as $e) {
      foreach ($e->get('field_kwe')->referencedEntities() as $re) {
        array_push($nids, $re->id());
      }
    }

    $nodes = $this->getEntityTypeManager()
      ->getStorage('node')
      ->loadMultiple($nids);

    if (!isset($nodes) || count($nodes) < 1) {
      return;
    }

    if (isset($to_extract[''])) {
      foreach ($to_extract[''] as $field) {
        $field->setValues($nodes);
      }
      unset($to_extract['']);
    }
    foreach ($nodes as $node) {
      $this->getFieldsHelper()->extractFields($node->getTypedData(), $to_extract, $item->getLanguage());
    }
  }
}
