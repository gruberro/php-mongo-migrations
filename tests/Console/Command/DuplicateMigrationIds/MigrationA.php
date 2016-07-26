<?php

namespace TestMigrations\DuplicateMigrationIds;

use Gruberro\MongoDbMigrations;
use MongoDB\Database;

class MigrationA implements MongoDbMigrations\MigrationInterface
{
    public function getId()
    {
        return 'migration';
    }

    public function getCreateDate()
    {
        return new \DateTime('2016-02-25 16:30:00');
    }

    public function execute(Database $db)
    {
    }
}
