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
   * The service constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The core entity type manager service.
   * @param ContentEntityExport $contentEntityExport
   *   Exporter.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ContentEntityExport $contentEntityExport
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->contentEntityExport = $contentEntityExport;
  }

  /**
   * Export.
   *
   * @param string $entityType
   * @param string $uuid
   * @return array
   *   Export.
   *
   * @throws MissingDataException
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

}
