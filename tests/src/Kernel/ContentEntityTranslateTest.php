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
    'link',
    'content_moderation',
    'workflows',
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

    $this->installEntitySchema('content_moderation_state');
    $this->installConfig('content_moderation');

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
    $export['root']['items'][1]['value'] = $expectedTranslation;

    $this->assertEqual($export['items'][2]['path'], 'field_meta_tags.0.value.key1');
    $export['root']['items'][2]['value'] = $expectedTranslation;
    $export['root']['items'][3]['value'] = $expectedTranslation;

    $targetLangcode = $this->german->id();
    $export['root']['targetLangcode'] = $targetLangcode;

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

    $this->assertEqual();
  }

  /**
   * Test map translation.
   */
  public function testMapTranslation() {
    $this->addField('node', 'article', 'field_map', TRUE, 'link');

    $node = Node::create([
      'type' => 'article',
      'title' => 'Test title',
      'field_map' => [
        'attributes' => [],
        'uri' => 'testUri',
        'title' => 'testTitle',
        'options' => [
          'nestedKey' => 'nestedValue',
        ],
      ],
    ]);
    $node->save();

    $export = $this->contentEntityExport->export($node);

    $targetLangcode = $this->german->id();
    $export['root']['targetLangcode'] = $targetLangcode;

    $export['root']['items'][1]['value'] = 'testUriX';
    $export['root']['items'][2]['value'] = 'testTitleX';
    $export['root']['items'][3]['value'] = 'nestedValueX';

    $this->contentEntityTranslate->translate($node, $export);

    /* @var \Drupal\node\NodeInterface $updatedSrcNode . */
    $updatedSrcNode = \Drupal::entityTypeManager()->getStorage('node')
      ->load($node->id());
    $translatedNode = $updatedSrcNode->getTranslation($targetLangcode);

    $this->assertEqual($translatedNode->field_map->getValue()[0], [
      'uri' => 'testUriX',
      'title' => 'testTitleX',
      'options' => [
        'nestedKey' => 'nestedValueX',
      ],
    ]);
  }

  /**
   * Test flatten map.
   */
  public function testFlattenMap() {
    $result = $this->contentEntityExport->flattenProp([
      'attributes' => [],
      'key' => 'value',
      'nestedKey' => [
        'nested' => 'nestedValue',
      ],
    ]);

    $this->assertEqual($result, [
      'key' => 'value',
      'nestedKey.nested' => 'nestedValue',
    ]);

    $roundtrip = $this->contentEntityTranslate->buildProp($result);

    $this->assertEqual($roundtrip, [
      'key' => 'value',
      'nestedKey' => [
        'nested' => 'nestedValue',
      ],
    ]);
  }

  /**
   * Test empty source values.
   */
  public function testEmptyValues() {
    $this->addField('node', 'article', 'field_string', TRUE, 'string');
    $testValues = ['test value', '', ' '];
    foreach ($testValues as $testValue) {
      $node = Node::create([
        'type' => 'article',
        'title' => 'Test title',
        'field_string' => [
          'value' => $testValue,
        ],
      ]);
      $node->save();

      $export = $this->contentEntityExport->export($node);

      if (isset($export['root']['items'][1])) {
        $this->assertEqual($export['root']['items'][1]['value'], 'test value');
      }

      $targetLangcode = $this->german->id();
      $export['root']['targetLangcode'] = $targetLangcode;

      $this->contentEntityTranslate->translate($node, $export);

      /* @var \Drupal\node\NodeInterface $updatedSrcNode . */
      $updatedSrcNode = \Drupal::entityTypeManager()->getStorage('node')
        ->load($node->id());
      $export2 = $this->contentEntityExport->export($updatedSrcNode);
      $export2['root']['targetLangcode'] = $targetLangcode;

      $this->contentEntityTranslate->translate($updatedSrcNode, $export2);
    }
  }

  /**
   * Add field util.
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
