#!/bin/sh

cd /opt/drupal
vendor/bin/drush cache:rebuild
apache2-foreground
