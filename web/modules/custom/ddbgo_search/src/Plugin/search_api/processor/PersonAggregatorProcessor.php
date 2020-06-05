<?php

namespace Drupal\ddbgo_search\Plugin\search_api\processor;

use Drupal;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Plugin\search_api\processor\Property\RenderedItemProperty;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Utility\FieldsHelperInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @SearchApiProcessor(
 *   id = "ddbgo_person_aggregator",
 *   label = @Translation("DDBgo Person Aggregator nodes"),
 *   description = @Translation("DDBgo Aggregators which are linked to Persons, used in Person's search"),
 *   stages = {
 *     "add_properties" = 20,
 *   },
 *   locked = FALSE,
 *   hidden = FALSE,
 * )
 */
class PersonAggregatorProcessor extends ProcessorPluginBase {

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
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var static $processor */
    $processor = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $processor->setEntityTypeManager($container->get('entity_type.manager'));
    $processor->setFieldsHelper($container->get('search_api.fields_helper'));

    return $processor;
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) {
    $properties = [];

    if (!$datasource) {
      $definition = [
        'label' => $this->t('DDBgo Person Aggregator nodes'),
        'description' => $this->t("DDBgo Aggregators which are linked to Persons, used in Person's search"),
        'type' => 'search_api_html',
        'processor_id' => $this->getPluginId(),
        // 'is_list' => TRUE,
      ];
      $properties['ddbgo_person_aggregator'] = new RenderedItemProperty($definition);
    }

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item) {
    $original_entity = $item->getOriginalObject()->getValue();

    if (!($original_entity instanceof Node)) {
      return;
    }

    $nids = Drupal::entityQuery('node')
      ->condition('status', 1)
      ->condition('type', 'aggregator')
      ->condition('field_personen.entity:paragraph.field_person.target_id', $original_entity->id())
      ->sort('title', 'ASC')
      ->execute();

    $nodes = $this->getEntityTypeManager()
      ->getStorage('node')
      ->loadMultiple($nids);

    $fields = $this->getFieldsHelper()
      ->filterForPropertyPath($item->getFields(), NULL, 'ddbgo_person_aggregator');

    foreach ($fields as $field) {
      $html = "";
      $j = 0;
      foreach ($nodes as $node) {
        $html .= $node->toLink()->toString();

        $rolesText = "";
        foreach ($node->get("field_personen")->getValue() as $parId) {
          $par = Paragraph::load($parId["target_id"]);
          $perId = $par->get("field_person")->target_id;
          if ($perId !== $original_entity->id()) {
            continue;
          }
          $rolle = $par->get("field_rolle")->entity;
          if ($rolle !== NULL) {
            $rolesText .= $rolle->getName();
            $rolesText .= ", ";
          }
        }

        if (strlen($rolesText) >= 2) {
          $rolesText = substr($rolesText, 0, strlen($rolesText) - 2);

          if (++$j < count($nodes)) {
            $html .= " (" . $rolesText . "); ";
          }
          else {
            $html .= " (" . $rolesText . ")";
          }
        }
        else {
          if (++$j < count($nodes)) {
            $html .= "; ";
          }
        }
      }
      $field->addValue($html);
    }
  }

  /**
   * Retrieves the entity type manager.
   *
   * @return \Drupal\Core\Entity\EntityTypeManagerInterface
   *   The entity type manager.
   */
  public function getEntityTypeManager() {
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
  public function setEntityTypeManager(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
    return $this;
  }

  /**
   * Retrieves the fields helper.
   *
   * @return \Drupal\search_api\Utility\FieldsHelperInterface
   *   The fields helper.
   */
  public function getFieldsHelper() {
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
  public function setFieldsHelper(FieldsHelperInterface $fields_helper) {
    $this->fieldsHelper = $fields_helper;
    return $this;
  }

}
