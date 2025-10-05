<?php
declare(strict_types=1);

// Use local libs helper once added under maxence/libs
require_once __DIR__ . '/../libs/ModuleRegistration.php';

class SolarCharger extends IPSModule
{
    // GUID-based charger adapter removed – variables-only control

    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID);
    }

    public function Create()
    {
        parent::Create();
        try {
            $mr = new MaxenceModuleRegistration($this);
            $config = include __DIR__ . '/module.config.php';
            $mr->Register($config);
            $this->SetBuffer('chargingActive', '0');
            $this->SetBuffer('chargingStartCandidate', '0');
            $this->SetBuffer('chargingStopCandidate', '0');
            $this->SetBuffer('chargingMinOnUntil', '0');
            $this->SetBuffer('chargingMinOnStartSoc', '0');
            $this->SetBuffer('houseConsumptionFallback', '');
            $this->SetBuffer('chargerPowerSource', '');
            $this->SetBuffer('effectiveMaxCurrent', '0');
            $this->SetBuffer('daytimeSunUnderSince', '0');
            $this->SetBuffer('daytimeSunOverSince', '0');
            $this->SetBuffer('daytimeState', 'inactive');
            $this->SetBuffer('chargerRefreshAt', '0');
        } catch (Exception $e) {
            $this->LogMessage(__CLASS__ . ': Error creating SolarCharger: ' . $e->getMessage(), KL_ERROR);
        }
        $this->ensureLocalizedVariableNames();
    }

    public function Destroy() {
        $config = include __DIR__ . '/module.config.php';
        if (isset($config['profiles'])) {
            $mr = new MaxenceModuleRegistration($this);
            $mr->DeleteProfiles($config['profiles']);
        }
        parent::Destroy();
    }

    public function ApplyChanges() {
        parent::ApplyChanges();
        // Connect to selected energy gateway if provided, else to any implementing the neutral energy interface
        try {
            $energyGateway = (int)$this->ReadPropertyInteger('energyGateway');
            if ($energyGateway > 0) {
                @IPS_ConnectInstance($this->InstanceID, $energyGateway);
            } else {
                $this->connectToInterfaceParent('{E1E5D9C2-3A4B-4C5D-9E8F-ABCDEF123456}');
            }
        } catch (Exception $e) {
            $this->LogMessage('ApplyChanges: could not connect to energy gateway: ' . $e->getMessage(), KL_ERROR);
        }
        // Remove legacy variables no longer used
        try {
            if (@$this->GetIDForIdent('minChargerCurrent')) {
                @$this->UnregisterVariable('minChargerCurrent');
            }
        } catch (Throwable $t) {
            $this->SendDebug('SolarCharger', 'Cleanup minChargerCurrent failed: ' . $t->getMessage(), 0);
        }
        $this->ensureLocalizedVariableNames();
        $this->resetDaytimeSunTracking();
        // Event-driven via ReceiveData
    }

    private function ensureLocalizedVariableNames(): void
    {
        $this->renameVariableIfMatching('availablePower', 'Verfügbare Leistung', ['Available Power']);
    }

    private function renameVariableIfMatching(string $ident, string $desiredName, array $oldNames = []): void
    {
        $varId = @$this->GetIDForIdent($ident);
        if ($varId === false || !IPS_VariableExists($varId)) {
            return;
        }
        $currentName = IPS_GetName($varId);
        if ($currentName === $desiredName) {
            return;
        }
        if ($currentName === '' || in_array($currentName, $oldNames, true)) {
            IPS_SetName($varId, $desiredName);
        }
    }

    protected function getBatteryDataFromEnergy(array $battery): array
    {
        $level = isset($battery['soc_percent']) ? (float)$battery['soc_percent'] : null;
        $status = $battery['status'] ?? null;
        if ($status === null && isset($battery['sleep_enabled'])) {
            $status = ($battery['sleep_enabled'] === true) ? 'Schlafmodus' : null;
        }
        if ($status === null && isset($battery['led_status']) && is_numeric($battery['led_status'])) {
            $map = [ 12 => 'lädt', 13 => 'entlädt', 14 => 'voll', 15 => 'inaktiv', 16 => 'inaktiv', 17 => 'leer' ];
            $ls = (int)$battery['led_status'];
            $status = $map[$ls] ?? ('unbekannt (' . (string)$ls . ')');
            if ($status === 'voll' && $level !== null && $level < 100) { $status = 'inaktiv'; }
            if ($status === 'leer' && $level !== null && $level > 5) { $status = 'inaktiv'; }
        }
        return [ 'level' => $level ?? 0.0, 'status' => $status ?? 'unbekannt' ];
    }

    public function RequestAction($ident, $value) {
        // Directly mirror UI changes first
        $this->SetValue($ident, $value);

        // Normalize frequently used values to ints for consistency
        $chargerMode = (int)$this->GetValue('chargerMode');
        $hardwareMin = (int)$this->ReadPropertyInteger('minChargerCurrent');
        $effectiveMax = max(0, $this->resolveEffectiveMaxCurrent());
        if ($effectiveMax <= 0) {
            $effectiveMax = (int)$this->ReadPropertyInteger('maxChargerCurrent');
        }

        $maxChargerCurrent = (int)$this->GetValue('maxChargerCurrent');
        $maxCurrentBufferKey = 'maxCurrentBeforeOff';
        $storedMaxRaw = $this->GetBuffer($maxCurrentBufferKey);
        $storedMaxCurrent = ($storedMaxRaw !== '') ? (int)$storedMaxRaw : null;

        if ($chargerMode !== 4 && $ident === 'chargerMode') {
            if ($storedMaxCurrent !== null && $maxChargerCurrent <= 0) {
                $maxChargerCurrent = $storedMaxCurrent;
                $this->SetValue('maxChargerCurrent', $maxChargerCurrent);
            }
            $this->SetBuffer($maxCurrentBufferKey, '');
        }

        if ($ident === 'chargerMode') {
            if ($chargerMode === 2) {
                $this->handleDaytimeModeSwitch();
            } else {
                $this->resetDaytimeSunTracking();
            }
        }

        $this->LogMessage("RequestAction: {$ident} = {$value}", KL_NOTIFY);
        $this->LogMessage("1 -> chargerMode: {$chargerMode}, maxChargerCurrent: {$maxChargerCurrent}", KL_NOTIFY);

        if ($chargerMode === 4) {
            if ($ident === 'chargerMode' && $maxChargerCurrent > 0) {
                $this->SetBuffer($maxCurrentBufferKey, (string)$maxChargerCurrent);
            }

            $this->SetValue('maxChargerCurrent', 0);

            if ($ident === 'chargerMode') {
                $this->SetChargerCurrent(0, true);
                $this->LogMessage('Charger mode set to OFF. Charging stopped.', KL_NOTIFY);
            } elseif ($ident === 'chargerUpdate' || $ident === 'chargerReboot') {
                $this->SetValue($ident, false);
            }
            $this->LogMessage('Charger mode OFF -> max current display reset to 0.', KL_NOTIFY);
            return;
        }

        switch ($chargerMode) {
            case 3:
                // Manual mode uses the variable as fixed setpoint
                $maxChargerCurrent = max($hardwareMin, min($effectiveMax, $maxChargerCurrent));
                break;
            default:
                // Automatic modes: allow values down to 0 but keep within hardware max
                $maxChargerCurrent = max(0, min($effectiveMax, $maxChargerCurrent));
                break;
        }

        $this->SetValue('maxChargerCurrent', (int)$maxChargerCurrent);

        $this->LogMessage("2 -> chargerMode: {$chargerMode}, maxChargerCurrent: {$maxChargerCurrent}", KL_NOTIFY);

        $targetCurrent = null;

    // Sende Kommandos immer – ausschließlich variablenbasierte Steuerung
        switch ($ident) {
            // no direct set-current via UI variable anymore
            case 'chargerUpdate':
                if ((bool)$value) {
                    $this->sendChargerCommand([
                        'command'     => 'refresh',
                        'requested_at' => time(),
                        'reason'      => 'manual',
                    ]);
                }
                $this->SetValue('chargerUpdate', false);
                break;
            case 'chargerReboot':
                if ((bool)$value) {
                    $this->sendChargerCommand([
                        'command' => 'reboot',
                    ]);
                }
                $this->SetValue('chargerReboot', false);
                break;
        }
    }

    protected function getChargerData(float $production, float $batteryLevel): array {
        // Read mode and constraints (as ints where appropriate)
        $chargerMode = (int)$this->GetValue('chargerMode');
        $useBattery = $chargerMode !== 0;
        $isDaytimeFixed = ($chargerMode === 2);
        $isFixedMode = ($chargerMode === 3);
        $daytimeOnly = in_array($chargerMode, [0, 1], true);

        $effectiveMax = max(0, $this->resolveEffectiveMaxCurrent());
        if ($effectiveMax <= 0) {
            $effectiveMax = (int)$this->ReadPropertyInteger('maxChargerCurrent');
        }

        // Variable limits from variables (ints)
        $maxChargerCurrent = (int)$this->GetValue('maxChargerCurrent');
        if ($effectiveMax > 0 && $maxChargerCurrent > $effectiveMax) {
            $maxChargerCurrent = $effectiveMax;
        }
    $minChargerCurrent = $isFixedMode ? $maxChargerCurrent : 0;

        // Compute available power (W) from PV/battery/base loads
        $rawPower = (float)$production;

        $minBatteryLevel = (int)$this->ReadPropertyInteger('minBatteryLevel');        // percent
        $maxDischargePower = (int)$this->ReadPropertyInteger('maxDischargePower');    // W
        $standbyBaseload  = (int)$this->ReadPropertyInteger('standbyBaseload');       // W
        $minSunPower      = (int)$this->ReadPropertyInteger('minSunPower');           // W

        if ($useBattery && ($batteryLevel > $minBatteryLevel)) {
            $rawPower += $maxDischargePower;
        }

        $rawPower -= $standbyBaseload;
        $availablePower = max(0.0, $rawPower); // W

        // Convert available power to charger current in mA for 3-phase @230V
        // Ensure integer current (mA) -> Option A
        $chargerCurrent = (int) round($availablePower / 3.0 / 230.0 * 1000.0, 0);

        // Daytime-only: require min sun power
        if ($isDaytimeFixed) {
            if ($production < $minSunPower) {
                $availablePower = 0.0;
                $maxChargerCurrent = 0;
                $minChargerCurrent = 0;
            } else {
                $minChargerCurrent = $maxChargerCurrent;
            }
        } elseif ($daytimeOnly && $production < $minSunPower) {
            $availablePower = 0.0;
            $maxChargerCurrent = 0;
        }

        // Constrain by user settings
        $chargerCurrent = max($chargerCurrent, (int)$minChargerCurrent);
        if ($maxChargerCurrent > 0) {
            $chargerCurrent = min($chargerCurrent, (int)$maxChargerCurrent);
        }

        // Constrain by charger capabilities (properties)
        $minAllowedCurrent = (int)$this->ReadPropertyInteger('minChargerCurrent');
        if ($chargerCurrent < $minAllowedCurrent) {
            $chargerCurrent = 0;
        }
        $maxAllowedCurrent = $effectiveMax > 0 ? $effectiveMax : (int)$this->ReadPropertyInteger('maxChargerCurrent');
        if ($maxAllowedCurrent > 0 && $chargerCurrent > $maxAllowedCurrent) {
            $chargerCurrent = $maxAllowedCurrent;
        }

        // Derive charger power (W) from final current
        $chargerPowerW = ($chargerCurrent * 3.0 * 230.0) / 1000.0; // W (since current is mA)

        return [
            'chargerCurrent' => (int)$chargerCurrent,     // mA
            'chargerPower'   => (float)$chargerPowerW,    // W
            'availablePower' => (float)($availablePower / 1000.0), // kW (used for charging window logic/UI)
        ];
    }

    private function getDaytimeSunDelaySeconds(): int
    {
        $minutes = (int)$this->ReadPropertyInteger('daytimeSunDelayMinutes');
        if ($minutes <= 0) {
            return 0;
        }
        return max(0, $minutes) * 60;
    }

    private function resetDaytimeSunTracking(): void
    {
        $this->SetBuffer('daytimeSunUnderSince', '0');
        $this->SetBuffer('daytimeSunOverSince', '0');
        $this->SetBuffer('daytimeState', 'inactive');
    }

    private function handleDaytimeModeSwitch(): void
    {
        $this->resetDaytimeSunTracking();

        $minSunPower = (float)$this->ReadPropertyInteger('minSunPower');
        $delaySeconds = $this->getDaytimeSunDelaySeconds();

        try {
            $productionKw = (float)$this->GetValue('production');
        } catch (Throwable $t) {
            $productionKw = 0.0;
        }
        $productionW = $productionKw * 1000.0;

        if ($productionW >= $minSunPower) {
            $this->SetBuffer('daytimeState', 'active');
            $this->SetBuffer('daytimeSunUnderSince', '0');
            $this->SetBuffer('daytimeSunOverSince', '0');
            $this->SendDebug('SolarCharger', sprintf('Daytime mode activated immediately (production %.1f W ≥ %.1f W).', $productionW, $minSunPower), 0);

            $maxChargerCurrent = (int)$this->GetValue('maxChargerCurrent');
            if ($maxChargerCurrent <= 0) {
                $maxChargerCurrent = (int)$this->ReadPropertyInteger('maxChargerCurrent');
            }
            $hardwareMin = (int)$this->ReadPropertyInteger('minChargerCurrent');
            if ($maxChargerCurrent < $hardwareMin) {
                $maxChargerCurrent = $hardwareMin;
            }
            $effectiveMax = max(0, $this->resolveEffectiveMaxCurrent());
            if ($effectiveMax > 0) {
                $maxChargerCurrent = min($maxChargerCurrent, $effectiveMax);
            }
            if ($maxChargerCurrent > 0) {
                $this->SetChargerCurrent($maxChargerCurrent, true);
            }
        } else {
            if ($delaySeconds > 0) {
                $this->SetBuffer('daytimeSunUnderSince', (string)time());
                $this->SendDebug('SolarCharger', sprintf('Daytime mode waiting for sun (production %.1f W < %.1f W).', $productionW, $minSunPower), 0);
            } else {
                $this->SendDebug('SolarCharger', sprintf('Daytime mode inactive (production %.1f W < %.1f W).', $productionW, $minSunPower), 0);
            }
        }
    }

    private function evaluateDaytimeMode(float $productionW): bool
    {
        $minSunPower = (float)$this->ReadPropertyInteger('minSunPower');
        $delaySeconds = $this->getDaytimeSunDelaySeconds();
        $now = time();

        $state = $this->GetBuffer('daytimeState');
        if ($state === '') {
            $state = 'inactive';
        }
        $overSince = (int)$this->GetBuffer('daytimeSunOverSince');
        $underSince = (int)$this->GetBuffer('daytimeSunUnderSince');

        if ($productionW >= $minSunPower) {
            $this->SetBuffer('daytimeSunUnderSince', '0');

            if ($delaySeconds === 0) {
                if ($state !== 'active') {
                    $this->SendDebug('SolarCharger', sprintf('Daytime sun sufficient (%.1f W ≥ %.1f W).', $productionW, $minSunPower), 0);
                }
                $this->SetBuffer('daytimeSunOverSince', '0');
                $this->SetBuffer('daytimeState', 'active');
                return true;
            }

            if ($state === 'active') {
                return true;
            }

            if ($overSince === 0) {
                $this->SetBuffer('daytimeSunOverSince', (string)$now);
                if ($state !== 'waitingOn') {
                    $this->SetBuffer('daytimeState', 'waitingOn');
                    $this->SendDebug('SolarCharger', sprintf('Daytime activation pending (%.1f W ≥ %.1f W).', $productionW, $minSunPower), 0);
                }
                return false;
            }

            if (($now - $overSince) >= $delaySeconds) {
                $this->SetBuffer('daytimeSunOverSince', '0');
                $this->SetBuffer('daytimeState', 'active');
                $this->SendDebug('SolarCharger', sprintf('Daytime activation after %.0f s of sufficient sun.', (float)($now - $overSince)), 0);
                return true;
            }

            return false;
        }

        $this->SetBuffer('daytimeSunOverSince', '0');

        if ($delaySeconds === 0) {
            if ($state !== 'inactive') {
                $this->SendDebug('SolarCharger', sprintf('Daytime sun insufficient (%.1f W < %.1f W).', $productionW, $minSunPower), 0);
            }
            $this->SetBuffer('daytimeState', 'inactive');
            return false;
        }

        if ($state === 'active' || $state === 'waitingOff') {
            if ($underSince === 0) {
                $this->SetBuffer('daytimeSunUnderSince', (string)$now);
                if ($state !== 'waitingOff') {
                    $this->SetBuffer('daytimeState', 'waitingOff');
                    $this->SendDebug('SolarCharger', sprintf('Daytime deactivation pending (%.1f W < %.1f W).', $productionW, $minSunPower), 0);
                }
                return true;
            }

            if (($now - $underSince) >= $delaySeconds) {
                $this->SetBuffer('daytimeSunUnderSince', '0');
                $this->SetBuffer('daytimeState', 'inactive');
                $this->SendDebug('SolarCharger', sprintf('Daytime stop after %.0f s of insufficient sun.', (float)($now - $underSince)), 0);
                return false;
            }

            $this->SetBuffer('daytimeState', 'waitingOff');
            return true;
        }

        if ($underSince === 0) {
            $this->SetBuffer('daytimeSunUnderSince', (string)$now);
        }
        if ($state !== 'inactive') {
            $this->SetBuffer('daytimeState', 'inactive');
        }
        return false;
    }

    // Parent/Child-Kopplung entfällt: Vorhaltefunktion liefert immer false
    private function hasChargerAdapter(): bool { return false; }

    private function sendChargerCommand(array $data): void
    {
        // Einziger Modus: Steuerung ausschließlich über Variablen auf der Ziel-Instanz
        $this->controlChargerViaVariables($data);
    }

    private function triggerChargerRefresh(): void
    {
        $now = time();
        $allowedAt = (int)$this->GetBuffer('chargerRefreshAt');
        if ($allowedAt !== 0 && $now < $allowedAt) {
            return;
        }

        $this->SetBuffer('chargerRefreshAt', (string)($now + 5));
        $this->sendChargerCommand([
            'command'      => 'refresh',
            'requested_at' => $now,
        ]);
        $this->SendDebug('SolarCharger', 'Requested charger telemetry refresh via variables.', 0);
    }

    private function controlChargerViaVariables(array $command): bool
    {
        $targetId = $this->getTargetChargerInstanceId();
        if ($targetId <= 0) {
            // No viable target found – make it visible why nothing will be written
            $this->SendDebug('SolarCharger', 'controlChargerViaVariables: no target charger available (configure targetCharger or ensure exactly one Warp2 instance exists).', 0);
            return false;
        }
        $this->SendDebug('SolarCharger', sprintf('controlChargerViaVariables: using targetCharger instance #%d', $targetId), 0);
        if (!IPS_InstanceExists($targetId)) {
            $this->SendDebug('SolarCharger', sprintf('controlChargerViaVariables: targetCharger instance #%d does not exist', $targetId), 0);
            return false;
        }

        $cmd = strtolower((string)($command['command'] ?? ''));
        $this->SendDebug('SolarCharger', sprintf('controlChargerViaVariables: cmd=%s, targetCharger=%d', $cmd, $targetId), 0);
        switch ($cmd) {
            case 'set_current':
                $current = isset($command['current_ma']) && is_numeric($command['current_ma']) ? (int)$command['current_ma'] : null;
                if ($current === null) {
                    $this->SendDebug('SolarCharger', 'controlChargerViaVariables: set_current without numeric current_ma', 0);
                    return false;
                }
                $written = $this->setInstanceInteger($targetId, 'mxccmd_target_current', $current);
                if ($written) {
                    $this->SendDebug('SolarCharger', sprintf('controlChargerViaVariables: wrote mxccmd_target_current=%d to instance #%d', $current, $targetId), 0);
                } else {
                    $this->SendDebug('SolarCharger', sprintf('controlChargerViaVariables: mxccmd_target_current not found on instance #%d, trying legacy target_current', $targetId), 0);
                    $legacyWritten = $this->setInstanceInteger($targetId, 'target_current', $current);
                    if ($legacyWritten) {
                        $this->SendDebug('SolarCharger', sprintf('controlChargerViaVariables: wrote target_current=%d to instance #%d', $current, $targetId), 0);
                    } else {
                        $this->SendDebug('SolarCharger', sprintf('controlChargerViaVariables: target_current also not found on instance #%d', $targetId), 0);
                    }
                }
                // Sofort anwenden, um "Warte auf Freigabe"/Blockade zu vermeiden
                $this->triggerInstanceAction($targetId, 'mxccmd_apply_now');
                return true;
            case 'refresh':
                $this->triggerInstanceAction($targetId, 'mxccmd_refresh');
                return true;
            case 'reboot':
                $this->triggerInstanceAction($targetId, 'mxccmd_reboot');
                return true;
            default:
                return false;
        }
    }

    // Resolve a usable target charger instance:
    // 1) If the property 'targetCharger' is set (>0) and the instance exists, use it
    // 2) Otherwise, auto-detect: if exactly one Warp2Gateway instance exists, use that
    // 3) Else, return 0 (no target)
    private function getTargetChargerInstanceId(): int
    {
        $configured = (int)$this->ReadPropertyInteger('targetCharger');
        if ($configured > 0 && IPS_InstanceExists($configured)) {
            return $configured;
        }

        // Auto-detect Warp2Gateway instance by ModuleID
        $warp2ModuleId = '{A3B1C2D3-4E5F-6789-ABCD-112233445566}';
        try {
            $instances = @IPS_GetInstanceListByModuleID($warp2ModuleId);
            if (is_array($instances) && count($instances) === 1) {
                return (int)$instances[0];
            }
            if (is_array($instances)) {
                $this->SendDebug('SolarCharger', sprintf('Auto-detect Warp2 instances: found %d; unable to select uniquely.', count($instances)), 0);
            }
        } catch (Throwable $t) {
            $this->SendDebug('SolarCharger', 'Auto-detect Warp2 instance failed: ' . $t->getMessage(), 0);
        }

        return 0;
    }

    private function setInstanceInteger(int $instanceId, string $ident, int $value): bool
    {
        $varId = @IPS_GetObjectIDByIdent($ident, $instanceId);
        if (!is_int($varId)) {
            $this->SendDebug('SolarCharger', sprintf('setInstanceInteger: ident %s not found on instance #%d', $ident, $instanceId), 0);
            return false;
        }

        // Prefer RequestAction if the variable has an action handler; else fall back to direct SetValue
        try {
            $obj = @IPS_GetObject($varId);
            $hasAction = is_array($obj) && isset($obj['ObjectType']) && ($obj['ObjectType'] === 2) && isset($obj['ObjectActionID']) && ((int)$obj['ObjectActionID'] > 0);
        } catch (Throwable $t) {
            $hasAction = false;
        }

        if ($hasAction) {
            try {
                @IPS_RequestAction($instanceId, $ident, $value);
                return true;
            } catch (Throwable $t) {
                $this->SendDebug('SolarCharger', sprintf('setInstanceInteger: RequestAction failed for %s on #%d: %s', $ident, $instanceId, $t->getMessage()), 0);
                // fall through to SetValue below
            }
        }

        try {
            @SetValueInteger($varId, $value);
            return true;
        } catch (Throwable $t) {
            $this->SendDebug('SolarCharger', sprintf('setInstanceInteger: SetValue failed for %s on #%d: %s', $ident, $instanceId, $t->getMessage()), 0);
            return false;
        }
    }

    private function triggerInstanceAction(int $instanceId, string $ident): void
    {
        try {
            @IPS_RequestAction($instanceId, $ident, true);
        } catch (Throwable $t) {
            $this->SendDebug('SolarCharger', sprintf('Trigger action failed for %s on #%d: %s', $ident, $instanceId, $t->getMessage()), 0);
        }
    }

    // Telemetry via GUID interface removed – rely on variables on the target Warp2Gateway
    // updateChargerTelemetry/extractHardwareMaxCurrent/getChargerTelemetry/isTelemetryFresh/ForwardData removed

    protected function resolveActualChargerPower(int $chargerCurrent, float $estimatedPower): float
    {
        if ($chargerCurrent <= 0) {
            $this->updateChargerPowerSource('idle');
            return 0.0;
        }

        // Prefer real power from target Warp2 instance variable 'charger_power'
        $targetId = $this->getTargetChargerInstanceId();
        if ($targetId > 0 && IPS_InstanceExists($targetId)) {
            $varId = @IPS_GetObjectIDByIdent('charger_power', $targetId);
            if (is_int($varId)) {
                try {
                    $value = @GetValueFloat($varId);
                    if (is_numeric($value) && $value > 0) {
                        $this->updateChargerPowerSource('warp2');
                        return (float)$value;
                    }
                } catch (Throwable $t) {
                    // ignore and fall back
                }
            }
        }

        $this->updateChargerPowerSource('estimate');
        return $estimatedPower;
    }

    private function resolveEffectiveMaxCurrent(): int
    {
        $configuredMax = max(0, (int)$this->ReadPropertyInteger('maxChargerCurrent'));
        // Variables-only: fetch hardware max via target Warp2 instance if available
        $hardwareMax = 0;
        $targetId = $this->getTargetChargerInstanceId();
        if ($targetId > 0 && IPS_InstanceExists($targetId)) {
            $varId = @IPS_GetObjectIDByIdent('hardware_max_current', $targetId);
            if (is_int($varId)) {
                try { $hardwareMax = max(0, (int)@GetValueInteger($varId)); } catch (Throwable $t) { $hardwareMax = 0; }
            }
        }

        if ($hardwareMax > 0 && $configuredMax > 0) {
            $effective = min($configuredMax, $hardwareMax);
        } elseif ($hardwareMax > 0) {
            $effective = $hardwareMax;
        } else {
            $effective = $configuredMax;
        }

        $bufferKey = 'effectiveMaxCurrent';
        $previous = @$this->GetBuffer($bufferKey);
        if ((string)$effective !== (string)$previous) {
            $this->SetBuffer($bufferKey, (string)$effective);
            if ($hardwareMax > 0 && $configuredMax > 0 && $hardwareMax < $configuredMax) {
                $this->LogMessage(
                    sprintf('Charger hardware limit (%d mA) caps configured maximum (%d mA).', $hardwareMax, $configuredMax),
                    KL_NOTIFY
                );
            }
        }

        if ($effective > 0) {
            $varId = @$this->GetIDForIdent('maxChargerCurrent');
            if ($varId !== false) {
                $currentValue = (int)$this->GetValue('maxChargerCurrent');
                if ($currentValue > $effective) {
                    $this->SetValue('maxChargerCurrent', $effective);
                }
            }
        }

        return $effective;
    }

    private function updateChargerPowerSource(string $source): void
    {
        $bufferKey = 'chargerPowerSource';
        $previous = @$this->GetBuffer($bufferKey);
        if ($previous === $source) {
            return;
        }

        $this->SetBuffer($bufferKey, $source);

        switch ($source) {
            case 'idle':
                $message = 'Charger power reset to idle (no current requested).';
                break;
            case 'warp2':
                $message = 'Charger power now uses Warp2 wallbox variable.';
                break;
            default:
                $message = 'Charger power falls back to estimation from target current.';
                break;
        }

        $this->LogMessage($message, KL_NOTIFY);
    }

    protected function getProductionData($data) {
        // get production, consumption and import from data
        // Enphase API returns an array of arrays containing the values we need.
        $result['production']  = (float)$data['production'][1]['wNow'];
        $result['consumption'] = (float)$data['consumption'][0]['wNow'];
        $result['import']      = (float)$data['consumption'][1]['wNow'];
        return $result;
    }

    protected function getHouseConsumption($meterData, $productionData, $livedata = null) {
        // Prefer livedata/status as primary source for house load without battery
        $value = $this->extractHouseConsumptionFromLivedata($livedata);
        if ($value !== null) {
            $this->clearHouseConsumptionFallback();
            return $value;
        }
        // Fallback to meters/readings
        $value = $this->extractHouseConsumptionFromMeters($meterData);
        if ($value !== null) {
            $this->clearHouseConsumptionFallback();
            return $value;
        }
        if (isset($productionData['consumption']) && is_numeric($productionData['consumption'])) {
            $value = (float)$productionData['consumption'];
            $storageFlow = $this->getBatteryFlow($meterData, $livedata);
            if ($storageFlow !== null && $storageFlow < 0) {
                $value += $storageFlow; // storageFlow negative while charging -> remove battery load
            }
            $this->logHouseConsumptionFallback($storageFlow !== null ? 'production+storage' : 'production');
            return $value;
        }
        return null;
    }

    protected function extractHouseConsumptionFromMeters($meterData) {
        if (!is_array($meterData)) {
            return null;
        }

        $collections = [];
        if (isset($meterData['meters']) && is_array($meterData['meters'])) {
            $collections[] = $meterData['meters'];
        } else {
            $collections[] = $meterData;
        }
        foreach (['consumption', 'totalConsumption', 'total_consumption', 'load', 'siteConsumption', 'site_consumption', 'site-consumption'] as $key) {
            if (isset($meterData[$key]) && is_array($meterData[$key])) {
                $collections[] = $meterData[$key];
            }
        }

        foreach ($collections as $entries) {
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $entry) {
                $type = strtolower((string)($entry['measurementType'] ?? $entry['type'] ?? $entry['name'] ?? ''));
                if (!$this->isHouseConsumptionMeasurement($type)) {
                    continue;
                }
                $value = $this->extractPowerValue($entry);
                if ($value !== null) {
                    return $value;
                }
                if (isset($entry['channels']) && is_array($entry['channels'])) {
                    foreach ($entry['channels'] as $channel) {
                        $channelType = strtolower((string)($channel['measurementType'] ?? $channel['type'] ?? $channel['name'] ?? ''));
                        if (!$this->isHouseConsumptionMeasurement($channelType)) {
                            continue;
                        }
                        $value = $this->extractPowerValue($channel);
                        if ($value !== null) {
                            return $value;
                        }
                    }
                }
            }
        }

        $directType = strtolower((string)($meterData['measurementType'] ?? $meterData['type'] ?? $meterData['name'] ?? ''));
        if ($this->isHouseConsumptionMeasurement($directType)) {
            $value = $this->extractPowerValue($meterData);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    protected function extractHouseConsumptionFromLivedata($livedata) {
        if (!is_array($livedata)) {
            return null;
        }
        // livedata values are typically in mW; convert to W
        $paths = [
            ['meters', 'load', 'p_mw'],
            ['meters', 'consumption', 'p_mw'],
            ['total', 'load', 'p_mw'],
            ['total', 'site', 'p_mw'],
            ['site', 'load', 'p_mw'],
            ['site', 'p_mw'],
            ['consumption', 'p_mw'],
            ['load', 'p_mw'],
        ];
        foreach ($paths as $path) {
            $val = $this->findNumericPath($livedata, $path);
            if ($val !== null) {
                return $val / 1000.0;
            }
        }
        return null;
    }

    protected function getBatteryFlow($meterData, $livedata = null) {
        // Prefer livedata/status for battery flow
        $value = $this->extractBatteryFlowFromLivedata($livedata);
        if ($value !== null) {
            return $value;
        }
        $value = $this->extractBatteryFlowFromMeters($meterData);
        if ($value !== null) {
            return $value;
        }
        return null;
    }

    protected function extractBatteryFlowFromMeters($meterData) {
        if (!is_array($meterData)) {
            return null;
        }

        $collections = [];
        if (isset($meterData['meters']) && is_array($meterData['meters'])) {
            $collections[] = $meterData['meters'];
        } else {
            $collections[] = $meterData;
        }
        foreach (['storage', 'battery', 'batteryStorage', 'storageSummary', 'encharge'] as $key) {
            if (isset($meterData[$key]) && is_array($meterData[$key])) {
                $collections[] = $meterData[$key];
            }
        }

        foreach ($collections as $entries) {
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $entry) {
                $type = strtolower((string)($entry['measurementType'] ?? $entry['type'] ?? $entry['name'] ?? ''));
                if (!$this->isBatteryMeasurement($type)) {
                    continue;
                }
                $value = $this->extractPowerValue($entry);
                if ($value !== null) {
                    return $value;
                }
                if (isset($entry['channels']) && is_array($entry['channels'])) {
                    foreach ($entry['channels'] as $channel) {
                        $channelType = strtolower((string)($channel['measurementType'] ?? $channel['type'] ?? $channel['name'] ?? ''));
                        if (!$this->isBatteryMeasurement($channelType)) {
                            continue;
                        }
                        $value = $this->extractPowerValue($channel);
                        if ($value !== null) {
                            return $value;
                        }
                    }
                }
            }
        }

        $directType = strtolower((string)($meterData['measurementType'] ?? $meterData['type'] ?? $meterData['name'] ?? ''));
        if ($this->isBatteryMeasurement($directType)) {
            $value = $this->extractPowerValue($meterData);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    protected function extractBatteryFlowFromLivedata($livedata) {
        if (!is_array($livedata)) {
            return null;
        }
        // livedata values are typically in mW; convert to W
        $paths = [
            ['meters', 'storage', 'p_mw'],
            ['total', 'storage', 'p_mw'],
            ['site', 'storage', 'p_mw'],
            ['storage', 'p_mw'],
        ];
        foreach ($paths as $path) {
            $val = $this->findNumericPath($livedata, $path);
            if ($val !== null) {
                return $val / 1000.0;
            }
        }
        return null;
    }

    protected function logHouseConsumptionFallback($source) {
        $bufferKey = 'houseConsumptionFallback';
        $lastSource = $this->GetBuffer($bufferKey);
        if ($lastSource === $source) {
            return;
        }
        $this->SetBuffer($bufferKey, $source);
        $this->LogMessage("Using fallback source '{$source}' for house consumption.", KL_NOTIFY);
    }

    protected function clearHouseConsumptionFallback() {
        $bufferKey = 'houseConsumptionFallback';
        if ($this->GetBuffer($bufferKey) !== '') {
            $this->SetBuffer($bufferKey, '');
            $this->LogMessage('House consumption fallback no longer needed; using native measurement again.', KL_NOTIFY);
        }
    }

    protected function extractPowerValue($source) {
        $keys = ['activePower', 'averagePower', 'wNow', 'power'];
        foreach ($keys as $key) {
            if (isset($source[$key]) && is_numeric($source[$key])) {
                return (float)$source[$key];
            }
        }
        return null;
    }

    protected function isHouseConsumptionMeasurement(string $type): bool
    {
        if ($type === '') {
            return false;
        }
        if (str_contains($type, 'net-consumption') || str_contains($type, 'net_consumption')) {
            return false;
        }
        return str_contains($type, 'load') || str_contains($type, 'consumption');
    }

    protected function isBatteryMeasurement(string $type): bool
    {
        if ($type === '') {
            return false;
        }
        return str_contains($type, 'storage') || str_contains($type, 'battery') || str_contains($type, 'encharge');
    }

    private function findNumericPath(array $data, array $path) {
        $ref = $data;
        foreach ($path as $segment) {
            if (!is_array($ref) || !array_key_exists($segment, $ref)) {
                return null;
            }
            $ref = $ref[$segment];
        }
        return is_numeric($ref) ? (float)$ref : null;
    }

    protected function manageChargingWindow(int $chargerMode, float $availablePowerKw, float $importPowerKw, float $batteryFlowKw, float $batteryLevel): bool
    {
        if (!$this->ReadPropertyBoolean('enabled')) {
            $this->resetChargingWindow();
            return false;
        }

        if ($chargerMode === 4) {
            $this->resetChargingWindow();
            return false;
        }

        if (!in_array($chargerMode, [0, 1, 2], true)) {
            $this->resetChargingWindow();
            return true;
        }

        $startThreshold = 4.6; // kW
        $stopThreshold  = 4.2; // kW
        $startDelay     = 90;  // seconds
        $stopDelay      = 180; // seconds
        $minOnDuration  = 600; // seconds
        $importDeadband = 0.25; // kW
        $minSocForDischargeDuringMinOn = 40.0; // percent

        $now = time();
        $chargingActive = (@$this->GetBuffer('chargingActive')) === '1';
        $startCandidate = (int)@$this->GetBuffer('chargingStartCandidate');
        $stopCandidate  = (int)@$this->GetBuffer('chargingStopCandidate');
        $minOnUntil     = (int)@$this->GetBuffer('chargingMinOnUntil');
        $minOnStartSoc  = (float)@$this->GetBuffer('chargingMinOnStartSoc');
        $importPowerKw  = max(0.0, $importPowerKw);

        if (!$chargingActive) {
            if ($availablePowerKw >= $startThreshold) {
                if ($startCandidate === 0) {
                    $startCandidate = $now;
                    $this->SetBuffer('chargingStartCandidate', (string)$startCandidate);
                } elseif (($now - $startCandidate) >= $startDelay) {
                    $chargingActive = true;
                    $this->SetBuffer('chargingActive', '1');
                    $minOnUntil = $now + $minOnDuration;
                    $this->SetBuffer('chargingMinOnUntil', (string)$minOnUntil);
                    $this->SetBuffer('chargingMinOnStartSoc', (string)$batteryLevel);
                    $this->SetBuffer('chargingStartCandidate', '0');
                    $this->SetBuffer('chargingStopCandidate', '0');
                    $this->LogMessage(sprintf('Charging window opened (%.2f kW available).', $availablePowerKw), KL_NOTIFY);
                }
            } elseif ($startCandidate !== 0) {
                $this->SetBuffer('chargingStartCandidate', '0');
            }
            return $chargingActive;
        }

        // charging is active
        if ($startCandidate !== 0) {
            $this->SetBuffer('chargingStartCandidate', '0');
        }
        if ($minOnUntil !== 0 && $now < $minOnUntil) {
            if ($importPowerKw > $importDeadband) {
                $this->resetChargingWindow();
                $this->LogMessage('Charging stopped during min-on because grid import exceeded deadband.', KL_NOTIFY);
                return false;
            }
            if ($chargerMode === 0 && $batteryFlowKw < -$importDeadband) {
                $allowedToDischarge = $minOnStartSoc > $minSocForDischargeDuringMinOn;
                if (!$allowedToDischarge) {
                    $this->resetChargingWindow();
                    $this->LogMessage(sprintf('Charging stopped during min-on because battery (start %.1f%%, now %.1f%%) started discharging.', $minOnStartSoc, $batteryLevel), KL_NOTIFY);
                    return false;
                }
            }
            if ($stopCandidate !== 0) {
                $this->SetBuffer('chargingStopCandidate', '0');
            }
            return true;
        }

        $shouldStop = ($availablePowerKw <= $stopThreshold) || ($importPowerKw > $importDeadband) || ($chargerMode === 0 && $batteryFlowKw < -$importDeadband);
        if ($shouldStop) {
            if ($stopCandidate === 0) {
                $stopCandidate = $now;
                $this->SetBuffer('chargingStopCandidate', (string)$stopCandidate);
            } elseif (($now - $stopCandidate) >= $stopDelay) {
                $this->resetChargingWindow();
                $this->LogMessage(sprintf('Charging window closed (available %.2f kW, import %.2f kW).', $availablePowerKw, $importPowerKw), KL_NOTIFY);
                return false;
            }
        } elseif ($stopCandidate !== 0) {
            $this->SetBuffer('chargingStopCandidate', '0');
        }

        return true;
    }

    protected function resetChargingWindow(bool $fullReset = true): void
    {
        $this->SetBuffer('chargingStartCandidate', '0');
        $this->SetBuffer('chargingStopCandidate', '0');
        $this->SetBuffer('chargingMinOnUntil', '0');
        $this->SetBuffer('chargingMinOnStartSoc', '0');
        if ($fullReset) {
            $this->SetBuffer('chargingActive', '0');
        }
    }

    private function scaleToKw($value) {
        if (!is_numeric($value)) {
            return null;
        }
        $scale = 1000.0;
        $epsilon = 0.1;
        // scale value to kW, round to 1 decimal place
        $result = round(((float)$value) / $scale, 1);
        return abs($result) < $epsilon ? 0.0 : $result;
    }

    public function SetVariables($data)
    {
        // write back all values to variables, scale if necessary
        $values = [];
        $values['batteryLevel']         = (float)$data['battery']['level'];
        $values['batteryStatus']        = (string)$data['battery']['status'];
        if (isset($data['battery']['powerKw'])) {
            $values['batteryPower']     = (float)$data['battery']['powerKw'];
        }
        $values['import']               = $this->scaleToKw($data['production']['import']);
        $values['production']           = $this->scaleToKw($data['production']['production']);
        $values['consumption']          = $this->scaleToKw($data['production']['consumption']);
        $values['houseConsumption']     = $this->scaleToKw($data['house']['consumption']);
        $values['chargerPower']         = $this->scaleToKw($data['charger']['chargerPower']);
        if (isset($data['charger']['availablePower'])) {
            $values['availablePower']  = (float)$data['charger']['availablePower'];
        }

        // Ensure integer current (mA) for variable
        $values['chargerCurrent']       = (int)$data['charger']['chargerCurrent'];

        foreach ($values as $key => $value) {
            if ($value === null) {
                continue;
            }
            $this->SetValue($key, $value);
        }
        return $values;
    }

    public function ReceiveData($json)
    {
        // receive data from EnphaseGateway and calculate the charger current
        $data = json_decode($json, true);
        // Only accept normalized energy payload from gateway; ignore frames without it
        if (!isset($data['energy']) || !is_array($data['energy']) || ($data['energy']['protocol'] ?? '') !== 'com.maxence.energy.v1') {
            $this->LogMessage('ReceiveData: Missing normalized energy payload (com.maxence.energy.v1); frame ignored.', KL_NOTIFY);
            return;
        }

        $e = $data['energy']['data'] ?? [];
        $productionData = [
            'production'  => isset($e['pv']['pv_power_w']) ? (float)$e['pv']['pv_power_w'] : 0.0,
            'consumption' => isset($e['site']['total_consumption_w']) ? (float)$e['site']['total_consumption_w'] : 0.0,
            'import'      => isset($e['site']['grid_power_w']) ? (float)$e['site']['grid_power_w'] : 0.0,
        ];
        $houseConsumption = isset($e['site']['house_power_w']) ? (float)$e['site']['house_power_w'] : null;

        $batteryData   = $this->getBatteryDataFromEnergy($e['battery'] ?? []);
        $batteryFlowW  = (isset($e['battery']['battery_power_w']) && is_numeric($e['battery']['battery_power_w']))
            ? (float)$e['battery']['battery_power_w'] : null;

        $chargerMode   = (int)$this->GetValue('chargerMode');
        $chargerData   = $this->getChargerData((float)$productionData['production'], (float)$batteryData['level']);

        $availablePowerKw = isset($chargerData['availablePower']) ? (float)$chargerData['availablePower'] : 0.0;
        $importPowerKw    = isset($productionData['import']) ? ((float)$productionData['import'] / 1000.0) : 0.0;
        $batteryFlowKw    = is_numeric($batteryFlowW) ? ((float)$batteryFlowW / 1000.0) : 0.0;
        $batteryLevel     = isset($batteryData['level']) ? (float)$batteryData['level'] : 0.0;

        if ($chargerMode === 2) {
            $allowCharging = $this->evaluateDaytimeMode((float)$productionData['production']);
        } else {
            if ($this->GetBuffer('daytimeState') !== 'inactive') {
                $this->resetDaytimeSunTracking();
            }
            $allowCharging = $this->manageChargingWindow(
                $chargerMode,
                $availablePowerKw,
                $importPowerKw,
                $batteryFlowKw,
                $batteryLevel
            );
        }

        $finalCurrent = $allowCharging ? (int)$chargerData['chargerCurrent'] : 0;
        $estimatedPower = (float)$chargerData['chargerPower'];
        if ($allowCharging) {
            $finalPower = $this->resolveActualChargerPower($finalCurrent, $estimatedPower);
        } else {
            $finalPower = 0.0;
            $this->updateChargerPowerSource('idle');
        }

        $chargerData['chargerCurrent'] = $finalCurrent;
        $chargerData['chargerPower']   = $finalPower;

        $totalConsumptionW = is_numeric($productionData['consumption']) ? (float)$productionData['consumption'] : null;
        $rawHouseConsumptionW = is_numeric($houseConsumption) ? (float)$houseConsumption : null;
        $chargerPowerW = max(0.0, $finalPower);
        $chargerThresholdW = 100.0; // ignore subtraction for tiny residuals

        $netHouseConsumptionW = $rawHouseConsumptionW;
        if ($totalConsumptionW !== null) {
            if ($chargerPowerW >= $chargerThresholdW) {
                $candidate = $totalConsumptionW - $chargerPowerW;
                if ($candidate < -$chargerThresholdW) {
                    $rawMinusCharger = ($rawHouseConsumptionW !== null) ? ($rawHouseConsumptionW - $chargerPowerW) : null;
                    if ($rawMinusCharger !== null && $rawMinusCharger >= -$chargerThresholdW) {
                        $netHouseConsumptionW = max(0.0, $rawMinusCharger);
                        $this->SendDebug('SolarCharger', sprintf('Net house derived from raw minus charger (total %.1f W, charger %.1f W, raw %.1f W).', $totalConsumptionW, $chargerPowerW, $rawHouseConsumptionW), 0);
                    } elseif ($rawHouseConsumptionW !== null) {
                        $netHouseConsumptionW = max(0.0, $rawHouseConsumptionW);
                        $this->SendDebug('SolarCharger', sprintf('Net house fallback to raw (total %.1f W, charger %.1f W, raw %.1f W).', $totalConsumptionW, $chargerPowerW, $rawHouseConsumptionW), 0);
                    } else {
                        $netHouseConsumptionW = 0.0;
                        $this->SendDebug('SolarCharger', sprintf('Net house fallback to zero (total %.1f W, charger %.1f W).', $totalConsumptionW, $chargerPowerW), 0);
                    }
                } else {
                    $netHouseConsumptionW = max(0.0, $candidate);
                }
            } elseif ($rawHouseConsumptionW === null) {
                $netHouseConsumptionW = $totalConsumptionW;
            } else {
                $netHouseConsumptionW = $rawHouseConsumptionW;
            }
        }

        $dataOut = [
            'battery'    => $batteryData + ['powerKw' => round($batteryFlowKw, 1)],
            'production' => $productionData,
            'charger'    => $chargerData,
            'house'      => [
                'consumption' => $netHouseConsumptionW,
                'totalConsumption' => $totalConsumptionW,
                'rawConsumption' => $rawHouseConsumptionW,
            ],
        ];

        $this->SetChargerCurrent($finalCurrent);

        // write all values to variables
        $this->SetVariables($dataOut);
    }

    public function SetChargerCurrent(int $current, bool $force = false): int
    {
        $enabled = $this->ReadPropertyBoolean('enabled');
        $chargerMode = (int)$this->GetValue('chargerMode');

        if (!$enabled || $chargerMode === 4) {
            $current = 0;
        }

        $effectiveMax = max(0, $this->resolveEffectiveMaxCurrent());
        if ($effectiveMax > 0) {
            $current = min($current, $effectiveMax);
        }
        $current = max(0, $current);

        $previousRaw = $this->GetBuffer('lastSetCurrent');
        $previous = is_numeric($previousRaw) ? (int)$previousRaw : -1;
        if ($previous !== $current) {
            $this->SetBuffer('lastSetCurrent', (string)$current);
        }

        // Kein Abbruch mehr bei fehlender Kopplung – variablenbasierte Steuerung übernimmt

        if ($previous === $current && !$force) {
            return $current;
        }

        $payload = [
            'command'    => 'set_current',
            'current_ma' => $current,
        ];
        if ($force) {
            $payload['force'] = true;
        }
        if ($effectiveMax > 0) {
            $payload['max_current_ma'] = $effectiveMax;
        }
        $hardwareMin = max(0, (int)$this->ReadPropertyInteger('minChargerCurrent'));
        if ($hardwareMin > 0) {
            $payload['min_current_ma'] = $hardwareMin;
        }
        $this->sendChargerCommand($payload);
        return $current;
    }

    private function connectToInterfaceParent(string $interfaceGuid): void
    {
        $moduleIds = IPS_GetModuleList();
        foreach ($moduleIds as $moduleId) {
            $module = IPS_GetModule($moduleId);
            $implemented = $module['Implemented'] ?? [];
            if (!in_array($interfaceGuid, $implemented, true)) {
                continue;
            }
            $instances = IPS_GetInstanceListByModuleID($moduleId);
            foreach ($instances as $instanceId) {
                if (!IPS_InstanceExists($instanceId)) {
                    continue;
                }
                if (@IPS_ConnectInstance($this->InstanceID, $instanceId)) {
                    return;
                }
            }
        }
        $this->LogMessage('ApplyChanges: no parent instance implementing neutral energy interface found.', KL_WARNING);
    }

    // Adapter coupling fully removed
}
