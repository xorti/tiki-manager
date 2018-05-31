<?php
use PHPUnit\Framework\TestCase;


class WrapperTest extends TestCase
{
    public function testWrapperPassProperties()
    {
        $object = new stdClass();
        $object->name = 'Property name';

        $wrapper = new Wrapper($object);
        $expected = $object->name;
        $result = $wrapper->name;

        $this->assertEquals($expected, $result);
    }

    public function testWrapperOverwriteProperties()
    {
        $object = new stdClass();
        $object->name = 'Property name';
        $object->other = 'Another property';

        $props = array(
            'other' => 'foo'
        );

        $wrapper = new Wrapper($object, $props);

        $expected = $object->name;
        $result = $wrapper->name;
        $this->assertEquals($expected, $result);

        $this->assertEquals($props['other'], $wrapper->other);
    }

    public function testWrapperSetNewProperty()
    {
        $object = new stdClass();
        $object->name = 'Property name';

        $wrapper = new Wrapper($object);
        $wrapper->other = 'Another property';
        $this->assertEquals('Another property', $wrapper->other);
    }

    public function testWrapperWorksWithIsset()
    {
        $object = new stdClass();
        $object->name = 'Property name';

        $wrapper = new Wrapper($object);
        $this->assertTrue(isset($wrapper->{'name'}));
        $this->assertFalse(isset($wrapper->{'other'}));

        $props = array('other' => 'foo');
        $wrapper = new Wrapper($object, $props);
        $this->assertTrue(isset($wrapper->{'name'}));
        $this->assertTrue(isset($wrapper->{'other'}));
    }

    public function testWrapperWorksWithUnset()
    {
        $object = new stdClass();
        $object->name = 'Property name';

        $props = array('other' => 'foo');
        $wrapper = new Wrapper($object, $props);
        $this->assertTrue(isset($wrapper->{'name'}));
        $this->assertTrue(isset($wrapper->{'other'}));

        unset($wrapper->other);
        $this->assertTrue(isset($wrapper->{'name'}));
        $this->assertFalse(isset($wrapper->{'other'}));

        unset($wrapper->name);
        $this->assertFalse(isset($wrapper->{'name'}));
        $this->assertFalse(isset($wrapper->{'other'}));

    }

    public function testWrapperPassMethodCalls()
    {
        $command = new Host_Command('cat', '-n', 'Hello World');
        $wrapper = new Wrapper($command);

        $expected = $command->getCommand();
        $result = $wrapper->getCommand();

        $this->assertEquals($expected, $result);
    }

    public function testWrapperOverloadMethodCalls()
    {
        $command = new Host_Command('cat', '-n', 'Hello World');

        $methods = array(
            'getCommand' => function() { return 'Hello World'; }
        );

        $wrapper = new Wrapper($command, array(), $methods);
        $result = $wrapper->getCommand();

        $this->assertEquals('Hello World', $result);
    }
}
