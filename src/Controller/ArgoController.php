<?php

namespace Drupal\argo\Controller;

use Drupal\argo\ArgoServiceInterface;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 *
 */
class ArgoController extends ControllerBase {

  /**
   * @var \Drupal\argo\ArgoServiceInterface
   */
  private $argoService;

  /**
   * Argo constructor.
   *
   * @param \Drupal\argo\ArgoServiceInterface $argoService
   */
  public function __construct(ArgoServiceInterface $argoService) {
    $this->argoService = $argoService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('argo.service')
    );
  }

  /**
   * Lists updated editorial content entity metadata using a
   * single 'changed' field type.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response object.
   */
  public function updatedContentEntities(Request $request) {
    $entityType = $request->get('type');
    $lastUpdate = intval($request->query->get('last-update'));
    $limit = intval($request->query->get('limit'));
    $offset = intval($request->query->get('offset'));

    $updated = $this->argoService->updated($entityType, $lastUpdate, $limit, $offset);

    return new JsonResponse($updated);
  }

  /**
   * Exports a content entity for translation.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response object.
   */
  public function exportContentEntity(Request $request) {
    $entityType = $request->get('type');
    $uuid = $request->get('uuid');

    $export = $this->argoService->export($entityType, $uuid);

    return new JsonResponse($export);
  }

  /**
   * Translates fields on a single entity.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response object.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function translateContentEntity(Request $request) {
    $entityType = $request->get('type');
    $uuid = $request->get('uuid');
    $translation = json_decode($request->getContent(), TRUE);

    $this->argoService->translate($entityType, $uuid, $translation);

    return new JsonResponse();
  }

  /**
   * Fetch deleted entity IDs
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response object.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getDeletionLog(Request $request) {
    $deleted = $this->argoService->getDeletionLog();
    return new JsonResponse($deleted);
  }

  /**
   * Reset deleted entity log
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response object.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function resetDeletionLog(Request $request) {
    $deleted = json_decode($request->getContent(), TRUE)['deleted'];
    $this->argoService->resetDeletionLog($deleted);
    return new Response();
  }

  /**
   * Returns the uuid for an entity
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response object.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function entityUuid(Request $request) {
    $type = $request->get('type');
    $id = $request->get('id');

    $entity = \Drupal::entityTypeManager()
      ->getStorage($type)
      ->load($id);

    return new JsonResponse(['uuid' => $entity->uuid()]);
  }

}
