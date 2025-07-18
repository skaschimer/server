<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\DAV\Tests\unit\CalDAV\Search\Xml\Request;

use OCA\DAV\CalDAV\Search\Xml\Request\CalendarSearchReport;
use Sabre\Xml\Reader;
use Test\TestCase;

class CalendarSearchReportTest extends TestCase {
	private array $elementMap = [
		'{http://nextcloud.com/ns}calendar-search'
			=> 'OCA\\DAV\\CalDAV\\Search\\Xml\\Request\\CalendarSearchReport',
	];

	public function testFoo(): void {
		$xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<nc:calendar-search xmlns:nc="http://nextcloud.com/ns" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:d="DAV:">
   <d:prop>
      <d:getetag />
      <c:calendar-data />
   </d:prop>
   <nc:filter>
      <nc:comp-filter name="VEVENT" />
      <nc:comp-filter name="VTODO" />
      <nc:prop-filter name="SUMMARY" />
      <nc:prop-filter name="LOCATION" />
      <nc:prop-filter name="ATTENDEE" />
      <nc:param-filter property="ATTENDEE" name="CN" />
      <nc:search-term>foo</nc:search-term>
   </nc:filter>
   <nc:limit>10</nc:limit>
   <nc:offset>5</nc:offset>
</nc:calendar-search>
XML;

		$result = $this->parse($xml);

		$calendarSearchReport = new CalendarSearchReport();
		$calendarSearchReport->properties = [
			'{DAV:}getetag',
			'{urn:ietf:params:xml:ns:caldav}calendar-data',
		];
		$calendarSearchReport->filters = [
			'comps' => [
				'VEVENT',
				'VTODO'
			],
			'props' => [
				'SUMMARY',
				'LOCATION',
				'ATTENDEE'
			],
			'params' => [
				[
					'property' => 'ATTENDEE',
					'parameter' => 'CN'
				]
			],
			'search-term' => 'foo'
		];
		$calendarSearchReport->limit = 10;
		$calendarSearchReport->offset = 5;

		$this->assertEquals(
			$calendarSearchReport,
			$result['value']
		);
	}

	public function testNoLimitOffset(): void {
		$xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<nc:calendar-search xmlns:nc="http://nextcloud.com/ns" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:d="DAV:">
   <d:prop>
      <d:getetag />
      <c:calendar-data />
   </d:prop>
   <nc:filter>
      <nc:comp-filter name="VEVENT" />
      <nc:prop-filter name="SUMMARY" />
      <nc:search-term>foo</nc:search-term>
   </nc:filter>
</nc:calendar-search>
XML;

		$result = $this->parse($xml);

		$calendarSearchReport = new CalendarSearchReport();
		$calendarSearchReport->properties = [
			'{DAV:}getetag',
			'{urn:ietf:params:xml:ns:caldav}calendar-data',
		];
		$calendarSearchReport->filters = [
			'comps' => [
				'VEVENT',
			],
			'props' => [
				'SUMMARY',
			],
			'search-term' => 'foo'
		];
		$calendarSearchReport->limit = null;
		$calendarSearchReport->offset = null;

		$this->assertEquals(
			$calendarSearchReport,
			$result['value']
		);
	}


	public function testRequiresCompFilter(): void {
		$this->expectException(\Sabre\DAV\Exception\BadRequest::class);
		$this->expectExceptionMessage('{http://nextcloud.com/ns}prop-filter or {http://nextcloud.com/ns}param-filter given without any {http://nextcloud.com/ns}comp-filter');

		$xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<nc:calendar-search xmlns:nc="http://nextcloud.com/ns" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:d="DAV:">
   <d:prop>
      <d:getetag />
      <c:calendar-data />
   </d:prop>
   <nc:filter>
      <nc:prop-filter name="SUMMARY" />
      <nc:prop-filter name="LOCATION" />
      <nc:prop-filter name="ATTENDEE" />
      <nc:param-filter property="ATTENDEE" name="CN" />
      <nc:search-term>foo</nc:search-term>
   </nc:filter>
   <nc:limit>10</nc:limit>
   <nc:offset>5</nc:offset>
</nc:calendar-search>
XML;

		$this->parse($xml);
	}


	public function testRequiresFilter(): void {
		$this->expectException(\Sabre\DAV\Exception\BadRequest::class);
		$this->expectExceptionMessage('The {http://nextcloud.com/ns}filter element is required for this request');

		$xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<nc:calendar-search xmlns:nc="http://nextcloud.com/ns" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:d="DAV:">
   <d:prop>
      <d:getetag />
      <c:calendar-data />
   </d:prop>
</nc:calendar-search>
XML;

		$this->parse($xml);
	}


	public function testNoSearchTerm(): void {
		$this->expectException(\Sabre\DAV\Exception\BadRequest::class);
		$this->expectExceptionMessage('{http://nextcloud.com/ns}search-term is required for this request');

		$xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<nc:calendar-search xmlns:nc="http://nextcloud.com/ns" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:d="DAV:">
   <d:prop>
      <d:getetag />
      <c:calendar-data />
   </d:prop>
   <nc:filter>
      <nc:comp-filter name="VEVENT" />
      <nc:comp-filter name="VTODO" />
      <nc:prop-filter name="SUMMARY" />
      <nc:prop-filter name="LOCATION" />
      <nc:prop-filter name="ATTENDEE" />
      <nc:param-filter property="ATTENDEE" name="CN" />
   </nc:filter>
   <nc:limit>10</nc:limit>
   <nc:offset>5</nc:offset>
</nc:calendar-search>
XML;

		$this->parse($xml);
	}


	public function testCompOnly(): void {
		$this->expectException(\Sabre\DAV\Exception\BadRequest::class);
		$this->expectExceptionMessage('At least one{http://nextcloud.com/ns}prop-filter or {http://nextcloud.com/ns}param-filter is required for this request');

		$xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<nc:calendar-search xmlns:nc="http://nextcloud.com/ns" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:d="DAV:">
   <d:prop>
      <d:getetag />
      <c:calendar-data />
   </d:prop>
   <nc:filter>
      <nc:comp-filter name="VEVENT" />
      <nc:comp-filter name="VTODO" />
      <nc:search-term>foo</nc:search-term>
   </nc:filter>
</nc:calendar-search>
XML;

		$result = $this->parse($xml);

		$calendarSearchReport = new CalendarSearchReport();
		$calendarSearchReport->properties = [
			'{DAV:}getetag',
			'{urn:ietf:params:xml:ns:caldav}calendar-data',
		];
		$calendarSearchReport->filters = [
			'comps' => [
				'VEVENT',
				'VTODO'
			],
			'search-term' => 'foo'
		];
		$calendarSearchReport->limit = null;
		$calendarSearchReport->offset = null;

		$this->assertEquals(
			$calendarSearchReport,
			$result['value']
		);
	}

	public function testPropOnly(): void {
		$xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<nc:calendar-search xmlns:nc="http://nextcloud.com/ns" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:d="DAV:">
   <d:prop>
      <d:getetag />
      <c:calendar-data />
   </d:prop>
   <nc:filter>
      <nc:comp-filter name="VEVENT" />
      <nc:prop-filter name="SUMMARY" />
      <nc:search-term>foo</nc:search-term>
   </nc:filter>
</nc:calendar-search>
XML;

		$result = $this->parse($xml);

		$calendarSearchReport = new CalendarSearchReport();
		$calendarSearchReport->properties = [
			'{DAV:}getetag',
			'{urn:ietf:params:xml:ns:caldav}calendar-data',
		];
		$calendarSearchReport->filters = [
			'comps' => [
				'VEVENT',
			],
			'props' => [
				'SUMMARY',
			],
			'search-term' => 'foo'
		];
		$calendarSearchReport->limit = null;
		$calendarSearchReport->offset = null;

		$this->assertEquals(
			$calendarSearchReport,
			$result['value']
		);
	}

	public function testParamOnly(): void {
		$xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<nc:calendar-search xmlns:nc="http://nextcloud.com/ns" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:d="DAV:">
   <d:prop>
      <d:getetag />
      <c:calendar-data />
   </d:prop>
   <nc:filter>
      <nc:comp-filter name="VEVENT" />
      <nc:param-filter property="ATTENDEE" name="CN" />
      <nc:search-term>foo</nc:search-term>
   </nc:filter>
</nc:calendar-search>
XML;

		$result = $this->parse($xml);

		$calendarSearchReport = new CalendarSearchReport();
		$calendarSearchReport->properties = [
			'{DAV:}getetag',
			'{urn:ietf:params:xml:ns:caldav}calendar-data',
		];
		$calendarSearchReport->filters = [
			'comps' => [
				'VEVENT',
			],
			'params' => [
				[
					'property' => 'ATTENDEE',
					'parameter' => 'CN'
				]
			],
			'search-term' => 'foo'
		];
		$calendarSearchReport->limit = null;
		$calendarSearchReport->offset = null;

		$this->assertEquals(
			$calendarSearchReport,
			$result['value']
		);
	}

	private function parse(string $xml, array $elementMap = []): array {
		$reader = new Reader();
		$reader->elementMap = array_merge($this->elementMap, $elementMap);
		$reader->xml($xml);
		return $reader->parse();
	}
}
