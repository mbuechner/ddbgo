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
 *   id = "ddbgo_person_bestand",
 *   label = @Translation("DDBgo Person Bestand nodes"),
 *   description = @Translation("DDBgo Bestand which are linked to Persons, used in Person's search"),
 *   stages = {
 *     "add_properties" = 20,
 *   },
 *   locked = FALSE,
 *   hidden = FALSE,
 * )
 */
class PersonBestandProcessor extends ProcessorPluginBase {

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
  public function getPropertyDefinitions(?DatasourceInterface $datasource = NULL) {
    $properties = [];

    if (!$datasource) {
      $definition = [
        'label' => $this->t('DDBgo Person Bestand nodes'),
        'description' => $this->t("DDBgo Bestand which are linked to Persons, used in Person's search"),
        'type' => 'search_api_html',
        'processor_id' => $this->getPluginId(),
        // 'is_list' => TRUE,
      ];
      $properties['ddbgo_person_bestand'] = new RenderedItemProperty($definition);
    }

    return $properties;
  }

  /**
   * {@inheritdoc}
   *
   * Enriches the Search API item with the rendered property
   * "ddbgo_person_bestand" for person nodes.
   *
   * Input:
   * - Search API item whose original object is expected to be a person node.
   *
   * Output (single HTML string per target field):
   * - "<a ...>Bestand A</a> (Rolle 1, Rolle 2); <a ...>Bestand B</a>"
   *
   * Notes:
   * - Keeps node order by title ASC from the query.
   * - Uses bulk loads (nodes, paragraphs, taxonomy terms) to avoid per-row
   *   entity loading in nested loops.
   */
  public function addFieldValues(ItemInterface $item) {
    // This processor only applies when the indexed item is a person node.
    $original_entity = $item->getOriginalObject()->getValue();

    if (!($original_entity instanceof Node)) {
      return;
    }

    // Find all published bestand nodes linked to the current person.
    $nids = Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('type', 'bestand')
      ->condition('field_personen.entity:paragraph.field_person.target_id', $original_entity->id())
      ->sort('title', 'ASC')
      ->execute();

    $nodes = $this->getEntityTypeManager()
      ->getStorage('node')
      ->loadMultiple($nids);

    // Use bulk loading to avoid repeated entity loads in later loops.
    $person_id = (int) $original_entity->id();
    $node_paragraph_ids = [];
    $paragraph_ids = [];

    foreach ($nodes as $node) {
      if (!($node instanceof Node)) {
        continue;
      }

      // Collect all paragraph references per node and globally.
      $ids = array_column($node->get('field_personen')->getValue(), 'target_id');
      $ids = array_values(array_filter($ids));
      $node_paragraph_ids[$node->id()] = $ids;
      $paragraph_ids = array_merge($paragraph_ids, $ids);
    }

    // Load all referenced paragraphs once and map them in memory.
    $paragraph_ids = array_values(array_unique($paragraph_ids));
    $paragraphs = [];
    if ($paragraph_ids) {
      $paragraphs = $this->getEntityTypeManager()
        ->getStorage('paragraph')
        ->loadMultiple($paragraph_ids);
    }

    // Resolve role term IDs for the current person per node.
    $node_role_ids = [];
    $role_ids = [];
    foreach ($node_paragraph_ids as $node_id => $ids) {
      $node_role_ids[$node_id] = [];
      foreach ($ids as $id) {
        $paragraph = $paragraphs[$id] ?? NULL;
        if (!($paragraph instanceof Paragraph)) {
          continue;
        }

        // Keep only paragraph rows that point to the current person.
        if ((int) $paragraph->get('field_person')->target_id !== $person_id) {
          continue;
        }

        $role_id = (int) $paragraph->get('field_rolle')->target_id;
        if ($role_id <= 0) {
          continue;
        }

        $node_role_ids[$node_id][] = $role_id;
        $role_ids[] = $role_id;
      }
    }

    // Load all role terms once; labels are resolved during output rendering.
    $role_ids = array_values(array_unique($role_ids));
    $roles = [];
    if ($role_ids) {
      $roles = $this->getEntityTypeManager()
        ->getStorage('taxonomy_term')
        ->loadMultiple($role_ids);
    }

    $fields = $this->getFieldsHelper()
      ->filterForPropertyPath($item->getFields(), NULL, 'ddbgo_person_bestand');

    // Build one HTML string per field in the configured property path.
    foreach ($fields as $field) {
      $html = "";
      $j = 0;
      $node_count = count($nodes);
      foreach ($nodes as $node) {
        if (!($node instanceof Node)) {
          continue;
        }
        /** @var \Drupal\node\Entity\Node $node */

        $html .= $node->toLink()->toString();

        // Build role labels for this node from preloaded role IDs.
        $role_names = [];
        foreach ($node_role_ids[$node->id()] ?? [] as $role_id) {
          if (isset($roles[$role_id])) {
            $role_names[] = $roles[$role_id]->label();
          }
        }

        // Preserve original output format: "Node link (Role1, Role2); ...".
        if ($role_names) {
          $html .= " (" . implode(', ', array_values(array_unique($role_names))) . ")";
        }

        if (++$j < $node_count) {
          $html .= "; ";
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
