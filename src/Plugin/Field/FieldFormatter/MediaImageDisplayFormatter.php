<?php

namespace Drupal\media_image_display_entity_view\Plugin\Field\FieldFormatter;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
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
   * The image style entity storage.
   *
   * @var \Drupal\image\ImageStyleStorageInterface
   */
  protected $imageStyleStorage;

  /**
   * Entity view display.
   *
   * @var \Drupal\Core\Entity\Display\EntityViewDisplayInterface
   */
  protected $viewDisplay;

  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition,
                              array $settings, $label, $view_mode, array $third_party_settings,
                              LoggerChannelFactoryInterface $logger_factory, EntityTypeManagerInterface $entity_type_manager,
                              EntityDisplayRepositoryInterface $entity_display_repository,
                              AccountInterface $account, ImageStyleStorageInterface $imageStyleStorage) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings, $logger_factory, $entity_type_manager, $entity_display_repository);
    $this->currentUser = $account;
    $this->imageStyleStorage = $imageStyleStorage;
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
      $container->get('entity_type.manager')->getStorage('image_style')
    );
  }


  public static function defaultSettings() {
    return [
        'image_style' => '',
      ] + parent::defaultSettings();
  }

  public function settingsForm(array $form, FormStateInterface $form_state) {
    $element = parent::settingsForm($form, $form_state);

    $image_styles = image_style_options(FALSE);
    $description_link = Link::fromTextAndUrl(
      $this->t('Configure Image Styles'),
      Url::fromRoute('entity.image_style.collection')
    );
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

    return $element;
  }

  public function settingsSummary() {
    $summary = parent::settingsSummary();

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


    return $summary;
  }

  public function viewElements(FieldItemListInterface $items, $langcode) {
    /** @var \Drupal\Core\Entity\FieldableEntityInterface $entity */
    $entities = $this->getEntitiesToView($items, $langcode);

    $build = [];
    foreach ($entities as $delta => $entity) {
      $build[$delta] = $this->getViewDisplay($entity->bundle())->build($entity);
    }
    return $build;
  }

  protected function getViewDisplay($bundle_id) {
    if (!isset($this->viewDisplay[$bundle_id])) {
      /* todo : Make this field dynamic
      $field_name = $this->getSetting('field_name'); */
      $field_name = 'field_media_image';
      $entity_type_id = $this->fieldDefinition->getSetting('target_type');
      if (($view_mode = $this->getSetting('view_mode')) && $view_display = EntityViewDisplay::load($entity_type_id . '.' . $bundle_id . '.' . $view_mode)) {
        /** @var \Drupal\Core\Entity\Display\EntityViewDisplayInterface $view_display */
        $components = $view_display->getComponents();
        foreach ($components as $component_name => $component) {
          if ($component_name == $field_name) {
            $component['settings']['image_style'] = $this->getSetting('image_style');
            $view_display->setComponent($field_name, $component);
          }
        }

        $this->viewDisplay[$bundle_id] = $view_display;
      }
    }
    return $this->viewDisplay[$bundle_id];
  }


}
