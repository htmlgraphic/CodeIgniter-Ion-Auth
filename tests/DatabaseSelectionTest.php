<?php

namespace IonAuth\Tests;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use IonAuth\Database\Seeds\IonAuthSeeder;
use IonAuth\Models\IonAuthModel;

class DatabaseSelectionTest extends CIUnitTestCase
{
    public function testEmptyAuthGroupUsesFrameworkTestDatabase(): void
    {
        config('IonAuth')->databaseGroupName = '';
        $db = (new IonAuthModel())->db();
        $this->assertSame('SQLite3', $db->DBDriver);
        $this->assertSame(':memory:', $db->database);
    }

    public function testSeederSelectsConfiguredGroupBeforeRun(): void
    {
        // "default" deliberately points to a second SQLite connection in this test.
        // Never allow the test to open a production or external connection.
        config('Database')->default = config('Database')->tests;
        config('IonAuth')->databaseGroupName = 'default';
        $seeder = new IonAuthSeeder(config('Database'), Database::connect('tests'));
        $property = new \ReflectionProperty(\CodeIgniter\Database\Seeder::class, 'db');
        $selected = $property->getValue($seeder);
        $this->assertSame(Database::connect('default'), $selected);
        $this->assertNotSame(Database::connect('tests'), $selected);
        $this->assertSame('SQLite3', $selected->DBDriver);
        $this->assertSame(':memory:', $selected->database);
    }
}
