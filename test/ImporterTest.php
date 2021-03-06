<?php

namespace Dkan\DatastoreTest;

use Contracts\Mock\Storage\Memory;
use Dkan\Datastore\Resource;
use Dkan\Datastore\Importer;
use Dkan\Datastore\Storage\StorageInterface;
use Procrastinator\Result;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Importer class.
 */
class ImporterTest extends TestCase
{

    private $database;

  /**
   * This method is called before each test.
   */
    protected function setUp(): void
    {
        $this->database = new TestMemStorage();
        $this->assertTrue($this->database instanceof StorageInterface);
    }

    private function getDatastore(Resource $resource)
    {
        $storage = new Memory();
        $config = [
          "resource" => $resource,
          "storage" => $this->database,
          "parser" => \CsvParser\Parser\Csv::getParser()
        ];
        return Importer::get("1", $storage, $config);
    }

    public function testBasics()
    {
        $resource = new Resource(1, __DIR__ . "/data/countries.csv", "text/csv");
        $this->assertEquals($resource->getID(), 1);

        $datastore = $this->getDatastore($resource);

        $this->assertTrue($datastore->getParser() instanceof \Contracts\ParserInterface);
        $this->assertEquals(Result::STOPPED, $datastore->getResult()->getStatus());

        $datastore->run();

        $schema = $datastore->getStorage()->getSchema();
        $this->assertTrue(is_array($schema['fields']));

        $status = $datastore->getResult()->getStatus();
        $this->assertEquals(Result::DONE, $status);

        $this->assertEquals(4, $datastore->getStorage()->count());

        $datastore->run();
        $status = $datastore->getResult()->getStatus();
        $this->assertEquals(Result::DONE, $status);

        $datastore->drop();

        $status = $datastore->getResult()->getStatus();
        $this->assertEquals(Result::STOPPED, $status);
    }

    public function testFileNotFound()
    {
        $resource = new Resource(1, __DIR__ . "/data/non-existent.csv", "text/csv");
        $datastore = $this->getDatastore($resource);
        $datastore->run();

        $this->assertEquals(Result::ERROR, $datastore->getResult()->getStatus());
    }

    public function testNonTextFile()
    {
        $resource = new Resource(1, __DIR__ . "/data/non-text.csv", "text/csv");
        $datastore = $this->getDatastore($resource);
        $datastore->run();

        $this->assertEquals(Result::ERROR, $datastore->getResult()->getStatus());
    }

    public function testLongColumnName()
    {
        $resource = new Resource(1, __DIR__ . "/data/longcolumn.csv", "text/csv");
        $datastore = $this->getDatastore($resource);
        $truncatedLongFieldName = 'extra_long_column_name_with_tons_of_characters_that_will_ne_0';

        $datastore->run();
        $schema = $datastore->getStorage()->getSchema();
        $fields = array_keys($schema['fields']);

        $this->assertEquals($truncatedLongFieldName, $fields[2]);
    }

    public function testColumnNameSpaces()
    {
        $resource = new Resource(1, __DIR__ . "/data/columnspaces.csv", "text/csv");
        $datastore = $this->getDatastore($resource);
        $noMoreSpaces = 'column_name_with_spaces_in_it';

        $datastore->run();
        $schema = $datastore->getStorage()->getSchema();
        $fields = array_keys($schema['fields']);
        $this->assertEquals($noMoreSpaces, $fields[2]);
    }

    public function testSerialization()
    {
        $timeLimit = 40;
        $resource = new Resource(1, __DIR__ . "/data/countries.csv", "text/csv");
        $this->assertEquals($resource->getID(), 1);

        $datastore = $this->getDatastore($resource);
        $datastore->setTimeLimit($timeLimit);
        $datastore->run();
        $json = json_encode($datastore);

        $datastore2 = Importer::hydrate($json);

        $this->assertEquals(Result::DONE, $datastore2->getResult()->getStatus());
        $this->assertEquals($timeLimit, $datastore2->getTimeLimit());
    }

    public function testMultiplePasses()
    {
        $resource = new Resource(1, __DIR__ . "/data/Bike_Lane.csv", "text/csv");

        $storage = new Memory();

        $config = [
          "resource" => $resource,
          "storage" => $this->database,
          "parser" => \CsvParser\Parser\Csv::getParser()
        ];

        $datastore = Importer::get("1", $storage, $config);

        // Hard to know, but unlikely that the file can be parsed in under one
        // second.
        $datastore->setTimeLimit(1);

        $datastore->run();
        // How many passes does it take to get through the data?
        $passes = 1;
        $results = $datastore->getStorage()->retrieveAll();

        while ($datastore->getResult()->getStatus() != Result::DONE) {
            $datastore = Importer::get("1", $storage, $config);
            $datastore->run();
            $results += $datastore->getStorage()->retrieveAll();
            $passes++;
        }
        // There needs to have been more than one pass for this test to be valid.
        $this->assertGreaterThan(1, $passes);

        $values = array_values($results);

        $a = '["1","11110000","L","1","DESIGNATED","16.814","16.846","51.484"]';
        $this->assertEquals($a, $values[0]);

        $b = '["5083","87080001","R","1","DESIGNATED","1.074","1.177","163.244"]';
        $this->assertEquals($b, $values[5000]);

        $c = '["11001","57060000","R","1","DESIGNATED","4.505","4.682","285.7762"]';
        $this->assertEquals($c, $values[10000]);
    }

    public function testBadStorage()
    {
        $storageInterfaceClass = StorageInterface::class;
        $this->expectExceptionMessage("Storage must be an instance of {$storageInterfaceClass}");
        $resource = new Resource(1, __DIR__ . "/data/countries.csv", "text/csv");

        $importer = Importer::get("1", new Memory(), [
          "resource" => $resource,
          "storage" => new TestMemStorageBad(),
          "parser" => \CsvParser\Parser\Csv::getParser()
        ]);

        $json = json_encode($importer);
        Importer::hydrate($json);
    }

    public function testNonStorage()
    {
        $this->expectExceptionMessage("Storage must be an instance of Dkan\Datastore\Storage\StorageInterface");
        $resource = new Resource(1, __DIR__ . "/data/countries.csv", "text/csv");
        $importer = Importer::get("1", new Memory(), [
          "resource" => $resource,
          "storage" => new class {
          },
          "parser" => \CsvParser\Parser\Csv::getParser()
        ]);
    }
}
