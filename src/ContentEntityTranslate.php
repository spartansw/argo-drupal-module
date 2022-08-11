<?php

namespace Drupal\argo;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\typed_data\Exception\InvalidArgumentException;
use Drupal\typed_data\DataFetcherInterface;
use Drupal\argo\Exception\FieldNotFoundException;

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
   * The service constructor.
   *
   * @param \Drupal\typed_data\DataFetcherInterface $dataFetcher
   *   Data fetcher.
   */
  public function __construct(
    DataFetcherInterface $dataFetcher
  ) {
    $this->dataFetcher = $dataFetcher;
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
        $data = $this->fetchDataByPropertyPath($translatedProperty, $targetEntity, $pathWithoutMapKey, $targetLangcode);
        $mapKey = $pathSplitByMapKeyMark[1];
        $built = $this->buildProp([$mapKey => $translatedPropertyValue]);
        $data->setValue($built);
      }
      else {
        $data = $this->fetchDataByPropertyPath($translatedProperty, $targetEntity, $path, $targetLangcode);
        $data->setValue($translatedPropertyValue);
      }
    }

    foreach ($metatags as $propPath => $metatag) {
      $data = $this->fetchDataByPropertyPath($translatedProperty, $targetEntity, $propPath, $targetLangcode);
      $data->setValue(serialize($metatag));
    }

    return $targetEntity;
  }

  /**
   * Fetch data by property path.
   */
  private function fetchDataByPropertyPath($translatedProperty, $targetEntity, $path, $targetLangcode) {
    try {
      return $this->dataFetcher->fetchDataByPropertyPath($targetEntity->getTypedData(), $path, NULL, $targetLangcode);
    }
    catch (InvalidArgumentException $e) {
      throw new FieldNotFoundException(sprintf('%s field with path "%s" and value "%s"',
        $translatedProperty['propertyType'], $path, $translatedProperty['value']), 0, $e);
    }
    catch (MissingDataException $e) {
      $explodePath = explode('.', $path);
      $fieldLabel = '';
      if (!empty($explodePath)) {
        $fieldDefinition = $targetEntity->getFieldDefinition($explodePath[0]);
        if (isset($fieldDefinition)) {
          $fieldLabel = $fieldDefinition->getLabel();
        }
      }
      throw new FieldNotFoundException(sprintf('List item does not exist for %s field "%s" with path "%s" and value "%s"',
        $translatedProperty['propertyType'], $fieldLabel, $path, $translatedProperty['value']), 0, $e);
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
