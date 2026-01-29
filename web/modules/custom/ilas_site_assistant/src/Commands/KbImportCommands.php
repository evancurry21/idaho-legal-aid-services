<?php

namespace Drupal\ilas_site_assistant\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drush\Commands\DrushCommands;
use Symfony\Component\Yaml\Yaml;

/**
 * Drush commands for importing KB content.
 */
class KbImportCommands extends DrushCommands {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a KbImportCommands object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct();
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Import FAQ stubs from YAML file.
   *
   * @param string $file
   *   Path to the YAML file containing FAQ stubs.
   * @param array $options
   *   Command options.
   *
   * @command ilas:kb-import
   * @aliases kb-import
   * @option dry-run Show what would be imported without creating entities.
   * @option node-id Node ID to attach paragraphs to (required for actual import).
   * @usage ilas:kb-import config/kb-stubs/top5-gap-faq-stubs.yml --dry-run
   *   Preview FAQ import.
   * @usage ilas:kb-import config/kb-stubs/top5-gap-faq-stubs.yml --node-id=123
   *   Import FAQs to node 123.
   */
  public function kbImport($file, array $options = ['dry-run' => FALSE, 'node-id' => NULL]) {
    $module_path = \Drupal::service('extension.list.module')->getPath('ilas_site_assistant');
    $file_path = $module_path . '/' . $file;

    if (!file_exists($file_path)) {
      // Try absolute path.
      $file_path = $file;
    }

    if (!file_exists($file_path)) {
      $this->logger()->error("File not found: {$file_path}");
      return 1;
    }

    $this->logger()->notice("Loading FAQ stubs from: {$file_path}");

    try {
      $data = Yaml::parseFile($file_path);
    }
    catch (\Exception $e) {
      $this->logger()->error("Failed to parse YAML: " . $e->getMessage());
      return 1;
    }

    if (empty($data['faq_stubs'])) {
      $this->logger()->error("No faq_stubs found in file.");
      return 1;
    }

    $stubs = $data['faq_stubs'];
    $this->logger()->notice("Found " . count($stubs) . " FAQ stubs.");

    $dry_run = $options['dry-run'];
    $node_id = $options['node-id'];

    if (!$dry_run && empty($node_id)) {
      $this->logger()->error("--node-id is required for actual import. Use --dry-run to preview.");
      return 1;
    }

    $node = NULL;
    if (!$dry_run) {
      $node = $this->entityTypeManager->getStorage('node')->load($node_id);
      if (!$node) {
        $this->logger()->error("Node {$node_id} not found.");
        return 1;
      }
      $this->logger()->notice("Will attach paragraphs to node: " . $node->label());
    }

    $created = 0;
    foreach ($stubs as $stub) {
      $this->logger()->notice("Processing: {$stub['id']}");
      $this->logger()->notice("  Question: " . substr($stub['question'], 0, 60) . "...");
      $this->logger()->notice("  Category: {$stub['category']}");
      $this->logger()->notice("  Anchor: {$stub['anchor_id']}");

      if (!$dry_run) {
        // Create the paragraph.
        $paragraph = Paragraph::create([
          'type' => 'faq_item',
          'field_faq_question' => $stub['question'],
          'field_faq_answer' => [
            'value' => $stub['answer'],
            'format' => 'basic_html',
          ],
        ]);

        // Add anchor_id field if it exists.
        if ($paragraph->hasField('field_anchor_id')) {
          $paragraph->set('field_anchor_id', $stub['anchor_id']);
        }

        $paragraph->save();
        $created++;

        $this->logger()->success("  Created paragraph ID: " . $paragraph->id());

        // Note: Attaching to node requires knowing the field name.
        // This would typically be done manually or with additional logic.
      }
    }

    if ($dry_run) {
      $this->logger()->notice("DRY RUN complete. Would create " . count($stubs) . " FAQ paragraphs.");
    }
    else {
      $this->logger()->success("Created {$created} FAQ paragraphs.");
      $this->logger()->notice("NOTE: Paragraphs created but not attached to node. Attach manually via the node edit form.");
    }

    return 0;
  }

  /**
   * List available KB stub files.
   *
   * @command ilas:kb-list
   * @aliases kb-list
   */
  public function kbList() {
    $module_path = \Drupal::service('extension.list.module')->getPath('ilas_site_assistant');
    $stub_dir = $module_path . '/config/kb-stubs';

    if (!is_dir($stub_dir)) {
      $this->logger()->notice("No kb-stubs directory found.");
      return;
    }

    $files = glob($stub_dir . '/*.yml');
    if (empty($files)) {
      $this->logger()->notice("No YAML files found in kb-stubs directory.");
      return;
    }

    $this->logger()->notice("Available KB stub files:");
    foreach ($files as $file) {
      $this->logger()->notice("  - " . basename($file));

      try {
        $data = Yaml::parseFile($file);
        if (!empty($data['faq_stubs'])) {
          $this->logger()->notice("    Contains " . count($data['faq_stubs']) . " FAQ stubs");
        }
      }
      catch (\Exception $e) {
        $this->logger()->warning("    Failed to parse: " . $e->getMessage());
      }
    }
  }

}
