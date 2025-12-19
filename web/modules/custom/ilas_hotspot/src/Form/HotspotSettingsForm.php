<?php

namespace Drupal\ilas_hotspot\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure ILAS Hotspot settings.
 */
class HotspotSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ilas_hotspot.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ilas_hotspot_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ilas_hotspot.settings');

    $form['hotspot_image'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Hotspot Base Image'),
      '#description' => $this->t('Path to the base image for hotspots. Use a relative path from the web root.'),
      '#default_value' => $config->get('hotspot_image') ?: '/themes/custom/b5subtheme/images/icons/impact-graphic-2.svg',
      '#required' => TRUE,
    ];

    $form['annual_report_media'] = [
      '#type' => 'media_library',
      '#allowed_bundles' => ['document'],
      '#title' => $this->t('Annual Report PDF'),
      '#description' => $this->t('Select the annual report PDF from the media library.'),
      '#default_value' => $config->get('annual_report_media'),
      '#cardinality' => 1,
    ];

    $form['hotspot_data'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Hotspot Configuration'),
      '#description' => $this->t('JSON array of hotspot configurations. Each hotspot should have: title, content, category, icon, and placement.'),
      '#default_value' => json_encode($config->get('hotspot_data') ?: $this->getDefaultHotspots(), JSON_PRETTY_PRINT),
      '#rows' => 20,
      '#required' => TRUE,
    ];

    $form['enable_analytics'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable analytics tracking'),
      '#description' => $this->t('Track hotspot interactions for analytics.'),
      '#default_value' => $config->get('enable_analytics') ?? TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    // Validate JSON format
    $hotspot_data = $form_state->getValue('hotspot_data');
    if ($hotspot_data) {
      $decoded = json_decode($hotspot_data, TRUE);
      if (json_last_error() !== JSON_ERROR_NONE) {
        $form_state->setErrorByName('hotspot_data', $this->t('Hotspot data must be valid JSON.'));
      } else {
        // Validate required fields
        foreach ($decoded as $index => $hotspot) {
          if (!isset($hotspot['title']) || !isset($hotspot['content'])) {
            $form_state->setErrorByName('hotspot_data', $this->t('Hotspot #@num is missing required fields (title, content).', ['@num' => $index + 1]));
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('ilas_hotspot.settings');

    // Process hotspot data
    $hotspot_data = $form_state->getValue('hotspot_data');
    $hotspot_data_array = $hotspot_data ? json_decode($hotspot_data, TRUE) : [];

    $config
      ->set('hotspot_image', $form_state->getValue('hotspot_image'))
      ->set('annual_report_media', $form_state->getValue('annual_report_media'))
      ->set('hotspot_data', $hotspot_data_array)
      ->set('enable_analytics', $form_state->getValue('enable_analytics'))
      ->save();

    parent::submitForm($form, $form_state);
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