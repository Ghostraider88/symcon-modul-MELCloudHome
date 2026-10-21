<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs/autoload.php';
require_once __DIR__ . '/../MELCloudKlimageraet/module.php';

final class PresentationOwnershipTestDevice extends MELCloudKlimageraet
{
    protected function getTime(): int
    {
        return 1_800_000_000;
    }
}

final class PresentationOwnershipTest extends TestCase
{
    private const DEVICE_MODULE_ID = '{73860314-C683-4067-B8BC-00005121318D}';
    private const UNIT_ID = 'test-unit-1';

    private int $instanceID;

    protected function setUp(): void
    {
        IPS\Kernel::reset();
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');

        $module = IPS\ModuleLoader::getModule(self::DEVICE_MODULE_ID);
        $module['Class'] = PresentationOwnershipTestDevice::class;
        $this->instanceID = IPS\ObjectManager::registerObject(1);
        IPS\InstanceManager::createInstance($this->instanceID, $module);

        IPS_SetProperty($this->instanceID, 'UnitID', self::UNIT_ID);
        IPS_ApplyChanges($this->instanceID);
    }

    protected function tearDown(): void
    {
        IPS\Kernel::reset();
    }

    public function testCapabilityAndModeUpdatesPreserveUserPresentations(): void
    {
        $idents = ['Mode', 'SetTemperature', 'FanSpeed', 'VaneVertical', 'VaneHorizontal'];
        $customPresentations = [
            'Mode'             => [
                'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                'OPTIONS'      => '[]',
                'ICON'         => 'user-mode'
            ],
            'SetTemperature'   => [
                'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                'MIN'          => 5,
                'MAX'          => 40
            ],
            'FanSpeed'         => [
                'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                'OPTIONS'      => '[]',
                'ICON'         => 'user-fan'
            ],
            'VaneVertical'     => [
                'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                'OPTIONS'      => '[]',
                'ICON'         => 'user-vertical'
            ],
            'VaneHorizontal'   => [
                'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                'OPTIONS'      => '[]',
                'ICON'         => 'user-horizontal'
            ]
        ];
        $variableIDs = [];
        $defaultPresentationsBefore = [];

        foreach ($idents as $ident) {
            $variableID = IPS_GetObjectIDByIdent($ident, $this->instanceID);
            self::assertIsInt($variableID);
            $variableIDs[$ident] = $variableID;
            $defaultPresentationsBefore[$ident] = IPS_GetVariable($variableID)['VariablePresentation'];
            IPS_SetVariableCustomPresentation($variableID, $customPresentations[$ident]);
        }

        $temperatureID = $variableIDs['SetTemperature'];
        SetValue($temperatureID, 22.5);

        $this->receiveStatus([
            'OperationMode' => 'Automatic',
            'Capabilities'  => [
                'hasAutoOperationMode'     => true,
                'hasHeatOperationMode'     => true,
                'hasCoolOperationMode'     => false,
                'hasDryOperationMode'      => false,
                'hasFanMode'               => false,
                'numberOfFanSpeeds'        => 2,
                'hasVerticalVane'          => false,
                'hasHorizontalVane'        => false,
                'hasHalfDegreeIncrements'  => false,
                'minTempAutomatic'         => 18,
                'maxTempAutomatic'         => 28,
                'minTempHeat'              => 10,
                'maxTempHeat'              => 30
            ]
        ]);

        foreach ($idents as $ident) {
            $variable = IPS_GetVariable($variableIDs[$ident]);
            self::assertNotSame(
                $defaultPresentationsBefore[$ident],
                $variable['VariablePresentation'],
                $ident . ' default presentation should update'
            );
            self::assertSame(
                $customPresentations[$ident],
                $variable['VariableCustomPresentation'],
                $ident . ' user presentation should remain unchanged'
            );
            self::assertSame(
                $this->instanceID,
                $variable['VariableAction'],
                $ident . ' action should remain enabled'
            );
        }

        $automaticTemperaturePresentation = IPS_GetVariable($temperatureID)['VariablePresentation'];
        self::assertSame(18.0, $automaticTemperaturePresentation['MIN']);
        self::assertSame(28.0, $automaticTemperaturePresentation['MAX']);
        self::assertSame(1.0, $automaticTemperaturePresentation['STEP_SIZE']);
        self::assertSame(22.5, GetValue($temperatureID));

        $this->receiveStatus(['OperationMode' => 'Heat']);

        $heatTemperature = IPS_GetVariable($temperatureID);
        self::assertSame(10.0, $heatTemperature['VariablePresentation']['MIN']);
        self::assertSame(30.0, $heatTemperature['VariablePresentation']['MAX']);
        self::assertSame($customPresentations['SetTemperature'], $heatTemperature['VariableCustomPresentation']);
        self::assertSame(22.5, $heatTemperature['VariableValue']);
    }

    /** @param array<string,mixed> $status */
    private function receiveStatus(array $status): void
    {
        $status['UnitID'] = self::UNIT_ID;
        $buffer = json_encode($status, JSON_THROW_ON_ERROR);
        $json = json_encode(['Buffer' => bin2hex($buffer)], JSON_THROW_ON_ERROR);
        IPS\InstanceManager::getInstanceInterface($this->instanceID)->ReceiveData($json);
    }
}
