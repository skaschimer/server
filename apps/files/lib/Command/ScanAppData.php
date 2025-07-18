<?php

/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Files\Command;

use OC\Core\Command\Base;
use OC\Core\Command\InterruptedException;
use OC\DB\Connection;
use OC\DB\ConnectionAdapter;
use OC\Files\Utils\Scanner;
use OC\ForbiddenException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\StorageNotAvailableException;
use OCP\IConfig;
use OCP\Server;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ScanAppData extends Base {
	protected float $execTime = 0;

	protected int $foldersCounter = 0;

	protected int $filesCounter = 0;

	public function __construct(
		protected IRootFolder $rootFolder,
		protected IConfig $config,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		parent::configure();

		$this
			->setName('files:scan-app-data')
			->setDescription('rescan the AppData folder');

		$this->addArgument('folder', InputArgument::OPTIONAL, 'The appdata subfolder to scan', '');
	}

	protected function scanFiles(OutputInterface $output, string $folder): int {
		try {
			/** @var Folder $appData */
			$appData = $this->getAppDataFolder();
		} catch (NotFoundException $e) {
			$output->writeln('<error>NoAppData folder found</error>');
			return self::FAILURE;
		}

		if ($folder !== '') {
			try {
				$appData = $appData->get($folder);
			} catch (NotFoundException $e) {
				$output->writeln('<error>Could not find folder: ' . $folder . '</error>');
				return self::FAILURE;
			}
		}

		$connection = $this->reconnectToDatabase($output);
		$scanner = new Scanner(
			null,
			new ConnectionAdapter($connection),
			Server::get(IEventDispatcher::class),
			Server::get(LoggerInterface::class)
		);

		# check on each file/folder if there was a user interrupt (ctrl-c) and throw an exception
		$scanner->listen('\OC\Files\Utils\Scanner', 'scanFile', function ($path) use ($output): void {
			$output->writeln("\tFile   <info>$path</info>", OutputInterface::VERBOSITY_VERBOSE);
			++$this->filesCounter;
			$this->abortIfInterrupted();
		});

		$scanner->listen('\OC\Files\Utils\Scanner', 'scanFolder', function ($path) use ($output): void {
			$output->writeln("\tFolder <info>$path</info>", OutputInterface::VERBOSITY_VERBOSE);
			++$this->foldersCounter;
			$this->abortIfInterrupted();
		});

		$scanner->listen('\OC\Files\Utils\Scanner', 'StorageNotAvailable', function (StorageNotAvailableException $e) use ($output): void {
			$output->writeln('Error while scanning, storage not available (' . $e->getMessage() . ')', OutputInterface::VERBOSITY_VERBOSE);
		});

		$scanner->listen('\OC\Files\Utils\Scanner', 'normalizedNameMismatch', function ($fullPath) use ($output): void {
			$output->writeln("\t<error>Entry \"" . $fullPath . '" will not be accessible due to incompatible encoding</error>');
		});

		try {
			$scanner->scan($appData->getPath());
		} catch (ForbiddenException $e) {
			$output->writeln('<error>Storage not writable</error>');
			$output->writeln('<info>Make sure you\'re running the scan command only as the user the web server runs as</info>');
			return self::FAILURE;
		} catch (InterruptedException $e) {
			# exit the function if ctrl-c has been pressed
			$output->writeln('<info>Interrupted by user</info>');
			return self::FAILURE;
		} catch (NotFoundException $e) {
			$output->writeln('<error>Path not found: ' . $e->getMessage() . '</error>');
			return self::FAILURE;
		} catch (\Exception $e) {
			$output->writeln('<error>Exception during scan: ' . $e->getMessage() . '</error>');
			$output->writeln('<error>' . $e->getTraceAsString() . '</error>');
			return self::FAILURE;
		}

		return self::SUCCESS;
	}


	protected function execute(InputInterface $input, OutputInterface $output): int {
		# restrict the verbosity level to VERBOSITY_VERBOSE
		if ($output->getVerbosity() > OutputInterface::VERBOSITY_VERBOSE) {
			$output->setVerbosity(OutputInterface::VERBOSITY_VERBOSE);
		}

		$output->writeln('Scanning AppData for files');
		$output->writeln('');

		$folder = $input->getArgument('folder');

		$this->initTools();

		$exitCode = $this->scanFiles($output, $folder);
		if ($exitCode === 0) {
			$this->presentStats($output);
		}
		return $exitCode;
	}

	/**
	 * Initialises some useful tools for the Command
	 */
	protected function initTools(): void {
		// Start the timer
		$this->execTime = -microtime(true);
		// Convert PHP errors to exceptions
		set_error_handler([$this, 'exceptionErrorHandler'], E_ALL);
	}

	/**
	 * Processes PHP errors as exceptions in order to be able to keep track of problems
	 *
	 * @see https://www.php.net/manual/en/function.set-error-handler.php
	 *
	 * @param int $severity the level of the error raised
	 * @param string $message
	 * @param string $file the filename that the error was raised in
	 * @param int $line the line number the error was raised
	 *
	 * @throws \ErrorException
	 */
	public function exceptionErrorHandler($severity, $message, $file, $line) {
		if (!(error_reporting() & $severity)) {
			// This error code is not included in error_reporting
			return;
		}
		throw new \ErrorException($message, 0, $severity, $file, $line);
	}

	protected function presentStats(OutputInterface $output): void {
		// Stop the timer
		$this->execTime += microtime(true);

		$headers = [
			'Folders', 'Files', 'Elapsed time'
		];

		$this->showSummary($headers, null, $output);
	}

	/**
	 * Shows a summary of operations
	 *
	 * @param string[] $headers
	 * @param string[] $rows
	 */
	protected function showSummary($headers, $rows, OutputInterface $output): void {
		$niceDate = $this->formatExecTime();
		if (!$rows) {
			$rows = [
				$this->foldersCounter,
				$this->filesCounter,
				$niceDate,
			];
		}
		$table = new Table($output);
		$table
			->setHeaders($headers)
			->setRows([$rows]);
		$table->render();
	}


	/**
	 * Formats microtime into a human-readable format
	 */
	protected function formatExecTime(): string {
		$secs = round($this->execTime);
		# convert seconds into HH:MM:SS form
		return sprintf('%02d:%02d:%02d', (int)($secs / 3600), ((int)($secs / 60) % 60), (int)$secs % 60);
	}

	protected function reconnectToDatabase(OutputInterface $output): Connection {
		/** @var Connection $connection */
		$connection = Server::get(Connection::class);
		try {
			$connection->close();
		} catch (\Exception $ex) {
			$output->writeln("<info>Error while disconnecting from database: {$ex->getMessage()}</info>");
		}
		while (!$connection->isConnected()) {
			try {
				$connection->connect();
			} catch (\Exception $ex) {
				$output->writeln("<info>Error while re-connecting to database: {$ex->getMessage()}</info>");
				sleep(60);
			}
		}
		return $connection;
	}

	/**
	 * @throws NotFoundException
	 */
	private function getAppDataFolder(): Node {
		$instanceId = $this->config->getSystemValue('instanceid', null);

		if ($instanceId === null) {
			throw new NotFoundException();
		}

		return $this->rootFolder->get('appdata_' . $instanceId);
	}
}
