<?php

namespace Drupal\argo;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\typed_data\DataFetcherInterface;

/**
 * Translate content entities.
 */
class ContentEntityTranslate {

  /**
   * Data fetcher.
   *
   * @var \Drupal\typed_data\DataFetcherInterface
   */
  private $dataFetcher;

  /**
   * The core entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private $entityTypeManager;

  /**
   * The service constructor.
   *
   * @param \Drupal\typed_data\DataFetcherInterface $dataFetcher
   *   Data fetcher.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The core entity type manager service.
   */
  public function __construct(
    DataFetcherInterface $dataFetcher,
    EntityTypeManagerInterface $entityTypeManager
  ) {
    $this->dataFetcher = $dataFetcher;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * Translate content entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $targetEntity
   *   Target content entity translation.
   * @param string $targetLangcode
   *   The target language to save the translation for.
   * @param array $translation
   *   Translations object.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   Translated entity.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   * @throws \Drupal\Core\TypedData\Exception\ReadOnlyException
   * @throws \Drupal\typed_data\Exception\InvalidArgumentException
   */
  public function translate(ContentEntityInterface $targetEntity, $targetLangcode, array $translation) {
    $metatags = [];
    foreach ($translation['items'] as $translatedProperty) {
      $path = $translatedProperty['path'];
      $translatedPropertyValue = $translatedProperty['value'];

      if ($translatedProperty['propertyType'] === 'metatag') {
        $splitPath = explode('.', $path);
        $key = $splitPath[count($splitPath) - 1];
        $propPath = implode('.', array_slice($splitPath, 0, -1));
        $metatags[$propPath][$key] = $translatedPropertyValue;
        continue;
      }

      // Map keys are marked in path with '!' to differentiate from property keys.
      $pathSplitByMapKeyMark = explode('!', $path);
      $pathHasMapKey = count(array_keys($pathSplitByMapKeyMark)) > 1;
      if ($pathHasMapKey) {
        $pathWithoutMapKey = $pathSplitByMapKeyMark[0];
        $data = $this->dataFetcher->fetchDataByPropertyPath($targetEntity->getTypedData(), $pathWithoutMapKey, NULL, $targetLangcode);
        $mapKey = $pathSplitByMapKeyMark[1];
        $built = $this->buildProp([$mapKey => $translatedPropertyValue]);
        $data->setValue($built);
      }
      else {
        $data = $this->dataFetcher->fetchDataByPropertyPath($targetEntity->getTypedData(), $path, NULL, $targetLangcode);
        $data->setValue($translatedPropertyValue);
      }
    }

    foreach ($metatags as $propPath => $metatag) {
      $data = $this->dataFetcher->fetchDataByPropertyPath($targetEntity->getTypedData(), $propPath, NULL, $targetLangcode);
      $data->setValue(serialize($metatag));
    }

    return $targetEntity;
  }

  /**
   * Translate paragraphs and their nested children.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $targetEntity
   *   The host entity to attach the paragraph translations to.
   * @param string $targetLangcode
   *   The target language to save the translation for.
   * @param array $translations
   *   The list of paragraph translations.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   * @throws \Drupal\Core\TypedData\Exception\ReadOnlyException
   * @throws \Drupal\typed_data\Exception\InvalidArgumentException
   */
  public function translateParagraphs(ContentEntityInterface $targetEntity, $targetLangcode, array $translations) {
    $paragraph_storage = $this->entityTypeManager->getStorage('paragraph');
    /** @var \Drupal\field\FieldConfigInterface[] $field_definitions */
    $field_definitions = $this->entityTypeManager->getStorage('field_config')->loadByProperties([
      'entity_type' => $targetEntity->getEntityTypeId(),
      'bundle' => $targetEntity->bundle(),
      'field_type' => 'entity_reference_revisions',
    ]);

    foreach ($field_definitions as $field_definition) {
      // Only apply this logic to paragraph fields.
      if ($field_definition->getFieldStorageDefinition()->getSetting('target_type') !== 'paragraph') {
        continue;
      }

      $field_name = $field_definition->getName();
      if (!$targetEntity->get($field_name)->isEmpty()) {
        foreach ($targetEntity->get($field_name) as $field) {
          // Try and load the paragraph entity.
          // - Look for the referenced entity on the field first.
          // - Then try and load it by its revision_id.
          // - Lastly load by it its entity id.
          /** @var \Drupal\paragraphs\ParagraphInterface $paragraph */
          $paragraph = $field->entity ?? NULL;
          if (!isset($paragraph)) {
            $value = $field->getValue();
            if (isset($value['target_revision_id'])) {
              $paragraph = $paragraph_storage->loadRevision($value['target_revision_id']);
            }
            elseif (isset($value['target_id'])) {
              $paragraph = $paragraph_storage->load($value['target_id']);
            }
          }
          if (!$paragraph instanceof ParagraphInterface) {
            continue;
          }

          foreach ($translations as $translation) {
            if ($paragraph->uuid() === $translation['entityId']) {
              if ($paragraph->hasTranslation($targetLangcode)) {
                // Must remove existing translation because it might not have
                // all fields.
                $paragraph->removeTranslation($targetLangcode);
              }

              // Copy src fields to target.
              $array = $paragraph->toArray();
              $paragraph->addTranslation($targetLangcode, $array);
              $paragraph = $this->translate($paragraph->getTranslation($targetLangcode), $targetLangcode, $translation);
              $this->translateParagraphs($paragraph, $targetLangcode, $translations);
              // @todo investigate converting paragraphs to asymmetrical here.
              $paragraph->setNewRevision(TRUE);
              $paragraph->setNeedsSave(TRUE);
              $field->entity = $paragraph;
              break;
            }
          }
        }
      }
    }
  }

  /**
   * Build prop util.
   */
  public function buildProp($array) {
    $map = [];

    foreach ($array as $path => $value) {
      $exploded = explode('.', $path);
      $cur = &$map;
      foreach ($exploded as $key) {
        $cur[$key] = [];
        $cur = &$cur[$key];
      }
      $cur = $value;
    }
    return $map;
  }

}
