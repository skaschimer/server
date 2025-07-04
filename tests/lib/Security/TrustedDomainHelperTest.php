<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Test\Security;

use OC\Security\TrustedDomainHelper;
use OCP\IConfig;

/**
 * Class TrustedDomainHelperTest
 */
class TrustedDomainHelperTest extends \Test\TestCase {
	/** @var IConfig */
	protected $config;

	protected function setUp(): void {
		parent::setUp();

		$this->config = $this->getMockBuilder(IConfig::class)->getMock();
	}

	/**
	 * @param string $trustedDomains
	 * @param string $testDomain
	 * @param bool $result
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('trustedDomainDataProvider')]
	public function testIsTrustedUrl($trustedDomains, $testDomain, $result): void {
		$this->config->method('getSystemValue')
			->willReturnMap([
				['overwritehost', '', ''],
				['trusted_domains', [], $trustedDomains],
			]);

		$trustedDomainHelper = new TrustedDomainHelper($this->config);
		$this->assertEquals($result, $trustedDomainHelper->isTrustedUrl('https://' . $testDomain . '/index.php/something'));
	}

	/**
	 * @param string $trustedDomains
	 * @param string $testDomain
	 * @param bool $result
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('trustedDomainDataProvider')]
	public function testIsTrustedDomain($trustedDomains, $testDomain, $result): void {
		$this->config->method('getSystemValue')
			->willReturnMap([
				['overwritehost', '', ''],
				['trusted_domains', [], $trustedDomains],
			]);

		$trustedDomainHelper = new TrustedDomainHelper($this->config);
		$this->assertEquals($result, $trustedDomainHelper->isTrustedDomain($testDomain));
	}

	/**
	 * @return array
	 */
	public static function trustedDomainDataProvider(): array {
		$trustedHostTestList = [
			'host.one.test',
			'host.two.test',
			'[1fff:0:a88:85a3::ac1f]',
			'host.three.test:443',
			'*.leading.host',
			'trailing.host*',
			'cen*ter',
			'*.leadingwith.port:123',
			'trailingwith.port*:456',
			'UPPERCASE.DOMAIN',
			'lowercase.domain',
		];
		return [
			// empty defaults to false with 8.1
			[null, 'host.one.test:8080', false],
			['', 'host.one.test:8080', false],
			[[], 'host.one.test:8080', false],
			// trust list when defined
			[$trustedHostTestList, 'host.two.test:8080', true],
			[$trustedHostTestList, 'host.two.test:9999', true],
			[$trustedHostTestList, 'host.three.test:8080', false],
			[$trustedHostTestList, 'host.two.test:8080:aa:222', false],
			[$trustedHostTestList, '[1fff:0:a88:85a3::ac1f]', true],
			[$trustedHostTestList, '[1fff:0:a88:85a3::ac1f]:801', true],
			[$trustedHostTestList, '[1fff:0:a88:85a3::ac1f]:801:34', false],
			[$trustedHostTestList, 'host.three.test:443', true],
			[$trustedHostTestList, 'host.three.test:80', false],
			[$trustedHostTestList, 'host.three.test', false],
			// trust localhost regardless of trust list
			[$trustedHostTestList, 'localhost', true],
			[$trustedHostTestList, 'localhost:8080', true],
			[$trustedHostTestList, '127.0.0.1', true],
			[$trustedHostTestList, '127.0.0.1:8080', true],
			// do not trust invalid localhosts
			[$trustedHostTestList, 'localhost:1:2', false],
			[$trustedHostTestList, 'localhost: evil.host', false],
			// do not trust casting
			[[1], '1', false],
			// leading *
			[$trustedHostTestList, 'abc.leading.host', true],
			[$trustedHostTestList, 'abc.def.leading.host', true],
			[$trustedHostTestList, 'abc.def.leading.host.another', false],
			[$trustedHostTestList, 'abc.def.leading.host:123', true],
			[$trustedHostTestList, 'leading.host', false],
			// trailing *
			[$trustedHostTestList, 'trailing.host', true],
			[$trustedHostTestList, 'trailing.host.abc', true],
			[$trustedHostTestList, 'trailing.host.abc.def', true],
			[$trustedHostTestList, 'trailing.host.abc:123', true],
			[$trustedHostTestList, 'another.trailing.host', false],
			// center *
			[$trustedHostTestList, 'center', true],
			[$trustedHostTestList, 'cenxxxter', true],
			[$trustedHostTestList, 'cen.x.y.ter', true],
			// with port
			[$trustedHostTestList, 'abc.leadingwith.port:123', true],
			[$trustedHostTestList, 'abc.leadingwith.port:1234', false],
			[$trustedHostTestList, 'trailingwith.port.abc:456', true],
			[$trustedHostTestList, 'trailingwith.port.abc:123', false],
			// bad hostname
			[$trustedHostTestList, '-bad', false],
			[$trustedHostTestList, '-bad.leading.host', false],
			[$trustedHostTestList, 'bad..der.leading.host', false],
			// case sensitivity
			[$trustedHostTestList, 'uppercase.domain', true],
			[$trustedHostTestList, 'LOWERCASE.DOMAIN', true],
		];
	}

	public function testIsTrustedDomainOverwriteHost(): void {
		$this->config->method('getSystemValue')
			->with('overwritehost')
			->willReturn('myproxyhost');

		$trustedDomainHelper = new TrustedDomainHelper($this->config);
		$this->assertTrue($trustedDomainHelper->isTrustedDomain('myproxyhost'));
		$this->assertTrue($trustedDomainHelper->isTrustedDomain('myotherhost'));
	}
}
