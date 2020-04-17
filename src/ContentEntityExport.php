<?php

namespace Drupal\argo;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Export Content Entities.
 */
class ContentEntityExport {

  /**
   *
   */
  public function __construct() {
  }

  /**
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *
   * @return array
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
              $hasFieldOut = TRUE;
              $hasItemOut = TRUE;
              $prop = $fieldItem->get($propName);
              $propertyPath = $prop->getPropertyPath();
              $value = $prop->getValue();
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
                      }
                    }
                    else {
                      // TODO: get defs.
                      $this->addWarning($warnings, $entity, $value, $dataType, $propName, 'map has defs but are not read');
                    }
                    $attributes = $value['attributes'];
                    if (isset($attributes)) {
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
    $traversableTypes = [
      'paragraph' => TRUE,
    ];
    foreach ($entity->referencedEntities() as $referencedEntity) {
      if (isset($traversableTypes[$referencedEntity->getEntityTypeId()])) {
        $references[] = [
          'entityType' => $referencedEntity->getEntityTypeId(),
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
   *
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
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *
   * @return array
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

}
