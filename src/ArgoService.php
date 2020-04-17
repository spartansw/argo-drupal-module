<?php

namespace Drupal\argo;

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

}
