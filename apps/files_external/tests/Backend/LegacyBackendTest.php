<?php

/**
 * SPDX-FileCopyrightText: 2019-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\Files_External\Tests\Backend;

use OCA\Files_External\Lib\Auth\Builtin;
use OCA\Files_External\Lib\Backend\LegacyBackend;
use OCA\Files_External\Lib\DefinitionParameter;
use OCA\Files_External\Lib\MissingDependency;

class LegacyBackendTest extends \Test\TestCase {

	/**
	 * @return MissingDependency[]
	 */
	public static function checkDependencies(): array {
		return [
			(new MissingDependency('abc'))->setMessage('foobar')
		];
	}

	public function testConstructor(): void {
		$auth = $this->createMock(Builtin::class);

		$class = self::class;
		$definition = [
			'configuration' => [
				'textfield' => 'Text field',
				'passwordfield' => '*Password field',
				'checkbox' => '!Checkbox',
				'optionaltext' => '&Optional text field',
				'optionalpassword' => '&*Optional password field',
			],
			'backend' => 'Backend text',
			'priority' => 123,
			'custom' => 'foo/bar.js',
			'has_dependencies' => true,
		];

		$backend = new LegacyBackend($class, $definition, $auth);

		$this->assertEquals(self::class, $backend->getStorageClass());
		$this->assertEquals('Backend text', $backend->getText());
		$this->assertEquals(123, $backend->getPriority());
		$this->assertContains('foo/bar.js', $backend->getCustomJs());
		$this->assertArrayHasKey('builtin', $backend->getAuthSchemes());
		$this->assertEquals($auth, $backend->getLegacyAuthMechanism());

		$dependencies = $backend->checkDependencies();
		$this->assertCount(1, $dependencies);
		$this->assertEquals('abc', $dependencies[0]->getDependency());
		$this->assertEquals('foobar', $dependencies[0]->getMessage());

		$parameters = $backend->getParameters();
		$this->assertEquals('Text field', $parameters['textfield']->getText());
		$this->assertEquals(DefinitionParameter::VALUE_TEXT, $parameters['textfield']->getType());
		$this->assertEquals(DefinitionParameter::FLAG_NONE, $parameters['textfield']->getFlags());
		$this->assertEquals('Password field', $parameters['passwordfield']->getText());
		$this->assertEquals(DefinitionParameter::VALUE_PASSWORD, $parameters['passwordfield']->getType());
		$this->assertEquals(DefinitionParameter::FLAG_NONE, $parameters['passwordfield']->getFlags());
		$this->assertEquals('Checkbox', $parameters['checkbox']->getText());
		$this->assertEquals(DefinitionParameter::VALUE_BOOLEAN, $parameters['checkbox']->getType());
		$this->assertEquals(DefinitionParameter::FLAG_NONE, $parameters['checkbox']->getFlags());
		$this->assertEquals('Optional text field', $parameters['optionaltext']->getText());
		$this->assertEquals(DefinitionParameter::VALUE_TEXT, $parameters['optionaltext']->getType());
		$this->assertEquals(DefinitionParameter::FLAG_OPTIONAL, $parameters['optionaltext']->getFlags());
		$this->assertEquals('Optional password field', $parameters['optionalpassword']->getText());
		$this->assertEquals(DefinitionParameter::VALUE_PASSWORD, $parameters['optionalpassword']->getType());
		$this->assertEquals(DefinitionParameter::FLAG_OPTIONAL, $parameters['optionalpassword']->getFlags());
	}

	public function testNoDependencies(): void {
		$auth = $this->createMock(Builtin::class);

		$class = self::class;
		$definition = [
			'configuration' => [
			],
			'backend' => 'Backend text',
		];

		$backend = new LegacyBackend($class, $definition, $auth);

		$dependencies = $backend->checkDependencies();
		$this->assertCount(0, $dependencies);
	}
}
