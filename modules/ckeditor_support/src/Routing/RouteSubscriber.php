<?php
namespace Drupal\ckeditor_support\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

class RouteSubscriber extends RouteSubscriberBase{

  protected function alterRoutes(RouteCollection $collection) {
    if ($route = $collection->get('entity_embed.dialog')) {
      $route->setDefault('_form', '\Drupal\ckeditor_support\Form\EntityEmbedDialog');
    }
  }
}
