<?php

namespace Drupal\family_history_migrate\Plugin\migrate\source;

use Drupal\migrate\Exception\RequirementsException;
use Drupal\migrate\Plugin\migrate\source\SourcePluginBase;
use Drupal\migrate\Plugin\MigrationInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Migrate from the filesystem.
 *
 * Options:
 * - *path* (string): Search path.
 * - *path_env* (string): Environment variable to use for `path`.
 * - *include* (string|array): Filenames to include. May be globs, strings,
 *   regexes or an array of globs, strings or regexes.  Defaults to all files.
 * - *exclude* (string|array): Filenames to exclude. May be globs, strings,
 *   regexes or an array of globs, strings or regexes.
 * - *depth* (string|array): Set the depth of the search. Basic expressions are
 *   supported, e.g. `== 0` to disable recursion completely, `< 3` to search
 *   at most 3 levels deep.
 *
 * @MigrateSource(
 *   id = "family_history_migrate_filesystem",
 *   source_module = "family_history_migrate"
 * )
 */
class Filesystem extends SourcePluginBase {

  protected const CONFIG_PATH = 'path';

  protected const CONFIG_PATH_ENV = 'path_env';

  protected const CONFIG_INCLUDE = 'include';

  protected const CONFIG_EXCLUDE = 'exclude';

  protected const CONFIG_DEPTH = 'depth';

  protected const FIELD_PARENT_PATH = 'parent_path';

  protected const FIELD_PATH = 'path';

  protected const FIELD_ABSOLUTE_PATH = 'absolute_path';

  protected const FIELD_NAME = 'name';

  protected const FIELD_BASENAME = 'basename';

  protected const FIELD_EXTENSION = 'extension';

  protected const FIELD_SIZE = 'size';

  protected const FIELD_TIME_CREATED = 'time_created';

  protected const FIELD_TIME_MODIFIED = 'time_modified';

  protected string $searchPath;

  /**
   * Filesystem constructor.
   *
   * @param array $configuration
   * @param string $plugin_id
   * @param mixed $plugin_definition
   * @param \Drupal\migrate\Plugin\MigrationInterface $migration
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration);
    $this->searchPath = $this->getSearchPath();
  }


  /**
   * {@inheritdoc}
   */
  public function __toString() {
    return 'Migrate from filesystem';
  }

  /**
   * {@inheritdoc}
   */
  protected function initializeIterator() {
    $finder = $this->getFinder()->files();

    // Highwater
    if ($this->highWaterProperty) {
      // When using highwater, the results MUST be sorted by that property.
      // However, because that can take a long time, only sort if requested.
      $finder->sort($this->getFieldSorter($this->highWaterProperty['name']));
    }

    foreach ($finder as $fileInfo) {
      $parentPath = dirname($fileInfo->getRelativePathname());
      yield [
        self::FIELD_PARENT_PATH => $parentPath === '.' ? NULL : $parentPath,
        self::FIELD_PATH => $fileInfo->getRelativePathname(),
        self::FIELD_ABSOLUTE_PATH => $fileInfo->getPathname(),
        self::FIELD_NAME => $fileInfo->getFilename(),
        self::FIELD_BASENAME => $fileInfo->getFilenameWithoutExtension(),
        self::FIELD_EXTENSION => $fileInfo->getExtension(),
        self::FIELD_SIZE => $fileInfo->getSize(),
        self::FIELD_TIME_CREATED => $fileInfo->getCTime(),
        self::FIELD_TIME_MODIFIED => $fileInfo->getMTime(),
      ];
    }
  }

  /**
   * Get a comparison function for the given field
   *
   * @param string $field One of the FIELD_* constants.
   *
   * @return callable
   */
  protected function getFieldSorter(string $field): callable {
    switch ($field) {
      case self::FIELD_PARENT_PATH:
        return function (SplFileInfo $a, SplFileInfo $b) {
          if ($a->getRelativePathname() === DIRECTORY_SEPARATOR) {
            return PHP_INT_MIN;
          }
          elseif ($b->getRelativePathname() === DIRECTORY_SEPARATOR) {
            return PHP_INT_MAX;
          }
          return strcmp(dirname($a->getRelativePathname()), dirname($b->getRelativePathname()));
        };
      case self::FIELD_PATH:
        return function (SplFileInfo $a, SplFileInfo $b) {
          return strcmp($a->getRelativePathname(), $b->getRelativePathname());
        };
      case self::FIELD_ABSOLUTE_PATH:
        return function (SplFileInfo $a, SplFileInfo $b) {
          return strcmp($a->getPathname(), $b->getPathname());
        };
      case self::FIELD_NAME:
        return function (SplFileInfo $a, SplFileInfo $b) {
          return strcmp($a->getFilename(), $b->getFilename());
        };
      case self::FIELD_BASENAME:
        return function (SplFileInfo $a, SplFileInfo $b) {
          return strcmp($a->getFilenameWithoutExtension(), $b->getFilenameWithoutExtension());
        };
      case self::FIELD_EXTENSION:
        return function (SplFileInfo $a, SplFileInfo $b) {
          return strcmp($a->getExtension(), $b->getExtension());
        };
      case self::FIELD_SIZE:
        return function (SplFileInfo $a, SplFileInfo $b) {
          return $a->getSize() - $b->getSize();
        };
      case self::FIELD_TIME_CREATED:
        return function (SplFileInfo $a, SplFileInfo $b) {
          return $a->getCTime() - $b->getCTime();
        };
      case self::FIELD_TIME_MODIFIED:
        return function (SplFileInfo $a, SplFileInfo $b) {
          return $a->getMTime() - $b->getMTime();
        };
    }
    throw new \LogicException("Cannot sort by field " . $field);
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      self::FIELD_PARENT_PATH => $this->t('Parent path, if it exists, or NULL'),
      self::FIELD_PATH => $this->t('File path, relative to base'),
      self::FIELD_ABSOLUTE_PATH => $this->t('File path, absolute'),
      self::FIELD_NAME => $this->t('File name, including extension'),
      self::FIELD_BASENAME => $this->t('File name, without extension'),
      self::FIELD_EXTENSION => $this->t('File extension'),
      self::FIELD_SIZE => $this->t('File size, in bytes'),
      self::FIELD_TIME_CREATED => $this->t('File creation UNIX timestamp'),
      self::FIELD_TIME_MODIFIED => $this->t('File modified UNIX timestamp'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      self::FIELD_PATH => [
        'type' => 'string',
        'max_length' => 255,
        'is_ascii' => FALSE,
      ],
    ];
  }

  /**
   * Initialize the finder using the migration config
   *
   * @return \Symfony\Component\Finder\Finder
   * @throws \Drupal\migrate\MigrateException
   */
  protected function getFinder(): Finder {
    $finder = new Finder();
    $finder->followLinks();

    // Path
    $finder->in($this->searchPath);

    // Filter
    if (isset($this->configuration[self::CONFIG_INCLUDE])) {
      $finder->name($this->configuration[self::CONFIG_INCLUDE]);
    }
    if (isset($this->configuration[self::CONFIG_EXCLUDE])) {
      $finder->notName($this->configuration[self::CONFIG_EXCLUDE]);
    }

    // Depth
    if (isset($this->configuration[self::CONFIG_DEPTH])) {
      $finder->depth($this->configuration[self::CONFIG_DEPTH]);
    }
    return $finder;
  }

  /**
   * Get the search path.
   *
   * @return string
   */
  protected function getSearchPath(): string {
    if (isset($this->configuration[self::CONFIG_PATH_ENV])) {
      $path = getenv($this->configuration[self::CONFIG_PATH_ENV]);
      if (empty($path)) {
        throw new RequirementsException(
          'The environment variable '
          . $this->configuration[self::CONFIG_PATH_ENV] . ' is empty.');
      }
      return $path;
    }
    elseif (isset($this->configuration[self::CONFIG_PATH])) {
      return $this->configuration[self::CONFIG_PATH];
    }
    else {
      throw new RequirementsException(
        'One of ' . self::CONFIG_PATH . ' or ' . self::CONFIG_PATH_ENV
        . ' must be set in the source configuration.');
    }
  }

}
