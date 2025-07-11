<?php

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */

namespace Test\Avatar;

use OC\Avatar\AvatarManager;
use OC\Avatar\PlaceholderAvatar;
use OC\Avatar\UserAvatar;
use OC\KnownUser\KnownUserService;
use OC\User\Manager;
use OC\User\User;
use OCP\Accounts\IAccount;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\IAccountProperty;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Class AvatarManagerTest
 */
class AvatarManagerTest extends \Test\TestCase {
	/** @var IUserSession|\PHPUnit\Framework\MockObject\MockObject */
	private $userSession;
	/** @var Manager|\PHPUnit\Framework\MockObject\MockObject */
	private $userManager;
	/** @var IAppData|\PHPUnit\Framework\MockObject\MockObject */
	private $appData;
	/** @var IL10N|\PHPUnit\Framework\MockObject\MockObject */
	private $l10n;
	/** @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject */
	private $logger;
	/** @var IConfig|\PHPUnit\Framework\MockObject\MockObject */
	private $config;
	/** @var IAccountManager|\PHPUnit\Framework\MockObject\MockObject */
	private $accountManager;
	/** @var AvatarManager | \PHPUnit\Framework\MockObject\MockObject */
	private $avatarManager;
	/** @var KnownUserService | \PHPUnit\Framework\MockObject\MockObject */
	private $knownUserService;

	protected function setUp(): void {
		parent::setUp();

		$this->userSession = $this->createMock(IUserSession::class);
		$this->userManager = $this->createMock(Manager::class);
		$this->appData = $this->createMock(IAppData::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->config = $this->createMock(IConfig::class);
		$this->accountManager = $this->createMock(IAccountManager::class);
		$this->knownUserService = $this->createMock(KnownUserService::class);

		$this->avatarManager = new AvatarManager(
			$this->userSession,
			$this->userManager,
			$this->appData,
			$this->l10n,
			$this->logger,
			$this->config,
			$this->accountManager,
			$this->knownUserService
		);
	}

	public function testGetAvatarInvalidUser(): void {
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('user does not exist');

		$this->userManager
			->expects($this->once())
			->method('get')
			->with('invalidUser')
			->willReturn(null);

		$this->avatarManager->getAvatar('invalidUser');
	}

	public function testGetAvatarForSelf(): void {
		$user = $this->createMock(User::class);
		$user
			->expects($this->any())
			->method('getUID')
			->willReturn('valid-user');

		$user
			->expects($this->any())
			->method('isEnabled')
			->willReturn(true);

		// requesting user
		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn($user);

		$this->userManager
			->expects($this->once())
			->method('get')
			->with('valid-user')
			->willReturn($user);

		$account = $this->createMock(IAccount::class);
		$this->accountManager->expects($this->once())
			->method('getAccount')
			->with($user)
			->willReturn($account);

		$property = $this->createMock(IAccountProperty::class);
		$account->expects($this->once())
			->method('getProperty')
			->with(IAccountManager::PROPERTY_AVATAR)
			->willReturn($property);

		$property->expects($this->once())
			->method('getScope')
			->willReturn(IAccountManager::SCOPE_PRIVATE);

		$this->knownUserService->expects($this->any())
			->method('isKnownToUser')
			->with('valid-user', 'valid-user')
			->willReturn(true);

		$folder = $this->createMock(ISimpleFolder::class);
		$this->appData
			->expects($this->once())
			->method('getFolder')
			->with('valid-user')
			->willReturn($folder);

		$expected = new UserAvatar($folder, $this->l10n, $user, $this->logger, $this->config);
		$this->assertEquals($expected, $this->avatarManager->getAvatar('valid-user'));
	}

	public function testGetAvatarValidUserDifferentCasing(): void {
		$user = $this->createMock(User::class);
		$this->userManager->expects($this->once())
			->method('get')
			->with('vaLid-USER')
			->willReturn($user);

		$user->expects($this->once())
			->method('getUID')
			->willReturn('valid-user');

		$user
			->expects($this->any())
			->method('isEnabled')
			->willReturn(true);

		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn($user);

		$folder = $this->createMock(ISimpleFolder::class);
		$this->appData
			->expects($this->once())
			->method('getFolder')
			->with('valid-user')
			->willReturn($folder);

		$account = $this->createMock(IAccount::class);
		$this->accountManager->expects($this->once())
			->method('getAccount')
			->with($user)
			->willReturn($account);

		$property = $this->createMock(IAccountProperty::class);
		$account->expects($this->once())
			->method('getProperty')
			->with(IAccountManager::PROPERTY_AVATAR)
			->willReturn($property);

		$property->expects($this->once())
			->method('getScope')
			->willReturn(IAccountManager::SCOPE_FEDERATED);

		$expected = new UserAvatar($folder, $this->l10n, $user, $this->logger, $this->config);
		$this->assertEquals($expected, $this->avatarManager->getAvatar('vaLid-USER'));
	}

	public static function dataGetAvatarScopes(): array {
		return [
			// public access cannot see real avatar
			[IAccountManager::SCOPE_PRIVATE, true, false, true],
			// unknown users cannot see real avatar
			[IAccountManager::SCOPE_PRIVATE, false, false, true],
			// known users can see real avatar
			[IAccountManager::SCOPE_PRIVATE, false, true, false],
			[IAccountManager::SCOPE_LOCAL, false, false, false],
			[IAccountManager::SCOPE_LOCAL, true, false, false],
			[IAccountManager::SCOPE_FEDERATED, false, false, false],
			[IAccountManager::SCOPE_FEDERATED, true, false, false],
			[IAccountManager::SCOPE_PUBLISHED, false, false, false],
			[IAccountManager::SCOPE_PUBLISHED, true, false, false],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('dataGetAvatarScopes')]
	public function testGetAvatarScopes($avatarScope, $isPublicCall, $isKnownUser, $expectedPlaceholder): void {
		if ($isPublicCall) {
			$requestingUser = null;
		} else {
			$requestingUser = $this->createMock(IUser::class);
			$requestingUser->method('getUID')->willReturn('requesting-user');
		}

		// requesting user
		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn($requestingUser);

		$user = $this->createMock(User::class);
		$user
			->expects($this->once())
			->method('getUID')
			->willReturn('valid-user');

		$user
			->expects($this->any())
			->method('isEnabled')
			->willReturn(true);

		$this->userManager
			->expects($this->once())
			->method('get')
			->with('valid-user')
			->willReturn($user);

		$account = $this->createMock(IAccount::class);
		$this->accountManager->expects($this->once())
			->method('getAccount')
			->with($user)
			->willReturn($account);

		$property = $this->createMock(IAccountProperty::class);
		$account->expects($this->once())
			->method('getProperty')
			->with(IAccountManager::PROPERTY_AVATAR)
			->willReturn($property);

		$property->expects($this->once())
			->method('getScope')
			->willReturn($avatarScope);

		$folder = $this->createMock(ISimpleFolder::class);
		$this->appData
			->expects($this->once())
			->method('getFolder')
			->with('valid-user')
			->willReturn($folder);

		if (!$isPublicCall) {
			$this->knownUserService->expects($this->any())
				->method('isKnownToUser')
				->with('requesting-user', 'valid-user')
				->willReturn($isKnownUser);
		} else {
			$this->knownUserService->expects($this->never())
				->method('isKnownToUser');
		}

		if ($expectedPlaceholder) {
			$expected = new PlaceholderAvatar($folder, $user, $this->createMock(LoggerInterface::class));
		} else {
			$expected = new UserAvatar($folder, $this->l10n, $user, $this->logger, $this->config);
		}
		$this->assertEquals($expected, $this->avatarManager->getAvatar('valid-user'));
	}
}
