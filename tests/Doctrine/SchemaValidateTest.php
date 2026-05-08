<?php

namespace App\Tests\Doctrine;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class SchemaValidateTest extends KernelTestCase
{
    public function testMappingAndDatabaseAreInSync(): void
    {
        self::bootKernel();
        $app = new Application(self::$kernel);
        $app->setAutoExit(false);

        $output = new BufferedOutput();
        $exit = $app->run(
            new ArrayInput(['command' => 'doctrine:schema:validate']),
            $output,
        );

        $this->assertSame(
            0,
            $exit,
            "doctrine:schema:validate should pass; got:\n" . $output->fetch(),
        );
    }
}
