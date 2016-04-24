<?php

/**
 * @file
 * Contains \Drupal\taxonomy_indented_links\Plugin\Block\TaxonomyIndentedLinks.
 */

namespace Drupal\taxonomy_indented_links\Plugin\Block;

//  Basic block plugin requirements.
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;

// Used when injecting services.
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

// Required for the injected TermStorage object.
use Drupal\taxonomy\TermStorageInterface;

// Required to construct the term routes.
use Drupal\Core\Url;

// Massage the array.
use Drupal\Component\Utility\NestedArray;

/**
 * Provides a 'TaxonomyIndentedLinks' block.
 *
 * @Block(
 *  id = "taxonomy_indented_links",
 *  admin_label = @Translation("Taxonomy Indented Links"),
 * )
 */
class TaxonomyIndentedLinks extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Term Storage Controller.
   *
   * TermStorage object to be injected into the plugin.
   * We could have used the ContentEntityStorageInterface, but TermStorageInterface
   * adds methods we need to get term parents and children and to construct the term
   * tree.
   *
   * @var \Drupal\taxonomy\TermStorageInterface
   */
  protected $TermStorage;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, array $plugin_definition, TermStorageInterface $term_storage) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->TermStorage = $term_storage;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity.manager')->getStorage('taxonomy_term')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form['vocabulary_vid'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Vocabulary vid'),
      '#description' => $this->t('Enter the machine name of the vocabulary.'),
      '#default_value' => isset($this->configuration['vocabulary_vid']) ? $this->configuration['vocabulary_vid'] : '',
      '#size' => 15,
    );
    $form['term_tid'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Parent tid'),
      '#description' => $this->t('Optionally enter the tid for the term that should serve as the top level of the desired tree.'),
      '#default_value' => isset($this->configuration['term_tid']) ? $this->configuration['term_tid'] : '',
      '#size' => 15,
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->configuration['vocabulary_vid'] = $form_state->getValue('vocabulary_vid');
    $this->configuration['term_tid'] = intval($form_state->getValue('term_tid'));
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $build = [];
    $items = $this->getIndentedItems($this->configuration['vocabulary_vid'], $this->configuration['term_tid']);
    if (!empty($items)) {
      $build = [
        '#theme' => 'item_list',
        '#items' => $items,
      ];
    }
    return $build;
  }

  /**
   * Return render array of indented taxonomy items.
   *
   * @param $vid: The vocabulary $vid (machine name).
   * @param $tid: Optional term tid to use as the parent for the tree.
   *
   * @return $items: A render array with the requested taxonomy terms.
   */
  public function getIndentedItems($vid, $tid) {

    $items = [];

    // The loadTree method has an option to return loaded entities instead of stdClass
    // objects, but that can exhaust all available memory for large vocabularies, so we
    // won't use it.
    $tree = $this->TermStorage->loadTree($vid, $tid);

    // The $term returned by loadTree() is not an entity, it is a simple stdClass object
    // with some of the most commonly-used term values, so we find values at
    // $term->depth, not $term->get('depth')->value.
    $term = current($tree);

    // If there are no terms, don't do anything.
    if (empty($term)) {
      return $build;
    }
    else {
      $base_depth = $term->depth;
    }

    $c = 0;
    $parent_array = [];

    // Iterate through the tree, setting up item links for each term.
    do {

      $depth = ($term->depth - $base_depth);
      $item = $this->getTermItem($term);

      if (!isset($previous_depth)) {
        $previous_depth = $depth;
      }

      if ($depth > $previous_depth) {
        // Add a new level to the parent array.
        $parent_array[] = $c;
        $c = 0;
      }
      elseif ($depth < $previous_depth) {
        // Move the parent array back up a level.
        $c = array_pop($parent_array);

        // Render the previous group as a sub-list of items.

        // Pull out the whole sub-group that was at the previous level.
        $item_parent = $parent_array;
        $item_parent[] = $c;
        $child_items = NestedArray::getValue($items, $item_parent);

        // Pull out the parent from the children.
        $child_array = [];
        $child_array['#type'] = $child_items['#type'];
        $child_array['#title'] = $child_items['#title'];
        $child_array['#url'] = $child_items['#url'];
        unset($child_items['#type'], $child_items['#title'], $child_items['#url']);

        // Add the children as a sub item list.
        $child_array[0]['#theme'] = 'item_list';
        $child_array[0]['#items'] = $child_items;
        NestedArray::setValue($items, $item_parent, $child_array);

        // Start a new index at this level.
        $c++;
      }
      else {
        // No change in level, just increment the index.
        $c++;
      }

      // Set this item value using the parent array plus the current index.
      $item_parent = $parent_array;
      $item_parent[] = $c;
      NestedArray::setValue($items, $item_parent, $item);

      // Update the previous depth.
      $previous_depth = $depth;

    } while ($term = next($tree));

    // If there was a nested subgroup at the end of the list, render it now.
    $c = array_pop($parent_array);
    $item_parent = $parent_array;
    $item_parent[] = $c;
    $child_items = NestedArray::getValue($items, $item_parent);
    if (!empty($child_items)) {
      $child_array['#type'] = $child_items['#type'];
      $child_array['#title'] = $child_items['#title'];
      $child_array['#url'] = $child_items['#url'];
      $child_array[0]['#theme'] = 'item_list';
      unset($child_items['#type'], $child_items['#title'], $child_items['#url']);
      $child_array[0]['#items'] = $child_items;
      NestedArray::setValue($items, $item_parent, $child_array);
    }

    return $items;
  }

  function getTermItem($term) {
    return [
      '#type' => 'link',
      '#title' => $term->name,
      // The stdClass $term object has no path information, so get it from the
      // taxonomy term route.
      '#url' => Url::fromRoute('entity.taxonomy_term.canonical', ['taxonomy_term' => $term->tid]),
    ];

  }

}
