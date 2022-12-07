<?php

namespace Drupal\argo;

use Drupal\link\Plugin\Field\FieldWidget\LinkWidget;

/**
 * Exposes protected LinkWidget functions needed to handle special Drupal URL schemes.
 */
class UriWrapper extends LinkWidget {

  /**
   * Convert display URI to Drupal format.
   *
   * @param string $uri
   *   URI.
   *
   * @return string
   *   Drupal formatted URI.
   */
  public static function getUri($uri) {
    return parent::getUserEnteredStringAsUri($uri);
  }

  /**
   * Convert Drupal URI to display format.
   *
   * @param string $uri
   *   URI.
   *
   * @return string
   *   Display formatted URI.
   */
  public static function getDisplayUri($uri) {
    return parent::getUriAsDisplayableString($uri);
  }

}
