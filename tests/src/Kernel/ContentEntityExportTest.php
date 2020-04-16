<?php

namespace Drupal\argo\Tests;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

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
    'argo',
    'node',
    'user',
    'field',
    'system',
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

    $this->contentEntityExport = \Drupal::service('argo.export');
  }

  /**
   * Test export.
   */
  public function testNodeExports() {
    $expectedTitle = 'Test';
    $expectedString = 'String value';

    $nodeType = NodeType::create([
      'type' => 'article',
      'label' => 'Article',
    ]);
    $nodeType->save();

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

    $this->assertEqual($actualResult['fields'][0]['items'][0]['properties'][0]['value'], $expectedTitle);
    $this->assertEqual($actualResult['fields'][1]['items'][0]['properties'][0]['value'], $expectedString);
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

}
