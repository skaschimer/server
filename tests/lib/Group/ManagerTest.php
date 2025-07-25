<?php

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */

namespace Test\Group;

use OC\Group\Database;
use OC\User\Manager;
use OC\User\User;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Group\Backend\ABackend;
use OCP\Group\Backend\IAddToGroupBackend;
use OCP\Group\Backend\ICreateGroupBackend;
use OCP\Group\Backend\IGroupDetailsBackend;
use OCP\Group\Backend\IRemoveFromGroupBackend;
use OCP\Group\Backend\ISearchableGroupBackend;
use OCP\GroupInterface;
use OCP\ICacheFactory;
use OCP\IUser;
use OCP\Security\Ip\IRemoteAddress;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

abstract class TestBackend extends ABackend implements ISearchableGroupBackend, IAddToGroupBackend, ICreateGroupBackend, IGroupDetailsBackend, IRemoveFromGroupBackend, GroupInterface {

}

class ManagerTest extends TestCase {
	/** @var Manager|MockObject */
	protected $userManager;
	/** @var IEventDispatcher|MockObject */
	protected $dispatcher;
	/** @var LoggerInterface|MockObject */
	protected $logger;
	/** @var ICacheFactory|MockObject */
	private $cache;
	/** @var IRemoteAddress|MockObject */
	private $remoteIpAddress;

	protected function setUp(): void {
		parent::setUp();

		$this->userManager = $this->createMock(Manager::class);
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->cache = $this->createMock(ICacheFactory::class);

		$this->remoteIpAddress = $this->createMock(IRemoteAddress::class);
		$this->remoteIpAddress->method('allowsAdminActions')->willReturn(true);
	}

	private function getTestUser($userId) {
		$mockUser = $this->createMock(IUser::class);
		$mockUser->expects($this->any())
			->method('getUID')
			->willReturn($userId);
		$mockUser->expects($this->any())
			->method('getDisplayName')
			->willReturn($userId);
		return $mockUser;
	}

	/**
	 * @param null|int $implementedActions
	 * @return \PHPUnit\Framework\MockObject\MockObject
	 */
	private function getTestBackend($implementedActions = null) {
		if ($implementedActions === null) {
			$implementedActions
				= GroupInterface::ADD_TO_GROUP
				| GroupInterface::REMOVE_FROM_GOUP
				| GroupInterface::COUNT_USERS
				| GroupInterface::CREATE_GROUP
				| GroupInterface::DELETE_GROUP;
		}
		// need to declare it this way due to optional methods
		// thanks to the implementsActions logic
		$backend = $this->getMockBuilder(TestBackend::class)
			->disableOriginalConstructor()
			->onlyMethods([
				'getGroupDetails',
				'implementsActions',
				'getUserGroups',
				'inGroup',
				'getGroups',
				'groupExists',
				'groupsExists',
				'usersInGroup',
				'createGroup',
				'addToGroup',
				'removeFromGroup',
				'searchInGroup',
			])
			->getMock();
		$backend->expects($this->any())
			->method('implementsActions')
			->willReturnCallback(function ($actions) use ($implementedActions) {
				return (bool)($actions & $implementedActions);
			});
		return $backend;
	}

	public function testGet(): void {
		/**
		 * @var \PHPUnit\Framework\MockObject\MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();
		$backend->expects($this->any())
			->method('groupExists')
			->with('group1')
			->willReturn(true);

		$manager = new \OC\Group\Manager($this->userManager, $this->dispatcher, $this->logger, $this->cache, $this->remoteIpAddress);
		$manager->addBackend($backend);

		$group = $manager->get('group1');
		$this->assertNotNull($group);
		$this->assertEquals('group1', $group->getGID());
	}

	public function testGetNoBackend(): void {
		$manager = new \OC\Group\Manager($this->userManager, $this->dispatcher, $this->logger, $this->cache, $this->remoteIpAddress);

		$this->assertNull($manager->get('group1'));
	}

	public function testGetNotExists(): void {
		/**
		 * @var \PHPUnit\Framework\MockObject\MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();
		$backend->expects($this->once())
			->method('groupExists')
			->with('group1')
			->willReturn(false);

		$manager = new \OC\Group\Manager($this->userManager, $this->dispatcher, $this->logger, $this->cache, $this->remoteIpAddress);
		$manager->addBackend($backend);

		$this->assertNull($manager->get('group1'));
	}

	public function testGetDeleted(): void {
		$backend = new \Test\Util\Group\Dummy();
		$backend->createGroup('group1');

		$manager = new \OC\Group\Manager($this->userManager, $this->dispatcher, $this->logger, $this->cache, $this->remoteIpAddress);
		$manager->addBackend($backend);

		$group = $manager->get('group1');
		$group->delete();
		$this->assertNull($manager->get('group1'));
	}

	public function testGetMultipleBackends(): void {
		/**
		 * @var \PHPUnit\Framework\MockObject\MockObject | \OC\Group\Backend $backend1
		 */
		$backend1 = $this->getTestBackend();
		$backend1->expects($this->any())
			->method('groupExists')
			->with('group1')
			->willReturn(false);

		/**
		 * @var \PHPUnit\Framework\MockObject\MockObject | \OC\Group\Backend $backend2
		 */
		$backend2 = $this->getTestBackend();
		$backend2->expects($this->any())
			->method('groupExists')
			->with('group1')
			->willReturn(true);

		$manager = new \OC\Group\Manager($this->userManager, $this->dispatcher, $this->logger, $this->cache, $this->remoteIpAddress);
		$manager->addBackend($backend1);
		$manager->addBackend($backend2);

		$group = $manager->get('group1');
		$this->assertNotNull($group);
		$this->assertEquals('group1', $group->getGID());
	}

	public function testCreate(): void {
		/** @var \PHPUnit\Framework\MockObject\MockObject|\OC\Group\Backend $backend */
		$backendGroupCreated = false;
		$backend = $this->getTestBackend();
		$backend->expects($this->any())
			->method('groupExists')
			->with('group1')
			->willReturnCallback(function () use (&$backendGroupCreated) {
				return $backendGroupCreated;
			});
		$backend->expects($this->once())
			->method('createGroup')
			->willReturnCallback(function () use (&$backendGroupCreated) {
				$backendGroupCreated = true;
				return true;
			});

		$manager = new \OC\Group\Manager($this->userManager, $this->dispatcher, $this->logger, $this->cache, $this->remoteIpAddress);
		$manager->addBackend($backend);

		$group = $manager->createGroup('group1');
		$this->assertEquals('group1', $group->getGID());
	}

	public function testCreateFailure(): void {
		/** @var \PHPUnit\Framework\MockObject\MockObject|\OC\Group\Backend $backend */
		$backendGroupCreated = false;
		$backend = $this->getTestBackend(
			GroupInterface::ADD_TO_GROUP
			| GroupInterface::REMOVE_FROM_GOUP
			| GroupInterface::COUNT_USERS
			| GroupInterface::CREATE_GROUP
			| GroupInterface::DELETE_GROUP
			| GroupInterface::GROUP_DETAILS
		);
		$backend->expects($this->any())
			->method('groupExists')
			->with('group1')
			->willReturn(false);
		$backend->expects($this->once())
			->method('createGroup')
			->willReturn(false);
		$backend->expects($this->once())
			->method('getGroupDetails')
			->willReturn([]);

		$manager = new \OC\Group\Manager($this->userManager, $this->dispatcher, $this->logger, $this->cache, $this->remoteIpAddress);
		$manager->addBackend($backend);

		$group = $manager->createGroup('group1');
		$this->assertEquals(null, $group);
	}

	public function testCreateTooLong(): void {
		/** @var \PHPUnit\Framework\MockObject\MockObject|\OC\Group\Backend $backend */
		$backendGroupCreated = false;
		$backend = $this->getTestBackend(
			GroupInterface::ADD_TO_GROUP
			| GroupInterface::REMOVE_FROM_GOUP
			| GroupInterface::COUNT_USERS
			| GroupInterface::CREATE_GROUP
			| GroupInterface::DELETE_GROUP
			| GroupInterface::GROUP_DETAILS
		);
		$groupName = str_repeat('x', 256);
		$backend->expects($this->any())
			->method('groupExists')
			->with($groupName)
			->willReturn(false);

		$manager = new \OC\Group\Manager($this->userManager, $this->dispatcher, $this->logger, $this->cache, $this->remoteIpAddress);
		$manager->addBackend($backend);

		$this->expectException(\Exception::class);
		$group = $manager->createGroup($groupName);
	}

	public function testCreateExists(): void {
		/** @var \PHPUnit\Framework\MockObject\MockObject|\OC\Group\Backend $backend */
		$backend = $this->getTestBackend();
		$backend->expects($this->any())
			->method('groupExists')
			->with('group1')
			->willReturn(true);
		$backend->expects($this->never())
			->method('createGroup');

		$manager = new \OC\Group\Manager($this->userManager, $this->dispatcher, $this->logger, $this->cache, $this->remoteIpAddress);
		$manager->addBackend($backend);

		$group = $manager->createGroup('group1');
		$this->assertEquals('group1', $group->getGID());
	}

	public function testSearch(): void {
		/**
		 * @var \PHPUnit\Framework\MockObject\MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();
		$backend->expects($this->once())
			->method('getGroups')
			->with('1')
			->willReturn(['group1']);
		$backend->expects($this->once())
			->method('getGroupDetails')
			->willReturnMap([
				['group1', ['displayName' => 'group1']],
			]);

		$manager = new \OC\Group\Manager($this->userManager, $this->dispatcher, $this->logger, $this->cache, $this->remoteIpAddress);
		$manager->addBackend($backend);

		$groups = $manager->search('1');
		$this->assertCount(1, $groups);
		$group1 = reset($groups);
		$this->assertEquals('group1', $group1->getGID());
	}

	public function testSearchMultipleBackends(): void {
		/**
		 * @var \PHPUnit\Framework\MockObject\MockObject | \OC\Group\Backend $backend1
		 */
		$backend1 = $this->getTestBackend();
		$backend1->expects($this->once())
			->method('getGroups')
			->with('1')
			->willReturn(['group1']);
		$backend1->expects($this->any())
			->method('getGroupDetails')
			->willReturnMap([
				['group1', ['displayName' => 'group1']],
				['group12', []],
			]);

		/**
		 * @var \PHPUnit\Framework\MockObject\MockObject | \OC\Group\Backend $backend2
		 */
		$backend2 = $this->getTestBackend();
		$backend2->expects($this->once())
			->method('getGroups')
			->with('1')
			->willReturn(['group12', 'group1']);
		$backend2->expects($this->any())
			->method('getGroupDetails')
			->willReturnMap([
				['group12', ['displayName' => 'group12']],
				['group1', ['displayName' => 'group1']],
			]);

		$manager = new \OC\Group\Manager($this->userManager, $this->dispatcher, $this->logger, $this->cache, $this->remoteIpAddress);
		$manager->addBackend($backend1);
		$manager->addBackend($backend2);

		$groups = $manager->search('1');
		$this->assertCount(2, $groups);
		$group1 = reset($groups);
		$group12 = next($groups);
		$this->assertEquals('group1', $group1->getGID());
		$this->assertEquals('group12', $group12->getGID());
	}

	public function testSearchMultipleBackendsLimitAndOffset(): void {
		/**
		 * @var \PHPUnit\Framework\MockObject\MockObject|\OC\Group\Backend $backend1
		 */
		$backend1 = $this->getTestBackend();
		$backend1->expects($this->once())
			->method('getGroups')
			->with('1', 2, 1)
			->willReturn(['group1']);
		$backend1->expects($this->any())
			->method('getGroupDetails')
			->willReturnMap([
				[1, []],
				[2, []],
				['group1', ['displayName' => 'group1']],
				['group12', []],
			]);

		/**
		 * @var \PHPUnit\Framework\MockObject\MockObject|\OC\Group\Backend $backend2
		 */
		$backend2 = $this->getTestBackend();
		$backend2->expects($this->once())
			->method('getGroups')
			->with('1', 2, 1)
			->willReturn(['group12']);
		$backend2->expects($this->any())
			->method('getGroupDetails')
			->willReturnMap([
				[1, []],
				[2, []],
				['group1', []],
				['group12', ['displayName' => 'group12']],
			]);

		$manager = new \OC\Group\Manager($this->userManager, $this->dispatcher, $this->logger, $this->cache, $this->remoteIpAddress);
		$manager->addBackend($backend1);
		$manager->addBackend($backend2);

		$groups = $manager->search('1', 2, 1);
		$this->assertCount(2, $groups);
		$group1 = reset($groups);
		$group12 = next($groups);
		$this->assertEquals('group1', $group1->getGID());
		$this->assertEquals('group12', $group12->getGID());
	}

	public function testSearchResultExistsButGroupDoesNot(): void {
		/** @var \PHPUnit\Framework\MockObject\MockObject|\OC\Group\Backend $backend */
		$backend = $this->createMock(Database::class);
		$backend->expects($this->once())
			->method('getGroups')
			->with('1')
			->willReturn(['group1']);
		$backend->expects($this->never())
			->method('groupExists');
		$backend->expects($this->once())
			->method('getGroupsDetails')
			->with(['group1'])
			->willReturn([]);

		/** @var \OC\User\Manager $userManager */
		$userManager = $this->createMock(Manager::class);

		$manager = new \OC\Group\Manager($userManager, $this->dispatcher, $this->logger, $this->cache, $this->remoteIpAddress);
		$manager->addBackend($backend);

		$groups = $manager->search('1');
		$this->assertEmpty($groups);
	}

	public function testGetUserGroups(): void {
		/**
		 * @var \PHPUnit\Framework\MockObject\MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();
		$backend->expects($this->once())
			->method('getUserGroups')
			->with('user1')
			->willReturn(['group1']);
		$backend->expects($this->any())
			->method('groupExists')
			->with('group1')
			->willReturn(true);

		$manager = new \OC\Group\Manager($this->userManager, $this->dispatcher, $this->logger, $this->cache, $this->remoteIpAddress);
		$manager->addBackend($backend);

		$groups = $manager->getUserGroups($this->getTestUser('user1'));
		$this->assertCount(1, $groups);
		$group1 = reset($groups);
		$this->assertEquals('group1', $group1->getGID());
	}

	public function testGetUserGroupIds(): void {
		/**
		 * @var \PHPUnit\Framework\MockObject\MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();
		$backend->method('getUserGroups')
			->with('myUID')
			->willReturn(['123', 'abc']);

		$manager = new \OC\Group\Manager($this->userManager, $this->dispatcher, $this->logger, $this->cache, $this->remoteIpAddress);
		$manager->addBackend($backend);

		/** @var User|\PHPUnit\Framework\MockObject\MockObject $user */
		$user = $this->createMock(IUser::class);
		$user->method('getUID')
			->willReturn('myUID');

		$groups = $manager->getUserGroupIds($user);
		$this->assertCount(2, $groups);

		foreach ($groups as $group) {
			$this->assertIsString($group);
		}
	}

	public function testGetUserGroupsWithDeletedGroup(): void {
		/**
		 * @var \PHPUnit\Framework\MockObject\MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->createMock(Database::class);
		$backend->expects($this->once())
			->method('getUserGroups')
			->with('user1')
			->willReturn(['group1']);
		$backend->expects($this->any())
			->method('groupExists')
			->with('group1')
			->willReturn(false);

		$manager = new \OC\Group\Manager($this->userManager, $this->dispatcher, $this->logger, $this->cache, $this->remoteIpAddress);
		$manager->addBackend($backend);

		/** @var User|\PHPUnit\Framework\MockObject\MockObject $user */
		$user = $this->createMock(IUser::class);
		$user->expects($this->atLeastOnce())
			->method('getUID')
			->willReturn('user1');

		$groups = $manager->getUserGroups($user);
		$this->assertEmpty($groups);
	}

	public function testInGroup(): void {
		/**
		 * @var \PHPUnit\Framework\MockObject\MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();
		$backend->expects($this->once())
			->method('getUserGroups')
			->with('user1')
			->willReturn(['group1', 'admin', 'group2']);
		$backend->expects($this->any())
			->method('groupExists')
			->willReturn(true);

		$manager = new \OC\Group\Manager($this->userManager, $this->dispatcher, $this->logger, $this->cache, $this->remoteIpAddress);
		$manager->addBackend($backend);

		$this->assertTrue($manager->isInGroup('user1', 'group1'));
	}

	public function testIsAdmin(): void {
		/**
		 * @var \PHPUnit\Framework\MockObject\MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();
		$backend->expects($this->once())
			->method('getUserGroups')
			->with('user1')
			->willReturn(['group1', 'admin', 'group2']);
		$backend->expects($this->any())
			->method('groupExists')
			->willReturn(true);

		$manager = new \OC\Group\Manager($this->userManager, $this->dispatcher, $this->logger, $this->cache, $this->remoteIpAddress);
		$manager->addBackend($backend);

		$this->assertTrue($manager->isAdmin('user1'));
	}

	public function testNotAdmin(): void {
		/**
		 * @var \PHPUnit\Framework\MockObject\MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();
		$backend->expects($this->once())
			->method('getUserGroups')
			->with('user1')
			->willReturn(['group1', 'group2']);
		$backend->expects($this->any())
			->method('groupExists')
			->willReturn(true);

		$manager = new \OC\Group\Manager($this->userManager, $this->dispatcher, $this->logger, $this->cache, $this->remoteIpAddress);
		$manager->addBackend($backend);

		$this->assertFalse($manager->isAdmin('user1'));
	}

	public function testGetUserGroupsMultipleBackends(): void {
		/**
		 * @var \PHPUnit\Framework\MockObject\MockObject | \OC\Group\Backend $backend1
		 */
		$backend1 = $this->getTestBackend();
		$backend1->expects($this->once())
			->method('getUserGroups')
			->with('user1')
			->willReturn(['group1']);
		$backend1->expects($this->any())
			->method('groupExists')
			->willReturn(true);

		/**
		 * @var \PHPUnit\Framework\MockObject\MockObject | \OC\Group\Backend $backend2
		 */
		$backend2 = $this->getTestBackend();
		$backend2->expects($this->once())
			->method('getUserGroups')
			->with('user1')
			->willReturn(['group1', 'group2']);
		$backend1->expects($this->any())
			->method('groupExists')
			->willReturn(true);

		$manager = new \OC\Group\Manager($this->userManager, $this->dispatcher, $this->logger, $this->cache, $this->remoteIpAddress);
		$manager->addBackend($backend1);
		$manager->addBackend($backend2);

		$groups = $manager->getUserGroups($this->getTestUser('user1'));
		$this->assertCount(2, $groups);
		$group1 = reset($groups);
		$group2 = next($groups);
		$this->assertEquals('group1', $group1->getGID());
		$this->assertEquals('group2', $group2->getGID());
	}

	public function testDisplayNamesInGroupWithOneUserBackend(): void {
		/**
		 * @var \PHPUnit\Framework\MockObject\MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();
		$backend->expects($this->exactly(1))
			->method('groupExists')
			->with('testgroup')
			->willReturn(true);

		$backend->expects($this->any())
			->method('inGroup')
			->willReturnCallback(function ($uid, $gid) {
				switch ($uid) {
					case 'user1': return false;
					case 'user2': return true;
					case 'user3': return false;
					case 'user33': return true;
					default:
						return null;
				}
			});

		$this->userManager->expects($this->any())
			->method('searchDisplayName')
			->with('user3')
			->willReturnCallback(function ($search, $limit, $offset) {
				switch ($offset) {
					case 0: return ['user3' => $this->getTestUser('user3'),
						'user33' => $this->getTestUser('user33')];
					case 2: return [];
				}
				return null;
			});
		$this->userManager->expects($this->any())
			->method('get')
			->willReturnCallback(function ($uid) {
				switch ($uid) {
					case 'user1': return $this->getTestUser('user1');
					case 'user2': return $this->getTestUser('user2');
					case 'user3': return $this->getTestUser('user3');
					case 'user33': return $this->getTestUser('user33');
					default:
						return null;
				}
			});

		$manager = new \OC\Group\Manager($this->userManager, $this->dispatcher, $this->logger, $this->cache, $this->remoteIpAddress);
		$manager->addBackend($backend);

		$users = $manager->displayNamesInGroup('testgroup', 'user3');
		$this->assertCount(1, $users);
		$this->assertFalse(isset($users['user1']));
		$this->assertFalse(isset($users['user2']));
		$this->assertFalse(isset($users['user3']));
		$this->assertTrue(isset($users['user33']));
	}

	public function testDisplayNamesInGroupWithOneUserBackendWithLimitSpecified(): void {
		/**
		 * @var \PHPUnit\Framework\MockObject\MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();
		$backend->expects($this->exactly(1))
			->method('groupExists')
			->with('testgroup')
			->willReturn(true);

		$backend->expects($this->any())
			->method('inGroup')
			->willReturnCallback(function ($uid, $gid) {
				switch ($uid) {
					case 'user1': return false;
					case 'user2': return true;
					case 'user3': return false;
					case 'user33': return true;
					case 'user333': return true;
					default:
						return null;
				}
			});

		$this->userManager->expects($this->any())
			->method('searchDisplayName')
			->with('user3')
			->willReturnCallback(function ($search, $limit, $offset) {
				switch ($offset) {
					case 0: return ['user3' => $this->getTestUser('user3'),
						'user33' => $this->getTestUser('user33')];
					case 2: return ['user333' => $this->getTestUser('user333')];
				}
				return null;
			});
		$this->userManager->expects($this->any())
			->method('get')
			->willReturnCallback(function ($uid) {
				switch ($uid) {
					case 'user1': return $this->getTestUser('user1');
					case 'user2': return $this->getTestUser('user2');
					case 'user3': return $this->getTestUser('user3');
					case 'user33': return $this->getTestUser('user33');
					case 'user333': return $this->getTestUser('user333');
					default:
						return null;
				}
			});

		$manager = new \OC\Group\Manager($this->userManager, $this->dispatcher, $this->logger, $this->cache, $this->remoteIpAddress);
		$manager->addBackend($backend);

		$users = $manager->displayNamesInGroup('testgroup', 'user3', 1);
		$this->assertCount(1, $users);
		$this->assertFalse(isset($users['user1']));
		$this->assertFalse(isset($users['user2']));
		$this->assertFalse(isset($users['user3']));
		$this->assertTrue(isset($users['user33']));
		$this->assertFalse(isset($users['user333']));
	}

	public function testDisplayNamesInGroupWithOneUserBackendWithLimitAndOffsetSpecified(): void {
		/**
		 * @var \PHPUnit\Framework\MockObject\MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();
		$backend->expects($this->exactly(1))
			->method('groupExists')
			->with('testgroup')
			->willReturn(true);

		$backend->expects($this->any())
			->method('inGroup')
			->willReturnCallback(function ($uid) {
				switch ($uid) {
					case 'user1': return false;
					case 'user2': return true;
					case 'user3': return false;
					case 'user33': return true;
					case 'user333': return true;
					default:
						return null;
				}
			});

		$this->userManager->expects($this->any())
			->method('searchDisplayName')
			->with('user3')
			->willReturnCallback(function ($search, $limit, $offset) {
				switch ($offset) {
					case 0:
						return [
							'user3' => $this->getTestUser('user3'),
							'user33' => $this->getTestUser('user33'),
							'user333' => $this->getTestUser('user333')
						];
				}
				return null;
			});
		$this->userManager->expects($this->any())
			->method('get')
			->willReturnCallback(function ($uid) {
				switch ($uid) {
					case 'user1': return $this->getTestUser('user1');
					case 'user2': return $this->getTestUser('user2');
					case 'user3': return $this->getTestUser('user3');
					case 'user33': return $this->getTestUser('user33');
					case 'user333': return $this->getTestUser('user333');
					default:
						return null;
				}
			});

		$manager = new \OC\Group\Manager($this->userManager, $this->dispatcher, $this->logger, $this->cache, $this->remoteIpAddress);
		$manager->addBackend($backend);

		$users = $manager->displayNamesInGroup('testgroup', 'user3', 1, 1);
		$this->assertCount(1, $users);
		$this->assertFalse(isset($users['user1']));
		$this->assertFalse(isset($users['user2']));
		$this->assertFalse(isset($users['user3']));
		$this->assertFalse(isset($users['user33']));
		$this->assertTrue(isset($users['user333']));
	}

	public function testDisplayNamesInGroupWithOneUserBackendAndSearchEmpty(): void {
		/**
		 * @var \PHPUnit\Framework\MockObject\MockObject|\OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();
		$backend->expects($this->exactly(1))
			->method('groupExists')
			->with('testgroup')
			->willReturn(true);

		$backend->expects($this->once())
			->method('searchInGroup')
			->with('testgroup', '', -1, 0)
			->willReturn(['user2' => $this->getTestUser('user2'), 'user33' => $this->getTestUser('user33')]);

		$this->userManager->expects($this->never())->method('get');

		$manager = new \OC\Group\Manager($this->userManager, $this->dispatcher, $this->logger, $this->cache, $this->remoteIpAddress);
		$manager->addBackend($backend);

		$users = $manager->displayNamesInGroup('testgroup', '');
		$this->assertCount(2, $users);
		$this->assertFalse(isset($users['user1']));
		$this->assertTrue(isset($users['user2']));
		$this->assertFalse(isset($users['user3']));
		$this->assertTrue(isset($users['user33']));
	}

	public function testDisplayNamesInGroupWithOneUserBackendAndSearchEmptyAndLimitSpecified(): void {
		/**
		 * @var \PHPUnit\Framework\MockObject\MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();
		$backend->expects($this->exactly(1))
			->method('groupExists')
			->with('testgroup')
			->willReturn(true);

		$backend->expects($this->once())
			->method('searchInGroup')
			->with('testgroup', '', 1, 0)
			->willReturn([new User('user2', null, $this->dispatcher)]);

		$this->userManager->expects($this->never())->method('get');

		$manager = new \OC\Group\Manager($this->userManager, $this->dispatcher, $this->logger, $this->cache, $this->remoteIpAddress);
		$manager->addBackend($backend);

		$users = $manager->displayNamesInGroup('testgroup', '', 1);
		$this->assertCount(1, $users);
		$this->assertFalse(isset($users['user1']));
		$this->assertTrue(isset($users['user2']));
		$this->assertFalse(isset($users['user3']));
		$this->assertFalse(isset($users['user33']));
	}

	public function testDisplayNamesInGroupWithOneUserBackendAndSearchEmptyAndLimitAndOffsetSpecified(): void {
		/**
		 * @var \PHPUnit\Framework\MockObject\MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();
		$backend->expects($this->exactly(1))
			->method('groupExists')
			->with('testgroup')
			->willReturn(true);

		$backend->expects($this->once())
			->method('searchInGroup')
			->with('testgroup', '', 1, 1)
			->willReturn(['user33' => $this->getTestUser('user33')]);

		$this->userManager->expects($this->never())->method('get');

		$manager = new \OC\Group\Manager($this->userManager, $this->dispatcher, $this->logger, $this->cache, $this->remoteIpAddress);
		$manager->addBackend($backend);

		$users = $manager->displayNamesInGroup('testgroup', '', 1, 1);
		$this->assertCount(1, $users);
		$this->assertFalse(isset($users['user1']));
		$this->assertFalse(isset($users['user2']));
		$this->assertFalse(isset($users['user3']));
		$this->assertTrue(isset($users['user33']));
	}

	public function testGetUserGroupsWithAddUser(): void {
		/**
		 * @var \PHPUnit\Framework\MockObject\MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();
		$expectedGroups = [];
		$backend->expects($this->any())
			->method('getUserGroups')
			->with('user1')
			->willReturnCallback(function () use (&$expectedGroups) {
				return $expectedGroups;
			});
		$backend->expects($this->any())
			->method('groupExists')
			->with('group1')
			->willReturn(true);

		$manager = new \OC\Group\Manager($this->userManager, $this->dispatcher, $this->logger, $this->cache, $this->remoteIpAddress);
		$manager->addBackend($backend);

		// prime cache
		$user1 = $this->getTestUser('user1');
		$groups = $manager->getUserGroups($user1);
		$this->assertEquals([], $groups);

		// add user
		$group = $manager->get('group1');
		$group->addUser($user1);
		$expectedGroups[] = 'group1';

		// check result
		$groups = $manager->getUserGroups($user1);
		$this->assertCount(1, $groups);
		$group1 = reset($groups);
		$this->assertEquals('group1', $group1->getGID());
	}

	public function testGetUserGroupsWithRemoveUser(): void {
		/**
		 * @var \PHPUnit\Framework\MockObject\MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();
		$expectedGroups = ['group1'];
		$backend->expects($this->any())
			->method('getUserGroups')
			->with('user1')
			->willReturnCallback(function () use (&$expectedGroups) {
				return $expectedGroups;
			});
		$backend->expects($this->any())
			->method('groupExists')
			->with('group1')
			->willReturn(true);
		$backend->expects($this->once())
			->method('inGroup')
			->willReturn(true);
		$backend->expects($this->once())
			->method('removeFromGroup')
			->willReturn(true);

		$manager = new \OC\Group\Manager($this->userManager, $this->dispatcher, $this->logger, $this->cache, $this->remoteIpAddress);
		$manager->addBackend($backend);

		// prime cache
		$user1 = $this->getTestUser('user1');
		$groups = $manager->getUserGroups($user1);
		$this->assertCount(1, $groups);
		$group1 = reset($groups);
		$this->assertEquals('group1', $group1->getGID());

		// remove user
		$group = $manager->get('group1');
		$group->removeUser($user1);
		$expectedGroups = [];

		// check result
		$groups = $manager->getUserGroups($user1);
		$this->assertEquals($expectedGroups, $groups);
	}

	public function testGetUserIdGroups(): void {
		/**
		 * @var \PHPUnit\Framework\MockObject\MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();
		$backend->expects($this->any())
			->method('getUserGroups')
			->with('user1')
			->willReturn(null);

		$manager = new \OC\Group\Manager($this->userManager, $this->dispatcher, $this->logger, $this->cache, $this->remoteIpAddress);
		$manager->addBackend($backend);

		$groups = $manager->getUserIdGroups('user1');
		$this->assertEquals([], $groups);
	}

	public function testGroupDisplayName(): void {
		/**
		 * @var \PHPUnit\Framework\MockObject\MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend(
			GroupInterface::ADD_TO_GROUP
			| GroupInterface::REMOVE_FROM_GOUP
			| GroupInterface::COUNT_USERS
			| GroupInterface::CREATE_GROUP
			| GroupInterface::DELETE_GROUP
			| GroupInterface::GROUP_DETAILS
		);
		$backend->expects($this->any())
			->method('getGroupDetails')
			->willReturnMap([
				['group1', ['gid' => 'group1', 'displayName' => 'Group One']],
				['group2', ['gid' => 'group2']],
			]);
		$manager = new \OC\Group\Manager($this->userManager, $this->dispatcher, $this->logger, $this->cache, $this->remoteIpAddress);
		$manager->addBackend($backend);

		// group with display name
		$group = $manager->get('group1');
		$this->assertNotNull($group);
		$this->assertEquals('group1', $group->getGID());
		$this->assertEquals('Group One', $group->getDisplayName());

		// group without display name
		$group = $manager->get('group2');
		$this->assertNotNull($group);
		$this->assertEquals('group2', $group->getGID());
		$this->assertEquals('group2', $group->getDisplayName());
	}
}
