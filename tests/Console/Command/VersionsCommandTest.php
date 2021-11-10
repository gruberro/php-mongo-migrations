<?php declare(strict_types=1);

namespace Gruberro\MongoDbMigrations\Tests\Console\Command;

use Gruberro\MongoDbMigrations;
use MongoDB;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

class VersionsCommandTest extends MongoDbMigrations\Tests\TestCase
{
    /**
     * @return MongoDbMigrations\Console\Command\VersionsCommand
     */
    public function testExecuteProjectExamples(): MongoDbMigrations\Console\Command\VersionsCommand
    {
        $application = new Application();
        $application->add(new MongoDbMigrations\Console\Command\VersionsCommand());

        /** @var MongoDbMigrations\Console\Command\VersionsCommand $command */
        $command = $application->find('php-mongodb-migrations:version');
        $commandTester = new CommandTester($command);
        $commandTester->execute(
            [
                'command' => $command->getName(),
                'database' => $this->getTestDatabaseName(),
                'migration-directories' => [__DIR__ . '/../../../examples/Migrations/'],
                '--all' => true,
            ]
        );

        $this->assertRegExp('/Successfully added 3 migrations to version collection/', $commandTester->getDisplay());

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
     * @param MongoDbMigrations\Console\Command\VersionsCommand $command
     */
    public function testExecuteProjectExamplesTwice(MongoDbMigrations\Console\Command\VersionsCommand $command)
    {
        $databaseMigrationsCollection = $this->getTestDatabase()->selectCollection('DATABASE_MIGRATIONS');

        $commandTester = new CommandTester($command);
        $commandTester->execute(
            [
                'command' => $command->getName(),
                'database' => $this->getTestDatabaseName(),
                'migration-directories' => [__DIR__ . '/../../../examples/Migrations/'],
                '--all' => true,
            ]
        );

        $this->assertRegExp('/Successfully added 3 migrations to version collection/', $commandTester->getDisplay());

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

    /**
     * @depends testExecuteProjectExamples
     *
     * @param MongoDbMigrations\Console\Command\VersionsCommand $command
     */
    public function testDatabaseMigrationsAreFullyCleared(MongoDbMigrations\Console\Command\VersionsCommand $command)
    {
        $commandTester = new CommandTester($command);
        $commandTester->execute(
            [
                'command' => $command->getName(),
                'database' => $this->getTestDatabaseName(),
                'migration-directories' => [__DIR__ . '/../../../examples/Migrations/'],
                '--all' => true,
                '--delete' => true,
            ]
        );

        $this->assertRegExp('/Successfully deleted 3 migrations from version collection/', $commandTester->getDisplay());

        $databaseMigrationsLockCollection = $this->getTestDatabase()->selectCollection('DATABASE_MIGRATIONS_LOCK');
        $this->assertFalse(
            $databaseMigrationsLockCollection->findOne(['locked' => ['$exists' => true]])['locked'],
            'The database lock must not be acquired!'
        );

        $databaseMigrationsCollection = $this->getTestDatabase()->selectCollection('DATABASE_MIGRATIONS');
        $executedMigrations = $databaseMigrationsCollection->find()->toArray();
        $this->assertCount(0, $executedMigrations);
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
        $application->add(new MongoDbMigrations\Console\Command\VersionsCommand());

        $command = $application->find('php-mongodb-migrations:version');
        $commandTester = new CommandTester($command);
        $commandTester->execute(
            [
                'command' => $command->getName(),
                'database' => $this->getTestDatabaseName(),
                'migration-directories' => [__DIR__ . '/../../../examples/Migrations/'],
                '--all' => true,
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
        $application->add(new MongoDbMigrations\Console\Command\VersionsCommand());

        $command = $application->find('php-mongodb-migrations:version');
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

    public function testExecuteWithValidationErrorDoesNotLockDatabase()
    {
        $application = new Application();
        $application->add(new MongoDbMigrations\Console\Command\VersionsCommand());

        $command = $application->find('php-mongodb-migrations:version');
        $commandTester = new CommandTester($command);
        $hasValidationException = false;

        try {
            $commandTester->execute(
                [
                    'command' => $command->getName(),
                    'database' => $this->getTestDatabaseName(),
                ]
            );
        } catch (\RuntimeException $e) {
            $hasValidationException = true;
            $this->assertSame('Specify --all or a single migration id', $e->getMessage());
        }

        $this->assertTrue($hasValidationException, 'Expected a validation exception to be thrown');

        $databaseMigrationsLockCollection = $this->getTestDatabase()->selectCollection('DATABASE_MIGRATIONS_LOCK');
        $this->assertNull(
            $databaseMigrationsLockCollection->findOne(['locked' => ['$exists' => true]]),
            'The database lock must not be acquired, even if the command fails!'
        );
    }

    public function testExecuteASingleIdOnly()
    {
        $application = new Application();
        $application->add(new MongoDbMigrations\Console\Command\VersionsCommand());

        /** @var MongoDbMigrations\Console\Command\VersionsCommand $command */
        $command = $application->find('php-mongodb-migrations:version');
        $commandTester = new CommandTester($command);
        $commandTester->execute(
            [
                'command' => $command->getName(),
                'database' => $this->getTestDatabaseName(),
                'migration-directories' => [__DIR__ . '/../../../examples/Migrations/'],
                '--id' => 'create-user-collection-and-its-indexes',
            ]
        );

        $this->assertRegExp('/Successfully added 1 migrations to version collection/', $commandTester->getDisplay());

        $databaseMigrationsLockCollection = $this->getTestDatabase()->selectCollection('DATABASE_MIGRATIONS_LOCK');
        $this->assertFalse(
            $databaseMigrationsLockCollection->findOne(['locked' => ['$exists' => true]])['locked'],
            'The database lock must not be acquired!'
        );

        $databaseMigrationsCollection = $this->getTestDatabase()->selectCollection('DATABASE_MIGRATIONS');
        $executedMigrations = $databaseMigrationsCollection->find()->toArray();
        $this->assertCount(1, $executedMigrations);

        $expectedMigrations = [
            md5('create-user-collection-and-its-indexes'),
        ];

        foreach ($executedMigrations as $migration) {
            $this->assertContains($migration['migration_id'], $expectedMigrations, 'Found a non excecuted migration');
        }

        $commandTester->execute(
            [
                'command' => $command->getName(),
                'database' => $this->getTestDatabaseName(),
                'migration-directories' => [__DIR__ . '/../../../examples/Migrations/'],
                '--id' => 'create-user-collection-and-its-indexes',
                '--delete' => true,
            ]
        );

        $this->assertRegExp('/Successfully deleted 1 migrations from version collection/', $commandTester->getDisplay());

        $databaseMigrationsLockCollection = $this->getTestDatabase()->selectCollection('DATABASE_MIGRATIONS_LOCK');
        $this->assertFalse(
            $databaseMigrationsLockCollection->findOne(['locked' => ['$exists' => true]])['locked'],
            'The database lock must not be acquired!'
        );

        $databaseMigrationsCollection = $this->getTestDatabase()->selectCollection('DATABASE_MIGRATIONS');
        $executedMigrations = $databaseMigrationsCollection->find()->toArray();
        $this->assertCount(0, $executedMigrations);
    }
}
