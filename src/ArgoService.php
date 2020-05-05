<?php

namespace Drupal\argo;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\TypedData\Exception\MissingDataException;

/**
 * Interacts with Argo.
 */
class ArgoService implements ArgoServiceInterface {

  /**
   * The core entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private $entityTypeManager;

  /**
   * Exporter.
   *
   * @var ContentEntityExport
   */
  private $contentEntityExport;

  /**
   * Translate.
   *
   * @var ContentEntityTranslate
   */
  private $contentEntityTranslate;

  /**
   * The service constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The core entity type manager service.
   * @param ContentEntityExport $contentEntityExport
   *   Exporter.
   * @param ContentEntityTranslate $contentEntityTranslate
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ContentEntityExport $contentEntityExport,
    ContentEntityTranslate $contentEntityTranslate
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->contentEntityExport = $contentEntityExport;
    $this->contentEntityTranslate = $contentEntityTranslate;
  }

  /**
   * Export.
   *
   * @param string $entityType
   * @param string $uuid
   *
   * @return array
   *   Export.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function export(string $entityType, string $uuid) {
    $loadResult = $this->entityTypeManager
      ->getStorage($entityType)
      ->load()
      ->loadByProperties(['uuid' => $uuid]);
    if (empty($loadResult)) {
      throw new MissingDataException();
    }
    $entity = $loadResult[array_keys($loadResult)[0]];

    return $this->contentEntityExport->export($entity);
  }

  /**
   * Translate.
   *
   * @param string $entityType
   * @param string $uuid
   * @param array $translation
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Drupal\Core\TypedData\Exception\ReadOnlyException
   * @throws \Drupal\typed_data\Exception\InvalidArgumentException
   */
  public function translate(string $entityType, string $uuid, array $translation) {
    $loadResult = $this->entityTypeManager
      ->getStorage($entityType)
      ->loadByProperties(['uuid' => $uuid]);
    if (empty($loadResult)) {
      throw new MissingDataException();
    }
    $entity = $loadResult[array_keys($loadResult)[0]];

    $this->contentEntityTranslate->translate($entity, $translation);
  }

  /**
   *
   */
  public function updated(string $entityType, int $lastUpdate, int $limit, int $offset) {
    $entityStorage = $this->entityTypeManager
      ->getStorage($entityType);

    // Performance of ordering by integer entity IDs is about
    // 2 times faster than by UUID.
    /** @var \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager */
    $idKey = $this->entityTypeManager->getDefinition($entityType)->getKey('id');

    /** @var \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager */
    $entityFieldManager = \Drupal::service('entity_field.manager');
    $changedFieldName = array_keys($entityFieldManager->getFieldMapByFieldType('changed')[$entityType])[0];

    $count = intval($this->updatedQuery($entityStorage, $lastUpdate, $changedFieldName)->count()->execute());
    $ids = $this->updatedQuery($entityStorage, $lastUpdate, $changedFieldName)
      ->sort($idKey)->range($offset, $limit)->execute();

    $nextOffset = $offset + $limit;
    $hasNext = $nextOffset < $count;

    $updated = [];
    if ($hasNext) {
      $updated['nextOffset'] = $nextOffset;
      $updated['count'] = $count;
    }

    $updated['data'] = [];
    foreach ($ids as $id) {
      /** @var \Drupal\Core\Entity\EditorialContentEntityBase $entity */
      $entity = $entityStorage->load($id);

      $updated['data'][] = [
        'typeId' => $entity->getEntityTypeId(),
        'bundle' => $entity->bundle(),
        'id' => $entity->id(),
        'uuid' => $entity->uuid(),
        'path' => $entity->toUrl()->toString(),
        'langcode' => $entity->language()->getId(),
        'changed' => intval($entity->get($changedFieldName)->value),
      ];
    }

    return $updated;
  }

  /**
   *
   */
  private function updatedQuery(EntityStorageInterface $entityStorage, $lastUpdate, $changedName) {
    return $entityStorage->getQuery()
      ->condition($changedName, $lastUpdate, '>');
  }

}
