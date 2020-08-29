<?php

namespace Drupal\argo\Controller;

use Drupal\argo\ArgoServiceInterface;
use Drupal\Core\Controller\ControllerBase;
use Exception;
use Psr\Log\LogLevel;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Argo module controller.
 */
class ArgoController extends ControllerBase {

  /**
   * Argo service.
   *
   * @var \Drupal\argo\ArgoServiceInterface
   */
  private $argoService;

  /**
   * Argo constructor.
   *
   * @param \Drupal\argo\ArgoServiceInterface $argoService
   *   Argo service.
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
   * Lists updated editorial content entity metadata.
   *
   * Uses a single 'changed' field type.
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

    $updated = $this->argoService->getUpdated($entityType, $lastUpdate, $limit, $offset);

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

    try {
      $this->argoService->translate($entityType, $uuid, $translation);
    }
    catch (Exception $e) {
      \Drupal::logger('argo')->log(LogLevel::ERROR, $e->__toString());
      return new JsonResponse([
        'message' => $e->__toString(),
      ],
        Response::HTTP_INTERNAL_SERVER_ERROR);
    }
    return new JsonResponse();
  }

  /**
   * Fetch deleted entity IDs.
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
   * Reset deleted entity log.
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
    try {
      $deleted = json_decode($request->getContent(), TRUE)['deleted'];
      $this->argoService->resetDeletionLog($deleted);
    }
    catch (Exception $e) {
      \Drupal::logger('argo')->log(LogLevel::ERROR, $e->__toString());
      return new JsonResponse([
        'message' => $e->__toString(),
      ],
        Response::HTTP_INTERNAL_SERVER_ERROR);
    }
    return new JsonResponse();
  }

  /**
   * Returns the uuid for an entity.
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

    $uuid = $this->argoService->entityUuid($type, $id);

    return new JsonResponse(['uuid' => $uuid]);
  }

}
