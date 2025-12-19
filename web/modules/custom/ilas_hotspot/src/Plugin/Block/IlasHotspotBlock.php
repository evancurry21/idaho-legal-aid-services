<?php

namespace Drupal\ilas_hotspot\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\file\FileInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Provides an 'ILAS Hotspot' Block.
 *
 * @Block(
 *   id = "ilas_hotspot_block",
 *   admin_label = @Translation("ILAS Hotspot"),
 *   category = @Translation("ILAS"),
 * )
 */
class IlasHotspotBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new IlasHotspotBlock.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $config = $this->configFactory->get('ilas_hotspot.settings');
    
    // Get hotspot data from configuration
    $hotspots = $config->get('hotspot_data');
    
    // If no configuration exists, use defaults
    if (empty($hotspots)) {
      $hotspots = $this->getDefaultHotspots();
    }
    
    // Get image path from configuration
    $image_path = $config->get('hotspot_image') ?: '/themes/custom/b5subtheme/images/icons/impact-graphic-2.svg';

    // Get annual report PDF URL from media entity
    $annual_report_path = $this->getAnnualReportUrl($config->get('annual_report_media'));
    
    // Create the hotspot render array
    $hotspot_render = ilas_hotspot_create($image_path, $hotspots, TRUE);
    
    // Create container with two columns
    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['ilas-impact-wrapper'],
      ],
    ];
    
    // Add header for accessibility
    $build['header'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => t('BY THE NUMBERS'),
      '#attributes' => [
        'class' => ['impact-header'],
      ],
    ];
    
    // Add a table for layout
    $build['table'] = [
      '#type' => 'html_tag',
      '#tag' => 'table',
      '#attributes' => [
        'class' => ['ilas-impact-table'],
        'role' => 'presentation',
      ],
    ];
    
    $build['table']['row'] = [
      '#type' => 'html_tag',
      '#tag' => 'tr',
    ];
    
    // Hotspot cell
    $build['table']['row']['hotspot_cell'] = [
      '#type' => 'html_tag',
      '#tag' => 'td',
      '#attributes' => [
        'class' => ['hotspot-cell'],
      ],
      'content' => $hotspot_render,
    ];
    
    // Annual report cell - only render if a media entity is selected
    if (!empty($annual_report_path)) {
      $build['table']['row']['annual_report_cell'] = [
        '#type' => 'html_tag',
        '#tag' => 'td',
        '#attributes' => [
          'class' => ['annual-report-cell'],
        ],
        'content' => [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['annual-report-content', 'text-center'],
          ],
          'image' => [
            '#markup' => '<a href="' . $annual_report_path . '" target="_blank" rel="noopener noreferrer">
                            <img src="/themes/custom/b5subtheme/images/Front Cover.svg"
                                 alt="' . t('ILAS Annual Report Cover') . '"
                                 class="annual-report-cover img-fluid mb-3">
                          </a>',
          ],
          'button' => [
            '#type' => 'html_tag',
            '#tag' => 'a',
            '#attributes' => [
              'href' => $annual_report_path,
              'class' => ['btn', 'btn-primary'],
              'target' => '_blank',
              'rel' => 'noopener noreferrer',
            ],
            '#value' => t('VIEW REPORT'),
          ],
        ],
      ];
    }
    
    // Add cache tags for the media entity and configuration
    $cache_tags = ['config:ilas_hotspot.settings'];
    $media_id = $config->get('annual_report_media');
    if (!empty($media_id)) {
      $cache_tags[] = 'media:' . $media_id;
    }

    $build['#cache'] = [
      'tags' => $cache_tags,
      'contexts' => ['url'],
    ];
    
    $build['#attached']['library'][] = 'ilas_hotspot/hotspot';
    
    return $build;
  }

  /**
   * Get the URL for the annual report PDF from a media entity.
   *
   * @param string|null $media_id
   *   The media entity ID.
   *
   * @return string
   *   The URL to the annual report PDF.
   */
  protected function getAnnualReportUrl(?string $media_id): string {
    if (empty($media_id)) {
      return '';
    }

    try {
      /** @var \Drupal\media\MediaInterface|null $media */
      $media = $this->entityTypeManager->getStorage('media')->load($media_id);
      if ($media && $media->hasField('field_media_document')) {
        $file_field = $media->get('field_media_document');
        if (!$file_field->isEmpty()) {
          /** @var \Drupal\file\FileInterface $file */
          $file = $file_field->entity;
          if ($file instanceof FileInterface) {
            /** @var \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator */
            $file_url_generator = \Drupal::service('file_url_generator');
            return $file_url_generator->generateAbsoluteString($file->getFileUri());
          }
        }
      }
    }
    catch (\Exception $e) {
      // Log the error and return empty string.
      \Drupal::logger('ilas_hotspot')->error('Failed to load annual report media: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return '';
  }

  /**
   * Get default hotspot configuration.
   */
  protected function getDefaultHotspots() {
    return [
      [
        'title' => 'Housing',
        'content' => 'Idaho Legal Aid helped <strong>1,505 clients with housing needs</strong>, preventing evictions and improving housing conditions for vulnerable Idahoans throughout the state.',
        'category' => 'housing',
        'icon' => '/themes/custom/b5subtheme/images/icons/house-icon.svg',
        'placement' => 'top',
      ],
      [
        'title' => 'Health',
        'content' => 'ILAS <strong>helped clients in 268 cases</strong> by advocating for access to necessary and lifesaving health care coverage and services.',
        'category' => 'health',
        'icon' => '/themes/custom/b5subtheme/images/icons/health-icon.svg',
        'placement' => 'right',
      ],
      [
        'title' => 'Consumer Rights',
        'content' => 'ILAS helped over 267 clients fight back against unlawful debt collection and predatory lending.',
        'category' => 'consumer-rights',
        'icon' => '/themes/custom/b5subtheme/images/icons/consumericon.svg',
        'placement' => 'left',
      ],
      [
        'title' => 'Individual Rights',
        'content' => '<strong>In over 174 cases</strong>, Idaho Legal Aid Services provided assistance with wills, advance directives, employment rights, expungement, and other individual rights matters.',
        'category' => 'individual-rights',
        'icon' => '/themes/custom/b5subtheme/images/icons/individual-rights-icon.svg',
        'placement' => 'bottom',
      ],
      [
        'title' => 'Older Adults',
        'content' => 'Our attorneys helped clients secure essential public benefits including SNAP, Medicaid, Social Security, and unemployment benefits to ensure families could meet their basic needs.',
        'category' => 'older-adults',
        'icon' => '/themes/custom/b5subtheme/images/icons/older-adults-icon.svg',
        'placement' => 'left',
      ],
      [
        'title' => 'Family',
        'content' => 'ILAS provided critical legal assistance to survivors of domestic violence, securing protection orders and helping families navigate difficult transitions. Over 1,686 clients were served in 2024.',
        'category' => 'family',
        'icon' => '/themes/custom/b5subtheme/images/icons/family.svg',
        'placement' => 'right',
      ],
    ];
  }

}