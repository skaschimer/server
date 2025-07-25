<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2013-2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */

use OC\Profiler\BuiltInProfiler;
use OC\Share20\GroupDeletedListener;
use OC\Share20\Hooks;
use OC\Share20\UserDeletedListener;
use OC\Share20\UserRemovedListener;
use OC\User\DisabledUserException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Events\BeforeFileSystemSetupEvent;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\Group\Events\UserRemovedEvent;
use OCP\IConfig;
use OCP\ILogger;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
use OCP\Server;
use OCP\Template\ITemplateManager;
use OCP\User\Events\UserChangedEvent;
use OCP\User\Events\UserDeletedEvent;
use OCP\Util;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use function OCP\Log\logger;

require_once 'public/Constants.php';

/**
 * Class that is a namespace for all global OC variables
 * No, we can not put this class in its own file because it is used by
 * OC_autoload!
 */
class OC {
	/**
	 * The installation path for Nextcloud  on the server (e.g. /srv/http/nextcloud)
	 */
	public static string $SERVERROOT = '';
	/**
	 * the current request path relative to the Nextcloud root (e.g. files/index.php)
	 */
	private static string $SUBURI = '';
	/**
	 * the Nextcloud root path for http requests (e.g. /nextcloud)
	 */
	public static string $WEBROOT = '';
	/**
	 * The installation path array of the apps folder on the server (e.g. /srv/http/nextcloud) 'path' and
	 * web path in 'url'
	 */
	public static array $APPSROOTS = [];

	public static string $configDir;

	/**
	 * requested app
	 */
	public static string $REQUESTEDAPP = '';

	/**
	 * check if Nextcloud runs in cli mode
	 */
	public static bool $CLI = false;

	public static \Composer\Autoload\ClassLoader $composerAutoloader;

	public static \OC\Server $server;

	private static \OC\Config $config;

	/**
	 * @throws \RuntimeException when the 3rdparty directory is missing or
	 *                           the app path list is empty or contains an invalid path
	 */
	public static function initPaths(): void {
		if (defined('PHPUNIT_CONFIG_DIR')) {
			self::$configDir = OC::$SERVERROOT . '/' . PHPUNIT_CONFIG_DIR . '/';
		} elseif (defined('PHPUNIT_RUN') and PHPUNIT_RUN and is_dir(OC::$SERVERROOT . '/tests/config/')) {
			self::$configDir = OC::$SERVERROOT . '/tests/config/';
		} elseif ($dir = getenv('NEXTCLOUD_CONFIG_DIR')) {
			self::$configDir = rtrim($dir, '/') . '/';
		} else {
			self::$configDir = OC::$SERVERROOT . '/config/';
		}
		self::$config = new \OC\Config(self::$configDir);

		OC::$SUBURI = str_replace('\\', '/', substr(realpath($_SERVER['SCRIPT_FILENAME'] ?? ''), strlen(OC::$SERVERROOT)));
		/**
		 * FIXME: The following lines are required because we can't yet instantiate
		 *        Server::get(\OCP\IRequest::class) since \OC::$server does not yet exist.
		 */
		$params = [
			'server' => [
				'SCRIPT_NAME' => $_SERVER['SCRIPT_NAME'] ?? null,
				'SCRIPT_FILENAME' => $_SERVER['SCRIPT_FILENAME'] ?? null,
			],
		];
		if (isset($_SERVER['REMOTE_ADDR'])) {
			$params['server']['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'];
		}
		$fakeRequest = new \OC\AppFramework\Http\Request(
			$params,
			new \OC\AppFramework\Http\RequestId($_SERVER['UNIQUE_ID'] ?? '', new \OC\Security\SecureRandom()),
			new \OC\AllConfig(new \OC\SystemConfig(self::$config))
		);
		$scriptName = $fakeRequest->getScriptName();
		if (substr($scriptName, -1) == '/') {
			$scriptName .= 'index.php';
			//make sure suburi follows the same rules as scriptName
			if (substr(OC::$SUBURI, -9) != 'index.php') {
				if (substr(OC::$SUBURI, -1) != '/') {
					OC::$SUBURI = OC::$SUBURI . '/';
				}
				OC::$SUBURI = OC::$SUBURI . 'index.php';
			}
		}

		if (OC::$CLI) {
			OC::$WEBROOT = self::$config->getValue('overwritewebroot', '');
		} else {
			if (substr($scriptName, 0 - strlen(OC::$SUBURI)) === OC::$SUBURI) {
				OC::$WEBROOT = substr($scriptName, 0, 0 - strlen(OC::$SUBURI));

				if (OC::$WEBROOT != '' && OC::$WEBROOT[0] !== '/') {
					OC::$WEBROOT = '/' . OC::$WEBROOT;
				}
			} else {
				// The scriptName is not ending with OC::$SUBURI
				// This most likely means that we are calling from CLI.
				// However some cron jobs still need to generate
				// a web URL, so we use overwritewebroot as a fallback.
				OC::$WEBROOT = self::$config->getValue('overwritewebroot', '');
			}

			// Resolve /nextcloud to /nextcloud/ to ensure to always have a trailing
			// slash which is required by URL generation.
			if (isset($_SERVER['REQUEST_URI']) && $_SERVER['REQUEST_URI'] === \OC::$WEBROOT
					&& substr($_SERVER['REQUEST_URI'], -1) !== '/') {
				header('Location: ' . \OC::$WEBROOT . '/');
				exit();
			}
		}

		// search the apps folder
		$config_paths = self::$config->getValue('apps_paths', []);
		if (!empty($config_paths)) {
			foreach ($config_paths as $paths) {
				if (isset($paths['url']) && isset($paths['path'])) {
					$paths['url'] = rtrim($paths['url'], '/');
					$paths['path'] = rtrim($paths['path'], '/');
					OC::$APPSROOTS[] = $paths;
				}
			}
		} elseif (file_exists(OC::$SERVERROOT . '/apps')) {
			OC::$APPSROOTS[] = ['path' => OC::$SERVERROOT . '/apps', 'url' => '/apps', 'writable' => true];
		}

		if (empty(OC::$APPSROOTS)) {
			throw new \RuntimeException('apps directory not found! Please put the Nextcloud apps folder in the Nextcloud folder'
				. '. You can also configure the location in the config.php file.');
		}
		$paths = [];
		foreach (OC::$APPSROOTS as $path) {
			$paths[] = $path['path'];
			if (!is_dir($path['path'])) {
				throw new \RuntimeException(sprintf('App directory "%s" not found! Please put the Nextcloud apps folder in the'
					. ' Nextcloud folder. You can also configure the location in the config.php file.', $path['path']));
			}
		}

		// set the right include path
		set_include_path(
			implode(PATH_SEPARATOR, $paths)
		);
	}

	public static function checkConfig(): void {
		// Create config if it does not already exist
		$configFilePath = self::$configDir . '/config.php';
		if (!file_exists($configFilePath)) {
			@touch($configFilePath);
		}

		// Check if config is writable
		$configFileWritable = is_writable($configFilePath);
		$configReadOnly = Server::get(IConfig::class)->getSystemValueBool('config_is_read_only');
		if (!$configFileWritable && !$configReadOnly
			|| !$configFileWritable && \OCP\Util::needUpgrade()) {
			$urlGenerator = Server::get(IURLGenerator::class);
			$l = Server::get(\OCP\L10N\IFactory::class)->get('lib');

			if (self::$CLI) {
				echo $l->t('Cannot write into "config" directory!') . "\n";
				echo $l->t('This can usually be fixed by giving the web server write access to the config directory.') . "\n";
				echo "\n";
				echo $l->t('But, if you prefer to keep config.php file read only, set the option "config_is_read_only" to true in it.') . "\n";
				echo $l->t('See %s', [ $urlGenerator->linkToDocs('admin-config') ]) . "\n";
				exit;
			} else {
				Server::get(ITemplateManager::class)->printErrorPage(
					$l->t('Cannot write into "config" directory!'),
					$l->t('This can usually be fixed by giving the web server write access to the config directory.') . ' '
					. $l->t('But, if you prefer to keep config.php file read only, set the option "config_is_read_only" to true in it.') . ' '
					. $l->t('See %s', [ $urlGenerator->linkToDocs('admin-config') ]),
					503
				);
			}
		}
	}

	public static function checkInstalled(\OC\SystemConfig $systemConfig): void {
		if (defined('OC_CONSOLE')) {
			return;
		}
		// Redirect to installer if not installed
		if (!$systemConfig->getValue('installed', false) && OC::$SUBURI !== '/index.php' && OC::$SUBURI !== '/status.php') {
			if (OC::$CLI) {
				throw new Exception('Not installed');
			} else {
				$url = OC::$WEBROOT . '/index.php';
				header('Location: ' . $url);
			}
			exit();
		}
	}

	public static function checkMaintenanceMode(\OC\SystemConfig $systemConfig): void {
		// Allow ajax update script to execute without being stopped
		if (((bool)$systemConfig->getValue('maintenance', false)) && OC::$SUBURI != '/core/ajax/update.php') {
			// send http status 503
			http_response_code(503);
			header('X-Nextcloud-Maintenance-Mode: 1');
			header('Retry-After: 120');

			// render error page
			$template = Server::get(ITemplateManager::class)->getTemplate('', 'update.user', 'guest');
			\OCP\Util::addScript('core', 'maintenance');
			\OCP\Util::addStyle('core', 'guest');
			$template->printPage();
			die();
		}
	}

	/**
	 * Prints the upgrade page
	 */
	private static function printUpgradePage(\OC\SystemConfig $systemConfig): void {
		$cliUpgradeLink = $systemConfig->getValue('upgrade.cli-upgrade-link', '');
		$disableWebUpdater = $systemConfig->getValue('upgrade.disable-web', false);
		$tooBig = false;
		if (!$disableWebUpdater) {
			$apps = Server::get(\OCP\App\IAppManager::class);
			if ($apps->isEnabledForAnyone('user_ldap')) {
				$qb = Server::get(\OCP\IDBConnection::class)->getQueryBuilder();

				$result = $qb->select($qb->func()->count('*', 'user_count'))
					->from('ldap_user_mapping')
					->executeQuery();
				$row = $result->fetch();
				$result->closeCursor();

				$tooBig = ($row['user_count'] > 50);
			}
			if (!$tooBig && $apps->isEnabledForAnyone('user_saml')) {
				$qb = Server::get(\OCP\IDBConnection::class)->getQueryBuilder();

				$result = $qb->select($qb->func()->count('*', 'user_count'))
					->from('user_saml_users')
					->executeQuery();
				$row = $result->fetch();
				$result->closeCursor();

				$tooBig = ($row['user_count'] > 50);
			}
			if (!$tooBig) {
				// count users
				$totalUsers = Server::get(\OCP\IUserManager::class)->countUsersTotal(51);
				$tooBig = ($totalUsers > 50);
			}
		}
		$ignoreTooBigWarning = isset($_GET['IKnowThatThisIsABigInstanceAndTheUpdateRequestCouldRunIntoATimeoutAndHowToRestoreABackup'])
			&& $_GET['IKnowThatThisIsABigInstanceAndTheUpdateRequestCouldRunIntoATimeoutAndHowToRestoreABackup'] === 'IAmSuperSureToDoThis';

		if ($disableWebUpdater || ($tooBig && !$ignoreTooBigWarning)) {
			// send http status 503
			http_response_code(503);
			header('Retry-After: 120');

			$serverVersion = \OCP\Server::get(\OCP\ServerVersion::class);

			// render error page
			$template = Server::get(ITemplateManager::class)->getTemplate('', 'update.use-cli', 'guest');
			$template->assign('productName', 'nextcloud'); // for now
			$template->assign('version', $serverVersion->getVersionString());
			$template->assign('tooBig', $tooBig);
			$template->assign('cliUpgradeLink', $cliUpgradeLink);

			$template->printPage();
			die();
		}

		// check whether this is a core update or apps update
		$installedVersion = $systemConfig->getValue('version', '0.0.0');
		$currentVersion = implode('.', \OCP\Util::getVersion());

		// if not a core upgrade, then it's apps upgrade
		$isAppsOnlyUpgrade = version_compare($currentVersion, $installedVersion, '=');

		$oldTheme = $systemConfig->getValue('theme');
		$systemConfig->setValue('theme', '');
		\OCP\Util::addScript('core', 'common');
		\OCP\Util::addScript('core', 'main');
		\OCP\Util::addTranslations('core');
		\OCP\Util::addScript('core', 'update');

		/** @var \OC\App\AppManager $appManager */
		$appManager = Server::get(\OCP\App\IAppManager::class);

		$tmpl = Server::get(ITemplateManager::class)->getTemplate('', 'update.admin', 'guest');
		$tmpl->assign('version', \OCP\Server::get(\OCP\ServerVersion::class)->getVersionString());
		$tmpl->assign('isAppsOnlyUpgrade', $isAppsOnlyUpgrade);

		// get third party apps
		$ocVersion = \OCP\Util::getVersion();
		$ocVersion = implode('.', $ocVersion);
		$incompatibleApps = $appManager->getIncompatibleApps($ocVersion);
		$incompatibleOverwrites = $systemConfig->getValue('app_install_overwrite', []);
		$incompatibleShippedApps = [];
		$incompatibleDisabledApps = [];
		foreach ($incompatibleApps as $appInfo) {
			if ($appManager->isShipped($appInfo['id'])) {
				$incompatibleShippedApps[] = $appInfo['name'] . ' (' . $appInfo['id'] . ')';
			}
			if (!in_array($appInfo['id'], $incompatibleOverwrites)) {
				$incompatibleDisabledApps[] = $appInfo;
			}
		}

		if (!empty($incompatibleShippedApps)) {
			$l = Server::get(\OCP\L10N\IFactory::class)->get('core');
			$hint = $l->t('Application %1$s is not present or has a non-compatible version with this server. Please check the apps directory.', [implode(', ', $incompatibleShippedApps)]);
			throw new \OCP\HintException('Application ' . implode(', ', $incompatibleShippedApps) . ' is not present or has a non-compatible version with this server. Please check the apps directory.', $hint);
		}

		$tmpl->assign('appsToUpgrade', $appManager->getAppsNeedingUpgrade($ocVersion));
		$tmpl->assign('incompatibleAppsList', $incompatibleDisabledApps);
		try {
			$defaults = new \OC_Defaults();
			$tmpl->assign('productName', $defaults->getName());
		} catch (Throwable $error) {
			$tmpl->assign('productName', 'Nextcloud');
		}
		$tmpl->assign('oldTheme', $oldTheme);
		$tmpl->printPage();
	}

	public static function initSession(): void {
		$request = Server::get(IRequest::class);

		// TODO: Temporary disabled again to solve issues with CalDAV/CardDAV clients like DAVx5 that use cookies
		// TODO: See https://github.com/nextcloud/server/issues/37277#issuecomment-1476366147 and the other comments
		// TODO: for further information.
		// $isDavRequest = strpos($request->getRequestUri(), '/remote.php/dav') === 0 || strpos($request->getRequestUri(), '/remote.php/webdav') === 0;
		// if ($request->getHeader('Authorization') !== '' && is_null($request->getCookie('cookie_test')) && $isDavRequest && !isset($_COOKIE['nc_session_id'])) {
		// setcookie('cookie_test', 'test', time() + 3600);
		// // Do not initialize the session if a request is authenticated directly
		// // unless there is a session cookie already sent along
		// return;
		// }

		if ($request->getServerProtocol() === 'https') {
			ini_set('session.cookie_secure', 'true');
		}

		// prevents javascript from accessing php session cookies
		ini_set('session.cookie_httponly', 'true');

		// Do not initialize sessions for 'status.php' requests
		// Monitoring endpoints can quickly flood session handlers
		// and 'status.php' doesn't require sessions anyway
		if (str_ends_with($request->getScriptName(), '/status.php')) {
			return;
		}

		// set the cookie path to the Nextcloud directory
		$cookie_path = OC::$WEBROOT ? : '/';
		ini_set('session.cookie_path', $cookie_path);

		// set the cookie domain to the Nextcloud domain
		$cookie_domain = self::$config->getValue('cookie_domain', '');
		if ($cookie_domain) {
			ini_set('session.cookie_domain', $cookie_domain);
		}

		// Let the session name be changed in the initSession Hook
		$sessionName = OC_Util::getInstanceId();

		try {
			$logger = null;
			if (Server::get(\OC\SystemConfig::class)->getValue('installed', false)) {
				$logger = logger('core');
			}

			// set the session name to the instance id - which is unique
			$session = new \OC\Session\Internal(
				$sessionName,
				$logger,
			);

			$cryptoWrapper = Server::get(\OC\Session\CryptoWrapper::class);
			$session = $cryptoWrapper->wrapSession($session);
			self::$server->setSession($session);

			// if session can't be started break with http 500 error
		} catch (Exception $e) {
			Server::get(LoggerInterface::class)->error($e->getMessage(), ['app' => 'base','exception' => $e]);
			//show the user a detailed error page
			Server::get(ITemplateManager::class)->printExceptionErrorPage($e, 500);
			die();
		}

		//try to set the session lifetime
		$sessionLifeTime = self::getSessionLifeTime();

		// session timeout
		if ($session->exists('LAST_ACTIVITY') && (time() - $session->get('LAST_ACTIVITY') > $sessionLifeTime)) {
			if (isset($_COOKIE[session_name()])) {
				setcookie(session_name(), '', -1, self::$WEBROOT ? : '/');
			}
			Server::get(IUserSession::class)->logout();
		}

		if (!self::hasSessionRelaxedExpiry()) {
			$session->set('LAST_ACTIVITY', time());
		}
		$session->close();
	}

	private static function getSessionLifeTime(): int {
		return Server::get(\OC\AllConfig::class)->getSystemValueInt('session_lifetime', 60 * 60 * 24);
	}

	/**
	 * @return bool true if the session expiry should only be done by gc instead of an explicit timeout
	 */
	public static function hasSessionRelaxedExpiry(): bool {
		return Server::get(\OC\AllConfig::class)->getSystemValueBool('session_relaxed_expiry', false);
	}

	/**
	 * Try to set some values to the required Nextcloud default
	 */
	public static function setRequiredIniValues(): void {
		// Don't display errors and log them
		@ini_set('display_errors', '0');
		@ini_set('log_errors', '1');

		// Try to configure php to enable big file uploads.
		// This doesn't work always depending on the webserver and php configuration.
		// Let's try to overwrite some defaults if they are smaller than 1 hour

		if (intval(@ini_get('max_execution_time') ?: 0) < 3600) {
			@ini_set('max_execution_time', strval(3600));
		}

		if (intval(@ini_get('max_input_time') ?: 0) < 3600) {
			@ini_set('max_input_time', strval(3600));
		}

		// Try to set the maximum execution time to the largest time limit we have
		if (strpos(@ini_get('disable_functions'), 'set_time_limit') === false) {
			@set_time_limit(max(intval(@ini_get('max_execution_time')), intval(@ini_get('max_input_time'))));
		}

		@ini_set('default_charset', 'UTF-8');
		@ini_set('gd.jpeg_ignore_warning', '1');
	}

	/**
	 * Send the same site cookies
	 */
	private static function sendSameSiteCookies(): void {
		$cookieParams = session_get_cookie_params();
		$secureCookie = ($cookieParams['secure'] === true) ? 'secure; ' : '';
		$policies = [
			'lax',
			'strict',
		];

		// Append __Host to the cookie if it meets the requirements
		$cookiePrefix = '';
		if ($cookieParams['secure'] === true && $cookieParams['path'] === '/') {
			$cookiePrefix = '__Host-';
		}

		foreach ($policies as $policy) {
			header(
				sprintf(
					'Set-Cookie: %snc_sameSiteCookie%s=true; path=%s; httponly;' . $secureCookie . 'expires=Fri, 31-Dec-2100 23:59:59 GMT; SameSite=%s',
					$cookiePrefix,
					$policy,
					$cookieParams['path'],
					$policy
				),
				false
			);
		}
	}

	/**
	 * Same Site cookie to further mitigate CSRF attacks. This cookie has to
	 * be set in every request if cookies are sent to add a second level of
	 * defense against CSRF.
	 *
	 * If the cookie is not sent this will set the cookie and reload the page.
	 * We use an additional cookie since we want to protect logout CSRF and
	 * also we can't directly interfere with PHP's session mechanism.
	 */
	private static function performSameSiteCookieProtection(IConfig $config): void {
		$request = Server::get(IRequest::class);

		// Some user agents are notorious and don't really properly follow HTTP
		// specifications. For those, have an automated opt-out. Since the protection
		// for remote.php is applied in base.php as starting point we need to opt out
		// here.
		$incompatibleUserAgents = $config->getSystemValue('csrf.optout');

		// Fallback, if csrf.optout is unset
		if (!is_array($incompatibleUserAgents)) {
			$incompatibleUserAgents = [
				// OS X Finder
				'/^WebDAVFS/',
				// Windows webdav drive
				'/^Microsoft-WebDAV-MiniRedir/',
			];
		}

		if ($request->isUserAgent($incompatibleUserAgents)) {
			return;
		}

		if (count($_COOKIE) > 0) {
			$requestUri = $request->getScriptName();
			$processingScript = explode('/', $requestUri);
			$processingScript = $processingScript[count($processingScript) - 1];

			if ($processingScript === 'index.php' // index.php routes are handled in the middleware
				|| $processingScript === 'cron.php' // and cron.php does not need any authentication at all
				|| $processingScript === 'public.php' // For public.php, auth for password protected shares is done in the PublicAuth plugin
			) {
				return;
			}

			// All other endpoints require the lax and the strict cookie
			if (!$request->passesStrictCookieCheck()) {
				logger('core')->warning('Request does not pass strict cookie check');
				self::sendSameSiteCookies();
				// Debug mode gets access to the resources without strict cookie
				// due to the fact that the SabreDAV browser also lives there.
				if (!$config->getSystemValueBool('debug', false)) {
					http_response_code(\OCP\AppFramework\Http::STATUS_PRECONDITION_FAILED);
					header('Content-Type: application/json');
					echo json_encode(['error' => 'Strict Cookie has not been found in request']);
					exit();
				}
			}
		} elseif (!isset($_COOKIE['nc_sameSiteCookielax']) || !isset($_COOKIE['nc_sameSiteCookiestrict'])) {
			self::sendSameSiteCookies();
		}
	}

	public static function init(): void {
		// First handle PHP configuration and copy auth headers to the expected
		// $_SERVER variable before doing anything Server object related
		self::setRequiredIniValues();
		self::handleAuthHeaders();

		// prevent any XML processing from loading external entities
		libxml_set_external_entity_loader(static function () {
			return null;
		});

		// Set default timezone before the Server object is booted
		if (!date_default_timezone_set('UTC')) {
			throw new \RuntimeException('Could not set timezone to UTC');
		}

		// calculate the root directories
		OC::$SERVERROOT = str_replace('\\', '/', substr(__DIR__, 0, -4));

		// register autoloader
		$loaderStart = microtime(true);

		self::$CLI = (php_sapi_name() == 'cli');

		// Add default composer PSR-4 autoloader, ensure apcu to be disabled
		self::$composerAutoloader = require_once OC::$SERVERROOT . '/lib/composer/autoload.php';
		self::$composerAutoloader->setApcuPrefix(null);


		try {
			self::initPaths();
			// setup 3rdparty autoloader
			$vendorAutoLoad = OC::$SERVERROOT . '/3rdparty/autoload.php';
			if (!file_exists($vendorAutoLoad)) {
				throw new \RuntimeException('Composer autoloader not found, unable to continue. Check the folder "3rdparty". Running "git submodule update --init" will initialize the git submodule that handles the subfolder "3rdparty".');
			}
			require_once $vendorAutoLoad;
		} catch (\RuntimeException $e) {
			if (!self::$CLI) {
				http_response_code(503);
			}
			// we can't use the template error page here, because this needs the
			// DI container which isn't available yet
			print($e->getMessage());
			exit();
		}
		$loaderEnd = microtime(true);

		// Enable lazy loading if activated
		\OC\AppFramework\Utility\SimpleContainer::$useLazyObjects = (bool)self::$config->getValue('enable_lazy_objects', true);

		// setup the basic server
		self::$server = new \OC\Server(\OC::$WEBROOT, self::$config);
		self::$server->boot();

		try {
			$profiler = new BuiltInProfiler(
				Server::get(IConfig::class),
				Server::get(IRequest::class),
			);
			$profiler->start();
		} catch (\Throwable $e) {
			logger('core')->error('Failed to start profiler: ' . $e->getMessage(), ['app' => 'base']);
		}

		if (self::$CLI && in_array('--' . \OCP\Console\ReservedOptions::DEBUG_LOG, $_SERVER['argv'])) {
			\OC\Core\Listener\BeforeMessageLoggedEventListener::setup();
		}

		$eventLogger = Server::get(\OCP\Diagnostics\IEventLogger::class);
		$eventLogger->log('autoloader', 'Autoloader', $loaderStart, $loaderEnd);
		$eventLogger->start('boot', 'Initialize');

		// Override php.ini and log everything if we're troubleshooting
		if (self::$config->getValue('loglevel') === ILogger::DEBUG) {
			error_reporting(E_ALL);
		}

		// initialize intl fallback if necessary
		OC_Util::isSetLocaleWorking();

		$config = Server::get(IConfig::class);
		if (!defined('PHPUNIT_RUN')) {
			$errorHandler = new OC\Log\ErrorHandler(
				\OCP\Server::get(\Psr\Log\LoggerInterface::class),
			);
			$exceptionHandler = [$errorHandler, 'onException'];
			if ($config->getSystemValueBool('debug', false)) {
				set_error_handler([$errorHandler, 'onAll'], E_ALL);
				if (\OC::$CLI) {
					$exceptionHandler = [Server::get(ITemplateManager::class), 'printExceptionErrorPage'];
				}
			} else {
				set_error_handler([$errorHandler, 'onError']);
			}
			register_shutdown_function([$errorHandler, 'onShutdown']);
			set_exception_handler($exceptionHandler);
		}

		/** @var \OC\AppFramework\Bootstrap\Coordinator $bootstrapCoordinator */
		$bootstrapCoordinator = Server::get(\OC\AppFramework\Bootstrap\Coordinator::class);
		$bootstrapCoordinator->runInitialRegistration();

		$eventLogger->start('init_session', 'Initialize session');

		// Check for PHP SimpleXML extension earlier since we need it before our other checks and want to provide a useful hint for web users
		// see https://github.com/nextcloud/server/pull/2619
		if (!function_exists('simplexml_load_file')) {
			throw new \OCP\HintException('The PHP SimpleXML/PHP-XML extension is not installed.', 'Install the extension or make sure it is enabled.');
		}

		$systemConfig = Server::get(\OC\SystemConfig::class);
		$appManager = Server::get(\OCP\App\IAppManager::class);
		if ($systemConfig->getValue('installed', false)) {
			$appManager->loadApps(['session']);
		}
		if (!self::$CLI) {
			self::initSession();
		}
		$eventLogger->end('init_session');
		self::checkConfig();
		self::checkInstalled($systemConfig);

		OC_Response::addSecurityHeaders();

		self::performSameSiteCookieProtection($config);

		if (!defined('OC_CONSOLE')) {
			$eventLogger->start('check_server', 'Run a few configuration checks');
			$errors = OC_Util::checkServer($systemConfig);
			if (count($errors) > 0) {
				if (!self::$CLI) {
					http_response_code(503);
					Util::addStyle('guest');
					try {
						Server::get(ITemplateManager::class)->printGuestPage('', 'error', ['errors' => $errors]);
						exit;
					} catch (\Exception $e) {
						// In case any error happens when showing the error page, we simply fall back to posting the text.
						// This might be the case when e.g. the data directory is broken and we can not load/write SCSS to/from it.
					}
				}

				// Convert l10n string into regular string for usage in database
				$staticErrors = [];
				foreach ($errors as $error) {
					echo $error['error'] . "\n";
					echo $error['hint'] . "\n\n";
					$staticErrors[] = [
						'error' => (string)$error['error'],
						'hint' => (string)$error['hint'],
					];
				}

				try {
					$config->setAppValue('core', 'cronErrors', json_encode($staticErrors));
				} catch (\Exception $e) {
					echo('Writing to database failed');
				}
				exit(1);
			} elseif (self::$CLI && $config->getSystemValueBool('installed', false)) {
				$config->deleteAppValue('core', 'cronErrors');
			}
			$eventLogger->end('check_server');
		}

		// User and Groups
		if (!$systemConfig->getValue('installed', false)) {
			self::$server->getSession()->set('user_id', '');
		}

		$eventLogger->start('setup_backends', 'Setup group and user backends');
		Server::get(\OCP\IUserManager::class)->registerBackend(new \OC\User\Database());
		Server::get(\OCP\IGroupManager::class)->addBackend(new \OC\Group\Database());

		// Subscribe to the hook
		\OCP\Util::connectHook(
			'\OCA\Files_Sharing\API\Server2Server',
			'preLoginNameUsedAsUserName',
			'\OC\User\Database',
			'preLoginNameUsedAsUserName'
		);

		//setup extra user backends
		if (!\OCP\Util::needUpgrade()) {
			OC_User::setupBackends();
		} else {
			// Run upgrades in incognito mode
			OC_User::setIncognitoMode(true);
		}
		$eventLogger->end('setup_backends');

		self::registerCleanupHooks($systemConfig);
		self::registerShareHooks($systemConfig);
		self::registerEncryptionWrapperAndHooks();
		self::registerAccountHooks();
		self::registerResourceCollectionHooks();
		self::registerFileReferenceEventListener();
		self::registerRenderReferenceEventListener();
		self::registerAppRestrictionsHooks();

		// Make sure that the application class is not loaded before the database is setup
		if ($systemConfig->getValue('installed', false)) {
			$appManager->loadApp('settings');
		}

		//make sure temporary files are cleaned up
		$tmpManager = Server::get(\OCP\ITempManager::class);
		register_shutdown_function([$tmpManager, 'clean']);
		$lockProvider = Server::get(\OCP\Lock\ILockingProvider::class);
		register_shutdown_function([$lockProvider, 'releaseAll']);

		// Check whether the sample configuration has been copied
		if ($systemConfig->getValue('copied_sample_config', false)) {
			$l = Server::get(\OCP\L10N\IFactory::class)->get('lib');
			Server::get(ITemplateManager::class)->printErrorPage(
				$l->t('Sample configuration detected'),
				$l->t('It has been detected that the sample configuration has been copied. This can break your installation and is unsupported. Please read the documentation before performing changes on config.php'),
				503
			);
			return;
		}

		$request = Server::get(IRequest::class);
		$host = $request->getInsecureServerHost();
		/**
		 * if the host passed in headers isn't trusted
		 * FIXME: Should not be in here at all :see_no_evil:
		 */
		if (!OC::$CLI
			&& !Server::get(\OC\Security\TrustedDomainHelper::class)->isTrustedDomain($host)
			&& $config->getSystemValueBool('installed', false)
		) {
			// Allow access to CSS resources
			$isScssRequest = false;
			if (strpos($request->getPathInfo() ?: '', '/css/') === 0) {
				$isScssRequest = true;
			}

			if (substr($request->getRequestUri(), -11) === '/status.php') {
				http_response_code(400);
				header('Content-Type: application/json');
				echo '{"error": "Trusted domain error.", "code": 15}';
				exit();
			}

			if (!$isScssRequest) {
				http_response_code(400);
				Server::get(LoggerInterface::class)->info(
					'Trusted domain error. "{remoteAddress}" tried to access using "{host}" as host.',
					[
						'app' => 'core',
						'remoteAddress' => $request->getRemoteAddress(),
						'host' => $host,
					]
				);

				$tmpl = Server::get(ITemplateManager::class)->getTemplate('core', 'untrustedDomain', 'guest');
				$tmpl->assign('docUrl', Server::get(IURLGenerator::class)->linkToDocs('admin-trusted-domains'));
				$tmpl->printPage();

				exit();
			}
		}
		$eventLogger->end('boot');
		$eventLogger->log('init', 'OC::init', $loaderStart, microtime(true));
		$eventLogger->start('runtime', 'Runtime');
		$eventLogger->start('request', 'Full request after boot');
		register_shutdown_function(function () use ($eventLogger) {
			$eventLogger->end('request');
		});

		register_shutdown_function(function () {
			$memoryPeak = memory_get_peak_usage();
			$logLevel = match (true) {
				$memoryPeak > 500_000_000 => ILogger::FATAL,
				$memoryPeak > 400_000_000 => ILogger::ERROR,
				$memoryPeak > 300_000_000 => ILogger::WARN,
				default => null,
			};
			if ($logLevel !== null) {
				$message = 'Request used more than 300 MB of RAM: ' . Util::humanFileSize($memoryPeak);
				$logger = Server::get(LoggerInterface::class);
				$logger->log($logLevel, $message, ['app' => 'core']);
			}
		});
	}

	/**
	 * register hooks for the cleanup of cache and bruteforce protection
	 */
	public static function registerCleanupHooks(\OC\SystemConfig $systemConfig): void {
		//don't try to do this before we are properly setup
		if ($systemConfig->getValue('installed', false) && !\OCP\Util::needUpgrade()) {
			// NOTE: This will be replaced to use OCP
			$userSession = Server::get(\OC\User\Session::class);
			$userSession->listen('\OC\User', 'postLogin', function () use ($userSession) {
				if (!defined('PHPUNIT_RUN') && $userSession->isLoggedIn()) {
					// reset brute force delay for this IP address and username
					$uid = $userSession->getUser()->getUID();
					$request = Server::get(IRequest::class);
					$throttler = Server::get(IThrottler::class);
					$throttler->resetDelay($request->getRemoteAddress(), 'login', ['user' => $uid]);
				}

				try {
					$cache = new \OC\Cache\File();
					$cache->gc();
				} catch (\OC\ServerNotAvailableException $e) {
					// not a GC exception, pass it on
					throw $e;
				} catch (\OC\ForbiddenException $e) {
					// filesystem blocked for this request, ignore
				} catch (\Exception $e) {
					// a GC exception should not prevent users from using OC,
					// so log the exception
					Server::get(LoggerInterface::class)->warning('Exception when running cache gc.', [
						'app' => 'core',
						'exception' => $e,
					]);
				}
			});
		}
	}

	private static function registerEncryptionWrapperAndHooks(): void {
		/** @var \OC\Encryption\Manager */
		$manager = Server::get(\OCP\Encryption\IManager::class);
		Server::get(IEventDispatcher::class)->addListener(
			BeforeFileSystemSetupEvent::class,
			$manager->setupStorage(...),
		);

		$enabled = $manager->isEnabled();
		if ($enabled) {
			\OC\Encryption\EncryptionEventListener::register(Server::get(IEventDispatcher::class));
		}
	}

	private static function registerAccountHooks(): void {
		/** @var IEventDispatcher $dispatcher */
		$dispatcher = Server::get(IEventDispatcher::class);
		$dispatcher->addServiceListener(UserChangedEvent::class, \OC\Accounts\Hooks::class);
	}

	private static function registerAppRestrictionsHooks(): void {
		/** @var \OC\Group\Manager $groupManager */
		$groupManager = Server::get(\OCP\IGroupManager::class);
		$groupManager->listen('\OC\Group', 'postDelete', function (\OCP\IGroup $group) {
			$appManager = Server::get(\OCP\App\IAppManager::class);
			$apps = $appManager->getEnabledAppsForGroup($group);
			foreach ($apps as $appId) {
				$restrictions = $appManager->getAppRestriction($appId);
				if (empty($restrictions)) {
					continue;
				}
				$key = array_search($group->getGID(), $restrictions);
				unset($restrictions[$key]);
				$restrictions = array_values($restrictions);
				if (empty($restrictions)) {
					$appManager->disableApp($appId);
				} else {
					$appManager->enableAppForGroups($appId, $restrictions);
				}
			}
		});
	}

	private static function registerResourceCollectionHooks(): void {
		\OC\Collaboration\Resources\Listener::register(Server::get(IEventDispatcher::class));
	}

	private static function registerFileReferenceEventListener(): void {
		\OC\Collaboration\Reference\File\FileReferenceEventListener::register(Server::get(IEventDispatcher::class));
	}

	private static function registerRenderReferenceEventListener() {
		\OC\Collaboration\Reference\RenderReferenceEventListener::register(Server::get(IEventDispatcher::class));
	}

	/**
	 * register hooks for sharing
	 */
	public static function registerShareHooks(\OC\SystemConfig $systemConfig): void {
		if ($systemConfig->getValue('installed')) {

			$dispatcher = Server::get(IEventDispatcher::class);
			$dispatcher->addServiceListener(UserRemovedEvent::class, UserRemovedListener::class);
			$dispatcher->addServiceListener(GroupDeletedEvent::class, GroupDeletedListener::class);
			$dispatcher->addServiceListener(UserDeletedEvent::class, UserDeletedListener::class);
		}
	}

	/**
	 * Handle the request
	 */
	public static function handleRequest(): void {
		Server::get(\OCP\Diagnostics\IEventLogger::class)->start('handle_request', 'Handle request');
		$systemConfig = Server::get(\OC\SystemConfig::class);

		// Check if Nextcloud is installed or in maintenance (update) mode
		if (!$systemConfig->getValue('installed', false)) {
			\OC::$server->getSession()->clear();
			$controller = Server::get(\OC\Core\Controller\SetupController::class);
			$controller->run($_POST);
			exit();
		}

		$request = Server::get(IRequest::class);
		$request->throwDecodingExceptionIfAny();
		$requestPath = $request->getRawPathInfo();
		if ($requestPath === '/heartbeat') {
			return;
		}
		if (substr($requestPath, -3) !== '.js') { // we need these files during the upgrade
			self::checkMaintenanceMode($systemConfig);

			if (\OCP\Util::needUpgrade()) {
				if (function_exists('opcache_reset')) {
					opcache_reset();
				}
				if (!((bool)$systemConfig->getValue('maintenance', false))) {
					self::printUpgradePage($systemConfig);
					exit();
				}
			}
		}

		$appManager = Server::get(\OCP\App\IAppManager::class);

		// Always load authentication apps
		$appManager->loadApps(['authentication']);
		$appManager->loadApps(['extended_authentication']);

		// Load minimum set of apps
		if (!\OCP\Util::needUpgrade()
			&& !((bool)$systemConfig->getValue('maintenance', false))) {
			// For logged-in users: Load everything
			if (Server::get(IUserSession::class)->isLoggedIn()) {
				$appManager->loadApps();
			} else {
				// For guests: Load only filesystem and logging
				$appManager->loadApps(['filesystem', 'logging']);

				// Don't try to login when a client is trying to get a OAuth token.
				// OAuth needs to support basic auth too, so the login is not valid
				// inside Nextcloud and the Login exception would ruin it.
				if ($request->getRawPathInfo() !== '/apps/oauth2/api/v1/token') {
					try {
						self::handleLogin($request);
					} catch (DisabledUserException $e) {
						// Disabled users would not be seen as logged in and
						// trying to log them in would fail, so the login
						// exception is ignored for the themed stylesheets and
						// images.
						if ($request->getRawPathInfo() !== '/apps/theming/theme/default.css'
							&& $request->getRawPathInfo() !== '/apps/theming/theme/light.css'
							&& $request->getRawPathInfo() !== '/apps/theming/theme/dark.css'
							&& $request->getRawPathInfo() !== '/apps/theming/theme/light-highcontrast.css'
							&& $request->getRawPathInfo() !== '/apps/theming/theme/dark-highcontrast.css'
							&& $request->getRawPathInfo() !== '/apps/theming/theme/opendyslexic.css'
							&& $request->getRawPathInfo() !== '/apps/theming/image/background'
							&& $request->getRawPathInfo() !== '/apps/theming/image/logo'
							&& $request->getRawPathInfo() !== '/apps/theming/image/logoheader'
							&& !str_starts_with($request->getRawPathInfo(), '/apps/theming/favicon')
							&& !str_starts_with($request->getRawPathInfo(), '/apps/theming/icon')) {
							throw $e;
						}
					}
				}
			}
		}

		if (!self::$CLI) {
			try {
				if (!\OCP\Util::needUpgrade()) {
					$appManager->loadApps(['filesystem', 'logging']);
					$appManager->loadApps();
				}
				Server::get(\OC\Route\Router::class)->match($request->getRawPathInfo());
				return;
			} catch (Symfony\Component\Routing\Exception\ResourceNotFoundException $e) {
				//header('HTTP/1.0 404 Not Found');
			} catch (Symfony\Component\Routing\Exception\MethodNotAllowedException $e) {
				http_response_code(405);
				return;
			}
		}

		// Handle WebDAV
		if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'PROPFIND') {
			// not allowed any more to prevent people
			// mounting this root directly.
			// Users need to mount remote.php/webdav instead.
			http_response_code(405);
			return;
		}

		// Handle requests for JSON or XML
		$acceptHeader = $request->getHeader('Accept');
		if (in_array($acceptHeader, ['application/json', 'application/xml'], true)) {
			http_response_code(404);
			return;
		}

		// Handle resources that can't be found
		// This prevents browsers from redirecting to the default page and then
		// attempting to parse HTML as CSS and similar.
		$destinationHeader = $request->getHeader('Sec-Fetch-Dest');
		if (in_array($destinationHeader, ['font', 'script', 'style'])) {
			http_response_code(404);
			return;
		}

		// Redirect to the default app or login only as an entry point
		if ($requestPath === '') {
			// Someone is logged in
			if (Server::get(IUserSession::class)->isLoggedIn()) {
				header('Location: ' . Server::get(IURLGenerator::class)->linkToDefaultPageUrl());
			} else {
				// Not handled and not logged in
				header('Location: ' . Server::get(IURLGenerator::class)->linkToRouteAbsolute('core.login.showLoginForm'));
			}
			return;
		}

		try {
			Server::get(\OC\Route\Router::class)->match('/error/404');
		} catch (\Exception $e) {
			if (!$e instanceof MethodNotAllowedException) {
				logger('core')->emergency($e->getMessage(), ['exception' => $e]);
			}
			$l = Server::get(\OCP\L10N\IFactory::class)->get('lib');
			Server::get(ITemplateManager::class)->printErrorPage(
				'404',
				$l->t('The page could not be found on the server.'),
				404
			);
		}
	}

	/**
	 * Check login: apache auth, auth token, basic auth
	 */
	public static function handleLogin(OCP\IRequest $request): bool {
		if ($request->getHeader('X-Nextcloud-Federation')) {
			return false;
		}
		$userSession = Server::get(\OC\User\Session::class);
		if (OC_User::handleApacheAuth()) {
			return true;
		}
		if (self::tryAppAPILogin($request)) {
			return true;
		}
		if ($userSession->tryTokenLogin($request)) {
			return true;
		}
		if (isset($_COOKIE['nc_username'])
			&& isset($_COOKIE['nc_token'])
			&& isset($_COOKIE['nc_session_id'])
			&& $userSession->loginWithCookie($_COOKIE['nc_username'], $_COOKIE['nc_token'], $_COOKIE['nc_session_id'])) {
			return true;
		}
		if ($userSession->tryBasicAuthLogin($request, Server::get(IThrottler::class))) {
			return true;
		}
		return false;
	}

	protected static function handleAuthHeaders(): void {
		//copy http auth headers for apache+php-fcgid work around
		if (isset($_SERVER['HTTP_XAUTHORIZATION']) && !isset($_SERVER['HTTP_AUTHORIZATION'])) {
			$_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['HTTP_XAUTHORIZATION'];
		}

		// Extract PHP_AUTH_USER/PHP_AUTH_PW from other headers if necessary.
		$vars = [
			'HTTP_AUTHORIZATION', // apache+php-cgi work around
			'REDIRECT_HTTP_AUTHORIZATION', // apache+php-cgi alternative
		];
		foreach ($vars as $var) {
			if (isset($_SERVER[$var]) && is_string($_SERVER[$var]) && preg_match('/Basic\s+(.*)$/i', $_SERVER[$var], $matches)) {
				$credentials = explode(':', base64_decode($matches[1]), 2);
				if (count($credentials) === 2) {
					$_SERVER['PHP_AUTH_USER'] = $credentials[0];
					$_SERVER['PHP_AUTH_PW'] = $credentials[1];
					break;
				}
			}
		}
	}

	protected static function tryAppAPILogin(OCP\IRequest $request): bool {
		if (!$request->getHeader('AUTHORIZATION-APP-API')) {
			return false;
		}
		$appManager = Server::get(OCP\App\IAppManager::class);
		if (!$appManager->isEnabledForAnyone('app_api')) {
			return false;
		}
		try {
			$appAPIService = Server::get(OCA\AppAPI\Service\AppAPIService::class);
			return $appAPIService->validateExAppRequestToNC($request);
		} catch (\Psr\Container\NotFoundExceptionInterface|\Psr\Container\ContainerExceptionInterface $e) {
			return false;
		}
	}
}

OC::init();
