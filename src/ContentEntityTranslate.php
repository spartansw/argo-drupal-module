<?php

namespace Drupal\argo;

use Drupal\content_moderation\Plugin\WorkflowType\ContentModeration;
use Drupal\Core\Entity\ContentEntityInterface;
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
   * @param \Drupal\Core\Entity\ContentEntityInterface $srcEntity
   *   Source content entity.
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
  public function translate(ContentEntityInterface $srcEntity, array $translation) {
    $targetLangcode = $translation['targetLangcode'];
    if ($srcEntity->hasTranslation($targetLangcode)) {
      // Must remove existing translation because it might not have all fields.
      $srcEntity->removeTranslation($targetLangcode);
    }

    // Copy src fields to target.
    $srcEntity->addTranslation($targetLangcode, $srcEntity->getFields());

    $targetEntity = $srcEntity->getTranslation($targetLangcode);

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
