<?php declare(strict_types=1);

namespace Gruberro\MongoDbMigrations\Tests\Console\Command;

use Gruberro\MongoDbMigrations;
use MongoDB;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

class MigrationsCommandTest extends MongoDbMigrations\Tests\TestCase
{
    /**
     * @return MongoDbMigrations\Console\Command\MigrationsCommand
     */
    public function testExecuteProjectExamples(): MongoDbMigrations\Console\Command\MigrationsCommand
    {
        $application = new Application();
        $application->add(new MongoDbMigrations\Console\Command\MigrationsCommand());

        /** @var MongoDbMigrations\Console\Command\MigrationsCommand $command */
        $command = $application->find('php-mongodb-migrations:migrate');
        $commandTester = new CommandTester($command);
        $commandTester->execute(
            [
                'command' => $command->getName(),
                'database' => $this->getTestDatabaseName(),
                'migration-directories' => [__DIR__ . '/../../../examples/Migrations/'],
                '--contexts' => ['staging']
            ]
        );

        $this->assertRegExp('/Successfully executed 3 migrations/', $commandTester->getDisplay());

        $databaseMigrationsLockCollection = $this->getTestDatabase()->selectCollection('DATABASE_MIGRATIONS_LOCK');
        $this->assertFalse(
            $databaseMigrationsLockCollection->findOne(['locked' => ['$exists' => true]])['locked'],
            'The database lock must not be acquired!'
        );

        $databaseMigrationsCollection = $this->getTestDatabase()->selectCollection('DATABASE_MIGRATIONS');
        $executedMigrations = $databaseMigrationsCollection->find()->toArray();
        $this->assertCount(3, $executedMigrations);

        $expectedMigrations = [
            md5('create-user-collection-and-its-indexes'),
            md5('obfuscate-production-email-addresses'),
            md5('release-counter'),
        ];

        foreach ($executedMigrations as $migration) {
            $this->assertContains($migration['migration_id'], $expectedMigrations, 'Found a non excecuted migration');
        }

        return $command;
    }

    /**
     * @depends testExecuteProjectExamples
     *
     * @param MongoDbMigrations\Console\Command\MigrationsCommand $command
     */
    public function testExecuteProjectExamplesTwice(MongoDbMigrations\Console\Command\MigrationsCommand $command)
    {
        $databaseMigrationsCollection = $this->getTestDatabase()->selectCollection('DATABASE_MIGRATIONS');
        $runAlwaysMigration = $databaseMigrationsCollection->findOne(['run_always' => true]);
        $this->assertNotNull($runAlwaysMigration, 'There must be at least one run always migration');

        sleep(1);

        $commandTester = new CommandTester($command);
        $commandTester->execute(
            [
                'command' => $command->getName(),
                'database' => $this->getTestDatabaseName(),
                'migration-directories' => [__DIR__ . '/../../../examples/Migrations/'],
                '--contexts' => ['staging']
            ]
        );

        $this->assertRegExp('/Successfully executed 1 migrations/', $commandTester->getDisplay());

        $databaseMigrationsLockCollection = $this->getTestDatabase()->selectCollection('DATABASE_MIGRATIONS_LOCK');
        $this->assertFalse(
            $databaseMigrationsLockCollection->findOne(['locked' => ['$exists' => true]])['locked'],
            'The database lock must not be acquired!'
        );

        $executedMigrations = $databaseMigrationsCollection->find()->toArray();
        $this->assertCount(3, $executedMigrations);

        $expectedMigrations = [
            md5('create-user-collection-and-its-indexes'),
            md5('obfuscate-production-email-addresses'),
            md5('release-counter'),
        ];

        foreach ($executedMigrations as $migration) {
            $this->assertContains($migration['migration_id'], $expectedMigrations, 'Found a non excecuted migration');

            if ($migration['run_always'] === true) {
                $this->assertGreaterThan(
                    $runAlwaysMigration['last_execution_date']->toDateTime(),
                    $migration['last_execution_date']->toDateTime(),
                    'A re-running migration must update its last execution date'
                );
            }
        }
    }

    /**
     * @depends testExecuteProjectExamples
     */
    public function testDatabaseMigrationsIsFilledProperly()
    {
        $databaseMigrationsCollection = $this->getTestDatabase()->selectCollection('DATABASE_MIGRATIONS');
        $executedMigrations = $databaseMigrationsCollection->find();

        foreach ($executedMigrations as $executedMigration) {
            $this->assertArrayHasKey('migration_id', $executedMigration, 'The migration_id must stored');
            $this->assertRegExp('/[0-9a-f][32]/', $executedMigration['migration_id'], 'The migration id must be stored as md5 hash');

            $this->assertArrayHasKey('migration_class', $executedMigration);
            $this->assertTrue(class_exists($executedMigration['migration_class']));
            $reflectionClass = new \ReflectionClass($executedMigration['migration_class']);
            $migrationClass = $reflectionClass->newInstance();
            $this->assertTrue($reflectionClass->implementsInterface(\Gruberro\MongoDbMigrations\MigrationInterface::class), 'The stored migration must implement the MigrationInterface');

            $this->assertArrayHasKey('last_execution_date', $executedMigration);
            $this->assertInstanceOf(MongoDB\BSON\UTCDatetime::class, $executedMigration['last_execution_date']);
            $this->assertGreaterThanOrEqual(new \DateTime('-5 sec'), $executedMigration['last_execution_date']->toDateTime(), 'The last execution date must be within the past seconds');

            $this->assertArrayHasKey('run_always', $executedMigration);
            $this->assertEquals($reflectionClass->implementsInterface(\Gruberro\MongoDbMigrations\RunAlwaysMigrationInterface::class), $executedMigration['run_always'], 'The run always flag must be set properly');

            if ($reflectionClass->implementsInterface(\Gruberro\MongoDbMigrations\ContextualMigrationInterface::class)) {
                $this->assertArrayHasKey('contexts', $executedMigration);
                $this->assertEquals($migrationClass->getContexts(), (array) $executedMigration['contexts'], 'The stored migration contexts must match');
            }
        }
    }

    public function testExecuteIsAbortedOnLockedDatabase()
    {
        $databaseMigrationsLockCollection = $this->getTestDatabase()->selectCollection('DATABASE_MIGRATIONS_LOCK');
        $databaseMigrationsLockCollection->insertOne(['locked' => true, 'last_locked_date' => new MongoDB\BSON\UTCDatetime((new \DateTime())->getTimestamp() * 1000)]);

        $this->expectException(RunTimeException::class);
        $this->expectExceptionMessageMatches(
            '/Concurrent migrations are not allowed/'
        );

        $application = new Application();
        $application->add(new MongoDbMigrations\Console\Command\MigrationsCommand());

        $command = $application->find('php-mongodb-migrations:migrate');
        $commandTester = new CommandTester($command);
        $commandTester->execute(
            [
                'command' => $command->getName(),
                'database' => $this->getTestDatabaseName(),
                'migration-directories' => [__DIR__ . '/../../../examples/Migrations/'],
                '--contexts' => ['staging']
            ]
        );
    }

    public function testExecuteWithInvalidMigrationDirectory()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches(
            '/\'invalid\/dir\' is no valid directory/'
        );

        $application = new Application();
        $application->add(new MongoDbMigrations\Console\Command\MigrationsCommand());

        $command = $application->find('php-mongodb-migrations:migrate');
        $commandTester = new CommandTester($command);
        $commandTester->execute(
            [
                'command' => $command->getName(),
                'database' => $this->getTestDatabaseName(),
                'migration-directories' => [__DIR__ . '/../../../examples/Migrations/', 'invalid/dir'],
            ]
        );

        $databaseMigrationsLockCollection = $this->getTestDatabase()->selectCollection('DATABASE_MIGRATIONS_LOCK');
        $this->assertFalse(
            $databaseMigrationsLockCollection->findOne(['locked' => ['$exists' => true]])['locked'],
            'The database lock must not be acquired, even if the command fails!'
        );
    }

    /**
     * @runInSeparateProcess
     */
    public function testExecuteWithDuplicatedMigrationIds()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches(
            "#Found a non unique migration id 'migration' in 'TestMigrations\\\\DuplicateMigrationIds\\\\Migration[A-B]', already defined by migration class 'TestMigrations\\\\DuplicateMigrationIds\\\\Migration[A-B]'#"
        );

        $application = new Application();
        $application->add(new MongoDbMigrations\Console\Command\MigrationsCommand());

        $command = $application->find('php-mongodb-migrations:migrate');
        $commandTester = new CommandTester($command);
        $commandTester->execute(
            [
                'command' => $command->getName(),
                'database' => $this->getTestDatabaseName(),
                'migration-directories' => [__DIR__ . '/../../../examples/Migrations/', __DIR__ . '/DuplicateMigrationIds/'],
            ]
        );

        $databaseMigrationsLockCollection = $this->getTestDatabase()->selectCollection('DATABASE_MIGRATIONS_LOCK');
        $this->assertFalse(
            $databaseMigrationsLockCollection->findOne(['locked' => ['$exists' => true]])['locked'],
            'The database lock must not be acquired, even if the command fails!'
        );
    }

    /**
     * @runInSeparateProcess
     */
    public function testExecuteWithFailingMigrations()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches(
            '/Error while executing migrations/'
        );

        $application = new Application();
        $application->add(new MongoDbMigrations\Console\Command\MigrationsCommand());

        $command = $application->find('php-mongodb-migrations:migrate');
        $commandTester = new CommandTester($command);
        $commandTester->execute(
            [
                'command' => $command->getName(),
                'database' => $this->getTestDatabaseName(),
                'migration-directories' => [__DIR__ . '/FailingMigrations/'],
            ]
        );

        $databaseMigrationsLockCollection = $this->getTestDatabase()->selectCollection('DATABASE_MIGRATIONS_LOCK');
        $this->assertFalse(
            $databaseMigrationsLockCollection->findOne(['locked' => ['$exists' => true]])['locked'],
            'The database lock must not be acquired, even if the command fails!'
        );
    }

    /**
     * @runInSeparateProcess
     */
    public function testExecuteANonIncludedContextMigration()
    {
        $application = new Application();
        $application->add(new MongoDbMigrations\Console\Command\MigrationsCommand());

        $command = $application->find('php-mongodb-migrations:migrate');
        $commandTester = new CommandTester($command);
        $commandTester->execute(
            [
                'command' => $command->getName(),
                'database' => $this->getTestDatabaseName(),
                'migration-directories' => [__DIR__ . '/ContextualMigrations/'],
                '--contexts' => ['testing']
            ]
        );

        $databaseMigrationsCollection = $this->getTestDatabase()->selectCollection('DATABASE_MIGRATIONS');
        $this->assertNull($databaseMigrationsCollection->findOne(['migration_id' => md5('some-context-migration')]));
    }

    /**
     * @runInSeparateProcess
     */
    public function testExecuteAnEmptyContextSpecification()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches(
            '/Error while executing migrations/'
        );

        $application = new Application();
        $application->add(new MongoDbMigrations\Console\Command\MigrationsCommand());

        $command = $application->find('php-mongodb-migrations:migrate');
        $commandTester = new CommandTester($command);
        $commandTester->execute(
            [
                'command' => $command->getName(),
                'database' => $this->getTestDatabaseName(),
                'migration-directories' => [__DIR__ . '/EmptyContextualMigrations/'],
                '--contexts' => ['testing']
            ]
        );
    }
}
