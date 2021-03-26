<?php

namespace Drupal\media_image_display_entity_view\Plugin\Field\FieldFormatter;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityType;
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
        'content_link_field' => '',
      ] + parent::defaultSettings();
  }

  public function settingsForm(array $form, FormStateInterface $form_state) {

    $element = parent::settingsForm($form, $form_state);

    /**
     * Fetches Form data for type 'media' and 'content'
     *
     * Also gets MediaFieldList
     */
    $settingsAndFields = $this->fetchSettingsAndFields();

    $image_styles = image_style_options(FALSE);
    $description_link = Link::fromTextAndUrl(
      $this->t('Configure Image Styles'),
      Url::fromRoute('entity.image_style.collection')
    );

    $element['image_field'] = [
      '#title' => t('Image Field'),
      '#type' => 'select',
      '#default_value' => $this->getSetting('image_field'),
      '#options' => $this->getFieldList($settingsAndFields['fields']['mediaFieldsList'], 'image'),
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

      ]
    ];


    if(!empty($settingsAndFields)) {
      foreach ($settingsAndFields['settings'] as $fieldKey => $settings){
        if(empty($settings['fieldLinks']))
          continue;
        $element['link_source']['#options'][$settings['fieldType']] = $this->t(Unicode::ucfirst($settings['fieldType']));
        $element[$settings['fieldType'] . '_link_field'] = [
          '#title' => t(Unicode::ucfirst($settings['fieldType']) . ' Link field'),
          '#type' => 'select',
          '#default_value' => $this->getSetting($settings['fieldType'] . '_link_field'),
          '#options' => $settings['fieldLinks'],
          '#states' => [
            'visible' => [
              ':input[name="fields['.$settings['fieldName'].'][settings_edit_form][settings][link_source]"]' => array('value' => $settings['fieldType'])
            ],
            'required' => [
              ':input[name="fields['.$settings['fieldName'].'][settings_edit_form][settings][link_source]"]' => array('value' => $settings['fieldType'])
            ]
          ],
        ];
      }
    }


    if(count($element['link_source']['#options']) == 1){
      $element['link_source']['#access'] = FALSE;
    }

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

    $summary[] = $this->t('Link Source : @link_source', ['@link_source' => $this->getSetting('link_source') ?? $this->t('Nothing') ]);

    if (!empty($this->getSetting('media_link_field') && $this->getSetting('link_source') == 'media')) {
      $summary[] = $this->t('Media Link Field : @media_link_field', ['@media_link_field' => $this->getSetting('media_link_field')]);
    }
    if (!empty($this->getSetting('content_link_field') && $this->getSetting('link_source') == 'content')) {
      $summary[] = $this->t('Content Link Field : @content_link_field', ['@content_link_field' => $this->getSetting('content_link_field')]);
    }

    return $summary;
  }

  public function viewElements(FieldItemListInterface $items, $langcode) {
    /** @var \Drupal\Core\Entity\FieldableEntityInterface $entity */
    $entities = $this->getEntitiesToView($items, $langcode);

    $build = [];
    $media_link_field_name = $this->getSetting('media_link_field');
    $content_link_field_name = $this->getSetting('content_link_field');

    $parent_entity = $items->getEntity();

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

      if(
        !empty($content_link_field_name) &&
        $this->getSetting('link_source') == 'content' &&
        $parent_entity->hasField($content_link_field_name) &&
        !$parent_entity->get($content_link_field_name)->isEmpty()
      ){
        $link = Url::fromUri($parent_entity->get($content_link_field_name)->uri)->toString();
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

  public static function isApplicable(FieldDefinitionInterface $field_definition) {
    $mediaFieldSettings = $field_definition->getSettings();
    $entity_type = $mediaFieldSettings['target_type'];
    if($entity_type != 'media')
      return FALSE;
    $bundle = empty($mediaFieldSettings['handler_settings']['target_bundles']) ? NULL : array_shift($mediaFieldSettings['handler_settings']['target_bundles']);
    $mediaFieldsList = \Drupal::service('entity_field.manager')->getFieldDefinitions($entity_type, $bundle);
    foreach ($mediaFieldsList as $fieldDefinition) {
      if ($fieldDefinition instanceof FieldDefinitionInterface && $fieldDefinition->getType() == 'image' && strpos($fieldDefinition->getName(), 'field_') !== FALSE) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @return array
   */
  protected function fetchSettingsAndFields(): array{
    $entityTypeBundles = [];
    $settingsAndFields = [];
    $fieldName = $this->fieldDefinition->getName();
    $mediaFieldSettings = $this->getFieldSettings();

    $entityTypeBundles[$mediaFieldSettings['target_type']] = [
      'entityType' => $mediaFieldSettings['target_type'],
      'bundle' => array_shift($mediaFieldSettings['handler_settings']['target_bundles'])
      ];

    $entityTypeBundles[$this->fieldDefinition->getTargetEntityTypeId()] = [
      'entityType' => $this->fieldDefinition->getTargetEntityTypeId() ,
      'bundle' => $this->fieldDefinition->getTargetBundle()
    ];

    foreach ($entityTypeBundles as $key => $entityTypeBundle) {
      $fieldsList = $this->entityFieldManager->getFieldDefinitions($entityTypeBundle['entityType'], $entityTypeBundle['bundle']);
      $fieldLink = $this->getFieldList($fieldsList, 'link');
      $settingsAndFields['settings'][$key] = [
        'fieldLinks' => $fieldLink,
        'fieldName' => $fieldName,
        'fieldType' => $key
      ];

    }

    $settingsAndFields['fields']['mediaFieldsList'] = $this->entityFieldManager->getFieldDefinitions($entityTypeBundles['media']['entityType'], $entityTypeBundles['media']['bundle']);

    return $settingsAndFields;
  }
}
