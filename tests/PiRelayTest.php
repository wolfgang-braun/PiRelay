<?php
namespace WolfgangBraun\PiRelay;

use WolfgangBraun\PiRelay\PiRelay;
use phpmock\phpunit\PHPMock;
use PHPUnit_Framework_TestCase;

class PiRelayTest extends PHPUnit_Framework_TestCase
{
    use PHPMock;

    public $valueMap = [
        '0000' => 0xff,
        '1000' => 0xfe,
        '0100' => 0xfd,
        '0010' => 0xfb,
        '0001' => 0xf7,
        '1100' => 0xfc,
        '0110' => 0xf9,
        '0011' => 0xf3,
        '1010' => 0xfa,
        '0101' => 0xf5,
        '1001' => 0xf6,
        '1110' => 0xf8,
        '0111' => 0xf1,
        '1011' => 0xf2,
        '1101' => 0xf4,
        '1111' => 0xf0
    ];

    public $currentValue = 0xff;

    public $lastShellExecCommand = null;

    public $mockShell = null;

    protected function _mockShellExec()
    {
        if (empty($this->mockShell)) {
            $this->mockShell = $this->getFunctionMock(__NAMESPACE__, "shell_exec");
        }
        $this->mockShell->expects($this->any())->willReturnCallback(
            function ($command) {
                $this->lastShellExecCommand = $command;
                return $this->currentValue;
            }
        );
    }

    public function testConstructor()
    {
        
        // Default values
        $i2cAddress = 0x20;
        $deviceRegister = 0x06;
        $block = 1;

        $PiRelay = new PiRelay();
        $this->assertEquals($PiRelay->i2cAddress, $i2cAddress);
        $this->assertEquals($PiRelay->deviceRegister, $deviceRegister);
        $this->assertEquals($PiRelay->block, $block);

        // Custom values
        $i2cAddress = 0xff;
        $deviceRegister = 0xfe;
        $block = 2;

        $PiRelay = new PiRelay($i2cAddress, $deviceRegister, $block);
        $this->assertEquals($PiRelay->i2cAddress, $i2cAddress);
        $this->assertEquals($PiRelay->deviceRegister, $deviceRegister);
        $this->assertEquals($PiRelay->block, $block);
    }

    public function testGetStateAndSetState()
    {
        $this->_mockShellExec();
        $PiRelay = new PiRelay();

        foreach ($this->valueMap as $config => $value) {
            $this->currentValue = $value;
            $channels = str_split($config);
            $stateArray = $PiRelay->getState();
            foreach ($channels as $channel => $expectedState) {
                $state = $PiRelay->getState($channel);
                if ($expectedState == '1') {
                    $PiRelay->setState($channel, PiRelay::STATE_ON);
                    
                    $this->assertEquals(PiRelay::STATE_ON, $state);
                    $this->assertEquals(PiRelay::STATE_ON, $stateArray[$channel]);
                } else {
                    $PiRelay->setState($channel, PiRelay::STATE_OFF);

                    $this->assertEquals(PiRelay::STATE_OFF, $state);
                    $this->assertEquals(PiRelay::STATE_OFF, $stateArray[$channel]);
                }
            }
        }
    }

    public function testValidation()
    {
        $this->_mockShellExec();
        $PiRelay = new PiRelay();

        try {
            $PiRelay->setState(PiRelay::CHANNEL_ALL, 'invalid_state');
            $this->assertTrue(false, "State validation failed");
        } catch (\InvalidArgumentException $e) {
        }

        try {
            $PiRelay->setState('invalid_channel', PiRelay::STATE_ON);
            $this->assertTrue(false, "Channel validation failed");
        } catch (\InvalidArgumentException $e) {
        }

        try {
            $this->currentValue = 'Oxinvalid';
            $PiRelay->getState();
            $this->currentValue = 0xff;
            $this->assertTrue(false, "Hex value validation failed");
        } catch (\BadFunctionCallException $e) {
        }
    }

    public function testSSH()
    {
        $PiRelay = new PiRelay();
        $defaultSSHConfig = [
          'host' => null,
          'user' => null,
          'keyPath' => null,
          'active' => false
        ];

        $initialSSHConfig = $PiRelay->getSSHConfig();
        $this->assertEquals($initialSSHConfig, $defaultSSHConfig);

        $expectedSSHConfig = [
          'host' => 'raspberry.local',
          'user' => 'pi',
          'keyPath' => '/ssh/key/path',
          'active' => true
        ];

        $modifiedSSHConfig = $PiRelay->setSSHConfig($expectedSSHConfig['host'], $expectedSSHConfig['user'], $expectedSSHConfig['keyPath']);
        $this->assertEquals( $modifiedSSHConfig, $expectedSSHConfig);

        $this->_mockShellExec();
        $PiRelay->getState();
        $expectedCommand = "ssh pi@raspberry.local -i /ssh/key/path '/usr/sbin/i2cget -y 1 32 6 2>&1'";
        $this->assertEquals($this->lastShellExecCommand, $expectedCommand);

    }
}
