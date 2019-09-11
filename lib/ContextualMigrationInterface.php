<?php declare(strict_types=1);

namespace Gruberro\MongoDbMigrations;

interface ContextualMigrationInterface extends MigrationInterface
{
    /**
     * Returns a list of context names
     *
     * @return string[]
     */
    public function getContexts(): array;
}
