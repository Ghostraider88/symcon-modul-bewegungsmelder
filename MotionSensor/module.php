<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/VisuStyle.php';
require_once __DIR__ . '/../libs/VisuState.php';

class MotionSensor extends IPSModuleStrict
{
    private const INPUT_PRESENCE = 'presence';
    private const INPUT_PULSE = 'pulse';

    public function Create(): void
    {
        parent::Create();
        $this->SetVisualizationType(1);

        $this->RegisterPropertyInteger('MotionVariableID', 0);
        $this->RegisterPropertyBoolean('AutomationEnabled', false);
        $this->RegisterPropertyString('InputMode', self::INPUT_PRESENCE);
        $this->RegisterPropertyBoolean('InvertInput', false);
        $this->RegisterPropertyString('AdditionalMotionInputs', '[]');
        $this->RegisterPropertyInteger('OffDelay', 120);
        $this->RegisterPropertyInteger('BlockVariableID', 0);
        $this->RegisterPropertyBoolean('BlockWhenActive', true);
        $this->RegisterPropertyInteger('BrightnessVariableID', 0);
        $this->RegisterPropertyFloat('BrightnessThreshold', 800.0);
        $this->RegisterPropertyString('BrightnessCondition', 'below');
        $this->RegisterPropertyString('OutputProfiles', '[]');
        $this->RegisterPropertyString('ProfileConditions', '[]');
        $this->RegisterPropertyInteger('TargetVariableID', 0);
        $this->RegisterPropertyString('TargetOnValue', 'true');
        $this->RegisterPropertyString('TargetOffValue', 'false');

        $this->RegisterAttributeInteger('DueAt', 0);
        $this->RegisterAttributeString('RegisteredMessageIDs', '[]');
        $this->RegisterAttributeString('RegisteredReferenceIDs', '[]');
        $this->RegisterAttributeString('LastCommandSignature', '');
        $this->RegisterAttributeString('LastOutputProfile', '');
        $this->RegisterAttributeBoolean('ManualOverrideActive', false);
        $this->RegisterAttributeBoolean('LastOutputState', false);
        $this->RegisterAttributeBoolean('HasLastOutputState', false);

        $this->RegisterTimer('PresenceTimer', 0, 'MSM_Tick($_IPS[\'TARGET\']);');
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->MaintainVariable('MotionDetected', $this->Translate('Motion detected'), VARIABLETYPE_BOOLEAN, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], 10, true);
        $this->MaintainVariable('Occupied', $this->Translate('Occupied'), VARIABLETYPE_BOOLEAN, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], 20, true);
        $this->MaintainVariable('RemainingSeconds', $this->Translate('Remaining off delay'), VARIABLETYPE_INTEGER, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' s', 'DIGITS' => 0], 30, true);
        $this->MaintainVariable('ManualOverride', $this->Translate('Manual override'), VARIABLETYPE_BOOLEAN, ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH], 40, true);
        $this->MaintainVariable('ManualOutput', $this->Translate('Manual output control'), VARIABLETYPE_BOOLEAN, ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH], 50, true);
        $this->MaintainVariable('OutputState', $this->Translate('Requested output state'), VARIABLETYPE_BOOLEAN, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], 60, true);
        $this->MaintainVariable('AutomaticBlocked', $this->Translate('Automatic control blocked'), VARIABLETYPE_BOOLEAN, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], 70, true);
        $this->MaintainVariable('TurnOnConditionMet', $this->Translate('Turn-on condition met'), VARIABLETYPE_BOOLEAN, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], 80, true);
        $this->MaintainVariable('ActiveProfile', $this->Translate('Active output profile'), VARIABLETYPE_STRING, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], 85, true);
        $this->MaintainVariable(
            'CurrentBrightness',
            $this->Translate('Current brightness'),
            VARIABLETYPE_FLOAT,
            ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' lx', 'DIGITS' => 0],
            90,
            $this->ReadPropertyInteger('BrightnessVariableID') > 0
        );

        $automationEnabled = $this->ReadPropertyBoolean('AutomationEnabled');
        $this->MaintainAction('ManualOverride', $automationEnabled);
        $this->MaintainAction('ManualOutput', $automationEnabled);

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->SetTimerInterval('PresenceTimer', 0);
            return;
        }

        try {
            $motionInputs = $this->GetMotionInputs();
            $outputProfiles = $this->GetOutputProfiles();
            $profileConditions = $this->GetProfileConditions();
        } catch (Throwable $exception) {
            $this->SetStatus(202);
            $this->SendDebug(__FUNCTION__, $exception->getMessage(), 0);
            $this->UpdateVisualizationValue($this->GetVisualizationPayload());
            return;
        }
        $blockVariableID = $this->ReadPropertyInteger('BlockVariableID');
        $brightnessVariableID = $this->ReadPropertyInteger('BrightnessVariableID');
        $targetVariableID = $this->ReadPropertyInteger('TargetVariableID');
        $this->UpdateReferences($motionInputs, $blockVariableID, $brightnessVariableID, $targetVariableID, $outputProfiles, $profileConditions);

        $offDelay = $this->ReadPropertyInteger('OffDelay');
        $inputMode = $this->ReadPropertyString('InputMode');
        if ($offDelay < 0 || $offDelay > 86400 || !in_array($inputMode, [self::INPUT_PRESENCE, self::INPUT_PULSE], true)) {
            $this->SetStatus(202);
            $this->UpdateVisualizationValue($this->GetVisualizationPayload());
            return;
        }

        if ($blockVariableID < 0 || $brightnessVariableID < 0 || $targetVariableID < 0) {
            $this->SetStatus(202);
            $this->UpdateVisualizationValue($this->GetVisualizationPayload());
            return;
        }

        if (count($motionInputs) === 0) {
            $this->SetValue('MotionDetected', false);
            $this->ClearDeadline();
            $this->SetOccupied(false);
            $this->SetStatus(104);
            $this->UpdateVisualizationValue($this->GetVisualizationPayload());
            return;
        }

        foreach ($motionInputs as $motionInput) {
            if (!IPS_VariableExists($motionInput['VariableID']) || !$this->IsSupportedVariable($motionInput['VariableID'])) {
                $this->SetStatus(202);
                $this->UpdateVisualizationValue($this->GetVisualizationPayload());
                return;
            }
        }

        if ($targetVariableID > 0 && (!IPS_VariableExists($targetVariableID) || !$this->IsSupportedVariable($targetVariableID))) {
            $this->SetStatus(202);
            $this->UpdateVisualizationValue($this->GetVisualizationPayload());
            return;
        }
        $conditionVariableIDs = array_values(array_unique(array_merge(
            array_filter([$blockVariableID, $brightnessVariableID], static fn(int $id): bool => $id > 0),
            array_column($profileConditions, 'VariableID')
        )));
        $inputVariableIDs = array_column($motionInputs, 'VariableID');
        if (count(array_unique(array_merge($inputVariableIDs, $conditionVariableIDs))) !== count(array_merge($inputVariableIDs, $conditionVariableIDs))) {
            $this->SetStatus(202);
            $this->UpdateVisualizationValue($this->GetVisualizationPayload());
            return;
        }
        $allOutputIDs = array_values(array_unique(array_merge([$targetVariableID], array_column($outputProfiles, 'VariableID'))));
        $allOutputIDs = array_filter($allOutputIDs, static fn(int $id): bool => $id > 0);
        if (array_intersect($allOutputIDs, array_merge($inputVariableIDs, $conditionVariableIDs)) !== []) {
            $this->SetStatus(202);
            $this->UpdateVisualizationValue($this->GetVisualizationPayload());
            return;
        }

        if ($blockVariableID > 0 && (!IPS_VariableExists($blockVariableID) || !$this->IsSupportedVariable($blockVariableID))) {
            $this->SetStatus(202);
            $this->UpdateVisualizationValue($this->GetVisualizationPayload());
            return;
        }

        if ($brightnessVariableID > 0 && (!IPS_VariableExists($brightnessVariableID) || !in_array(IPS_GetVariable($brightnessVariableID)['VariableType'], [VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT], true))) {
            $this->SetStatus(202);
            $this->UpdateVisualizationValue($this->GetVisualizationPayload());
            return;
        }

        $brightnessThreshold = $this->ReadPropertyFloat('BrightnessThreshold');
        if (!in_array($this->ReadPropertyString('BrightnessCondition'), ['below', 'above'], true) || !is_finite($brightnessThreshold) || $brightnessThreshold < 0) {
            $this->SetStatus(202);
            $this->UpdateVisualizationValue($this->GetVisualizationPayload());
            return;
        }

        if ($targetVariableID > 0) {
            try {
                $this->GetTargetValue(true);
                $this->GetTargetValue(false);
            } catch (InvalidArgumentException) {
                $this->SetStatus(202);
                $this->UpdateVisualizationValue($this->GetVisualizationPayload());
                return;
            }
        }

        $profileNames = array_values(array_unique(array_column($outputProfiles, 'Profile')));
        foreach ($outputProfiles as $outputProfile) {
            $variableID = $outputProfile['VariableID'];
            if (!IPS_VariableExists($variableID) || !$this->IsSupportedVariable($variableID)) {
                $this->SetStatus(202);
                $this->UpdateVisualizationValue($this->GetVisualizationPayload());
                return;
            }
            try {
                if ($outputProfile['OnValue'] !== '') {
                    $this->ParseVariableValue($variableID, $outputProfile['OnValue']);
                }
                if ($outputProfile['OffValue'] !== '') {
                    $this->ParseVariableValue($variableID, $outputProfile['OffValue']);
                }
            } catch (InvalidArgumentException) {
                $this->SetStatus(202);
                $this->UpdateVisualizationValue($this->GetVisualizationPayload());
                return;
            }
        }
        foreach ($profileConditions as $profileCondition) {
            if (!in_array($profileCondition['Profile'], $profileNames, true)
                || !IPS_VariableExists($profileCondition['VariableID'])
                || !$this->IsSupportedVariable($profileCondition['VariableID'])) {
                $this->SetStatus(202);
                $this->UpdateVisualizationValue($this->GetVisualizationPayload());
                return;
            }
            if (in_array($profileCondition['Operator'], ['equals', 'not_equals'], true)) {
                try {
                    $this->ParseVariableValue($profileCondition['VariableID'], $profileCondition['ExpectedValue']);
                } catch (InvalidArgumentException) {
                    $this->SetStatus(202);
                    $this->UpdateVisualizationValue($this->GetVisualizationPayload());
                    return;
                }
            }
        }

        $this->SetStatus($automationEnabled ? 102 : 104);

        $dueAt = $this->ReadAttributeInteger('DueAt');
        if ($dueAt > 0 && $dueAt <= time()) {
            $this->Tick();
        } else {
            $this->RefreshFromMotionSource();
            $this->RestoreTimer();
        }

        $this->SynchronizeAutomaticOutput(!$this->ReadAttributeBoolean('ManualOverrideActive'));

        $this->UpdateVisualizationValue($this->GetVisualizationPayload());
    }

    public function MessageSink(int $timestamp, int $senderID, int $message, array $data): void
    {
        if ($message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }

        if ($message !== VM_UPDATE) {
            return;
        }

        try {
            $motionInputIDs = array_column($this->GetMotionInputs(), 'VariableID');
            $profileConditionIDs = array_column($this->GetProfileConditions(), 'VariableID');
        } catch (Throwable) {
            return;
        }

        if (in_array($senderID, $motionInputIDs, true)) {
            $this->RefreshFromMotionSource($senderID);
            return;
        }

        if ($senderID === $this->ReadPropertyInteger('BlockVariableID')
            || $senderID === $this->ReadPropertyInteger('BrightnessVariableID')
            || in_array($senderID, $profileConditionIDs, true)) {
            $this->RefreshFromMotionSource($senderID);
        }
    }

    public function RequestAction(string $ident, mixed $value): void
    {
        if (!is_bool($value)) {
            throw new InvalidArgumentException($ident . ' expects a boolean value.');
        }

        if (!$this->ReadPropertyBoolean('AutomationEnabled')) {
            $this->SendDebug(__FUNCTION__, 'Automation is disabled; manual action ignored.', 0);
            return;
        }

        switch ($ident) {
            case 'ManualOverride':
                $this->WriteAttributeBoolean('ManualOverrideActive', $value);
                $this->SetValue('ManualOverride', $value);
                $this->SynchronizeAutomaticOutput(!$value);
                break;

            case 'ManualOutput':
                $this->WriteAttributeBoolean('ManualOverrideActive', true);
                $this->SetValue('ManualOverride', true);
                $this->SynchronizeAutomaticOutput(false);
                $this->SetOutputState($value, true);
                break;

            default:
                throw new Exception('Invalid Ident: ' . $ident);
        }

        $this->UpdateVisualizationValue($this->GetVisualizationPayload());
    }

    public function Refresh(): void
    {
        $this->RefreshFromMotionSource();
    }

    public function Tick(): void
    {
        $dueAt = $this->ReadAttributeInteger('DueAt');
        if ($dueAt === 0) {
            $this->SetTimerInterval('PresenceTimer', 0);
            $this->SetValue('RemainingSeconds', 0);
            $this->UpdateVisualizationValue($this->GetVisualizationPayload());
            return;
        }

        if ($dueAt > time()) {
            $this->UpdateRemainingSeconds();
            $this->SetTimerInterval('PresenceTimer', 1000);
            $this->UpdateVisualizationValue($this->GetVisualizationPayload());
            return;
        }

        if ($this->HasActivePresenceInput()) {
            $this->ClearDeadline();
            $this->SetValue('MotionDetected', true);
            $this->SetOccupied(true);
        } else {
            $this->ClearDeadline();
            $this->SetValue('MotionDetected', false);
            $this->SetOccupied(false);
        }

        $this->UpdateVisualizationValue($this->GetVisualizationPayload());
    }

    public function GetVisualizationTile(): string
    {
        return ModuleVisuStyle::renderTile($this->GetVisualizationConfiguration());
    }

    private function UpdateReferences(
        array $motionInputs,
        int $blockVariableID,
        int $brightnessVariableID,
        int $targetVariableID,
        array $outputProfiles,
        array $profileConditions
    ): void
    {
        $motionInputIDs = array_column($motionInputs, 'VariableID');
        $profileConditionIDs = array_column($profileConditions, 'VariableID');
        $profileOutputIDs = array_column($outputProfiles, 'VariableID');
        $messageIDs = array_values(array_unique(array_filter(
            array_merge($motionInputIDs, [$blockVariableID, $brightnessVariableID], $profileConditionIDs),
            static fn(int $id): bool => $id > 0 && IPS_VariableExists($id)
        )));
        $referenceIDs = array_values(array_unique(array_filter(
            array_merge($messageIDs, [$targetVariableID], $profileOutputIDs),
            static fn(int $id): bool => $id > 0 && IPS_VariableExists($id)
        )));

        $previousMessageIDs = json_decode($this->ReadAttributeString('RegisteredMessageIDs'), true) ?: [];
        $previousReferenceIDs = json_decode($this->ReadAttributeString('RegisteredReferenceIDs'), true) ?: [];

        foreach (array_diff($previousMessageIDs, $messageIDs) as $id) {
            $this->UnregisterMessage((int)$id, VM_UPDATE);
        }
        foreach (array_diff($previousReferenceIDs, $referenceIDs) as $id) {
            $this->UnregisterReference((int)$id);
        }
        // Message subscriptions are runtime filters; rebuild them after a module reload.
        foreach ($messageIDs as $id) {
            $this->RegisterMessage((int)$id, VM_UPDATE);
        }
        foreach (array_diff($referenceIDs, $previousReferenceIDs) as $id) {
            $this->RegisterReference((int)$id);
        }

        $this->WriteAttributeString('RegisteredMessageIDs', json_encode($messageIDs, JSON_THROW_ON_ERROR));
        $this->WriteAttributeString('RegisteredReferenceIDs', json_encode($referenceIDs, JSON_THROW_ON_ERROR));
    }

    private function RefreshFromMotionSource(?int $changedVariableID = null): void
    {
        try {
            $motionInputs = $this->GetMotionInputs();
        } catch (Throwable $exception) {
            $this->SetStatus(202);
            $this->SendDebug(__FUNCTION__, $exception->getMessage(), 0);
            $this->UpdateVisualizationValue($this->GetVisualizationPayload());
            return;
        }
        if (count($motionInputs) === 0) {
            return;
        }

        try {
            $motionDetected = false;
            $presenceActive = false;
            $pulseTriggered = false;
            foreach ($motionInputs as $motionInput) {
                $variableID = $motionInput['VariableID'];
                if (!IPS_VariableExists($variableID)) {
                    throw new InvalidArgumentException('A configured motion input no longer exists.');
                }

                $active = $this->ReadVariableActive($variableID, $motionInput['InvertInput']);
                $motionDetected = $motionDetected || $active;
                $presenceActive = $presenceActive || ($active && $motionInput['InputMode'] === self::INPUT_PRESENCE);
                $pulseTriggered = $pulseTriggered || (
                    $active
                    && $motionInput['InputMode'] === self::INPUT_PULSE
                    && ($changedVariableID === null || $changedVariableID === $variableID)
                );
            }
        } catch (Throwable $exception) {
            $this->SetStatus(203);
            $this->SendDebug(__FUNCTION__, $exception->getMessage(), 0);
            $this->UpdateVisualizationValue($this->GetVisualizationPayload());
            return;
        }

        $this->SetValue('MotionDetected', $motionDetected);
        if ($presenceActive) {
            $this->ClearDeadline();
            $this->SetOccupied(true);
        } elseif ($pulseTriggered) {
            $this->WriteAttributeInteger('DueAt', time() + $this->ReadPropertyInteger('OffDelay'));
            $this->SetOccupied(true);
            $this->RestoreTimer();
        } elseif ((bool)$this->GetValue('Occupied')) {
            if ($this->ReadAttributeInteger('DueAt') === 0) {
                $offDelay = $this->ReadPropertyInteger('OffDelay');
                if ($offDelay === 0) {
                    $this->SetOccupied(false);
                } else {
                    $this->WriteAttributeInteger('DueAt', time() + $offDelay);
                    $this->RestoreTimer();
                }
            } else {
                $this->RestoreTimer();
            }
        }

        $this->SynchronizeAutomaticOutput(!$this->ReadAttributeBoolean('ManualOverrideActive'));
        $this->UpdateRemainingSeconds();
        $this->UpdateVisualizationValue($this->GetVisualizationPayload());
    }

    private function GetMotionInputs(): array
    {
        $inputs = [];
        $motionVariableID = $this->ReadPropertyInteger('MotionVariableID');
        if ($motionVariableID > 0) {
            $inputs[] = [
                'VariableID' => $motionVariableID,
                'InputMode' => $this->ReadPropertyString('InputMode'),
                'InvertInput' => $this->ReadPropertyBoolean('InvertInput'),
            ];
        }

        $additionalInputs = json_decode($this->ReadPropertyString('AdditionalMotionInputs'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($additionalInputs)) {
            throw new InvalidArgumentException('Additional motion inputs must be a list.');
        }

        foreach ($additionalInputs as $additionalInput) {
            if (!is_array($additionalInput)) {
                throw new InvalidArgumentException('Each additional motion input must be an object.');
            }

            $variableID = filter_var($additionalInput['VariableID'] ?? 0, FILTER_VALIDATE_INT);
            if ($variableID === false) {
                throw new InvalidArgumentException('An additional motion input has an invalid variable ID.');
            }
            if ($variableID === 0) {
                continue;
            }

            $inputMode = $additionalInput['InputMode'] ?? self::INPUT_PRESENCE;
            $invertInput = $additionalInput['InvertInput'] ?? false;
            if (!is_string($inputMode) || !in_array($inputMode, [self::INPUT_PRESENCE, self::INPUT_PULSE], true) || !is_bool($invertInput)) {
                throw new InvalidArgumentException('Additional motion input settings are invalid.');
            }
            $inputs[] = [
                'VariableID' => (int)$variableID,
                'InputMode' => $inputMode,
                'InvertInput' => $invertInput,
            ];
        }

        $seen = [];
        foreach ($inputs as $input) {
            if (isset($seen[$input['VariableID']])) {
                throw new InvalidArgumentException('A motion variable can only be configured once.');
            }
            $seen[$input['VariableID']] = true;
        }

        return $inputs;
    }

    private function GetOutputProfiles(): array
    {
        $profiles = json_decode($this->ReadPropertyString('OutputProfiles'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($profiles)) {
            throw new InvalidArgumentException('Output profiles must be a list.');
        }

        $result = [];
        foreach ($profiles as $profile) {
            if (!is_array($profile)) {
                throw new InvalidArgumentException('Each output profile entry must be an object.');
            }

            $name = trim((string)($profile['Profile'] ?? ''));
            $variableID = filter_var($profile['VariableID'] ?? 0, FILTER_VALIDATE_INT);
            $onValue = $profile['OnValue'] ?? null;
            $offValue = $profile['OffValue'] ?? null;
            if ($name === '' && ($variableID === 0 || $variableID === false) && ($onValue === null || $onValue === '') && ($offValue === null || $offValue === '')) {
                continue;
            }
            if ($name === '' || $variableID === false || $variableID <= 0 || !is_string($onValue) || !is_string($offValue) || ($onValue === '' && $offValue === '')) {
                throw new InvalidArgumentException('An output profile requires a name, target variable, and on/off values.');
            }

            $result[] = [
                'Profile' => $name,
                'VariableID' => (int)$variableID,
                'OnValue' => $onValue,
                'OffValue' => $offValue,
            ];
        }

        $seen = [];
        foreach ($result as $profile) {
            $key = $profile['Profile'] . ':' . $profile['VariableID'];
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('A profile can only define one action per target variable.');
            }
            $seen[$key] = true;
        }

        return $result;
    }

    private function GetProfileConditions(): array
    {
        $conditions = json_decode($this->ReadPropertyString('ProfileConditions'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($conditions)) {
            throw new InvalidArgumentException('Profile conditions must be a list.');
        }

        $result = [];
        foreach ($conditions as $condition) {
            if (!is_array($condition)) {
                throw new InvalidArgumentException('Each profile condition entry must be an object.');
            }

            $profile = trim((string)($condition['Profile'] ?? ''));
            $variableID = filter_var($condition['VariableID'] ?? 0, FILTER_VALIDATE_INT);
            $operator = $condition['Operator'] ?? 'equals';
            $expectedValue = $condition['ExpectedValue'] ?? '';
            if ($profile === '' && ($variableID === 0 || $variableID === false) && $expectedValue === '') {
                continue;
            }
            if ($profile === '' || $variableID === false || $variableID <= 0
                || !is_string($operator) || !in_array($operator, ['equals', 'not_equals', 'active', 'inactive'], true)
                || !is_string($expectedValue)) {
                throw new InvalidArgumentException('A profile condition requires a profile, variable, operator, and valid value.');
            }

            $result[] = [
                'Profile' => $profile,
                'VariableID' => (int)$variableID,
                'Operator' => $operator,
                'ExpectedValue' => $expectedValue,
            ];
        }

        return $result;
    }

    private function GetActiveProfileName(): string
    {
        $profiles = $this->GetOutputProfiles();
        $conditions = $this->GetProfileConditions();
        $profileNames = array_values(array_unique(array_column($profiles, 'Profile')));

        foreach ($profileNames as $profileName) {
            $matches = true;
            foreach ($conditions as $condition) {
                if ($condition['Profile'] === $profileName && !$this->DoesProfileConditionMatch($condition)) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                return $profileName;
            }
        }

        return '';
    }

    private function DoesProfileConditionMatch(array $condition): bool
    {
        $variableID = $condition['VariableID'];
        return match ($condition['Operator']) {
            'active' => $this->ReadVariableActive($variableID, false),
            'inactive' => !$this->ReadVariableActive($variableID, false),
            'equals' => GetValue($variableID) === $this->ParseVariableValue($variableID, $condition['ExpectedValue']),
            'not_equals' => GetValue($variableID) !== $this->ParseVariableValue($variableID, $condition['ExpectedValue']),
            default => false,
        };
    }

    private function GetOutputCommands(bool $state): array
    {
        $profileName = $this->GetActiveProfileName();
        $profiles = $this->GetOutputProfiles();
        $commands = [];
        if ($profileName !== '') {
            foreach ($profiles as $profile) {
                if ($profile['Profile'] === $profileName) {
                    $rawValue = $state ? $profile['OnValue'] : $profile['OffValue'];
                    if ($rawValue === '') {
                        continue;
                    }
                    $commands[] = [
                        'VariableID' => $profile['VariableID'],
                        'Value' => $this->ParseVariableValue($profile['VariableID'], $rawValue),
                    ];
                }
            }
        } else {
            $targetVariableID = $this->ReadPropertyInteger('TargetVariableID');
            if ($targetVariableID > 0) {
                $commands[] = [
                    'VariableID' => $targetVariableID,
                    'Value' => $this->GetTargetValue($state),
                ];
            }
        }

        $previousProfile = $this->ReadAttributeString('LastOutputProfile');
        if ($this->ReadAttributeBoolean('LastOutputState') && $previousProfile !== $profileName) {
            $currentTargetIDs = array_column($commands, 'VariableID');
            $previousCommands = [];
            if ($previousProfile !== '') {
                foreach ($profiles as $profile) {
                    if ($profile['Profile'] === $previousProfile) {
                        if ($profile['OffValue'] === '') {
                            continue;
                        }
                        $previousCommands[] = [
                            'VariableID' => $profile['VariableID'],
                            'Value' => $this->ParseVariableValue($profile['VariableID'], $profile['OffValue']),
                        ];
                    }
                }
            } elseif ($this->ReadPropertyInteger('TargetVariableID') > 0) {
                $targetVariableID = $this->ReadPropertyInteger('TargetVariableID');
                $previousCommands[] = [
                    'VariableID' => $targetVariableID,
                    'Value' => $this->GetTargetValue(false),
                ];
            }

            foreach ($previousCommands as $previousCommand) {
                if (!in_array($previousCommand['VariableID'], $currentTargetIDs, true)) {
                    array_unshift($commands, $previousCommand);
                }
            }
        }

        return ['Profile' => $profileName, 'Commands' => $commands];
    }

    private function HasActivePresenceInput(): bool
    {
        foreach ($this->GetMotionInputs() as $motionInput) {
            if ($motionInput['InputMode'] === self::INPUT_PRESENCE && $this->ReadVariableActive($motionInput['VariableID'], $motionInput['InvertInput'])) {
                return true;
            }
        }

        return false;
    }

    private function ReadVariableActive(int $variableID, bool $invert): bool
    {
        $value = GetValue($variableID);
        $active = match (gettype($value)) {
            'boolean' => $value,
            'integer', 'double' => $value != 0,
            'string' => in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes', 'motion', 'occupied'], true),
            default => throw new InvalidArgumentException('The selected motion variable has an unsupported value type.'),
        };

        return $invert ? !$active : $active;
    }

    private function SetOccupied(bool $occupied): void
    {
        $changed = (bool)$this->GetValue('Occupied') !== $occupied;
        $this->SetValue('Occupied', $occupied);
        if (!$occupied) {
            $this->SetValue('RemainingSeconds', 0);
        }

        if ($changed && !$this->ReadAttributeBoolean('ManualOverrideActive')) {
            $this->SynchronizeAutomaticOutput();
        }

        if (!$occupied) {
            $this->SetTimerInterval('PresenceTimer', 0);
        }
    }

    private function SynchronizeAutomaticOutput(bool $dispatch = true): void
    {
        try {
            $this->SetValue('ActiveProfile', $this->GetActiveProfileName());
        } catch (Throwable $exception) {
            $this->SetStatus(203);
            $this->SendDebug(__FUNCTION__, $exception->getMessage(), 0);
        }
        $blocked = $this->IsExternalBlockActive();
        $occupied = (bool)$this->GetValue('Occupied');
        $brightnessConditionMet = $this->IsBrightnessConditionMet();
        $this->SetValue('AutomaticBlocked', $blocked);
        $this->SetValue('TurnOnConditionMet', $brightnessConditionMet);

        if (!$dispatch || $blocked || ($occupied && !$brightnessConditionMet)) {
            return;
        }

        $this->SetOutputState($occupied);
    }

    private function IsExternalBlockActive(): bool
    {
        $blockVariableID = $this->ReadPropertyInteger('BlockVariableID');
        if ($blockVariableID <= 0) {
            return false;
        }

        try {
            $active = $this->ReadVariableActive($blockVariableID, false);
            return $this->ReadPropertyBoolean('BlockWhenActive') ? $active : !$active;
        } catch (Throwable $exception) {
            $this->SetStatus(203);
            $this->SendDebug(__FUNCTION__, $exception->getMessage(), 0);
            return true;
        }
    }

    private function IsBrightnessConditionMet(): bool
    {
        $brightnessVariableID = $this->ReadPropertyInteger('BrightnessVariableID');
        if ($brightnessVariableID <= 0) {
            return true;
        }

        try {
            $brightness = GetValue($brightnessVariableID);
            if (!is_int($brightness) && !is_float($brightness)) {
                throw new InvalidArgumentException('The selected brightness variable is not numeric.');
            }

            $this->SetValue('CurrentBrightness', (float)$brightness);
            $threshold = $this->ReadPropertyFloat('BrightnessThreshold');
            return $this->ReadPropertyString('BrightnessCondition') === 'below'
                ? $brightness < $threshold
                : $brightness >= $threshold;
        } catch (Throwable $exception) {
            $this->SetStatus(203);
            $this->SendDebug(__FUNCTION__, $exception->getMessage(), 0);
            return false;
        }
    }

    private function RestoreTimer(): void
    {
        $dueAt = $this->ReadAttributeInteger('DueAt');
        if ($dueAt <= 0) {
            $this->SetTimerInterval('PresenceTimer', 0);
            $this->SetValue('RemainingSeconds', 0);
            return;
        }

        $this->UpdateRemainingSeconds();
        $this->SetTimerInterval('PresenceTimer', 1000);
    }

    private function UpdateRemainingSeconds(): void
    {
        $dueAt = $this->ReadAttributeInteger('DueAt');
        $remaining = $dueAt > 0 ? max(0, $dueAt - time()) : 0;
        $this->SetValue('RemainingSeconds', $remaining);
    }

    private function ClearDeadline(): void
    {
        $this->WriteAttributeInteger('DueAt', 0);
        $this->SetTimerInterval('PresenceTimer', 0);
        $this->SetValue('RemainingSeconds', 0);
    }

    private function SetOutputState(bool $state, bool $force = false): void
    {
        $output = $this->GetOutputCommands($state);
        $profileName = $output['Profile'];
        $commands = $output['Commands'];
        $commandSignature = json_encode(
            [$profileName, $commands],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $targetChanged = $commandSignature !== $this->ReadAttributeString('LastCommandSignature');
        $hasLastState = $this->ReadAttributeBoolean('HasLastOutputState');
        $lastState = $this->ReadAttributeBoolean('LastOutputState');
        $mustSend = $commands !== [] && ($force || $targetChanged || !$hasLastState || $lastState !== $state);

        $this->SetValue('ManualOutput', $state);
        $this->SetValue('OutputState', $state);
        $this->SetValue('ActiveProfile', $profileName);

        if (!$this->ReadPropertyBoolean('AutomationEnabled')) {
            return;
        }

        if (!$mustSend) {
            $this->WriteAttributeBoolean('LastOutputState', $state);
            $this->WriteAttributeBoolean('HasLastOutputState', true);
            $this->WriteAttributeString('LastOutputProfile', $profileName);
            $this->WriteAttributeString('LastCommandSignature', $commandSignature);
            return;
        }

        try {
            foreach ($commands as $command) {
                RequestAction($command['VariableID'], $command['Value']);
            }
            $this->WriteAttributeBoolean('LastOutputState', $state);
            $this->WriteAttributeBoolean('HasLastOutputState', true);
            $this->WriteAttributeString('LastOutputProfile', $profileName);
            $this->WriteAttributeString('LastCommandSignature', $commandSignature);
            $this->SetStatus(102);
        } catch (Throwable $exception) {
            $this->SetStatus(203);
            $this->SendDebug(__FUNCTION__, $exception->getMessage(), 0);
        }
    }

    private function GetTargetValue(bool $on): bool|int|float|string
    {
        $targetVariableID = $this->ReadPropertyInteger('TargetVariableID');
        $rawValue = $this->ReadPropertyString($on ? 'TargetOnValue' : 'TargetOffValue');

        return $this->ParseVariableValue($targetVariableID, $rawValue);
    }

    private function ParseVariableValue(int $variableID, string $rawValue): bool|int|float|string
    {
        $variableType = IPS_GetVariable($variableID)['VariableType'];
        return match ($variableType) {
            VARIABLETYPE_BOOLEAN => $this->ParseBooleanValue($rawValue),
            VARIABLETYPE_INTEGER => $this->ParseIntegerValue($rawValue),
            VARIABLETYPE_FLOAT => $this->ParseFloatValue($rawValue),
            VARIABLETYPE_STRING => $rawValue,
            default => throw new InvalidArgumentException('The selected target variable has an unsupported value type.'),
        };
    }

    private function ParseBooleanValue(string $value): bool
    {
        return match (strtolower(trim($value))) {
            '1', 'true', 'on', 'yes' => true,
            '0', 'false', 'off', 'no' => false,
            default => throw new InvalidArgumentException('Boolean target values must be true/false, on/off, yes/no, or 1/0.'),
        };
    }

    private function ParseIntegerValue(string $value): int
    {
        if (!preg_match('/^-?\d+$/D', trim($value))) {
            throw new InvalidArgumentException('Integer target values must contain a whole number.');
        }

        return (int)trim($value);
    }

    private function ParseFloatValue(string $value): float
    {
        if (!is_numeric(trim($value))) {
            throw new InvalidArgumentException('Float target values must be numeric.');
        }

        $parsed = (float)trim($value);
        if (!is_finite($parsed)) {
            throw new InvalidArgumentException('Float target values must be finite.');
        }

        return $parsed;
    }

    private function IsSupportedVariable(int $variableID): bool
    {
        return in_array(
            IPS_GetVariable($variableID)['VariableType'],
            [VARIABLETYPE_BOOLEAN, VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT, VARIABLETYPE_STRING],
            true
        );
    }

    private function GetVisualizationPayload(): string
    {
        $configuration = $this->GetVisualizationConfiguration();
        return json_encode([
            'type'       => 'delta',
            'state'      => $configuration['state'],
            'stateLabel' => $configuration['stateLabel'],
            'content'    => $configuration['content'],
            'footer'     => '',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function GetVisualizationConfiguration(): array
    {
        $occupied = (bool)$this->GetValue('Occupied');
        $override = $this->ReadAttributeBoolean('ManualOverrideActive');
        $automationEnabled = $this->ReadPropertyBoolean('AutomationEnabled');
        try {
            $configured = count($this->GetMotionInputs()) > 0;
        } catch (Throwable) {
            $configured = false;
        }
        $state = !$configured ? 'not_configured' : (!$automationEnabled ? 'warning' : ($override ? 'warning' : ($occupied ? 'active' : 'inactive')));
        $stateLabel = !$configured
            ? $this->Translate('Configure a motion variable')
            : (!$automationEnabled
                ? $this->Translate('Automation disabled')
                : ($override ? $this->Translate('Manual override active') : ($occupied ? $this->Translate('Occupied') : $this->Translate('Clear'))));

        return [
            'title'      => $this->Translate('Motion Sensor'),
            'icon'       => 'fa-light fa-person-walking',
            'state'      => ModuleVisuState::cssState($state),
            'stateLabel' => $stateLabel,
            'content'    => $this->RenderVisualizationContent($occupied, $override, $automationEnabled),
            'footer'     => '',
        ];
    }

    private function RenderVisualizationContent(bool $occupied, bool $override, bool $automationEnabled): string
    {
        $motion = (bool)$this->GetValue('MotionDetected');
        $remaining = (int)$this->GetValue('RemainingSeconds');
        $output = (bool)$this->GetValue('OutputState');
        $content = ModuleVisuStyle::stateBlock(
            $this->Translate('Motion'),
            $motion ? $this->Translate('Detected') : $this->Translate('No motion'),
            $motion ? 'active' : 'inactive'
        );
        $content .= ModuleVisuStyle::stateBlock(
            $this->Translate('Occupancy'),
            $occupied
                ? ($remaining > 0 ? sprintf($this->Translate('Occupied, %d s remaining'), $remaining) : $this->Translate('Occupied'))
                : $this->Translate('Clear'),
            $occupied ? 'active' : 'inactive'
        );
        if ($occupied && $remaining > 0) {
            $content .= ModuleVisuStyle::section(
                $this->Translate('Off delay'),
                '<div class="mvs-content">' . htmlspecialchars((string)$remaining, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' s</div>'
            );
        }
        if ($this->ReadPropertyInteger('BrightnessVariableID') > 0) {
            $content .= ModuleVisuStyle::stateBlock(
                $this->Translate('Current brightness'),
                sprintf('%.0f lx', (float)$this->GetValue('CurrentBrightness')),
                'info'
            );
        }
        $content .= ModuleVisuStyle::stateBlock(
            $this->Translate('Requested output'),
            $output ? $this->Translate('On') : $this->Translate('Off'),
            $output ? 'active' : 'inactive'
        );
        if (!$automationEnabled) {
            $content .= ModuleVisuStyle::alert($this->Translate('Automation is disabled; output actions are suppressed.'), 'warning');
        }
        $activeProfile = (string)$this->GetValue('ActiveProfile');
        if ($activeProfile !== '') {
            $content .= ModuleVisuStyle::stateBlock($this->Translate('Active output profile'), $activeProfile, 'info');
        }
        if ($this->ReadPropertyInteger('TargetVariableID') === 0 && $activeProfile === '') {
            $content .= ModuleVisuStyle::alert($this->Translate('No output target selected; the requested state remains local.'));
        }
        if ((bool)$this->GetValue('AutomaticBlocked')) {
            $content .= ModuleVisuStyle::alert($this->Translate('Automatic control is blocked by the selected variable.'), 'warning');
        } elseif ($occupied && !(bool)$this->GetValue('TurnOnConditionMet')) {
            $content .= ModuleVisuStyle::alert($this->Translate('Brightness condition prevents automatic turn-on.'), 'warning');
        }
        if ($override) {
            $content .= ModuleVisuStyle::alert($this->Translate('Automatic output control is paused until automatic mode is restored.'), 'warning');
        }
        if ($automationEnabled) {
            $content .= ModuleVisuStyle::section(
                $this->Translate('Control'),
                '<div class="mvs-actions">'
                . ModuleVisuStyle::button($this->Translate('Switch on manually'), 'ManualOutput', true)
                . ModuleVisuStyle::button($this->Translate('Switch off manually'), 'ManualOutput', false, true)
                . ModuleVisuStyle::button($this->Translate('Resume automatic mode'), 'ManualOverride', false, true)
                . '</div>'
            );
        }

        return $content;
    }
}
