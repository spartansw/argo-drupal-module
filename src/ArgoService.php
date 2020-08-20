<?php

namespace Drupal\argo;

use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\TableMappingInterface;
use Drupal\Core\Language\Language;
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
   * Entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  private $entityFieldManager;

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
   * Moderation info.
   *
   * @var \Drupal\content_moderation\ModerationInformationInterface
   */
  private $moderationInfo;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  private $connection;

  /**
   * The service constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The core entity type manager service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The core entity field manager service.
   * @param ContentEntityExport $contentEntityExport
   *   Exporter.
   * @param ContentEntityTranslate $contentEntityTranslate
   *   Content entity translation service.
   * @param \Drupal\content_moderation\ModerationInformationInterface $moderationInfo
   *   Moderation info.
   * @param Connection $connection
   *   DB connection.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entity_field_manager,
    ContentEntityExport $contentEntityExport,
    ContentEntityTranslate $contentEntityTranslate,
    ModerationInformationInterface $moderationInfo,
    Connection $connection
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->contentEntityExport = $contentEntityExport;
    $this->contentEntityTranslate = $contentEntityTranslate;
    $this->moderationInfo = $moderationInfo;
    $this->connection = $connection;
  }

  /**
   * Export.
   *
   * @param string $entityType
   *   Entity type ID.
   * @param string $uuid
   *   Entity UUID.
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
   *   Entity type ID.
   * @param string $uuid
   *   Entity UUID.
   * @param array $translation
   *   Translation object.
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
    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity = $loadResult[array_keys($loadResult)[0]];

    $translated = $this->contentEntityTranslate->translate($entity, $translation);

    if ($translated instanceof EntityPublishedInterface) {
      $translated->setUnpublished();
    }
    if (isset($translation['stateId'])) {
      if ($translation['stateId'] === 'published') {
        $translated->setPublished();
      }
    }
    if ($this->moderationInfo->isModeratedEntity($translated)) {
      if (!isset($translation['stateId']) || strlen($translation['stateId']) < 1) {
        /** @var \Drupal\content_moderation\Plugin\WorkflowType\ContentModerationInterface $contentModeration */
        $contentModeration = $this->moderationInfo->getWorkflowForEntity($translated)->getTypePlugin();
        $stateId = $contentModeration->getInitialState($translated)->id();
      }
      else {
        $stateId = $translation['stateId'];
      }
      $translated->set('moderation_state', $stateId);
    }
    $translated->save();
  }

  private function getColumnName(TableMappingInterface $tableMapping, string $key) {
    return $tableMapping->getColumnNames($key)['value'];
  }

  /**
   * Get updated entities.
   */
  public function getUpdated(string $entityType, int $lastUpdate, int $limit, int $offset) {
    $entityStorage = $this->entityTypeManager
      ->getStorage($entityType);

    /** @var \Drupal\Core\Entity\ContentEntityTypeInterface $contentEntityType */
    $contentEntityType = $this->entityTypeManager->getDefinition($entityType);
    $idKey = $contentEntityType->getKey('id');
    $langcodeKey = $contentEntityType->getKey('langcode');
    $revisionCreatedKey = $contentEntityType->getRevisionMetadataKey('revision_created');

    $changedFieldName = array_keys($this->entityFieldManager->getFieldMapByFieldType('changed')[$entityType])[0];

    $baseTable = $entityStorage->getBaseTable();
    $dataTable = $entityStorage->getDataTable();
    $revisionTable = $entityStorage->getRevisionTable();

    /** @var TableMappingInterface $tableMapping */
    $tableMapping = $entityStorage->getTableMapping();

    $idCol = $this->getColumnName($tableMapping, $idKey);
    $langcodeCol = $this->getColumnName($tableMapping, $langcodeKey);
    $changedCol = $this->getColumnName($tableMapping, $changedFieldName);
    $revisionCol = $this->getColumnName($tableMapping, $revisionCreatedKey);

    // Handmade query due to Entity Storage API adding unnecessary and slow joins
    // Get all entity IDs of a given type modified since last update
    $rawIds = $this->connection->query('
    SELECT base.' . $idCol . '
    FROM {'. $baseTable . '} AS base
         LEFT JOIN {' . $dataTable . '} AS data ON data.'. $idCol .' = base. ' . $idCol . '
         LEFT JOIN {' . $revisionTable . '} revision ON revision. ' . $idCol . ' = base.' . $idCol . '
    WHERE (data.' . $langcodeCol . '!= \'und\')
      AND ((data.' . $changedCol . ' > :last_update) OR (revision.' . $revisionCol . ' > :last_update))
    GROUP BY base.' . $idCol . ' ORDER BY base.' . $idCol . ' ASC;
    ', ['last_update' => $lastUpdate])->fetchAll();

    $ids = [];
    foreach ($rawIds as $id) {
      $ids[] = intval($id->$idKey);
    }

    $count = count($ids);

    $ids = array_slice($ids, $offset, $limit);

    $nextOffset = $offset + $limit;
    $hasNext = $nextOffset < $count;

    $updated = [];
    if ($hasNext) {
      $updated['nextOffset'] = $nextOffset;
      $updated['count'] = $count;
    }

    $updated['data'] = [];
    $entities = $entityStorage->loadMultiple($ids);
    /** @var \Drupal\Core\Entity\EditorialContentEntityBase $entity */
    foreach ($entities as $entity) {
      $changedTime = intval($entity->get($changedFieldName)->value);
      if ($changedTime === 0) {
        $changedTime = intval($entity->getRevisionCreationTime());
      }
      $updated['data'][] = [
        'typeId' => $entity->getEntityTypeId(),
        'bundle' => $entity->bundle(),
        'id' => $entity->id(),
        'uuid' => $entity->uuid(),
        'path' => $entity->toUrl()->toString(),
        'langcode' => $entity->language()->getId(),
        'changed' => $changedTime,
      ];
    }

    return $updated;
  }

  /**
   * Updated query util.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $entityStorage
   *   Entity storage.
   * @param int $lastUpdate
   *   Last update epoch seconds.
   * @param string $changedName
   *   Name of changed field.
   * @param string $langcodeKey
   *   Name of langcode field.
   * @param string $revisionCreatedKey
   *   Name of revision created field.
   *
   * @return \Drupal\Core\Entity\Query\QueryInterface
   *   Query.
   */
  private function updatedQuery(EntityStorageInterface $entityStorage,
                                $lastUpdate,
                                $changedName,
                                $langcodeKey,
                                $revisionCreatedKey) {
    $query = $entityStorage->getQuery();
    return $query
      ->condition($langcodeKey, Language::LANGCODE_NOT_SPECIFIED, '!=')
      ->condition($query->orConditionGroup()
        ->condition($changedName, $lastUpdate, '>')
        ->condition($revisionCreatedKey, $lastUpdate, '>'));
  }

  /**
   * Get deletion log.
   */
  public function getDeletionLog() {
    $conn = Database::getConnection();
    $deleted = $conn->query('SELECT * FROM {argo_entity_deletion}')->fetchAll();
    return ['deleted' => $deleted];
  }

  /**
   * Reset deletion log.
   *
   * @param array $deleted
   *   Deleted entity UUIDs to clear from log.
   */
  public function resetDeletionLog(array $deleted) {
    $ids = [];
    foreach ($deleted as $item) {
      $ids[] = $item['uuid'];
    }

    $conn = Database::getConnection();
    $conn->delete('argo_entity_deletion')->condition('uuid', $ids, 'IN')->execute();
  }

  /**
   * Get entity UUID.
   */
  public function entityUuid($type, $id) {
    $entity = $this->entityTypeManager
      ->getStorage($type)
      ->load($id);

    return $entity->uuid();
  }

}
