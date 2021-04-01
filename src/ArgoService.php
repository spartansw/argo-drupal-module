<?php

namespace Drupal\argo;

use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EditorialContentEntityBase;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Entity\Sql\TableMappingInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Language\Language;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Interacts with Argo.
 */
class ArgoService implements ArgoServiceInterface {

  /**
   * Configuration (entity) string export/import service.
   *
   * @var \Drupal\argo\ConfigService
   */
  private $configService;

  /**
   * Content exporter.
   *
   * @var ContentEntityExport
   */
  private $contentEntityExport;

  /**
   * Content translation service.
   *
   * @var ContentEntityTranslate
   */
  private $contentEntityTranslate;

  /**
   * UI string translation service.
   *
   * @var \Drupal\argo\LocaleService
   */
  private $localeService;

  /**
   * The core entity repository service.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  private $entityRepository;

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
   * @param ConfigService $configService
   *   Config exporter/importer service.
   * @param ContentEntityExport $contentEntityExport
   *   Exporter.
   * @param ContentEntityTranslate $contentEntityTranslate
   *   Content entity translation service.
   * @param \Drupal\argo\LocaleService $localeService
   *   UI string translation service.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entityRepository
   *   The core entity repository service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The core entity type manager service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The core entity field manager service.
   * @param \Drupal\content_moderation\ModerationInformationInterface $moderationInfo
   *   Moderation info.
   * @param \Drupal\Core\Database\Connection $connection
   *   DB connection.
   */
  public function __construct(
    ConfigService $configService,
    ContentEntityExport $contentEntityExport,
    ContentEntityTranslate $contentEntityTranslate,
    LocaleService $localeService,
    EntityRepositoryInterface $entityRepository,
    EntityTypeManagerInterface $entityTypeManager,
    EntityFieldManagerInterface $entityFieldManager,
    ModerationInformationInterface $moderationInfo,
    Connection $connection
  ) {
    $this->entityRepository = $entityRepository;
    $this->configService = $configService;
    $this->contentEntityExport = $contentEntityExport;
    $this->contentEntityTranslate = $contentEntityTranslate;
    $this->localeService = $localeService;
    $this->entityTypeManager = $entityTypeManager;
    $this->entityFieldManager = $entityFieldManager;
    $this->moderationInfo = $moderationInfo;
    $this->connection = $connection;
  }



  /**
   * {@inheritdoc}
   */
  public function exportConfig(string $langcode, array $options = []) {
    return $this->configService->export($langcode, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function translateConfig(string $langcode, array $translations) {
    $this->configService->import($langcode, $translations);
  }

  /**
   * {@inheritdoc}
   */
  public function exportContent(string $entityType, string $uuid, array $traversableEntityTypes, array $traversableContentTypes, int $revisionId = NULL) {
    $entity = $this->loadEntity($entityType, $uuid, $revisionId);
    return $this->contentEntityExport->export($entity, $traversableEntityTypes, $traversableContentTypes);
  }

  /**
   * {@inheritdoc}
   */
  public function translateContent(string $entityType, string $uuid, array $translations) {
    $rootTranslation = $translations['root'];
    $childTranslations = $translations['children'];

    $langcode = $rootTranslation['targetLangcode'];
    $revisionId = $rootTranslation['revisionId'] ?? NULL;
    $entity = $this->loadEntity($entityType, $uuid, $revisionId);
    $target_entity = $this->loadEntity($entityType, $uuid);

    $translationsById = [$rootTranslation['entityId'] => $rootTranslation];
    foreach ($childTranslations as $childTranslation) {
      $translationsById[$childTranslation['entityId']] = $childTranslation;
    }

    $visitedUuids = [$target_entity->uuid() => TRUE];
    $this->translateContentEntity($target_entity, $langcode, $translationsById, $visitedUuids);
  }

  /**
   * Translate content entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $source_entity
   *   Source entity.
   * @param string $langcode
   *   Langcode.
   * @param array $translationsById
   *   Translations by ID.
   * @param array $visitedUuids
   *   Visited UUIDs.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   * @throws \Drupal\Core\TypedData\Exception\ReadOnlyException
   * @throws \Drupal\typed_data\Exception\InvalidArgumentException
   */
  private function translateContentEntity(ContentEntityInterface $source_entity,
                                          string $langcode,
                                          array $translationsById,
                                          array $visitedUuids) {
    $translation = $translationsById[$source_entity->uuid()];

    // Ensure translation exists.
    if ($source_entity->hasTranslation($langcode)) {
      // Must remove existing translation because it might not have all fields.
      $source_entity->removeTranslation($langcode);
    }

    // Copy source fields to target.
    $array = $source_entity->toArray();
    $source_entity->addTranslation($langcode, $array);

    $target_entity = $source_entity->getTranslation($langcode);

    // Translate fields.
    $translated = $this->contentEntityTranslate->translate($target_entity,
      $langcode, $translationsById[$target_entity->uuid()]);

    // Translate references.
    $this->recurseReferences($target_entity, $langcode, $translationsById, $visitedUuids);

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

    // Update the revision details.
    $current_user = \Drupal::currentUser();
    if ($translated instanceof RevisionLogInterface) {
      $translated->setRevisionCreationTime(\Drupal::time()->getRequestTime());
      $translated->setRevisionLogMessage('Translation imported from Argo.');
      $translated->setRevisionUserId($current_user->id());
    }

    // Update the author to reflect the Argo service account.
    if ($translated instanceof EntityOwnerInterface) {
      $translated->setOwnerId($current_user->id());
    }

    // The new translation should be marked as revision translation affected,
    // we enforce that here.
    $translated->setRevisionTranslationAffected(TRUE);
    $translated->setRevisionTranslationAffectedEnforced(TRUE);

    $translated->save();
  }

  /**
   * Recurse references.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $target_entity
   *   Target entity.
   * @param string $langcode
   *   Langcode.
   * @param array $translationsById
   *   Translations by ID.
   * @param array $visitedUuids
   *   Visited UUIDs.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   * @throws \Drupal\Core\TypedData\Exception\ReadOnlyException
   * @throws \Drupal\typed_data\Exception\InvalidArgumentException
   */
  private function recurseReferences(ContentEntityInterface $target_entity, string $langcode, array $translationsById, array $visitedUuids) {
    foreach ($target_entity->getFields(FALSE) as $fieldItemList) {
      if ($fieldItemList instanceof EntityReferenceFieldItemListInterface) {
        foreach ($fieldItemList as $delta => $item) {
          /** @var \Drupal\Core\Entity\EntityInterface $item */
          if ($item->entity) {
            $referencedEntity = $item->entity;
            $uuid = !empty($referencedEntity->duplicateSource) ? $referencedEntity->duplicateSource->uuid() : $referencedEntity->uuid();
            if (isset($translationsById[$uuid])) {
              if (!isset($visitedUuids[$uuid])) {
                $visitedUuids[$uuid] = TRUE;
                if ($referencedEntity instanceof ParagraphInterface) {
                  // Replace parent field with reference to translated paragraph.
                  $fieldItemList[$delta] = $this->translateParagraph($referencedEntity, $uuid, $langcode, $translationsById, $visitedUuids);
                }
                elseif ($referencedEntity instanceof ContentEntityInterface) {
                  $this->translateContentEntity($referencedEntity, $langcode, $translationsById, $visitedUuids);
                }
              }
            }
          }
        }
      }
    }
  }

  /**
   * Translate paragraph.
   *
   * @param \Drupal\paragraphs\Entity\ParagraphInterface $paragraph
   *   Paragraph.
   * @param string $uuid
   *   UUID.
   * @param string $langcode
   *   Langcode.
   * @param array $translationsById
   *   Translations by ID.
   * @param array $visitedUuids
   *   Visited UUIDs.
   *
   * @return \Drupal\core\Entity\ContentEntityInterface
   *   Translated paragraph.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   * @throws \Drupal\Core\TypedData\Exception\ReadOnlyException
   * @throws \Drupal\typed_data\Exception\InvalidArgumentException
   */
  private function translateParagraph(ParagraphInterface $paragraph,
                                      string $uuid,
                                      string $langcode,
                                      array $translationsById,
                                      array $visitedUuids) {
    $translated = $this->contentEntityTranslate->translate($paragraph->getTranslation($langcode),
      $langcode, $translationsById[$uuid]);

    $this->recurseReferences($translated, $langcode, $translationsById, $visitedUuids);

    $translated->setNewRevision(TRUE);
    $translated->setNeedsSave(TRUE);

    return $translated;
  }

  /**
   * Loads an entity by its uuid or revision Id - if available.
   *
   * @param string $entityType
   *   Entity type ID.
   * @param string $uuid
   *   Entity UUID.
   * @param int|null $revisionId
   *   (optional) Entity revision ID.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The loaded entity.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  private function loadEntity(string $entityType, string $uuid, int $revisionId = NULL) {
    // Unfortunately loading an entity by its uuid will only load the latest
    // "published" revision which could be different from the original entity.
    // Until Drupal core supports loading entity revisions by a uuid, we try and
    // load the entity by its revision id.
    // @see https://www.drupal.org/project/drupal/issues/1812202
    if (isset($revisionId)) {
      /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
      $entity = $this->entityTypeManager
        ->getStorage($entityType)
        ->loadRevision($revisionId);
    }
    else {
      // If the revision id is not available, we resort to the uuid. Some
      // entities might not support revisions.
      $entity = $this->entityRepository->loadEntityByUuid($entityType, $uuid);
      // Find the latest translation affected entity (e.g. draft revision).
      $entity = $this->entityRepository->getActive($entityType, $entity->id(), []);
    }

    return $entity;
  }

  /**
   * Get column name for a given table mapping and key.
   */
  private function getColumnName(TableMappingInterface $tableMapping, string $key) {
    return $tableMapping->getColumnNames($key)['value'];
  }

  /**
   * Get updated entities.
   *
   * @param string $entityType
   *   Editorial content entity type ID.
   * @param int $lastUpdate
   *   UNIX timestamp of last update query.
   * @param int $limit
   *   Number of records to return.
   * @param int $offset
   *   Query offset.
   * @param array $publishedOnlyBundles
   *   Return only latest published revisions for specified bundle names.
   *   Else return the latest revisions regardless of status.
   * @param string $langcode
   *   Langcode.
   *
   * @return array
   *   Editorial content entities updated since $lastUpdate, or have no change timestamp.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityMalformedException
   * @throws \Exception
   *   If $entityType is not an editorial content entity.
   */
  public function getUpdated(string $entityType,
                             int $lastUpdate,
                             int $limit,
                             int $offset,
                             array $publishedOnlyBundles = NULL,
                             string $langcode = NULL) {
    $langcode = is_null($langcode) ? 'en-US' : $langcode;
    $publishedOnlyBundles = is_null($publishedOnlyBundles) ? [''] : $publishedOnlyBundles;
    $offset = is_null($offset) ? 0 : $offset;

    /** @var \Drupal\Core\Entity\ContentEntityStorageInterface $entityStorage */
    $entityStorage = $this->entityTypeManager
      ->getStorage($entityType);

    /** @var \Drupal\Core\Entity\ContentEntityTypeInterface $contentEntityType */
    $contentEntityType = $this->entityTypeManager->getDefinition($entityType);

    if (!$contentEntityType->entityClassImplements(EditorialContentEntityBase::class)) {
      throw new \Exception("\"{$contentEntityType->id()}\" is not an editorial content entity type");
    }

    $idKey = $contentEntityType->getKey('id');
    $revisionIdKey = $contentEntityType->getKey('revision');
    $publishedKey = $contentEntityType->getKey('published');
    $langcodeKey = $contentEntityType->getKey('langcode');
    $bundleCol = $contentEntityType->getKey('bundle');

    /** @var \Drupal\Core\Entity\Sql\SqlContentEntityStorage $entityStorage */
    $revisionTable = $entityStorage->getRevisionDataTable();
    $baseTable = $entityStorage->getBaseTable();

    /** @var \Drupal\Core\Entity\Sql\TableMappingInterface $tableMapping */
    $tableMapping = $entityStorage->getTableMapping();

    $idCol = $this->getColumnName($tableMapping, $idKey);
    $revisionIdCol = $this->getColumnName($tableMapping, $revisionIdKey);
    $publishedCol = $this->getColumnName($tableMapping, $publishedKey);
    $langcodeCol = $this->getColumnName($tableMapping, $langcodeKey);

    // EntityChangedTrait implies all editorial content entities have a "changed" field.
    $changedCol = 'changed';

    // Allow null changed values if it's the first sync. This is useful for taxonomy terms lacking a
    // changed value.
    $showNullChangedRevisions = '';
    if ($lastUpdate === 0) {
      $showNullChangedRevisions = "OR revision.{$changedCol} IS NULL";
    }

    // Handmade query due to Entity Storage API adding unnecessary and slow joins.
    // Get all entity revision IDs of a given type changed since last update.
    $results = $this->connection->query("
    SELECT MAX(revision.{$revisionIdCol}) AS {$revisionIdCol}
    FROM {$revisionTable} AS revision
             JOIN {$baseTable} AS base ON base.{$idCol} = revision.{$idCol}
    WHERE revision.{$langcodeCol} = :langcode
        AND (revision.{$changedCol} > :last_update
              {$showNullChangedRevisions})
        AND ((base.{$bundleCol} IN (:published_only_bundles[]) AND
              revision.{$publishedCol} = 1) OR
              base.{$bundleCol} NOT IN (:published_only_bundles[]))
    GROUP BY revision.{$idCol}
    ORDER BY revision.{$changedCol} DESC, revision.{$revisionIdCol} DESC;
    ", [
      ':last_update' => $lastUpdate,
      ':langcode' => $langcode,
      ':published_only_bundles[]' => $publishedOnlyBundles
    ])->fetchAll();

    $revisionIds = [];
    foreach ($results as $result) {
      $revisionIds[] = intval($result->$revisionIdKey);
    }

    $count = count($revisionIds);

    $revisionIds = array_slice($revisionIds, $offset, $limit);

    $nextOffset = $offset + $limit;
    $hasNext = $nextOffset < $count;

    $updated = [];
    if ($hasNext) {
      $updated['nextOffset'] = $nextOffset;
      $updated['count'] = $count;
    }

    $updated['data'] = [];
    $entities = $entityStorage->loadMultipleRevisions($revisionIds);
    /** @var \Drupal\Core\Entity\EditorialContentEntityBase $entity */
    foreach ($entities as $entity) {
      $changedTime = intval($entity->getChangedTime());
      $updated['data'][] = [
        'typeId' => $entity->getEntityTypeId(),
        'bundle' => $entity->bundle(),
        'id' => $entity->id(),
        'revisionId' => $entity->getRevisionId(),
        'uuid' => $entity->uuid(),
        'path' => $entity->toUrl()->toString(),
        'langcode' => $entity->language()->getId(),
        'changed' => $changedTime,
        'published' => $entity->isPublished()
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
   * Get entity UUID & revision ID.
   */
  public function entityInfo($type, $id) {
    $entity = $this->entityTypeManager
      ->getStorage($type)
      ->load($id);

    return [
      'uuid' => $entity->uuid(),
      'revisionId' => $this->contentEntityExport->getRevisionId($entity)
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function exportLocale(string $langcode, array $options = []) {
    return $this->localeService->export($langcode, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function translateLocale(string $langcode, array $translations) {
    $this->localeService->import($langcode, $translations);
  }

}
