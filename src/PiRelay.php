<?php

namespace WolfgangBraun\PiRelay;

/**
 * Class to interact with seedstudio's Raspberry Pi Relay Board v1.0
 *
 * @link http://www.seeedstudio.com/wiki/Raspberry_Pi_Relay_Board_v1.0
 * @author Wolfgang Braun
 */
class PiRelay
{
    /**
     * First channel constant
     *
     * @var int
     */
    const CHANNEL_1 = 0;

    /**
     * Second channel constant
     *
     * @var int
     */
    const CHANNEL_2 = 1;

    /**
     * Third channel constant
     *
     * @var int
     */
    const CHANNEL_3 = 2;

    /**
     * Fourth channel constant
     *
     * @var int
     */
    const CHANNEL_4 = 3;

    /**
     * All channels constant
     *
     * @var string
     */
    const CHANNEL_ALL = 'all';

    /**
     * On state
     *
     * @var string
     */
    const STATE_ON = 'on';

    /**
     * Off state
     *
     * @var string
     */
    const STATE_OFF = 'off';

    /**
     * I2C Address
     *
     * @var hexadecimal
     */
    public $i2cAddress = 0x20;

    /**
     * Device Register
     *
     * @var hexadecimal
     */
    public $deviceRegister = 0x06;

    /**
     * I2C Address
     *
     * @var int
     */
    public $block = 1;

    /**
     * SSH configuration
     *
     * @var array
     */
    protected $_ssh = [
      'host' => null,
      'user' => null,
      'keyPath' => null,
      'active' => false
    ];

    /**
     * Constructor
     *
     * @param hexadecimal $i2cAddress 7 bit address (will be left shifted to add the read write bit)
     * @param hexadecimal $deviceRegister Device register to write on
     * @param int $block 0 = /dev/i2c-0 (port I2C0), 1 = /dev/i2c-1 (port I2C1)
     */
    public function __construct($i2cAddress = null, $deviceRegister = null, $block = null)
    {
        if (!empty($i2cAddress)) {
            $this->i2cAddress = $i2cAddress;
        }
        if (!empty($deviceRegister)) {
            $this->deviceRegister = $deviceRegister;
        }
        if (!empty($block)) {
            $this->block = $block;
        }
    }

    /**
     * Set SSH configuration data
     *
     * @param string $host IP or hostname of RPI
     * @param string $user SSH user of RPI
     * @param string $keyPath Path to the private ssh key
     * @param bool $active Whether to use ssh or not
     * @return void
     */
    public function setSSHConfig($host, $user, $keyPath = null, $active = true)
    {
      $this->_ssh['host'] = $host;
      $this->_ssh['user'] = $user;
      $this->_ssh['keyPath'] = $keyPath;
      $this->_ssh['active'] = $active;
      return $this->_ssh;
    }

    /**
     * Getter for SSH config
     *
     * @return array
     */
    public function getSSHConfig()
    {
      return $this->_ssh;
    }

    /**
     * Wrapper for shell_exec
     * Adds ssh support
     *
     * @param string $command Command to be executed
     * @return string
     */
    protected function _shellExec($command)
    {
      if ($this->_ssh['active']) {
        $sshPrefix = 'ssh ';
        $sshPrefix .= $this->_ssh['user'] . '@';
        $sshPrefix .= $this->_ssh['host'] . ' ';
        if (!empty($this->_ssh['keyPath'])) {
          $sshPrefix .= '-i ' . $this->_ssh['keyPath'] . ' ';
        }
        $command = $sshPrefix . "'" . $command . "'";
      }
      return shell_exec($command);
    }

    /**
     * Read data using i2cget
     *
     * @return hexadecimal
     */
    protected function _i2cget()
    {
        $command = '/usr/sbin/i2cget -y ';
        $command .= $this->block . ' ';
        $command .= $this->i2cAddress . ' ';
        $command .= $this->deviceRegister . ' ';
        $command .= '2>&1';
        $value = $this->_shellExec($command);
        $this->__validateValue($value);
        return $value;
    }

    /**
     * State getter
     *
     * @param int $channel Channel to get the state of
     * @return hexadecimal
     */
    public function getState($channel = null)
    {
        if ($channel === null) {
            $channel = self::CHANNEL_ALL;
        }
        $this->__validateChannel($channel);

        $currentValue = $this->_i2cget();
        $decimalRepresentation = is_int($currentValue) ? $currentValue : hexdec($currentValue);
        $binaryRepresentation = decbin($decimalRepresentation);
        $relevantBits = substr($binaryRepresentation, -4);
        $ordered = strrev($relevantBits);
        $exploded = str_split($ordered);
        $states = array_map(function ($off) {
            return $off ? self::STATE_OFF : self::STATE_ON;
        }, $exploded);
        if (isset($states[$channel])) {
            return $states[$channel];
        }
        return $states;
    }

    /**
     * State setter
     *
     * @param string $channel Channel between 1 and 4 or all
     * @param string $state on or off
     * @return string
     */
    public function setState($channel, $state)
    {
        $newValue = $this->_computeNewValue($channel, $state);
        return $this->_i2cset($newValue);
    }

    /**
     * Get the correct value for a state and channel configuration based on the current device state
     *
     * @param string $channel Channel between 1 and 4 or all
     * @param string $state on or off
     * @return hexadecimal
     */
    protected function _computeNewValue($channel, $state)
    {
        $this->__validateChannel($channel);
        $this->__validateState($state);

        $currentValue = hexdec($this->_i2cget());

        switch ($state) {
            case self::STATE_ON:
                if ($channel == self::CHANNEL_ALL) {
                    $currentValue &= ~(0xf<<0);
                } else {
                    $currentValue &= ~(0x1<<$channel);
                }
                break;

            case self::STATE_OFF:
                if ($channel == self::CHANNEL_ALL) {
                    $currentValue |= (0xf<<0);
                } else {
                    $currentValue |= (0x1<<$channel);
                }
                break;
        }
        return $currentValue;
    }

    /**
     * Write data using i2cset
     *
     * @param hexadecimal $value Value to be written
     * @return string
     */
    protected function _i2cset($value)
    {
        $command = '/usr/sbin/i2cset -y ';
        $command .= $this->block . ' ';
        $command .= $this->i2cAddress . ' ';
        $command .= $this->deviceRegister . ' ';
        $command .= $value . ' ';
        $command .= '2>&1';
        return $this->_shellExec($command);
    }

    /**
     * Validates a channel value
     *
     * @param string $channel Channel to validate
     * @return void
     * @throws Exception if no valid channel has been provided
     */
    private function __validateChannel($channel)
    {
        $validChannels = [
            self::CHANNEL_1,
            self::CHANNEL_2,
            self::CHANNEL_3,
            self::CHANNEL_4,
            self::CHANNEL_ALL
        ];
        if (!key_exists($channel, array_flip($validChannels))) {
            throw new \InvalidArgumentException('Invalid channel provided. Use PiRelay::CHANNEL_1, PiRelay::CHANNEL_2, PiRelay::CHANNEL_3, PiRelay::CHANNEL_4 or PiRelay::CHANNEL_ALL');
        }
    }

    /**
     * Validates a state value
     *
     * @param string $state State to validate
     * @return void
     * @throws Exception if no valid state has been provided
     */
    private function __validateState($state)
    {
        $validStates = [
            self::STATE_ON,
            self::STATE_OFF
        ];
        if (!in_array($state, $validStates)) {
            throw new \InvalidArgumentException('Invalid state provided. Use PiRelay::STATE_ON or PiRelay::STATE_OFF');
        }
    }

    /**
     * Validates a value to be read or written
     *
     * @param hexadecimal $value Value to validate
     * @return void
     * @throws Exception if provided value is invalid
     */
    private function __validateValue($value)
    {
        $validValues = [0xff, 0xfe, 0xfd, 0xfb, 0xf7, 0xfc, 0xf9, 0xf3, 0xfa, 0xf5, 0xf6, 0xf8, 0xf1, 0xf2, 0xf4, 0xf0];
        if (!in_array($value, $validValues)) {
            throw new \BadFunctionCallException('Invalid HEX value: ' . $value);
        }
    }
}
