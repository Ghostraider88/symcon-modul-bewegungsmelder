<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/Validator.php';

final class MotionSensorTest extends TestCaseSymconValidation
{
    protected function setUp(): void
    {
        IPS\Kernel::reset();
        parent::setUp();
    }

    public function testValidateModule(): void
    {
        $this->validateModule(__DIR__ . '/../MotionSensor');
    }

    public function testPresenceInputDrivesTypedOutputAndOffDelay(): void
    {
        $motionVariableID = $this->createVariable(VARIABLETYPE_BOOLEAN, false);
        $targetVariableID = $this->createActionableVariable(VARIABLETYPE_INTEGER, 0);
        $instanceID = $this->createInstance();
        $this->configure($instanceID, [
            'MotionVariableID' => $motionVariableID,
            'TargetVariableID' => $targetVariableID,
            'TargetOnValue'    => '7',
            'TargetOffValue'   => '0',
            'OffDelay'         => 120,
        ]);

        $this->assertSame(102, IPS_GetInstance($instanceID)['InstanceStatus']);
        $occupiedID = IPS_GetObjectIDByIdent('Occupied', $instanceID);
        $motionID = IPS_GetObjectIDByIdent('MotionDetected', $instanceID);
        $remainingID = IPS_GetObjectIDByIdent('RemainingSeconds', $instanceID);
        $refresh = $this->getModuleConfiguration()['prefix'] . '_Refresh';

        SetValue($motionVariableID, true);
        $refresh($instanceID);

        $this->assertTrue(GetValueBoolean($motionID));
        $this->assertTrue(GetValueBoolean($occupiedID));
        $this->assertSame(7, GetValueInteger($targetVariableID));

        SetValue($motionVariableID, false);
        $refresh($instanceID);

        $this->assertFalse(GetValueBoolean($motionID));
        $this->assertTrue(GetValueBoolean($occupiedID));
        $this->assertGreaterThanOrEqual(119, GetValueInteger($remainingID));
        $this->assertLessThanOrEqual(120, GetValueInteger($remainingID));
        $this->assertSame(7, GetValueInteger($targetVariableID));
    }

    public function testMessageSinkProcessesMotionUpdates(): void
    {
        $motionVariableID = $this->createVariable(VARIABLETYPE_BOOLEAN, false);
        $instanceID = $this->createInstance();
        $this->configure($instanceID, [
            'MotionVariableID' => $motionVariableID,
            'OffDelay'         => 0,
        ]);

        SetValue($motionVariableID, true);
        IPS\InstanceManager::getInstanceInterface($instanceID)->MessageSink(time(), $motionVariableID, VM_UPDATE, []);

        $this->assertTrue(GetValueBoolean(IPS_GetObjectIDByIdent('Occupied', $instanceID)));
    }

    public function testPulseInputIgnoresFallingEdge(): void
    {
        $motionVariableID = $this->createVariable(VARIABLETYPE_BOOLEAN, false);
        $instanceID = $this->createInstance();
        $this->configure($instanceID, [
            'MotionVariableID' => $motionVariableID,
            'InputMode'        => 'pulse',
            'OffDelay'         => 30,
        ]);

        $occupiedID = IPS_GetObjectIDByIdent('Occupied', $instanceID);
        $remainingID = IPS_GetObjectIDByIdent('RemainingSeconds', $instanceID);
        $refresh = $this->getModuleConfiguration()['prefix'] . '_Refresh';

        SetValue($motionVariableID, true);
        $refresh($instanceID);
        SetValue($motionVariableID, false);
        $refresh($instanceID);

        $this->assertTrue(GetValueBoolean($occupiedID));
        $this->assertGreaterThanOrEqual(29, GetValueInteger($remainingID));
        $this->assertLessThanOrEqual(30, GetValueInteger($remainingID));
    }

    public function testAdditionalPresenceInputsKeepOccupancyUntilAllAreClear(): void
    {
        $primaryVariableID = $this->createVariable(VARIABLETYPE_BOOLEAN, false);
        $additionalVariableID = $this->createVariable(VARIABLETYPE_BOOLEAN, false);
        $targetVariableID = $this->createActionableVariable(VARIABLETYPE_INTEGER, 0);
        $instanceID = $this->createInstance();
        $additionalInputs = json_encode([[
            'VariableID' => $additionalVariableID,
            'InputMode' => 'presence',
            'InvertInput' => false,
        ]], JSON_THROW_ON_ERROR);
        $this->configure($instanceID, [
            'MotionVariableID'       => $primaryVariableID,
            'AdditionalMotionInputs' => $additionalInputs,
            'TargetVariableID'       => $targetVariableID,
            'TargetOnValue'          => '9',
            'TargetOffValue'         => '0',
            'OffDelay'               => 0,
        ]);

        $occupiedID = IPS_GetObjectIDByIdent('Occupied', $instanceID);
        $refresh = $this->getModuleConfiguration()['prefix'] . '_Refresh';
        SetValue($primaryVariableID, true);
        $refresh($instanceID);
        SetValue($additionalVariableID, true);
        $refresh($instanceID);
        SetValue($primaryVariableID, false);
        $refresh($instanceID);

        $this->assertTrue(GetValueBoolean($occupiedID));
        $this->assertSame(9, GetValueInteger($targetVariableID));

        SetValue($additionalVariableID, false);
        $refresh($instanceID);

        $this->assertFalse(GetValueBoolean($occupiedID));
        $this->assertSame(0, GetValueInteger($targetVariableID));
    }

    public function testBlockAndBrightnessConditionsGateAutomaticActions(): void
    {
        $motionVariableID = $this->createVariable(VARIABLETYPE_BOOLEAN, false);
        $blockVariableID = $this->createVariable(VARIABLETYPE_BOOLEAN, true);
        $brightnessVariableID = $this->createVariable(VARIABLETYPE_FLOAT, 1000.0);
        $targetVariableID = $this->createActionableVariable(VARIABLETYPE_INTEGER, 0);
        $instanceID = $this->createInstance();
        $this->configure($instanceID, [
            'MotionVariableID'      => $motionVariableID,
            'BlockVariableID'       => $blockVariableID,
            'BrightnessVariableID'  => $brightnessVariableID,
            'BrightnessThreshold'   => 800.0,
            'BrightnessCondition'   => 'below',
            'TargetVariableID'      => $targetVariableID,
            'TargetOnValue'         => '9',
            'TargetOffValue'        => '0',
            'OffDelay'              => 0,
        ]);

        $occupiedID = IPS_GetObjectIDByIdent('Occupied', $instanceID);
        $blockedID = IPS_GetObjectIDByIdent('AutomaticBlocked', $instanceID);
        $conditionID = IPS_GetObjectIDByIdent('TurnOnConditionMet', $instanceID);
        $refresh = $this->getModuleConfiguration()['prefix'] . '_Refresh';
        SetValue($motionVariableID, true);
        $refresh($instanceID);

        $this->assertTrue(GetValueBoolean($occupiedID));
        $this->assertTrue(GetValueBoolean($blockedID));
        $this->assertFalse(GetValueBoolean($conditionID));
        $this->assertSame(0, GetValueInteger($targetVariableID));

        SetValue($blockVariableID, false);
        $refresh($instanceID);
        $this->assertFalse(GetValueBoolean($blockedID));
        $this->assertSame(0, GetValueInteger($targetVariableID));

        SetValue($brightnessVariableID, 400.0);
        $refresh($instanceID);
        $this->assertTrue(GetValueBoolean($conditionID));
        $this->assertSame(9, GetValueInteger($targetVariableID));

        SetValue($blockVariableID, true);
        $refresh($instanceID);
        SetValue($motionVariableID, false);
        $refresh($instanceID);
        $this->assertFalse(GetValueBoolean($occupiedID));
        $this->assertSame(9, GetValueInteger($targetVariableID));

        SetValue($blockVariableID, false);
        $refresh($instanceID);
        $this->assertSame(0, GetValueInteger($targetVariableID));
    }

    public function testOutputProfilesSwitchMultipleTargetsAndReleasePreviousProfile(): void
    {
        $motionVariableID = $this->createVariable(VARIABLETYPE_BOOLEAN, false);
        $modeVariableID = $this->createVariable(VARIABLETYPE_INTEGER, 1);
        $dayLightID = $this->createActionableVariable(VARIABLETYPE_BOOLEAN, false);
        $dayBrightnessID = $this->createActionableVariable(VARIABLETYPE_INTEGER, 0);
        $nightLightID = $this->createActionableVariable(VARIABLETYPE_BOOLEAN, false);
        $nightBrightnessID = $this->createActionableVariable(VARIABLETYPE_INTEGER, 0);
        $fallbackID = $this->createActionableVariable(VARIABLETYPE_BOOLEAN, false);
        $profiles = json_encode([
            ['Profile' => 'Day', 'VariableID' => $dayLightID, 'OnValue' => 'true', 'OffValue' => 'false'],
            ['Profile' => 'Day', 'VariableID' => $dayBrightnessID, 'OnValue' => '100', 'OffValue' => '0'],
            ['Profile' => 'Night', 'VariableID' => $nightLightID, 'OnValue' => 'true', 'OffValue' => 'false'],
            ['Profile' => 'Night', 'VariableID' => $nightBrightnessID, 'OnValue' => '25', 'OffValue' => '0'],
        ], JSON_THROW_ON_ERROR);
        $conditions = json_encode([
            ['Profile' => 'Day', 'VariableID' => $modeVariableID, 'Operator' => 'equals', 'ExpectedValue' => '0'],
            ['Profile' => 'Night', 'VariableID' => $modeVariableID, 'Operator' => 'equals', 'ExpectedValue' => '1'],
        ], JSON_THROW_ON_ERROR);
        $instanceID = $this->createInstance();
        $this->configure($instanceID, [
            'MotionVariableID'  => $motionVariableID,
            'OffDelay'           => 0,
            'OutputProfiles'     => $profiles,
            'ProfileConditions'  => $conditions,
            'TargetVariableID'   => $fallbackID,
            'TargetOnValue'      => 'true',
            'TargetOffValue'     => 'false',
        ]);

        $refresh = $this->getModuleConfiguration()['prefix'] . '_Refresh';
        $activeProfileID = IPS_GetObjectIDByIdent('ActiveProfile', $instanceID);
        SetValue($motionVariableID, true);
        $refresh($instanceID);
        $this->assertSame('Night', GetValueString($activeProfileID));
        $this->assertTrue(GetValueBoolean($nightLightID));
        $this->assertSame(25, GetValueInteger($nightBrightnessID));
        $this->assertFalse(GetValueBoolean($fallbackID));

        SetValue($modeVariableID, 0);
        $refresh($instanceID);
        $this->assertSame('Day', GetValueString($activeProfileID));
        $this->assertFalse(GetValueBoolean($nightLightID));
        $this->assertSame(0, GetValueInteger($nightBrightnessID));
        $this->assertTrue(GetValueBoolean($dayLightID));
        $this->assertSame(100, GetValueInteger($dayBrightnessID));

        SetValue($modeVariableID, 2);
        $refresh($instanceID);
        $this->assertSame('', GetValueString($activeProfileID));
        $this->assertFalse(GetValueBoolean($dayLightID));
        $this->assertSame(0, GetValueInteger($dayBrightnessID));
        $this->assertTrue(GetValueBoolean($fallbackID));

        SetValue($motionVariableID, false);
        $refresh($instanceID);
        $this->assertFalse(GetValueBoolean($fallbackID));
    }

    public function testProfileCanUseDifferentActionsForOccupiedAndClearStates(): void
    {
        $motionVariableID = $this->createVariable(VARIABLETYPE_BOOLEAN, false);
        $modeVariableID = $this->createVariable(VARIABLETYPE_BOOLEAN, false);
        $onSceneID = $this->createActionableVariable(VARIABLETYPE_INTEGER, 0);
        $offSceneID = $this->createActionableVariable(VARIABLETYPE_INTEGER, 0);
        $profiles = json_encode([
            ['Profile' => 'Day', 'VariableID' => $onSceneID, 'OnValue' => '2', 'OffValue' => ''],
            ['Profile' => 'Day', 'VariableID' => $offSceneID, 'OnValue' => '', 'OffValue' => '2'],
        ], JSON_THROW_ON_ERROR);
        $conditions = json_encode([
            ['Profile' => 'Day', 'VariableID' => $modeVariableID, 'Operator' => 'equals', 'ExpectedValue' => 'false'],
        ], JSON_THROW_ON_ERROR);
        $instanceID = $this->createInstance();
        $this->configure($instanceID, [
            'MotionVariableID' => $motionVariableID,
            'OffDelay' => 0,
            'OutputProfiles' => $profiles,
            'ProfileConditions' => $conditions,
        ]);

        $this->assertSame(2, GetValueInteger($offSceneID));
        $this->assertSame(0, GetValueInteger($onSceneID));

        SetValue($motionVariableID, true);
        IPS\InstanceManager::getInstanceInterface($instanceID)->MessageSink(time(), $motionVariableID, VM_UPDATE, []);

        $this->assertSame(2, GetValueInteger($onSceneID));
        $this->assertSame(2, GetValueInteger($offSceneID));
    }

    public function testTimerClearsOccupancyAfterPresenceFalls(): void
    {
        $motionVariableID = $this->createVariable(VARIABLETYPE_BOOLEAN, true);
        $instanceID = $this->createInstance();
        $this->configure($instanceID, [
            'MotionVariableID' => $motionVariableID,
            'OffDelay'         => 1,
        ]);

        $occupiedID = IPS_GetObjectIDByIdent('Occupied', $instanceID);
        $refresh = $this->getModuleConfiguration()['prefix'] . '_Refresh';
        $tick = $this->getModuleConfiguration()['prefix'] . '_Tick';

        $this->assertTrue(GetValueBoolean($occupiedID));
        SetValue($motionVariableID, false);
        $refresh($instanceID);
        sleep(2);
        $tick($instanceID);

        $this->assertFalse(GetValueBoolean($occupiedID));
    }

    public function testManualOverrideHoldsOutputUntilAutomaticIsRestored(): void
    {
        $motionVariableID = $this->createVariable(VARIABLETYPE_BOOLEAN, false);
        $targetVariableID = $this->createActionableVariable(VARIABLETYPE_BOOLEAN, false);
        $instanceID = $this->createInstance();
        $this->configure($instanceID, [
            'MotionVariableID' => $motionVariableID,
            'TargetVariableID' => $targetVariableID,
            'OffDelay'         => 0,
        ]);

        $module = IPS\InstanceManager::getInstanceInterface($instanceID);
        $module->RequestAction('ManualOutput', true);
        $this->assertTrue(GetValueBoolean($targetVariableID));

        $refresh = $this->getModuleConfiguration()['prefix'] . '_Refresh';
        SetValue($motionVariableID, true);
        $refresh($instanceID);
        SetValue($motionVariableID, false);
        $refresh($instanceID);

        $this->assertFalse(GetValueBoolean(IPS_GetObjectIDByIdent('Occupied', $instanceID)));
        $this->assertTrue(GetValueBoolean($targetVariableID));

        $module->RequestAction('ManualOverride', false);
        $this->assertFalse(GetValueBoolean($targetVariableID));
        $this->assertFalse(GetValueBoolean(IPS_GetObjectIDByIdent('ManualOverride', $instanceID)));
    }

    public function testRequestActionRejectsWrongValueType(): void
    {
        $instanceID = $this->createInstance();
        $module = IPS\InstanceManager::getInstanceInterface($instanceID);

        $this->expectException(InvalidArgumentException::class);
        $module->RequestAction('ManualOutput', 'true');
    }

    public function testDisabledAutomationSuppressesAutomaticAndManualOutputActions(): void
    {
        $motionVariableID = $this->createVariable(VARIABLETYPE_BOOLEAN, false);
        $targetVariableID = $this->createActionableVariable(VARIABLETYPE_BOOLEAN, true);
        $instanceID = $this->createInstance(false);
        $this->configure($instanceID, [
            'MotionVariableID' => $motionVariableID,
            'TargetVariableID' => $targetVariableID,
            'OffDelay'         => 0,
        ]);

        $this->assertTrue(GetValueBoolean($targetVariableID));

        $module = IPS\InstanceManager::getInstanceInterface($instanceID);
        $module->RequestAction('ManualOutput', false);
        SetValue($motionVariableID, true);
        $module->MessageSink(time(), $motionVariableID, VM_UPDATE, []);
        SetValue($motionVariableID, false);
        $module->MessageSink(time(), $motionVariableID, VM_UPDATE, []);

        $this->assertTrue(GetValueBoolean($targetVariableID));
        $this->assertFalse(GetValueBoolean(IPS_GetObjectIDByIdent('MotionDetected', $instanceID)));

        IPS_SetProperty($instanceID, 'AutomationEnabled', true);
        IPS_ApplyChanges($instanceID);

        $this->assertFalse(GetValueBoolean($targetVariableID));
    }

    private function createInstance(bool $automationEnabled = true): int
    {
        $moduleConfiguration = $this->getModuleConfiguration();
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');

        $instanceID = IPS_CreateInstance($moduleConfiguration['id']);
        IPS_SetProperty($instanceID, 'AutomationEnabled', $automationEnabled);

        return $instanceID;
    }

    private function configure(int $instanceID, array $properties): void
    {
        foreach ($properties as $name => $value) {
            IPS_SetProperty($instanceID, $name, $value);
        }
        IPS_ApplyChanges($instanceID);
    }

    private function createVariable(int $type, bool|int|float|string $initialValue): int
    {
        $variableID = IPS_CreateVariable($type);
        IPS_SetParent($variableID, 0);
        IPS_SetName($variableID, 'Test Variable ' . $variableID);
        SetValue($variableID, $initialValue);

        return $variableID;
    }

    private function createActionableVariable(int $type, bool|int|float|string $initialValue): int
    {
        $variableID = $this->createVariable($type, $initialValue);
        $scriptID = IPS_CreateScript(0);
        IPS_SetScriptContent($scriptID, 'SetValue($_IPS[\'VARIABLE\'], $_IPS[\'VALUE\']);');
        IPS_SetVariableCustomAction($variableID, $scriptID);

        return $variableID;
    }

    private function getModuleConfiguration(): array
    {
        return json_decode(
            file_get_contents(__DIR__ . '/../MotionSensor/module.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }
}
