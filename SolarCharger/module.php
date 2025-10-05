<?php
declare(strict_types=1);

// Use local libs helper once added under maxence/libs
require_once __DIR__ . '/../libs/ModuleRegistration.php';

class SolarCharger extends IPSModule
{
    protected $api = null; // kept for backward compatibility; not used for Warp2 anymore
    protected $chargerModuleId = '{19D1EA3A-54AA-8EB2-A6B1-54530EF8A0F7}';

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
            $this->SetBuffer('warpApiErrorCount', 0);
            $this->SetBuffer('chargingActive', '0');
            $this->SetBuffer('chargingStartCandidate', '0');
            $this->SetBuffer('chargingStopCandidate', '0');
            $this->SetBuffer('chargingMinOnUntil', '0');
            $this->SetBuffer('chargingMinOnStartSoc', '0');
            $this->SetBuffer('houseConsumptionFallback', '');
            $this->SetBuffer('lastChargerState', '-1');
            $this->SetBuffer('chargerPowerSource', '');
            $this->SetBuffer('warpHardwareMaxCurrent', '0');
            $this->SetBuffer('effectiveMaxCurrent', '0');
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

        $this->LogMessage("RequestAction: {$ident} = {$value}", KL_NOTIFY);
        $this->LogMessage("1 -> chargerMode: {$chargerMode}, maxChargerCurrent: {$maxChargerCurrent}", KL_NOTIFY);

        if ($chargerMode === 4) {
            if ($ident === 'chargerMode' && $maxChargerCurrent > 0) {
                $this->SetBuffer($maxCurrentBufferKey, (string)$maxChargerCurrent);
            }

            $this->SetValue('maxChargerCurrent', 0);

            if ($ident === 'chargerSetCurrent') {
                $target = (int)$value;
                if ($effectiveMax > 0) {
                    $target = min($target, $effectiveMax);
                }
                $target = max(0, $target);
                if ($target !== (int)$value) {
                    $this->SetValue('chargerSetCurrent', $target);
                }
            } elseif ($ident === 'chargerMode') {
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

        // Passthrough actions to Warp2Gateway
        $gatewayId = (int)$this->ReadPropertyInteger('warp2Gateway');
        if ($gatewayId > 0) {
            try {
                switch ($ident) {
                    case 'chargerSetCurrent':
                        $target = (int)$value;
                        if ($effectiveMax > 0) {
                            $target = min($target, $effectiveMax);
                        }
                        $target = max(0, $target);
                        if ($target !== (int)$value) {
                            $this->SetValue('chargerSetCurrent', $target);
                        }
                        @IPS_RequestAction($gatewayId, 'target_current', $target);
                        break;
                    case 'chargerUpdate':
                        if ((bool)$value) { @IPS_RequestAction($gatewayId, 'update_now', true); }
                        $this->SetValue('chargerUpdate', false);
                        break;
                    case 'chargerReboot':
                        if ((bool)$value) { @IPS_RequestAction($gatewayId, 'reboot', true); }
                        $this->SetValue('chargerReboot', false);
                        break;
                }
            } catch (Exception $e) {
                $this->LogMessage('Warp2 passthrough action failed: ' . $e->getMessage(), KL_ERROR);
            }
        }
    }

    protected function getChargerData(float $production, float $batteryLevel): array {
        // Read mode and constraints (as ints where appropriate)
        $chargerMode = (int)$this->GetValue('chargerMode');
        $useBattery = $chargerMode !== 0;
        $daytimeOnly = $chargerMode < 3;

        $effectiveMax = max(0, $this->resolveEffectiveMaxCurrent());
        if ($effectiveMax <= 0) {
            $effectiveMax = (int)$this->ReadPropertyInteger('maxChargerCurrent');
        }

        // Variable limits from variables (ints)
        $maxChargerCurrent = (int)$this->GetValue('maxChargerCurrent');
        if ($effectiveMax > 0 && $maxChargerCurrent > $effectiveMax) {
            $maxChargerCurrent = $effectiveMax;
        }
        $minChargerCurrent = ($chargerMode === 3) ? $maxChargerCurrent : 0;

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
        if ($daytimeOnly && $production < $minSunPower) {
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

    protected function resolveActualChargerPower(int $chargerCurrent, float $estimatedPower): float
    {
        if ($chargerCurrent <= 0) {
            $this->updateChargerPowerSource('idle');
            return 0.0;
        }

        $gatewayId = (int)$this->ReadPropertyInteger('warp2Gateway');
        if ($gatewayId <= 0) {
            $this->updateChargerPowerSource('estimate');
            return $estimatedPower;
        }

        if (!function_exists('WARP2_GetMeterState') || !function_exists('WARP2_GetMeterValues')) {
            $this->updateChargerPowerSource('estimate');
            return $estimatedPower;
        }

        try {
            $state = @WARP2_GetMeterState($gatewayId);
            if (!is_array($state) || (int)($state['state'] ?? 0) === 0) {
                $this->updateChargerPowerSource('estimate');
                return $estimatedPower;
            }

            $values = @WARP2_GetMeterValues($gatewayId);
            if (is_array($values) && isset($values['power']) && is_numeric($values['power'])) {
                $power = (float)$values['power'];
                if ($power < 0) {
                    $power = 0.0;
                }
                $this->updateChargerPowerSource('meter');
                return $power;
            }
        } catch (Throwable $t) {
            $this->SendDebug('SolarCharger', 'Meter query failed: ' . $t->getMessage(), 0);
        }

        $this->updateChargerPowerSource('estimate');
        return $estimatedPower;
    }

    protected function getWarpChargerState(): ?int
    {
        $gatewayId = (int)$this->ReadPropertyInteger('warp2Gateway');
        if ($gatewayId <= 0) {
            return null;
        }

        $varId = @IPS_GetObjectIDByIdent('charger_state', $gatewayId);
        if ($varId === false || !IPS_VariableExists($varId)) {
            return null;
        }

        $value = @GetValue($varId);
        return is_numeric($value) ? (int)$value : null;
    }

    private function getWarpHardwareMaxCurrent(): int
    {
        $bufferKey = 'warpHardwareMaxCurrent';
        $cachedRaw = @$this->GetBuffer($bufferKey);
        $cached = is_numeric($cachedRaw) ? (int)$cachedRaw : 0;

        $gatewayId = (int)$this->ReadPropertyInteger('warp2Gateway');
        if ($gatewayId <= 0 || !function_exists('WARP2_GetHardwareMaxCurrent')) {
            return $cached;
        }

        try {
            $value = @WARP2_GetHardwareMaxCurrent($gatewayId);
            if (is_numeric($value)) {
                $value = max(0, (int)$value);
                if ((string)$value !== (string)$cachedRaw) {
                    $this->SetBuffer($bufferKey, (string)$value);
                    $this->SendDebug('SolarCharger', 'Warp hardware max current updated: ' . $value . ' mA', 0);
                }
                return $value;
            }
        } catch (Throwable $t) {
            $this->SendDebug('SolarCharger', 'Warp hardware max query failed: ' . $t->getMessage(), 0);
        }

        return $cached;
    }

    private function resolveEffectiveMaxCurrent(): int
    {
        $configuredMax = max(0, (int)$this->ReadPropertyInteger('maxChargerCurrent'));
        $hardwareMax = max(0, $this->getWarpHardwareMaxCurrent());

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
                $this->LogMessage(sprintf('Warp hardware limit (%d mA) caps configured maximum (%d mA).', $hardwareMax, $configuredMax), KL_NOTIFY);
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
            case 'meter':
                $message = 'Charger power now uses Warp meter readings.';
                break;
            case 'idle':
                $message = 'Charger power reset to idle (no current requested).';
                break;
            case 'waiting':
                $message = 'Charger power held at zero (charger not actively charging).';
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

        $allowCharging = $this->manageChargingWindow(
            $chargerMode,
            $availablePowerKw,
            $importPowerKw,
            $batteryFlowKw,
            $batteryLevel
        );

        $finalCurrent = $allowCharging ? (int)$chargerData['chargerCurrent'] : 0;
        $estimatedPower = (float)$chargerData['chargerPower'];
        if ($allowCharging) {
            $finalPower = $this->resolveActualChargerPower($finalCurrent, $estimatedPower);
        } else {
            $finalPower = 0.0;
            $this->updateChargerPowerSource('idle');
        }

        $chargerState = $this->getWarpChargerState();
        if ($chargerState !== null && $chargerState !== 3) {
            $finalPower = 0.0;
            if ($finalCurrent > 0) {
                $this->updateChargerPowerSource('waiting');
            } else {
                $this->updateChargerPowerSource('idle');
            }
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
                if ($candidate < -$chargerThresholdW && $rawHouseConsumptionW !== null) {
                    $netHouseConsumptionW = max(0.0, $rawHouseConsumptionW);
                    $this->SendDebug('SolarCharger', sprintf('Net house fallback to raw (total %.1f W, charger %.1f W, raw %.1f W).', $totalConsumptionW, $chargerPowerW, $rawHouseConsumptionW), 0);
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

        $previous = (int)$this->GetValue('chargerSetCurrent');
        if ($previous !== $current) {
            $this->SetValue('chargerSetCurrent', $current);
        }

        $gatewayId = (int)$this->ReadPropertyInteger('warp2Gateway');
        if ($gatewayId <= 0) {
            $this->LogMessage('SetChargerCurrent: No Warp2Gateway configured.', KL_WARNING);
            return $current;
        }

        if ($previous === $current && !$force) {
            return $current;
        }

        try {
            @IPS_RequestAction($gatewayId, 'target_current', $current);
            return $current;
        } catch (Exception $e) {
            $this->LogMessage('SetChargerCurrent failed: ' . $e->getMessage(), KL_ERROR);
            return $current;
        }
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
}
