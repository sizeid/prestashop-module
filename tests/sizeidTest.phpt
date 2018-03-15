<?php

use Tester\Assert;
use Tester\TestCase;
use Mockery as m;

require __DIR__ . '/bootstrap.php';

/**
 * @testCase
 */
class sizeidTest extends TestCase
{

	public function testInstall()
	{
		Assert::true($this->createSizeID()->install());
	}

	public function testUninstall()
	{
		Assert::true($this->createSizeID()->uninstall());
	}

	/**
	 * @return sizeidTest
	 */
	private function createSizeID()
	{
		$configuration = m::mock('overload:Configuration');
		$configuration->shouldReceive('get');
		$configuration->shouldReceive('deleteByName');
		$db = m::mock('overload:Db');
		$dbInstance = m::mock();
		$dbInstance->shouldReceive('execute')
			->andReturn(TRUE);
		$db->shouldReceive('getInstance')
			->andReturn($dbInstance);
		return new SizeID();
	}
}

(new sizeidTest())->run();
