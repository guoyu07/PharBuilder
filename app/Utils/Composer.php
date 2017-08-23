<?php

namespace MacFJA\PharBuilder\Utils;

/**
 * Class Composer.
 *
 * An helper class to read data from `composer.json` et `composer.lock` files.
 *
 * @package MacFJA\PharBuilder\Utils
 * @author  MacFJA
 * @license MIT
 */
class Composer
{
    /**
     * The path to the `composer.json` file
     *
     * @var string
     */
    protected $composerJsonPath;
    /**
     * Instance cache for the `composer.lock` file
     *
     * @var array|null
     */
    private $lockContentCache;

    /**
     * Constructor of the helper class.
     *
     * @param string $composerFile The path to the `composer.json` file
     */
    public function __construct($composerFile)
    {
        $this->composerJsonPath = $composerFile;
    }

    /**
     * Get the path to the `composer.json` file.
     *
     * @return string
     */
    public function getComposerJsonPath()
    {
        return $this->composerJsonPath;
    }

    /**
     * Get the value for the composer.json
     *
     * @param string $atPath The XPath like path to the value
     *
     * @return mixed|null
     */
    public function getValue($atPath)
    {
        $parts   = explode('/', $atPath);
        $content = file_get_contents($this->composerJsonPath);
        if ($content === false) {
            return null;
        }
        $search = json_decode($content, true);

        foreach ($parts as $part) {
            if (array_key_exists($part, $search)) {
                $search = $search[$part];
            } else {
                return null;
            }
        }
        return $search;
    }

    /**
     * Get all paths (files and directories) of root package sources.
     *
     * @param bool $includeDev Indicate if the dev files must be added.
     *
     * @return array
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    public function getSourcePaths($includeDev = false)
    {
        $content = file_get_contents($this->composerJsonPath);
        if ($content === false) {
            return array();
        }
        $composer = json_decode($content, true);

        $paths = $this->readAutoload($composer['autoload']);

        if ($includeDev && array_key_exists('autoload-dev', $composer)) {
            $paths = array_merge(
                $paths,
                $this->readAutoload($composer['autoload-dev'])
            );
        }

        return $paths;
    }

    /**
     * Read the JSON node (associative array). The node is the content of a autoload(-dev) entry.
     *
     * @param array $jsonNode The autoload(-dev) entry
     *
     * @return array
     */
    protected function readAutoload($jsonNode)
    {
        $dirs  = array();
        $files = array();

        foreach ($jsonNode as $type => $autoload) {
            if (in_array($type, array('psr-4', 'psr-0'), true)) {
                foreach ($autoload as /*$namespace =>*/ $dir) {
                    $dirs[] = $dir;
                }
            } elseif (in_array($type, array('files', 'classmap'), true)) {
                foreach ($autoload as $item) {
                    if (is_dir($item)) {
                        $dirs[] = $item;
                    } elseif (is_file($item)) {
                        $files[] = $item;
                    }
                }
            }
        }
        return array('dirs' => $dirs, 'files' => $files);
    }

    /**
     * Get the list of package name that are only use in require-dev
     *
     * @return array
     *
     * @throws \RuntimeException
     */
    public function getDevOnlyPackageName()
    {
        $lock = $this->getLockFileContent();

        if (!array_key_exists('packages-dev', $lock)) {
            return array();
        }

        $names = array();
        foreach ($lock['packages-dev'] as $package) {
            $names[] = $package['name'];
        }

        return $names;
    }

    /**
     * Get all files required by require-dev that have to be stubbed.
     *
     * @param bool $includeDev Indicate if the dev files must be added.
     *
     * @return array
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    public function getStubFiles($includeDev = false)
    {
        if ($includeDev) {
            return array();
        }

        $lock = $this->getLockFileContent();

        $files = array();

        if (isset($lock['packages-dev'])) {
            foreach ($lock['packages-dev'] as $package) {
                if (isset($package['autoload']['files'])) {
                    $files = array_merge(
                        $files,
                        array_map(
                            /**
                             * Get the full path a file
                             *
                             * @param string $file The file path
                             *
                             * @return string
                             */
                            function ($file) use ($package) {
                                return $package['name'] . '/' . $file;
                            },
                            $package['autoload']['files']
                        )
                    );
                }
            }
        }

        return $files;
    }

    /**
     * Get the JSON (array) content of the `composer.lock` file
     *
     * @return array
     *
     * @throws \RuntimeException
     */
    protected function getLockFileContent()
    {
        if (null === $this->lockContentCache) {
            $lockFile = dirname($this->composerJsonPath) . DIRECTORY_SEPARATOR . 'composer.lock';

            if (!is_file($lockFile)) {
                throw new \RuntimeException(
                    sprintf('The "composer.lock" (%s) does not exist. Please run "composer install".', $lockFile)
                );
            }

            $content = file_get_contents($lockFile);
            if ($content === false) {
                throw new \RuntimeException(
                    sprintf('The "composer.lock" (%s) cannot be read.', $lockFile)
                );
            }
            $this->lockContentCache = json_decode($content, true);
        }

        return $this->lockContentCache;
    }

    /**
     * Get the vendor directory name.
     * By default it's **vendor** but it can be changed in `composer.json` file.
     *
     * @return string
     */
    public function getVendorDir()
    {
        $vendorDir = $this->getValue('config/vendor-dir');
        return $vendorDir !== null?$vendorDir:'vendor';
    }

    /**
     * Remove packages files from the **files** autoloader
     *
     * @param string[] $packageNames List of the packages name
     *
     * @return void
     *
     * @throws \RuntimeException
     */
    public function removeFilesAutoloadFor($packageNames)
    {
        if (0 === count($packageNames)) {
            return;
        }

        $autoloadFile = $this->getVendorDir() . DIRECTORY_SEPARATOR .
            'composer' . DIRECTORY_SEPARATOR . 'autoload_files.php';
        $content      = file_get_contents($autoloadFile);

        foreach ($packageNames as $name) {
            // Search for "$vendor . '/PACKAGE_NAME/"
            $pattern = '/^.+\$vendorDir\s*\.\s*\'\/%s\/.+$/mU';
            $content = preg_replace(sprintf($pattern, preg_quote($name, '/')), '', (string) $content);

            if (null == $content) {
                throw new \RuntimeException(sprintf('An error occur when removing "%s" from files autoloader', $name));
            }
        }

        file_put_contents($autoloadFile, $content);
    }

    /**
     * Get the phar-builder config data from the composer.json
     *
     * @return array
     */
    public function getComposerConfig()
    {
        /*
         * Read the composer.json file.
         * All information we need is store in it.
         */
        $extra = $this->getValue('extra/phar-builder');
        return $extra !== null?$extra:array();
    }
}
