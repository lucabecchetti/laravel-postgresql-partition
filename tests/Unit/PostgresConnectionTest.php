<?php

namespace Brokenice\LaravelPgsqlPartition\Tests\Unit;

use Brokenice\LaravelPgsqlPartition\PostgresConnection;
use Brokenice\LaravelPgsqlPartition\Schema\PostgresGrammar;
use Brokenice\LaravelPgsqlPartition\Schema\QueryBuilder;
use Mockery;

class PostgresConnectionTest extends BaseTestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test that PostgresConnection returns custom QueryBuilder.
     */
    public function testQueryReturnsCustomQueryBuilder()
    {
        $pdo = Mockery::mock(\PDO::class);
        $pdo->shouldReceive('getAttribute')->andReturn('15.0');
        
        $connection = new PostgresConnection($pdo, 'test_database');
        $query = $connection->query();
        
        $this->assertInstanceOf(QueryBuilder::class, $query);
    }

    /**
     * Test QueryBuilder partition methods.
     */
    public function testQueryBuilderPartitionMethods()
    {
        $pdo = Mockery::mock(\PDO::class);
        $pdo->shouldReceive('getAttribute')->andReturn('15.0');
        
        $connection = new PostgresConnection($pdo, 'test_database');
        $query = $connection->query();
        
        // Test partition method
        $query->partition('orders_2024');
        $this->assertTrue($query->hasPartitions());
        $this->assertEquals(['orders_2024'], $query->getPartitions());
    }

    /**
     * Test QueryBuilder multiple partitions.
     */
    public function testQueryBuilderMultiplePartitions()
    {
        $pdo = Mockery::mock(\PDO::class);
        $pdo->shouldReceive('getAttribute')->andReturn('15.0');
        
        $connection = new PostgresConnection($pdo, 'test_database');
        $query = $connection->query();
        
        // Test partitions method
        $query->partitions(['orders_2024', 'orders_2025']);
        $this->assertTrue($query->hasPartitions());
        $this->assertEquals(['orders_2024', 'orders_2025'], $query->getPartitions());
    }

    /**
     * Test QueryBuilder schema method.
     */
    public function testQueryBuilderSchemaMethod()
    {
        $pdo = Mockery::mock(\PDO::class);
        $pdo->shouldReceive('getAttribute')->andReturn('15.0');
        
        $connection = new PostgresConnection($pdo, 'test_database');
        $query = $connection->query();
        
        $query->schema('sales');
        $this->assertEquals('sales', $query->getSchema());
    }

    /**
     * Test QueryBuilder without partitions.
     */
    public function testQueryBuilderWithoutPartitions()
    {
        $pdo = Mockery::mock(\PDO::class);
        $pdo->shouldReceive('getAttribute')->andReturn('15.0');
        
        $connection = new PostgresConnection($pdo, 'test_database');
        $query = $connection->query();
        
        $this->assertFalse($query->hasPartitions());
        $this->assertEquals([], $query->getPartitions());
    }
}
