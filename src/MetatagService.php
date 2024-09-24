<?php

namespace Drupal\argo;

/**
 * Metatag translation utilities.
 */
class MetatagService {

    /**
     * Construct.
     */
    public function __construct() {
    }

    /**
     * @return bool
     *   True if metatag extension version matches V1
     */
    public function isMetatagV1(string $value) {
        return str_starts_with($value, 'a:');
        }

}
