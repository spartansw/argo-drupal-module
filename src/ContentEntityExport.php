<?php

namespace Drupal\argo;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Export Content Entities.
 */
class ContentEntityExport {

  /**
   * Construct.
   */
  public function __construct() {
  }

  /**
   * Export content entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Entity to export.
   *
   * @return array
   *   Exported entity.
   */
  public function export(ContentEntityInterface $entity): array {
    $translatableFields = $entity->getTranslatableFields(FALSE);

    foreach ($translatableFields as $name => $translatableField) {
      $fieldItemDef = $translatableField->getDataDefinition();
      if ($fieldItemDef instanceof FieldDefinitionInterface) {
        if ($fieldItemDef->getType() === 'language') {
          unset($translatableFields[$name]);
        }
      }
    }

    $warnings = [];

    $ignoreTypes = [
      'integer' => TRUE,
      'boolean' => TRUE,
      'timestamp' => TRUE,
      'filter_format' => TRUE,
      'datetime_iso8601' => TRUE,
      'email' => TRUE,
    ];

    $stringTypes = [
      'string' => TRUE,
      'uri' => TRUE,
    ];

    $translationOut = ['fields' => []];
    foreach ($translatableFields as $fieldName => $field) {
      $hasFieldOut = FALSE;
      $itemsOut = [];
      /* @var \Drupal\Core\Field\FieldItemInterface $fieldItem . */
      foreach ($field as $itemNumber => $fieldItem) {
        $hasItemOut = FALSE;
        $propDefs = $fieldItem->getDataDefinition()->getPropertyDefinitions();
        $propertiesOut = [];
        foreach ($propDefs as $propName => $propDef) {
          $dataType = $propDef->getDataType();
          // TODO: recurse complex types.
          if (!$propDef->isComputed()) {
            if (!isset($ignoreTypes[$dataType])) {
              $prop = $fieldItem->get($propName);
              $propertyPath = $prop->getPropertyPath();
              $value = $prop->getValue();

              if (!is_string($value) || strlen(str_replace(' ', '', $value)) > 0) {
                $hasFieldOut = TRUE;
                $hasItemOut = TRUE;
              }
              if ($value !== NULL) {
                if (isset($stringTypes[$dataType])) {
                  // TODO: remove null values?
                  $outValue = $value;
                }
                elseif ($dataType === 'metatag') {
                  $metatag = unserialize($value);
                  foreach ($metatag as $tagName => $tagValue) {
                    if (is_string($tagValue)) {
                      $propertiesOut[] = [
                        'name' => $propName,
                        'label' => (string) $propDef->getLabel(),
                        'type' => $dataType,
                        'value' => $tagValue,
                        'path' => $propertyPath . '.' . $tagName,
                      ];
                    }
                  }
                  continue;
                }
                else {
                  if ($dataType === 'map') {
                    /* @var \Drupal\Core\TypedData\MapDataDefinition $mapDataDef . */
                    $mapDataDef = $prop->getDataDefinition();
                    $mapPropDefs = $mapDataDef->getPropertyDefinitions();
                    if (count($mapPropDefs) === 0) {
                      if (count($value) > 0) {
                        // TODO: recurse finding strings.
                        $this->addWarning($warnings, $entity, $value, $dataType, $propName, 'map has no prop defs for values');

                        foreach ($this->flattenProp($value) as $key => $value) {
                          // Map keys are marked in path with '!' to differentiate from property keys.
                          $propertiesOut[] = [
                            'name' => $propName,
                            'label' => (string) $propDef->getLabel(),
                            'type' => $dataType,
                            'value' => $value,
                            'path' => $propertyPath . '!' . $key,
                          ];
                        }
                        continue;
                      }
                    }
                    else {
                      // TODO: get defs.
                      $this->addWarning($warnings, $entity, $value, $dataType, $propName, 'map has defs but are not read');
                    }
                    if (isset($value['attributes'])) {
                      $attributes = $value['attributes'];
                      if (count($attributes) > 0) {
                        if (!(isset($attributes['target']) && ($attributes['target'] === '_blank') || $attributes['target'] === 0)) {
                          $this->addWarning($warnings, $entity, $value, $dataType, $propName, 'strange attributes map');
                          $outValue = $value;
                        }
                      }
                    }
                    else {
                      $this->addWarning($warnings, $entity, $value, $dataType, $propName, 'unknown map type');
                      $outValue = $value;
                    }
                  }
                  else {
                    $this->addWarning($warnings, $entity, $value, $dataType, $propName, 'unknown data type');
                    $outValue = $value;
                  }
                }
                $propertiesOut[] = [
                  'name' => $propName,
                  'label' => (string) $propDef->getLabel(),
                  'type' => $dataType,
                  'value' => $outValue,
                  'path' => $propertyPath,
                ];
              }
            }
          }
        }
        if ($hasItemOut) {
          $itemsOut[] = [
            'position' => $itemNumber,
            'properties' => $propertiesOut,
          ];
        }
      }
      if ($hasFieldOut) {
        $dataDefinition = $field->getDataDefinition();
        $fieldOut = [
          'name' => $fieldName,
          'type' => $dataDefinition->getType(),
          'label' => (string) $dataDefinition->getLabel(),
          'description' => $dataDefinition->getDescription(),
          'items' => $itemsOut,
        ];
        $translationOut['fields'][] = $fieldOut;
      }
    }
    $translationOut['warnings'] = $warnings;

    $references = [];
    $traversableEntityTypes = [
      'paragraph' => TRUE,
    ];
    $traversableContentTypes = [
      'person' => TRUE,
    ];
    foreach ($entity->referencedEntities() as $referencedEntity) {
      $referencedEntityTypeId = $referencedEntity->getEntityTypeId();
      if (isset($traversableEntityTypes[$referencedEntityTypeId]) ||
        ($referencedEntityTypeId === 'node' && isset($traversableContentTypes[$referencedEntity->bundle()]))) {
        $references[] = [
          'entityType' => $referencedEntityTypeId,
          'uuid' => $referencedEntity->uuid(),
        ];
      }
    }
    $translationOut['references'] = $references;

    $translationOut['items'] = $this->flattenExport($translationOut['fields']);
    unset($translationOut['fields']);
    return $translationOut;
  }

  /**
   * Add warning to export.
   */
  public function addWarning(array &$warnings, ContentEntityInterface $entity, $value, $type, $name, $msg) {
    $warnings[] = [
      'uuid' => $entity->uuid(),
      'entityType' => $entity->getEntityTypeId(),
      'bundle' => $entity->bundle(),
      'propertyName' => $name,
      'propertyValue' => $value,
      'type' => $type,
      'description' => $msg,
    ];
  }

  /**
   * Flatten export.
   *
   * @param array $exportFields
   *   Fields to flatten.
   *
   * @return array
   *   Flattened array.
   */
  public function flattenExport(array $exportFields): array {
    $flattened = [];

    foreach ($exportFields as $field) {
      foreach ($field['items'] as $item) {
        foreach ($item['properties'] as $property) {
          $flattened[] = [
            'fieldLabel' => $field['label'],
            'propertyLabel' => $property['label'],
            'propertyType' => $property['type'],
            'path' => $property['path'],
            'value' => $property['value'],
          ];
        }
      }
    }
    return $flattened;
  }

  /**
   * Flatten props.
   */
  public function flattenProp($array, $baseKey = '') {
    $flat = [];

    foreach ($array as $key => $value) {
      $nextKey = $key;
      if (strlen($baseKey) > 0) {
        $nextKey = $baseKey . '.' . $key;
      }
      if (is_array($value)) {
        $flat = array_merge($flat, $this->flattenProp($value, $nextKey));
      }
      else {
        $flat[$nextKey] = $value;
      }
    }
    return $flat;
  }

}
