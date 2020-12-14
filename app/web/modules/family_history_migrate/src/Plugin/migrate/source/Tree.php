<?php

namespace Drupal\family_history_migrate\Plugin\migrate\source;

use Symfony\Component\Finder\Finder;

/**
 * Migrate the tree structure
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
 *   id = "family_history_migrate_tree",
 *   source_module = "family_history_migrate"
 * )
 */
class Tree extends Filesystem {

  private const FIELD_DESCRIPTION = 'description';

  private const FIELD_MEDIA = 'media';

  private const FIELD_WEIGHT = 'weight';

  /** The contents of this file are used as the description */
  private const FILENAME_DESCRIPTION = '(0).docx';

  private const DOCS_PATTERNS = ['*.docx', '*.txt', '*.rtf'];

  private const PANDOC_FILE_EXTENSIONS = ['docx', 'rtf'];


  /**
   * {@inheritdoc}
   */
  public function __toString() {
    return 'Migrate tree structure';
  }

  /**
   * {@inheritdoc}
   */
  protected function initializeIterator() {
    $finder = $this->getFinder()->directories();

    // Highwater
    if ($this->highWaterProperty) {
      // When using highwater, the results MUST be sorted by that property.
      // However, because that can take a long time, only sort if requested.
      $finder->sort($this->getFieldSorter($this->highWaterProperty['name']));
    }

    foreach ($finder as $fileInfo) {
      // Ignore empty directories
      if ($fileInfo->isDir()
        && empty(array_diff(scandir($fileInfo->getPathname()), ['..', '.']))) {
        continue;
      }

      // Check if the directory has the description file inside, called (0).docx
      // by convention
      $descriptionFilePath = $fileInfo->getPathname() . DIRECTORY_SEPARATOR . self::FILENAME_DESCRIPTION;
      if (file_exists($descriptionFilePath)) {
        // Read the file
        $ret = 0;
        exec('pandoc ' . escapeshellarg($descriptionFilePath) . ' -t html5',
          $description, $ret);
        $description = trim(implode("\n", $description));
        if ($ret) {
          trigger_error("Error running pandoc on '$descriptionFilePath' ($ret):\n$description", E_USER_WARNING);
          $description = '';
        }
      }
      else {
        $description = '';
      }

      // Search for contained media
      $mediaFinder = new Finder();
      $mediaFinder->files()
        ->in($fileInfo->getPathname())
        ->depth(0)
        ->notName(array_merge([
          self::FILENAME_DESCRIPTION,
          'Thumbs.db',
        ], self::DOCS_PATTERNS));
      $media = [];
      foreach ($mediaFinder as $mediaFileInfo) {
        $media[] = [
          'path' => substr($mediaFileInfo->getPathname(), strlen($this->searchPath)),
          'extension' => strtolower($mediaFileInfo->getExtension()),
        ];
      }

      // Parent path
      $parentPath = dirname($fileInfo->getRelativePathname());

      // Place all entries with defined weights at the beginning of the list.
      // -10000 is the lowest weight supported.  This doesn't appear to be
      // documented anywhere. When https://www.drupal.org/project/drupal/issues/3187353
      // is fixed this can be removed.
      if (preg_match('`^\((\d+)\)`', $fileInfo->getFilename(), $matches)) {
        $weight = -10000 + (int) $matches[1];
      }
      else {
        $weight = NULL;
      }

      $result = [
        self::FIELD_PARENT_PATH => $parentPath === '.' ? NULL : $parentPath,
        self::FIELD_PATH => $fileInfo->getRelativePathname(),
        self::FIELD_ABSOLUTE_PATH => $fileInfo->getPathname(),
        self::FIELD_NAME => $fileInfo->getFilenameWithoutExtension(),
        self::FIELD_TIME_CREATED => $fileInfo->getCTime(),
        self::FIELD_TIME_MODIFIED => $fileInfo->getMTime(),
        self::FIELD_DESCRIPTION => $description,
        self::FIELD_MEDIA => $media,
        self::FIELD_WEIGHT => $weight,
      ];
      yield $result;
    }

    // A second finder for the text documents included
    $docsFinder = new Finder();
    $docsFinder->files()
      ->in($this->getSearchPath())
      ->name(self::DOCS_PATTERNS);
    if (isset($this->configuration[self::CONFIG_DEPTH])) {
      $docsFinder->depth($this->configuration[self::CONFIG_DEPTH]);
    }
    if ($this->highWaterProperty) {
      // When using highwater, the results MUST be sorted by that property.
      // However, because that can take a long time, only sort if requested.
      $docsFinder->sort($this->getFieldSorter($this->highWaterProperty['name']));
    }

    foreach ($docsFinder as $fileInfo) {
      if ($fileInfo->getFilename() === self::FILENAME_DESCRIPTION) {
        continue;
      }
      if (in_array($fileInfo->getExtension(), self::PANDOC_FILE_EXTENSIONS)) {
        // Get the content using pandoc
        $filePath = $fileInfo->getPathname();
        $ret = 0;
        exec('pandoc ' . escapeshellarg($filePath) . ' -t html5',
          $content, $ret);
        $content = trim(implode("\n", $content));
        if ($ret) {
          trigger_error("Error running pandoc on '$filePath' ($ret):\n$content", E_USER_WARNING);
          $content = $this->t('Data import error!  File corrupted.');
        }
      }
      else {
        $content = $fileInfo->getContents();
      }
      $parentPath = dirname($fileInfo->getRelativePathname());
      $result = [
        self::FIELD_PARENT_PATH => $parentPath === '.' ? NULL : $parentPath,
        self::FIELD_PATH => $fileInfo->getRelativePathname(),
        self::FIELD_ABSOLUTE_PATH => $fileInfo->getPathname(),
        self::FIELD_NAME => $fileInfo->getFilenameWithoutExtension(),
        self::FIELD_TIME_CREATED => $fileInfo->getCTime(),
        self::FIELD_TIME_MODIFIED => $fileInfo->getMTime(),
        self::FIELD_DESCRIPTION => $content,
        self::FIELD_MEDIA => NULL,
        self::FIELD_WEIGHT => NULL,
      ];
      yield $result;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      self::FIELD_PARENT_PATH => $this->t('Parent path, if it exists, or NULL'),
      self::FIELD_PATH => $this->t('Directory path, relative to base'),
      self::FIELD_ABSOLUTE_PATH => $this->t('Directory path, absolute'),
      self::FIELD_NAME => $this->t('Directory name'),
      self::FIELD_TIME_CREATED => $this->t('Creation UNIX timestamp'),
      self::FIELD_TIME_MODIFIED => $this->t('Modified UNIX timestamp'),
      self::FIELD_DESCRIPTION => $this->t('Description'),
      self::FIELD_MEDIA => $this->t('Media'),
      self::FIELD_WEIGHT => $this->t('Display weight, or null if not defined in the filesystem'),
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

}
