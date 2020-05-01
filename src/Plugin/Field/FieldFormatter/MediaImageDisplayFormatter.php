<?php

namespace Drupal\media_image_display_entity_view\Plugin\Field\FieldFormatter;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceEntityFormatter;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\image\ImageStyleStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'media_thumbnail' formatter.
 *
 * @FieldFormatter(
 *   id = "media_image_display",
 *   label = @Translation("Media Image Display"),
 *   field_types = {
 *     "entity_reference"
 *   }
 * )
 */

class MediaImageDisplayFormatter extends EntityReferenceEntityFormatter implements ContainerFactoryPluginInterface{
  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Entity view display.
   *
   * @var \Drupal\Core\Entity\Display\EntityViewDisplayInterface
   */
  protected $viewDisplay;


  /**
   * @var EntityViewDisplay
   */
  protected $entityViewDisplayStorage;

  /**
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition,
                              array $settings, $label, $view_mode, array $third_party_settings,
                              LoggerChannelFactoryInterface $logger_factory, EntityTypeManagerInterface $entity_type_manager,
                              EntityDisplayRepositoryInterface $entity_display_repository,
                              AccountInterface $account, EntityTypeManagerInterface $entityTypeManager,
                              EntityFieldManagerInterface $entityFieldManager) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings, $logger_factory, $entity_type_manager, $entity_display_repository);
    $this->currentUser = $account;
    $this->entityViewDisplayStorage = $entityTypeManager->getStorage('entity_view_display');
    $this->entityFieldManager = $entityFieldManager;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('logger.factory'),
      $container->get('entity_type.manager'),
      $container->get('entity_display.repository'),
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager')
    );
  }


  public static function defaultSettings() {
    return [
        'image_style' => '',
        'image_field' => '',
        'link_source' => 'nothing',
        'media_link_field' => '',
      ] + parent::defaultSettings();
  }

  public function settingsForm(array $form, FormStateInterface $form_state) {

    $element = parent::settingsForm($form, $form_state);

    $mediaFieldSettings = $this->getFieldSettings();
    $entity_type = $mediaFieldSettings['target_type'];
    $bundle = array_shift($mediaFieldSettings['handler_settings']['target_bundles']);
    $mediaFieldsList = $this->entityFieldManager->getFieldDefinitions($entity_type, $bundle);


    $image_styles = image_style_options(FALSE);
    $description_link = Link::fromTextAndUrl(
      $this->t('Configure Image Styles'),
      Url::fromRoute('entity.image_style.collection')
    );

    $element['image_field'] = [
      '#title' => t('Image Field'),
      '#type' => 'select',
      '#default_value' => $this->getSetting('image_field'),
      '#options' => $this->getFieldList($mediaFieldsList, 'image'),
      '#required' => TRUE,
    ];

    $element['image_style'] = [
      '#title' => t('Image style'),
      '#type' => 'select',
      '#default_value' => $this->getSetting('image_style'),
      '#empty_option' => t('None (original image)'),
      '#options' => $image_styles,
      '#description' => $description_link->toRenderable() + [
          '#access' => $this->currentUser->hasPermission('administer image styles'),
        ],
    ];

    $element['link_source'] = [
      '#title' => t('Link source'),
      '#type' => 'radios',
      '#required' => TRUE,
      '#default_value' => $this->getSetting('link_source'),
      '#options' => [
        'nothing' => $this->t('Nothing'),
//        'content' => $this->t('Content'),
        'media' => $this->t('Media')],
    ];

    $element['media_link_field'] = [
      '#title' => t('Media Link field'),
      '#type' => 'select',
      '#default_value' => $this->getSetting('media_link_field'),
      '#options' => $this->getFieldList($mediaFieldsList, 'link'),
      '#states' => [
        'visible' => [
          ':input[name="fields['.$this->fieldDefinition->getName().'][settings_edit_form][settings][link_source]"]' => array('value' => 'media')
        ],
        'required' => [
          ':input[name="fields['.$this->fieldDefinition->getName().'][settings_edit_form][settings][link_source]"]' => array('value' => 'media')
        ]
      ],
    ];

    return $element;
  }

  public function settingsSummary() {
    $summary = [];
    $fieldSettings = $this->getFieldSettings();
    if (count($fieldSettings['handler_settings']['target_bundles']) > 1) {
      $summary[] = $this->t('WARNING - You have selected two media type in this field.');
      $summary[] = $this->t('This formatter may have difficult to work.');
    }

    $summary = array_merge($summary, parent::settingsSummary());

    $summary[] = $this->t('Image Field : @image_field', ['@image_field' => $this->getSetting('image_field')]);

    $image_styles = image_style_options(FALSE);
    // Unset possible 'No defined styles' option.
    unset($image_styles['']);
    // Styles could be lost because of enabled/disabled modules that defines
    // their styles in code.
    $image_style_setting = $this->getSetting('image_style');
    if (isset($image_styles[$image_style_setting])) {
      $summary[] = $this->t('Image style: @style', ['@style' => $image_styles[$image_style_setting]]);
    }
    else {
      $summary[] = $this->t('Original image');
    }

    $summary[] = $this->t('Link Source : @link_source', ['@link_source' => $this->getSetting('link_source')]);

    if (!empty($this->getSetting('media_link_field') && $this->getSetting('link_source') == 'media')) {
      $summary[] = $this->t('Media Link Field : @media_link_field', ['@media_link_field' => $this->getSetting('media_link_field')]);
    }

    return $summary;
  }

  public function viewElements(FieldItemListInterface $items, $langcode) {
    /** @var \Drupal\Core\Entity\FieldableEntityInterface $entity */
    $entities = $this->getEntitiesToView($items, $langcode);

    $build = [];
    $media_link_field_name = $this->getSetting('media_link_field');

    foreach ($entities as $delta => $entity) {
      $link = '';
      if(
        !empty($media_link_field_name) &&
        $this->getSetting('link_source') == 'media' &&
        $entity->hasField($media_link_field_name) &&
        !$entity->get($media_link_field_name)->isEmpty()
      ){
        $link = Url::fromUri($entity->get($media_link_field_name)->uri)->toString();
      }
      $buildEntity = $this->getViewDisplay($entity->bundle())->build($entity);
      $build[$delta] = ['#theme' => 'media_image_display', '#link' => $link, '#media' => $buildEntity];
    }

    return $build;
  }

  protected function getViewDisplay($bundle_id) {
    if (!isset($this->viewDisplay[$bundle_id])) {
      $image_field_name = $this->getSetting('image_field');
      $media_link_field_name = $this->getSetting('media_link_field');
      $entity_type_id = $this->fieldDefinition->getSetting('target_type');

      if (($view_mode = $this->getSetting('view_mode')) &&
        $view_display = $this->entityViewDisplayStorage->load($entity_type_id . '.' . $bundle_id . '.' . $view_mode)) {
        /** @var \Drupal\Core\Entity\Display\EntityViewDisplayInterface $view_display */
        $components = $view_display->getComponents();
        if(!empty($components[$image_field_name])) {
          $components[$image_field_name]['settings']['image_style'] = $this->getSetting('image_style');
          $view_display->setComponent($image_field_name, $components[$image_field_name]);
        }
        if(!empty($components[$media_link_field_name])) {
          $view_display->removeComponent($media_link_field_name);
        }

        $this->viewDisplay[$bundle_id] = $view_display;
      }
    }
    return $this->viewDisplay[$bundle_id];
  }


  protected function getFieldList(array $fieldDefinitions, string $type) {
    $fields = [];
    foreach ($fieldDefinitions as $fieldDefinition) {
      if ($fieldDefinition instanceof FieldDefinitionInterface && $fieldDefinition->getType() == $type && strpos($fieldDefinition->getName(), 'field_') !== FALSE) {
        $fields[$fieldDefinition->getName()] = $fieldDefinition->getLabel() . " (" . $fieldDefinition->getName() . ")";
      }
    }
    return $fields;
  }
}
