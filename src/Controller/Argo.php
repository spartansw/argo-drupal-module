<?php

namespace Drupal\argo\Controller;

use Drupal\Core\Controller\ControllerBase;
use LogicException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class Argo extends ControllerBase {

  public function entityPath(Request $request) {
    $invalidMethod = !['GET' => TRUE][$request->getMethod()];
    if ($invalidMethod) {
      return $this->json_response(405, ["error" => "405 Method Not Allowed"]);
    }

    $nid = $request->query->get('nid');
    $node = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->load($nid);

    $path = $node->path->getValue()[0];
    $hasAlias = isset($path['alias']);

    if (!$hasAlias) {
      $path['source'] = '/node/' . $node->id();
    }

    return $this->json_response(200, $path);
  }

  /*
   * There's no way to get base field definitions via JSON:API.
   * Field configurations for non-base fields are available, but there's no point in using 2 different
   * methods so I combine both definition types into one response here.
   */
  public function fieldDefinitions(Request $request) {
    $invalidMethod = !['GET' => TRUE][$request->getMethod()];
    if ($invalidMethod) {
      return $this->json_response(405, ["error" => "405 Method Not Allowed"]);
    }

    $entityTypeId = $request->query->get('entityTypeId');
    $bundle = $request->query->get('bundle');

    $entityFieldManager = \Drupal::getContainer()->get('entity_field.manager');

    try {
      $definitions = $entityFieldManager->getFieldDefinitions($entityTypeId, $bundle);
    } catch (LogicException $e) {
      // Thrown if a config entity type is given or if one of the entity keys is flagged as translatable.
      // Ignored if entity type is non-fieldable
      $definitions = [];
    }

    $outDefs = [];
    foreach ($definitions as $field => $definition) {
      $isRequired = $definition->isRequired();
      $isTranslatable = $definition->isTranslatable();
      $outDef = [
        'field_name' => $field,
        'field_type' => $definition->getType(),
        'required' => $isRequired,
        'translatable' => $isTranslatable,
        'target_entity_type_id' => $definition->getTargetEntityTypeId(),
        'target_bundle' => $definition->getTargetBundle(),
      ];
      array_push($outDefs, $outDef);
    }
    return $this->json_response(200, ['data' => $outDefs]);
  }

  /*
   * Translates fields on a single entity
   */
  public function translation(Request $request) {
    $invalidMethod = !['POST' => TRUE][$request->getMethod()];
    if ($invalidMethod) {
      return $this->json_response(405, ["error" => "405 Method Not Allowed"]);
    }

    //TODO: content moderation

    $requestJson = json_decode($request->getContent(), TRUE);
    $id = $requestJson['id'];
    $targetLangcode = $requestJson['targetLangcode'];
    $entityTypeId = $requestJson['entityTypeId'];
    $translation = $requestJson['translation'];
    $exclusions = $requestJson['exclusions'];

    $loadResult = \Drupal::entityTypeManager()
      ->getStorage($entityTypeId)
      ->loadByProperties(['uuid' => $id]);
    if (empty($loadResult)) {
      //TODO ERROR
    }
    $srcEntity = $loadResult[array_key_first($loadResult)];

    // TODO: why is srcEntity->isTranslatable() sometimes false? Translation settings say otherwise

    /*
     * Existing translations are always removed
     * and then added, because removing an existing translation prevents you from getting a field
     * in the target language unless a new translation is added.
     * Also using addTranslation initialization parameter for convenience
     * of filling targets with copies of source values before translation.
     */
    if ($srcEntity->hasTranslation($targetLangcode)) {
      $srcEntity->removeTranslation($targetLangcode);
    }

    // Entity cannot be translated if it is language neutral
    if ($srcEntity->language()->getId() == "und") {
      return new Response("", 201);
    }

    $srcEntity->addTranslation($targetLangcode, $srcEntity->getFields());
    $entityTranslation = $srcEntity->getTranslation($targetLangcode);


    //TODO: Why is langcode and status marked as translatable if setting the value is not allowed?
    // This forces us to keep a whitelist of fields that are actually translatable
    $translatableFields = $srcEntity->getTranslatableFields($include_computed = FALSE);
    foreach ($translatableFields as $sourceField) {
      $sourceFieldName = $sourceField->getName();
      $targetField = $entityTranslation->get($sourceFieldName);

      $fieldHasTranslation = isset($translation[$sourceFieldName]);
      $fieldIsExcluded = isset($exclusions[$sourceFieldName]);

      if ($fieldHasTranslation) {
        $targetValue = $translation[$sourceFieldName];
      }
      elseif ($fieldIsExcluded) {
        // copy from source
        $sourceValue = $sourceField->getValue();
        $targetValue = $sourceValue;
      }
      else {
        $isRequiredField = $sourceField->getFieldDefinition()->isRequired();
        if ($isRequiredField) {
          return $this->json_response(500,
            ["error" => "Field '" . $sourceFieldName . "'' is required but has no translation"]);
        }
        continue;
      }

      $targetField->setValue($targetValue);
    }

    $entityTranslation->save();

    return new Response("", 201);
  }

  private function json_response($statusCode, $json) {
    return new Response(json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), $statusCode,
      ["Content-Type" => "application/json"]);
  }
}
