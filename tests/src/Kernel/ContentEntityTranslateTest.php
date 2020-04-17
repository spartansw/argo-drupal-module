<?php

namespace Drupal\argo\Tests;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * Argo translate test.
 *
 * @group argo
 */
class ContentEntityTranslateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'argo',
    'node',
    'user',
    'field',
    'system',
    'typed_data',
    'language',
    'token',
    'metatag',
  ];

  /**
   * Service under test.
   *
   * @var \Drupal\argo\ContentEntityTranslate
   */
  private $contentEntityTranslate;

  /**
   * Service under test.
   *
   * @var \Drupal\argo\ContentEntityExport
   */
  private $contentEntityExport;

  /**
   * Service under test.
   *
   * @var \Drupal\Core\Entity\EntityInterface
   */
  private $german;

  /**
   * {@inheritDoc}
   */
  public function setUp() {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);

    $nodeType = NodeType::create([
      'type' => 'article',
      'label' => 'Article',
    ]);
    $nodeType->save();

    $this->german = ConfigurableLanguage::create([
      'id' => 'de-DE',
      'label' => 'German',
    ]);
    $this->german->save();

    $this->contentEntityExport = \Drupal::service('argo.export');
    $this->contentEntityTranslate = \Drupal::service('argo.translate');
  }

  /**
   * Test can translate nodes.
   */
  public function testNodeTranslation() {
    $expectedTranslation = 'Translated value';
    $srcValue = 'Source value';

    $this->addField('node', 'article', 'field_test', TRUE, 'string');
    $this->addField('node', 'article', 'field_meta_tags', TRUE, 'metatag');

    $node = Node::create([
      'type' => 'article',
      'title' => 'Test title',
      'field_test' => $srcValue,
      'field_meta_tags' => serialize([
        'key1' => $srcValue,
        'key2' => $srcValue,
      ]),
    ]);
    $node->save();

    $export = $this->contentEntityExport->export($node);
    $export['items'][1]['value'] = $expectedTranslation;

    $this->assertEqual($export['items'][2]['path'], 'field_meta_tags.0.value.key1');
    $export['items'][2]['value'] = $expectedTranslation;
    $export['items'][3]['value'] = $expectedTranslation;

    $targetLangcode = $this->german->id();
    $export['targetLangcode'] = $targetLangcode;

    $this->contentEntityTranslate->translate($node, $export);

    /* @var \Drupal\node\NodeInterface $updatedSrcNode . */
    $updatedSrcNode = \Drupal::entityTypeManager()->getStorage('node')
      ->load($node->id());
    $translatedNode = $updatedSrcNode->getTranslation($targetLangcode);

    $this->assertEqual($translatedNode->field_test->value, $expectedTranslation);
    $this->assertEqual($updatedSrcNode->field_test->value, $srcValue);

    $translatedMetatag = unserialize($translatedNode->field_meta_tags->value);
    $this->assertEqual($translatedMetatag['key1'], $expectedTranslation);
    $this->assertEqual($translatedMetatag['key2'], $expectedTranslation);

    $this->contentEntityTranslate->translate($node, $export);
  }

  /**
   *
   */
  private function addField(string $entityType, string $bundle, string $fieldName, bool $translatable, string $dataType) {
    $fieldStorage = FieldStorageConfig::create([
      'field_name' => $fieldName,
      'type' => $dataType,
      'entity_type' => $entityType,
    ]);
    $fieldStorage->save();

    FieldConfig::create([
      'entity_type' => $entityType,
      'field_name' => $fieldName,
      'bundle' => $bundle,
      'translatable' => $translatable,
    ])->save();
  }

}
