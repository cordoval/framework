<?php

namespace Pagekit\Component\Database\Tests;

use Doctrine\Common\Cache\ArrayCache;
use Pagekit\Component\Cache\Cache;
use Pagekit\Component\Database\DatabaseServiceProvider;
use Pagekit\Tests\ServiceProviderTest;

class DatabaseServiceProviderTest extends ServiceProviderTest
{
    /**
     * @var DatabaseServiceProvider
     */
    protected $provider;

    public function setUp()
	{
		parent::setUp();
		$this->provider = new DatabaseServiceProvider;
	}

	public function testDatabaseServiceProvider()
	{

		$conf = array(
        'database' => array(
            'default' => 'mysql',
            'connections' => array(
                'mysql' => array(
                    'driver'   => 'pdo_mysql',
                    'dbname'   => '',
                    'host'     => 'localhost',
                    'user'     => 'root',
                    'password' => '',
                    'engine'   => 'InnoDB',
                    'charset'  => 'utf8',
                    'collate'  => 'utf8_unicode_ci',
                    'prefix'   => ''
                )
            )
        ));

        $this->app['caches'] = array('phpfile' => new Cache(new ArrayCache));

		$this->app->set('config', $this->getConfig($conf));
		$this->provider->register($this->app);

        $this->assertInstanceOf('Pagekit\Component\Database\Connection', $this->app['db']);
        $this->assertInstanceOf('Pagekit\Component\Database\ORM\EntityManager', $this->app['db.em']);
		$this->assertInstanceOf('Pagekit\Component\Database\ORM\MetadataManager', $this->app['db.metas']);
	}
}