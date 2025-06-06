<?php

declare(strict_types=1);

/*
 * This file is part of Cecil.
 *
 * Copyright (c) Arnaud Ligny <arnaud@ligny.fr>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cecil\Command;

use Cecil\Exception\RuntimeException;
use Cecil\Util;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Yosymfony\ResourceWatcher\Crc32ContentHash;
use Yosymfony\ResourceWatcher\ResourceCacheMemory;
use Yosymfony\ResourceWatcher\ResourceWatcher;

/**
 * Starts the built-in server.
 */
class Serve extends AbstractCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('serve')
            ->setDescription('Starts the built-in server')
            ->setDefinition([
                new InputArgument('path', InputArgument::OPTIONAL, 'Use the given path as working directory'),
                new InputOption('config', 'c', InputOption::VALUE_REQUIRED, 'Set the path to extra config files (comma-separated)'),
                new InputOption('drafts', 'd', InputOption::VALUE_NONE, 'Include drafts'),
                new InputOption('page', 'p', InputOption::VALUE_REQUIRED, 'Build a specific page'),
                new InputOption('open', 'o', InputOption::VALUE_NONE, 'Open web browser automatically'),
                new InputOption('host', null, InputOption::VALUE_REQUIRED, 'Server host'),
                new InputOption('port', null, InputOption::VALUE_REQUIRED, 'Server port'),
                new InputOption('optimize', null, InputOption::VALUE_OPTIONAL, 'Optimize files (disable with "no")', false),
                new InputOption('clear-cache', null, InputOption::VALUE_OPTIONAL, 'Clear cache before build (optional cache key regular expression)', false),
                new InputOption('no-ignore-vcs', null, InputOption::VALUE_NONE, 'Changes watcher must not ignore VCS directories'),
                new InputOption('timeout', null, InputOption::VALUE_OPTIONAL, 'Sets the process timeout (max. runtime) in seconds', 3600 * 2),
            ])
            ->setHelp(
                <<<'EOF'
The <info>%command.name%</> command starts the live-reloading-built-in web server.

To start the server, run:

  <info>%command.full_name%</>

To start the server from a specific directory, run:

  <info>%command.full_name% path/to/directory</>

To start the server with a specific configuration file, run:

  <info>%command.full_name% --config=config.yml</>

To start the server and open web browser automatically, run:

  <info>%command.full_name% --open</>

To start the server with a specific host, run:

  <info>%command.full_name% --host=127.0.0.1</>

To start the server with a specific port, run:

  <info>%command.full_name% --port=8080</>

To start the server with changes watcher not ignoring VCS directories, run:

  <info>%command.full_name% --no-ignore-vcs</>

To define the process timeout (in seconds), run:

  <info>%command.full_name% --timeout=3600</>
EOF
            );
    }

    /**
     * {@inheritdoc}
     *
     * @throws RuntimeException
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $drafts = $input->getOption('drafts');
        $open = $input->getOption('open');
        $host = $input->getOption('host') ?? 'localhost';
        $port = $input->getOption('port') ?? '8000';
        $optimize = $input->getOption('optimize');
        $clearcache = $input->getOption('clear-cache');
        $verbose = $input->getOption('verbose');
        $page = $input->getOption('page');
        $noignorevcs = $input->getOption('no-ignore-vcs');
        $timeout = $input->getOption('timeout');

        $this->setUpServer($host, $port);

        $phpFinder = new PhpExecutableFinder();
        $php = $phpFinder->find();
        if ($php === false) {
            throw new RuntimeException('Can\'t find a local PHP executable.');
        }

        $command = \sprintf(
            '"%s" -S %s:%d -t "%s" "%s"',
            $php,
            $host,
            $port,
            Util::joinFile($this->getPath(), (string) $this->getBuilder()->getConfig()->get('output.dir')),
            Util::joinFile($this->getPath(), self::TMP_DIR, 'router.php')
        );
        $process = Process::fromShellCommandline($command);

        $buildProcessArguments = [
            $php,
            $_SERVER['argv'][0],
        ];
        $buildProcessArguments[] = 'build';
        $buildProcessArguments[] = $this->getPath();
        if (!empty($this->getConfigFiles())) {
            $buildProcessArguments[] = '--config';
            $buildProcessArguments[] = implode(',', $this->getConfigFiles());
        }
        if ($drafts) {
            $buildProcessArguments[] = '--drafts';
        }
        if ($optimize === null) {
            $buildProcessArguments[] = '--optimize';
        }
        if (!empty($optimize)) {
            $buildProcessArguments[] = '--optimize';
            $buildProcessArguments[] = $optimize;
        }
        if ($clearcache === null) {
            $buildProcessArguments[] = '--clear-cache';
        }
        if (!empty($clearcache)) {
            $buildProcessArguments[] = '--clear-cache';
            $buildProcessArguments[] = $clearcache;
        }
        if ($verbose) {
            $buildProcessArguments[] = '-' . str_repeat('v', $_SERVER['SHELL_VERBOSITY']);
        }
        if (!empty($page)) {
            $buildProcessArguments[] = '--page';
            $buildProcessArguments[] = $page;
        }

        $buildProcess = new Process(
            $buildProcessArguments,
            null,
            ['BOX_REQUIREMENT_CHECKER' => '0'] // prevents double check (build then serve)
        );

        $buildProcess->setTty(Process::isTtySupported());
        $buildProcess->setPty(Process::isPtySupported());
        $buildProcess->setTimeout($timeout);

        $processOutputCallback = function ($type, $buffer) use ($output) {
            $output->write($buffer, false, OutputInterface::OUTPUT_RAW);
        };

        // (re)builds before serve
        $output->writeln(\sprintf('<comment>Build process: %s</comment>', implode(' ', $buildProcessArguments)), OutputInterface::VERBOSITY_DEBUG);
        $buildProcess->run($processOutputCallback);
        if ($buildProcess->isSuccessful()) {
            $this->buildSuccess($output);
        }
        if ($buildProcess->getExitCode() !== 0) {
            return 1;
        }

        // handles process
        if (!$process->isStarted()) {
            // set resource watcher
            $finder = new Finder();
            $finder->files()
                ->in($this->getPath())
                ->exclude((string) $this->getBuilder()->getConfig()->get('output.dir'));
            if (file_exists(Util::joinFile($this->getPath(), '.gitignore')) && $noignorevcs === false) {
                $finder->ignoreVCSIgnored(true);
            }
            $hashContent = new Crc32ContentHash();
            $resourceCache = new ResourceCacheMemory();
            $resourceWatcher = new ResourceWatcher($resourceCache, $finder, $hashContent);
            $resourceWatcher->initialize();

            // starts server
            try {
                if (\function_exists('\pcntl_signal')) {
                    pcntl_async_signals(true);
                    pcntl_signal(SIGINT, [$this, 'tearDownServer']);
                    pcntl_signal(SIGTERM, [$this, 'tearDownServer']);
                }
                $output->writeln(\sprintf('<comment>Server process: %s</comment>', $command), OutputInterface::VERBOSITY_DEBUG);
                $output->writeln(\sprintf('Starting server (<href=http://%s:%d>http://%s:%d</>)...', $host, $port, $host, $port));
                $process->start(function ($type, $buffer) {
                    if ($type === Process::ERR) {
                        error_log($buffer, 3, Util::joinFile($this->getPath(), self::TMP_DIR, 'errors.log'));
                    }
                });
                if ($open) {
                    $output->writeln('Opening web browser...');
                    Util\Platform::openBrowser(\sprintf('http://%s:%s', $host, $port));
                }
                while ($process->isRunning()) {
                    sleep(1); // wait for server is ready
                    if (!fsockopen($host, (int) $port)) {
                        $output->writeln('<info>Server is not ready.</info>');

                        return 1;
                    }
                    $watcher = $resourceWatcher->findChanges();
                    if ($watcher->hasChanges()) {
                        // prints deleted/new/updated files in debug mode
                        $output->writeln('<comment>Changes detected.</comment>');
                        if (\count($watcher->getDeletedFiles()) > 0) {
                            $output->writeln('<comment>Deleted files:</comment>', OutputInterface::VERBOSITY_DEBUG);
                            foreach ($watcher->getDeletedFiles() as $file) {
                                $output->writeln("<comment>- $file</comment>", OutputInterface::VERBOSITY_DEBUG);
                            }
                        }
                        if (\count($watcher->getNewFiles()) > 0) {
                            $output->writeln('<comment>New files:</comment>', OutputInterface::VERBOSITY_DEBUG);
                            foreach ($watcher->getNewFiles() as $file) {
                                $output->writeln("<comment>- $file</comment>", OutputInterface::VERBOSITY_DEBUG);
                            }
                        }
                        if (\count($watcher->getUpdatedFiles()) > 0) {
                            $output->writeln('<comment>Updated files:</comment>', OutputInterface::VERBOSITY_DEBUG);
                            foreach ($watcher->getUpdatedFiles() as $file) {
                                $output->writeln("<comment>- $file</comment>", OutputInterface::VERBOSITY_DEBUG);
                            }
                        }
                        $output->writeln('');
                        // re-builds
                        $buildProcess->run($processOutputCallback);
                        if ($buildProcess->isSuccessful()) {
                            $this->buildSuccess($output);
                        }

                        $output->writeln('<info>Server is runnning...</info>');
                    }
                }
                if ($process->getExitCode() > 0) {
                    $output->writeln(\sprintf('<comment>%s</comment>', trim($process->getErrorOutput())));
                }
            } catch (ProcessFailedException $e) {
                $this->tearDownServer();

                throw new RuntimeException(\sprintf($e->getMessage()));
            }
        }

        return 0;
    }

    /**
     * Build success.
     */
    private function buildSuccess(OutputInterface $output): void
    {
        // writes `changes.flag` file
        Util\File::getFS()->dumpFile(Util::joinFile($this->getPath(), self::TMP_DIR, 'changes.flag'), time());
        // writes `headers.ini` file
        $headers = $this->getBuilder()->getConfig()->get('headers');
        if (is_iterable($headers)) {
            $output->writeln('Writing headers file...');
            Util\File::getFS()->remove(Util::joinFile($this->getPath(), self::TMP_DIR, 'headers.ini'));
            foreach ($headers as $entry) {
                Util\File::getFS()->appendToFile(Util::joinFile($this->getPath(), self::TMP_DIR, 'headers.ini'), "[{$entry['path']}]\n");
                foreach ($entry['headers'] ?? [] as $header) {
                    Util\File::getFS()->appendToFile(Util::joinFile($this->getPath(), self::TMP_DIR, 'headers.ini'), "{$header['key']} = \"{$header['value']}\"\n");
                }
            }
        }
    }

    /**
     * Prepares server's files.
     *
     * @throws RuntimeException
     */
    private function setUpServer(string $host, string $port): void
    {
        try {
            // define root path
            $root = Util\Platform::isPhar() ? Util\Platform::getPharPath() . '/' : realpath(Util::joinFile(__DIR__, '/../../'));
            // copying router
            Util\File::getFS()->copy(
                $root . '/resources/server/router.php',
                Util::joinFile($this->getPath(), self::TMP_DIR, 'router.php'),
                true
            );
            // copying livereload JS
            Util\File::getFS()->copy(
                $root . '/resources/server/livereload.js',
                Util::joinFile($this->getPath(), self::TMP_DIR, 'livereload.js'),
                true
            );
            // copying baseurl text file
            Util\File::getFS()->dumpFile(
                Util::joinFile($this->getPath(), self::TMP_DIR, 'baseurl'),
                \sprintf(
                    '%s;%s',
                    (string) $this->getBuilder()->getConfig()->get('baseurl'),
                    \sprintf('http://%s:%s/', $host, $port)
                )
            );
        } catch (IOExceptionInterface $e) {
            throw new RuntimeException(\sprintf('An error occurred while copying server\'s files to "%s".', $e->getPath()));
        }
        if (!is_file(Util::joinFile($this->getPath(), self::TMP_DIR, 'router.php'))) {
            throw new RuntimeException(\sprintf('Router not found: "%s".', Util::joinFile(self::TMP_DIR, 'router.php')));
        }
    }

    /**
     * Removes temporary directory.
     *
     * @throws RuntimeException
     */
    public function tearDownServer(): void
    {
        $this->output->writeln('');
        $this->output->writeln('<info>Server stopped.</info>');

        try {
            Util\File::getFS()->remove(Util::joinFile($this->getPath(), self::TMP_DIR));
        } catch (IOExceptionInterface $e) {
            throw new RuntimeException($e->getMessage());
        }
    }
}
