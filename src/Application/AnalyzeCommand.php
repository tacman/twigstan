<?php

declare(strict_types=1);

namespace TwigStan\Application;

use LogicException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Throwable;
use TwigStan\Error\Baseline\PhpBaselineDumper;
use TwigStan\Error\BaselineError;
use TwigStan\Error\BaselineErrorFilter;
use TwigStan\Error\ErrorCollapser;
use TwigStan\Error\ErrorFilter;
use TwigStan\Error\ErrorToSourceFileMapper;
use TwigStan\Error\ErrorTransformer;
use TwigStan\Finder\FilesFinder;
use TwigStan\Finder\GivenFilesFinder;
use TwigStan\PHPStan\Analysis\PHPStanAnalysisResult;
use TwigStan\PHPStan\Collector\TemplateContextCollector;
use TwigStan\Processing\Compilation\CompilationResultCollection;
use TwigStan\Processing\Compilation\TwigCompiler;
use TwigStan\Processing\Flattening\TwigFlattener;
use TwigStan\Processing\ScopeInjection\TwigScopeInjector;
use TwigStan\Twig\DependencyFinder;
use TwigStan\Twig\DependencySorter;
use TwigStan\Twig\SourceLocation;
use TwigStan\Twig\TwigFileCanonicalizer;
use TwigStan\Twig\UnableToCanonicalizeTwigFileException;

#[AsCommand(name: 'analyze', aliases: ['analyse'])]
final class AnalyzeCommand extends Command
{
    public function __construct(
        private TwigCompiler $twigCompiler,
        private TwigFlattener $twigFlattener,
        private TwigScopeInjector $twigScopeInjector,
        private DependencyFinder $dependencyFinder,
        private DependencySorter $dependencySorter,
        private TwigFileCanonicalizer $twigFileCanonicalizer,
        private PHPStanRunner $phpStanRunner,
        private Filesystem $filesystem,
        private FilesFinder $phpFilesFinder,
        private FilesFinder $twigFilesFinder,
        private GivenFilesFinder $givenFilesFinder,
        private ErrorFilter $errorFilter,
        private BaselineErrorFilter $baselineErrorFilter,
        private ErrorCollapser $errorCollapser,
        private ErrorTransformer $errorTransformer,
        private ErrorToSourceFileMapper $errorToSourceFileMapper,
        private string $environmentLoader,
        private string $tempDirectory,
        private string $currentWorkingDirectory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('paths', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Directories or files to analyze');
        $this->addOption('debug', null, InputOption::VALUE_NONE, 'Enable debug mode');
        $this->addOption('xdebug', null, InputOption::VALUE_NONE, 'Enable xdebug mode');
        $this->addOption('generate-baseline', 'b', InputOption::VALUE_OPTIONAL, 'Path to a file where the baseline should be saved', false);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $generateBaselineFile = $input->getOption('generate-baseline');

        if ($generateBaselineFile === null) {
            $generateBaselineFile = 'twigstan-baseline.php';
        } elseif ($generateBaselineFile !== false && Path::getExtension($input->getOption('generate-baseline')) !== 'php') {
            $errorOutput->writeln('<error>Baseline file must have .php extension</error>');

            return self::FAILURE;
        } elseif ($generateBaselineFile === false) {
            $generateBaselineFile = null;
        }

        $result = $this->analyze(
            $input->getArgument('paths'),
            $output,
            $errorOutput,
            $input->getOption('debug') === true,
            $input->getOption('xdebug') === true,
            $generateBaselineFile,
        );

        if ($generateBaselineFile !== null) {
            return self::SUCCESS;
        }

        foreach ($result->errors as $error) {
            $errorOutput->writeln($error->message);

            if ($error->tip !== null) {
                foreach (explode("\n", $error->tip) as $line) {
                    $errorOutput->writeln(sprintf('💡 <fg=blue>%s</>', ltrim($line, ' •')));
                }
            }

            if ($error->identifier !== null) {
                $errorOutput->writeln(sprintf('🔖 <fg=blue>%s</>', $error->identifier));
            }

            if ($error->phpFile !== null && $error->phpLine !== null) {
                $errorOutput->writeln(
                    sprintf(
                        '🐘 <href=%s>%s:%d</>',
                        str_replace(
                            ['%file%', '%line%'],
                            [$error->phpFile, $error->phpLine],
                            'phpstorm://open?file=%file%&line=%line%',
                        ),
                        sprintf(
                            'compiled_%s.php',
                            preg_replace(
                                '/(\.html)?\.twig\.\w+\.php$/',
                                '',
                                basename($error->phpFile),
                            ),
                        ),
                        $error->phpLine,
                    ),
                );
            }

            if ($error->twigSourceLocation !== null) {
                foreach ($error->twigSourceLocation as $sourceLocation) {
                    $errorOutput->writeln(
                        sprintf(
                            '🌱 <href=%s>%s:%d</>',
                            str_replace(
                                ['%file%', '%line%'],
                                [$sourceLocation->fileName, $sourceLocation->lineNumber],
                                'phpstorm://open?file=%file%&line=%line%',
                            ),
                            Path::makeRelative($sourceLocation->fileName, $this->currentWorkingDirectory),
                            $sourceLocation->lineNumber,
                        ),
                    );
                }
            }

            foreach ($error->renderPoints as $renderPoint) {
                $errorOutput->writeln(
                    sprintf(
                        '🕹️ <href=%s>%s:%d</>',
                        str_replace(
                            ['%file%', '%line%'],
                            [$renderPoint->fileName, $renderPoint->lineNumber],
                            'phpstorm://open?file=%file%&line=%line%',
                        ),
                        Path::makeRelative($renderPoint->fileName, $this->currentWorkingDirectory),
                        $renderPoint->lineNumber,
                    ),
                );
            }

            $errorOutput->writeln('');
        }

        if (count($result->errors) > 0) {
            $output->writeln(sprintf('<error>Found %d errors</error>', count($result->errors)));

            return self::FAILURE;
        }

        $output->writeln('No errors found');

        return self::SUCCESS;
    }

    /**
     * @param list<string> $paths
     *
     * @throws Throwable
     */
    public function analyze(
        array $paths,
        OutputInterface $output,
        OutputInterface $errorOutput,
        bool $debugMode,
        bool $xdebugMode,
        ?string $generateBaselineFile,
    ): TwigStanAnalysisResult {
        $output->writeln('TwigStan by Ruud Kamphuis and contributors.');

        $compilationDirectory = Path::normalize($this->tempDirectory . '/compilation');
        $this->filesystem->remove($compilationDirectory);
        $this->filesystem->mkdir($compilationDirectory);

        $flatteningDirectory = Path::normalize($this->tempDirectory . '/flattening');
        $this->filesystem->remove($flatteningDirectory);
        $this->filesystem->mkdir($flatteningDirectory);

        $scopeInjectionDirectory = Path::normalize($this->tempDirectory . '/scope-injection');
        $this->filesystem->remove($scopeInjectionDirectory);
        $this->filesystem->mkdir($scopeInjectionDirectory);

        if ($paths !== []) {
            $files = $this->givenFilesFinder->find($paths);
        } else {
            $files = [...$this->phpFilesFinder->find(), ...$this->twigFilesFinder->find()];
        }

        $twigFileNames = [];
        $phpFileNames = [];
        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $phpFileNames[] = $file->getRealPath();
                continue;
            }

            if ($file->getExtension() === 'twig') {
                $twigFileNames[] = $this->twigFileCanonicalizer->canonicalize($file->getRealPath());
                continue;
            }

            $errorOutput->writeln(sprintf('<error>Unsupported file type: %s</error>', $file->getRealPath()));
        }

        if ($twigFileNames === []) {
            $output->writeln('<error>No templates found</error>');

            return new TwigStanAnalysisResult();
        }

        $twigFileNamesToAnalyze = $twigFileNames;

        $output->writeln(sprintf('Finding dependencies for %d templates...', count($twigFileNamesToAnalyze)));

        // Maybe this should be done using a graph later.
        $dependencies = $this->dependencyFinder->getDependencies($twigFileNames);
        $twigFileNames = array_values(array_unique([...$dependencies, ...$twigFileNames]));
        $twigFileNames = $this->dependencySorter->sortByDependencies($twigFileNames);

        $count = count($twigFileNames);
        $dependencyCount = $count - count($twigFileNamesToAnalyze);
        $output->writeln(sprintf('Found %d %s...', $dependencyCount, $dependencyCount === 1 ? 'dependency' : 'dependencies'));

        $output->writeln(sprintf('Compiling %d templates...', $count));

        $progressBar = new ProgressBar($output, $count);
        $progressBar->start();

        $compilationResults = new CompilationResultCollection();
        foreach ($twigFileNames as $twigFile) {
            try {
                $compilationResults = $compilationResults->with(
                    $this->twigCompiler->compile(
                        $twigFile,
                        $compilationDirectory,
                    ),
                );
            } catch (Throwable $error) {
                $progressBar->clear();
                $errorOutput->writeln(sprintf(
                    'Error compiling %s: %s',
                    Path::makeRelative($twigFile, $this->currentWorkingDirectory),
                    $error->getMessage(),
                ));

                if ($debugMode) {
                    throw $error;
                }
            } finally {
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $progressBar->clear();

        $output->writeln(sprintf('Flattening %d templates...', $count));

        $flatteningResults = $this->twigFlattener->flatten(
            $compilationResults,
            $flatteningDirectory,
        );

        $output->writeln('Collecting scopes...');

        $analysisResult = $this->phpStanRunner->run(
            $output,
            $errorOutput,
            $this->environmentLoader,
            [
                ...$phpFileNames,
                $flatteningDirectory,
            ],
            $debugMode,
            $xdebugMode,
            collectOnly: true,
        );

        $result = new TwigStanAnalysisResult();

        foreach ($analysisResult->notFileSpecificErrors as $fileSpecificError) {
            $result = $result->withFileSpecificError($fileSpecificError);

            $errorOutput->writeln(sprintf('<error>Error</error> %s', $fileSpecificError));
        }

        if ($analysisResult->notFileSpecificErrors !== []) {
            return $result;
        }

        /**
         * @var array<string, array<string, list<int>>> $templateToRenderPoint
         */
        $templateToRenderPoint = [];
        foreach ($analysisResult->collectedData as $data) {
            if (is_a($data->collecterType, TemplateContextCollector::class, true)) {
                foreach ($data->data as $renderData) {
                    try {
                        $filePath = $data->filePath;
                        if ($flatteningResults->hasPhpFile($data->filePath)) {
                            $filePath = $flatteningResults->getByPhpFile($data->filePath)->twigFileName;
                        }
                        $template = $this->twigFileCanonicalizer->canonicalize($renderData['template']);
                        $templateToRenderPoint[$template][$filePath][] = $renderData['startLine'];
                    } catch (UnableToCanonicalizeTwigFileException) {
                        // Ignore
                    }
                }
            }
        }

        // Make sure the render points are sorted by file name and line number
        $templateToRenderPoint = array_map(
            function ($renderPoints) {
                uksort($renderPoints, function ($a, $b) {
                    return strnatcmp($a, $b);
                });
                foreach ($renderPoints as &$lineNumbers) {
                    sort($lineNumbers);
                }

                return $renderPoints;
            },
            $templateToRenderPoint,
        );

        $output->writeln('Injecting scope into templates...');

        $scopeInjectionResults = $this->twigScopeInjector->inject($analysisResult->collectedData, $flatteningResults, $scopeInjectionDirectory);

        $phpFileNamesToAnalyze = array_map(
            fn($twigFileName) => $scopeInjectionResults->getByTwigFileName($twigFileName)->phpFile,
            $twigFileNamesToAnalyze,
        );

        $output->writeln('Analyzing templates');

        $analysisResult = $this->phpStanRunner->run(
            $output,
            $errorOutput,
            $this->environmentLoader,
            $phpFileNamesToAnalyze,
            $debugMode,
            $xdebugMode,
        );

        // Transform PHPStan errors to TwigStan errors
        $errors = $this->errorToSourceFileMapper->map($scopeInjectionResults, $analysisResult->errors);
        $errors = $this->errorFilter->filter($errors);
        $errors = $this->errorCollapser->collapse($errors);
        $errors = $this->errorTransformer->transform($errors);

        if ($generateBaselineFile === null) {
            $errors = $this->baselineErrorFilter->filter($errors);
        }

        $analysisResult = new PHPStanAnalysisResult(
            $errors,
            $analysisResult->collectedData,
            $analysisResult->notFileSpecificErrors,
        );

        if ($generateBaselineFile !== null) {
            $errorsCount = 0;

            /**
             * @var array<string, BaselineError> $baselineErrors
             */
            $baselineErrors = [];
            foreach ($errors as $error) {
                if ( ! $error->canBeIgnored) {
                    continue;
                }

                if ($error->sourceLocation === null) {
                    throw new LogicException('Error without source location should not be present here');
                }

                $errorsCount++;

                $twigFilePath = Path::makeRelative(
                    $compilationResults->getByTwigFileName($error->sourceLocation->fileName)->twigFilePath,
                    $this->currentWorkingDirectory,
                );

                $key = $error->message . "\n" . $error->identifier . "\n" . $twigFilePath;

                if (array_key_exists($key, $baselineErrors)) {
                    $baselineErrors[$key]->increaseCount();

                    continue;
                }

                $baselineErrors[$key] = new BaselineError(
                    $error->message,
                    $error->identifier,
                    $twigFilePath,
                    1,
                );
            }

            $dumper = new PhpBaselineDumper($this->currentWorkingDirectory);

            $this->filesystem->dumpFile(
                $generateBaselineFile,
                $dumper->dump(
                    array_values($baselineErrors),
                    Path::getDirectory($generateBaselineFile),
                ),
            );

            $output->writeln(sprintf('Baseline generated with %d %s in %s.', $errorsCount, $errorsCount === 1 ? 'error' : 'errors', $generateBaselineFile));

            return $result;
        }

        foreach ($analysisResult->notFileSpecificErrors as $fileSpecificError) {
            $result = $result->withFileSpecificError($fileSpecificError);

            $errorOutput->writeln(sprintf('<error>Error</error> %s', $fileSpecificError));
        }

        foreach ($analysisResult->errors as $error) {
            $twigSourceLocation = null;
            $renderPoints = [];
            if ($error->sourceLocation !== null) {
                $lastTwigFileName = null;
                foreach ($error->sourceLocation as $sourceLocation) {
                    $twigFilePath = $compilationResults
                        ->getByTwigFileName($sourceLocation->fileName)
                        ->twigFilePath;

                    $lastTwigFileName = $sourceLocation->fileName;

                    $twigSourceLocation = SourceLocation::append(
                        $twigSourceLocation,
                        new SourceLocation(
                            $twigFilePath,
                            $sourceLocation->lineNumber,
                        ),
                    );
                }

                foreach ($templateToRenderPoint[$lastTwigFileName] ?? [] as $renderPointFileName => $lineNumbers) {
                    foreach ($lineNumbers as $lineNumber) {
                        $renderPoints[] = new SourceLocation(
                            $renderPointFileName,
                            $lineNumber,
                        );
                    }
                }
            }

            $result = $result->withError(
                new TwigStanError(
                    $error->message,
                    $error->identifier,
                    $error->tip,
                    $error->phpFile,
                    $error->phpLine,
                    $twigSourceLocation,
                    $renderPoints,
                ),
            );
        }

        return $result;
    }
}
