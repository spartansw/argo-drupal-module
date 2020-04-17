<?php

namespace Drupal\argo\Tests;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\paragraphs\Entity\Paragraph;

/**
 * Argo export test.
 *
 * @group argo
 */
class ContentEntityExportTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'typed_data',
    'argo',
    'node',
    'user',
    'field',
    'system',
    'entity_reference_revisions',
    'paragraphs',
  ];

  /**
   * Service under test.
   *
   * @var \Drupal\argo\ContentEntityExport
   */
  private $contentEntityExport;

  /**
   * {@inheritDoc}
   */
  public function setUp() {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');

    $nodeType = NodeType::create([
      'type' => 'article',
      'label' => 'Article',
    ]);
    $nodeType->save();

    $this->contentEntityExport = \Drupal::service('argo.export');
  }

  /**
   * Test export.
   */
  public function testNodeExports() {
    $expectedTitle = 'Test';
    $expectedString = 'String value';

    $fieldStorage = FieldStorageConfig::create([
      'field_name' => 'field_test',
      'type' => 'string',
      'entity_type' => 'node',
    ]);
    $fieldStorage->save();

    FieldConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_test',
      'bundle' => 'article',
      'translatable' => TRUE,
    ])->save();

    $node = Node::create([
      'title' => $expectedTitle,
      'type' => 'article',
      'field_test' => $expectedString,
    ]);
    $node->save();

    $actualResult = $this->contentEntityExport->export($node);

    $items = $actualResult['items'];
    $this->assertEqual($items[0]['value'], $expectedTitle);
    $this->assertEqual($items[1]['value'], $expectedString);
  }

  /**
   * Must be a content entity.
   */
  public function testMustBeContentEntity() {
    $this->assertTrue(FALSE);
  }

  /**
   * Only translatable fields.
   */
  public function testOnlyTranslatableFields() {
    $this->assertTrue(FALSE);
  }

  /**
   * No computed properties.
   */
  public function testNoComputedProperties() {
    $this->assertTrue(FALSE);
  }

  /**
   * Only translatable properties.
   */
  public function testOnlyTranslatableProperties() {
    $this->assertTrue(FALSE);
  }

  /**
   * Extracts complex or list types.
   */
  public function testExtractsComplexOrListTypes() {
    $this->assertTrue(FALSE);
  }

  /**
   * Extracts Metatags.
   */
  public function testExtractsMetatags() {
    $this->assertTrue(FALSE);
  }

  /**
   * Only traversable references.
   */
  public function testOnlyTraversableReferences() {
    $field_storage = FieldStorageConfig::create([
      'field_name' => 'field_page_sections',
      'entity_type' => 'node',
      'type' => 'entity_reference_revisions',
      'settings' => [
        'target_type' => 'paragraph',
      ],
    ]);
    $field_storage->save();
    $field = FieldConfig::create([
      'field_storage' => $field_storage,
      'bundle' => 'article',
    ]);
    $field->save();

    $child = Paragraph::create([
      'type' => 'text_passage',
      'title' => 'Child node',
    ]);
    $child->save();

    $node = Node::create([
      'type' => 'article',
      'title' => 'Parent node',
      'field_page_sections' => [
        [
          'target_id' => $child->id(),
          'target_revision_id' => $child->getRevisionId(),
        ],
      ],
    ]);
    $node->save();

    $actualResult = $this->contentEntityExport->export($node);

    $references = $actualResult['references'];
    $this->assertEqual(count($references), 1);
    $reference = $references[0];
    $this->assertEqual($reference['entityType'], 'paragraph');
    $this->assertEqual($reference['uuid'], $child->uuid());
  }

}
