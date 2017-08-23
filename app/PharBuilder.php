<?php


namespace MacFJA\PharBuilder;

use MacFJA\PharBuilder\Utils\Composer;
use MacFJA\PharBuilder\Utils\Config;
use Rych\ByteSize\ByteSize;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;
use webignition\ReadableDuration\ReadableDuration;

/**
 * Class PharBuilder
 * This class create the Phar file of a specific composer based project
 *
 * @package MacFJA\PharBuilder
 * @author  MacFJA
 * @license MIT
 */
class PharBuilder
{
    /**
     * The configurations holder
     *
     * @var Config
     */
    protected $config;

    /**
     * The Phar object
     *
     * @var \Phar
     */
    protected $phar = null;
    /**
     * The path of the entry point of the application
     *
     * @var string
     */
    protected $stubFile = '';
    /**
     * The Symfony Style Input/Output
     *
     * @var SymfonyStyle
     */
    protected $ioStyle;
    /**
     * The composer file reader
     *
     * @var Composer
     */
    protected $composerReader = null;

    /**
     * List of equivalence for compression
     *
     * @var array
     */
    private $compressionList = array(
        Config\Compression::NO => \Phar::NONE,
        Config\Compression::GZIP => \Phar::GZ,
        Config\Compression::BZIP2 => \Phar::BZ2,
    );

    /**
     * Get the path of the generated PHAR
     *
     * @return string
     */
    public function getPharPath()
    {
        return $this->getOutputDir().DIRECTORY_SEPARATOR.$this->getPharName();
    }

    /**
     * Get the name of the PHAR
     *
     * @return string
     */
    public function getPharName()
    {
        return $this->config->getValue('name');
    }

    /**
     * Get the directory path where the PHAR will be built
     *
     * @return string
     */
    public function getOutputDir()
    {
        return rtrim($this->config->getValue('output-dir'), DIRECTORY_SEPARATOR);
    }



    /**
     * Get the path of the stub (entry-point)
     *
     * @return string
     */
    public function getStubFile()
    {
        return $this->config->getValue('entry-point');
    }


    /**
     * Get the compression name.
     * The compression type (see `$compressionList`)
     *
     * @return string
     */
    public function getCompression()
    {
        return array_search($this->config->getValue('compression'), $this->compressionList, true);
    }


    /**
     * Get the list of path that will be included
     *
     * @return \string[]
     */
    public function getIncludes()
    {
        return $this->config->getValue('includes');
    }


    /**
     * Indicate if dev source/package must be added to the PHAR
     *
     * @return boolean
     */
    public function isKeepDev()
    {
        return $this->config->getValue('include-dev');
    }


    /**
     * Set the path the composer.json file
     *
     * @param string $composer The path the composer.json file
     *
     * @return void
     */
    public function setComposer($composer)
    {
        $this->composerReader = new Composer($composer);
    }

    /**
     * Get the composer reader object
     *
     * @return Composer
     */
    public function getComposerReader()
    {
        return $this->composerReader;
    }

    /**
     * Indicates whether the shebang should be skipped or not.
     *
     * @return bool
     */
    public function isSkipShebang()
    {
        return $this->config->getValue('shebang');
    }

    /**
     * The class constructor
     *
     * @param SymfonyStyle $ioStyle The Symfony Style Input/Output
     * @param Config       $config  The configurations holder
     */
    public function __construct(SymfonyStyle $ioStyle, Config $config)
    {
        $this->ioStyle = $ioStyle;
        $this->config  = $config;
    }

    /**
     * The main function.
     * This function create the Phar file and add all file in it
     *
     * @return void
     *
     * @throws \BadMethodCallException
     * @throws \UnexpectedValueException
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function buildPhar()
    {
        $startTime = time();

        $this->ioStyle->title('Creating your Phar application...');

        $this->ioStyle->section('Reading composer.json...');
        $composerInfo = $this->readComposerAutoload();
        $this->ioStyle->success('composer.json analysed');

        // Unlink, otherwise we just add things to the already existing phar
        if (file_exists($this->getPharPath())) {
            unlink($this->getPharPath());
        }

        chdir(dirname($this->composerReader->getComposerJsonPath()));

        $this->phar = new \Phar(
            $this->getPharPath(),
            \FilesystemIterator::CURRENT_AS_FILEINFO | \FilesystemIterator::KEY_AS_FILENAME,
            $this->getPharName()
        );
        $this->phar->startBuffering();

        $this->stubFile = $this->makePathRelative($this->getStubFile());
        $this->phar->setStub(
            (!$this->isSkipShebang() ? '#!/usr/bin/env php' . PHP_EOL : '') .
            '<?php Phar::mapPhar(); include "phar://' . $this->getPharName() . '/' . $this->stubFile .
            '"; __HALT_COMPILER(); ?>'
        );

        //Adding files to the archive
        $this->ioStyle->section('Adding files to Phar...');

        // Add all project file (based on composer declaration)
        foreach ($composerInfo['dirs'] as $dir) {
            $this->addDir($dir);
        }
        foreach ($composerInfo['files'] as $file) {
            $this->addFile($file);
        }
        foreach ($composerInfo['stubs'] as $file) {
            $this->addFakeFile($file);
        }
        // Add included directories
        foreach ($this->getIncludes() as $dir) {
            $this->addDir($dir);
        }
        // Add the composer vendor dir
        $this->composerReader->removeFilesAutoloadFor($composerInfo['excludes']);
        $this->addDir($composerInfo['vendor'], $composerInfo['excludes']);
        $this->addFile('composer.json');
        $this->addFile('composer.lock');
        $this->addStub();

        $this->ioStyle->success('All files added');

        $this->phar->stopBuffering();

        $endTime = time();
        $size    = new ByteSize();

        $this->ioStyle->success(array(
            'Phar creation successful',
            'File size: ' . $size->formatBinary(filesize($this->getPharPath())?:0) . PHP_EOL .
            'Process duration: ' . $this->buildDuration($startTime, $endTime)
        ));
    }

    /**
     * Calculate and build a readable duration
     *
     * @param int $start start timestamp
     * @param int $end   end timestamp
     *
     * @return string
     *
     * @psalm-suppress InvalidArgument -- ReadableDuration constructor badly typed
     */
    protected function buildDuration($start, $end)
    {
        $duration = new ReadableDuration($end - $start);
        $data     = $duration->getInMostAppropriateUnits(2);
        $result   = array();
        foreach ($data as $unit) {
            $result[] = $unit['value'] . ' ' . $unit['unit'] . ($unit['value'] > 1 ? 's' : '');
        }
        return implode(', ', $result);
    }

    /**
     * Add a directory in the Phar file
     *
     * @param string $directory The relative (to composer.json file) path of the directory
     * @param array  $excludes  List of path to exclude
     *
     * @return void
     *
     * @throws \InvalidArgumentException
     */
    protected function addDir($directory, $excludes = array())
    {
        foreach ($excludes as &$exclude) {
            $exclude = str_replace(realpath($directory) . DIRECTORY_SEPARATOR, '', $exclude);
            $exclude = rtrim($exclude, DIRECTORY_SEPARATOR);
        }

        $directory = rtrim($directory, DIRECTORY_SEPARATOR);
        $files     = Finder::create()->files()
            ->ignoreVCS(true)//Remove VCS
            ->ignoreDotFiles(true)//Remove system hidden file
            ->notName('composer.*')//Remove composer configuration
            ->notName('*~')//Remove backup file
            ->notName('*.back')//Remove backup file
            ->notName('*.swp')//Remove backup file
            ->notName('*Spec.php')//Remove PhpSpec test file
            ->notPath('/(^|\/)doc(s)?\//i')//Remove documentation
            ->notPath('/.*phpunit\/.*/')//Remove Unit test
            ->notPath('/(^|\/)test(s)?\/.*/i')//Remove Unit test
            ->exclude($excludes)
            ->in($directory);
        foreach ($files as $file) {
            /**
             * The found file
             *
             * @var \Symfony\Component\Finder\SplFileInfo $file
             */
            $this->addFile($directory . DIRECTORY_SEPARATOR . $file->getRelativePathname());
        }
    }

    /**
     * Add stubfile to the Phar and remove the shebang if present
     *
     * @return void
     */
    protected function addStub()
    {
        $this->ioStyle->write("\r\033[2K" . ' > ' . $this->stubFile);

        $stub = file_get_contents($this->stubFile);

        // Remove shebang if present
        $shebang = "~^#!/(.*)\n~";
        $stub    = preg_replace($shebang, '', $stub?:'');

        $this->phar->addFromString($this->stubFile, $stub);
        $this->compressFile($this->stubFile);
    }

    /**
     * Ensure that $path is a relative path
     *
     * @param string $path The path to test and correct
     *
     * @return string
     */
    protected function makePathRelative($path)
    {
        if (0 === strpos($path, getcwd()?:'./')) {
            $path = substr($path, strlen(getcwd()?:'./'));
            $path = ltrim($path, DIRECTORY_SEPARATOR);
        }

        return $path;
    }

    /**
     * Add a file to the Phar
     *
     * @param string $filePath The path MUST be relative to the composer.json parent directory
     *
     * @return void
     */
    protected function addFile($filePath)
    {
        $this->ioStyle->write("\r\033[2K" . ' > ' . $filePath);

        //Add the file
        $this->phar->addFile($filePath);
        // Compress the file (see the reason of one file compressing just after)
        $this->compressFile($filePath);
    }

    /**
     * Add a fake file (stub) to the Phar
     *
     * @param string $filePath The path MUST be relative to the composer.json parent directory
     *
     * @return void
     */
    protected function addFakeFile($filePath)
    {
        $filePath = str_replace('/./', '/', $filePath);

        $this->ioStyle->write("\r\033[2K" . ' > ' . $filePath);

        //Add the file
        $this->phar->addFromString($filePath, '');
    }

    /**
     * Compress a given file (if compression is enabled and the file type is _compressible_)
     *
     * Note: The compression is made file by file because Phar have a bug with compressing the whole archive.
     * The problem is (if I understand correctly) a C implementation issue cause by temporary file resource
     * be opened but not closed (until the end of the compression).
     * This walk around reduce the performance of the Phar creation
     * (compared to the whole Phar compression(that can be done on small application))
     *
     * @param string $file The path in the Phar
     *
     * @return void
     */
    protected function compressFile($file)
    {
        // Check is compression is enable, if it's not the case stop right away, don't need to go further
        if (!in_array($this->getCompression(), array(\Phar::BZ2, \Phar::GZ), true)) {
            return;
        }
        // Some frequent text based file extension that can be compressed in a good rate
        $toCompressExtension = array(
            '.php', '.txt', '.md', '.xml', '.js', '.css', '.less', '.scss', '.json', '.html', '.rst', '.svg'
        );
        $canCompress         = false;
        foreach ($toCompressExtension as $extension) {
            if (substr($file, -strlen($extension)) === $extension) {
                $canCompress = true;
            }
        }
        if (!$canCompress) {
            return;
        }

        $this->ioStyle->write('...');
        $this->phar[$file]->compress($this->getCompression());
        $this->ioStyle->write(' <info>compressed</info>');
    }

    /**
     * Read composer's files
     *
     * The result array format is:
     *   - ["dirs"]: array, List of directories to include (project source)
     *   - ["files"]: array, List of files to include (project source)
     *   - ["vendor"]: string, Path to the composer vendor directory
     *   - ["exclude"]: List package name to exclude
     *   - ["stubs"]: List of files that have to be stubbed
     *
     * @return array list of relative path
     *
     * @throws \RuntimeException
     */
    protected function readComposerAutoload()
    {
        $paths = $this->composerReader->getSourcePaths($this->isKeepDev());

        $paths['excludes'] = array();
        $paths['vendor']   = $this->composerReader->getVendorDir();

        if (!$this->isKeepDev()) {
            $paths['excludes'] = $this->composerReader->getDevOnlyPackageName();
        }

        $paths['stubs'] = array_map(
            /**
             * Get the full path of a file
             *
             * @param string $file The file path
             *
             * @return string
             */
            function ($file) use ($paths) {
                return $paths['vendor'] . '/' . $file;
            },
            $this->composerReader->getStubFiles()
        );

        return $paths;
    }
}
