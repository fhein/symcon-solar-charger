<?php
declare(strict_types=1);

// Use local libs helper once added under maxence/libs
require_once __DIR__ . '/../libs/ModuleRegistration.php';

class SmartChargingManager extends IPSModule
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
		} catch (Exception $e) {
			$this->LogMessage(__CLASS__ . ': Error creating instance: ' . $e->getMessage(), KL_ERROR);
		}
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
				// Fall back to interface-based connect to any parent exposing the neutral energy interface
				$this->ConnectParent('{E1E5D9C2-3A4B-4C5D-9E8F-ABCDEF123456}');
			}
		} catch (Exception $e) {
			$this->LogMessage('ApplyChanges: could not connect to energy gateway: ' . $e->getMessage(), KL_ERROR);
		}
		// Event-driven via ReceiveData
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
		$this->SetValue($ident, $value);

		$chargerMode = $this->GetValue('chargerMode');
		if ($chargerMode === 4) {
			$this->SetValue('minChargerCurrent', 0);
			$this->SetValue('maxChargerCurrent', 0);
			$this->LogMessage('Charger mode set to OFF. Skipping update.', KL_NOTIFY);
			return;
		}
		$minChargerCurrent = $this->GetValue('minChargerCurrent');
		$maxChargerCurrent = $this->GetValue('maxChargerCurrent');
		$this->LogMessage("RequestAction: " . $ident . " = " . $value, KL_NOTIFY);
		$this->LogMessage("1 -> chargerMode: " . $chargerMode . ", minChargerCurrent: " . $minChargerCurrent . ", maxChargerCurrent: " . $maxChargerCurrent, KL_NOTIFY);
		switch ($chargerMode) {
			case 0:
			case 1:
				$minChargerCurrent = 0;
			case 2:
				$daytimeOnly = true;
				break;
			case 3:
				$daytimeOnly = false;
				switch ($ident) {
					case 'minChargerCurrent':
						$minChargerCurrent = max(6000, $value);
						$maxChargerCurrent = $minChargerCurrent;
						break;
					case 'maxChargerCurrent':
						$minChargerCurrent = $maxChargerCurrent;
				}
				if ($minChargerCurrent < $maxChargerCurrent) {
					$minChargerCurrent = $maxChargerCurrent;
				}
				break;
		}
		if ($maxChargerCurrent < $minChargerCurrent) {
			$maxChargerCurrent = $minChargerCurrent;
		}
		$this->LogMessage("2 -> chargerMode: " . $chargerMode . ", minChargerCurrent: " . $minChargerCurrent . ", maxChargerCurrent: " . $maxChargerCurrent, KL_NOTIFY);
		$this->SetValue('minChargerCurrent', $minChargerCurrent);
		$this->SetValue('maxChargerCurrent', $maxChargerCurrent);

		// Passthrough actions to Warp2Gateway
		$gatewayId = (int)$this->ReadPropertyInteger('warp2Gateway');
		if ($gatewayId > 0) {
			try {
				switch ($ident) {
					case 'chargerSetCurrent':
						@IPS_RequestAction($gatewayId, 'target_current', (int)$value);
						break;
					case 'chargerUpdate':
						if ($value) { @IPS_RequestAction($gatewayId, 'update_now', true); }
						$this->SetValue('chargerUpdate', false);
						break;
					case 'chargerReboot':
						if ($value) { @IPS_RequestAction($gatewayId, 'reboot', true); }
						$this->SetValue('chargerReboot', false);
						break;
				}
			} catch (Exception $e) {
				$this->LogMessage('Warp2 passthrough action failed: ' . $e->getMessage(), KL_ERROR);
			}
		}
	}

    protected function getChargerData($production, $batteryLevel) {
		
		// get charger settings for current mode
		$chargerMode = $this->GetValue('chargerMode');
		$useBattery = $chargerMode != 0;
		$daytimeOnly = $chargerMode < 3;
		$minChargerCurrent = $this->GetValue('minChargerCurrent');
		$maxChargerCurrent = $this->GetValue('maxChargerCurrent');
		$t = $daytimeOnly ? 'ja' : 'nein';

		
		// we have to calculate the charger power from production and battery level
		// because consumption is erratic and not reliable and depends on the car's 
		// charger unit
		$rawPower = $production;
		
		// we add the discharge power if the battery has more than 20% charge to enable charging
		// even if the sun is not shining that much
		$batteryStatus = $this->GetValue('batteryStatus');
		$minBatteryLevel = $this->ReadPropertyInteger('minBatteryLevel');
		if ($useBattery && ($batteryLevel > $minBatteryLevel)) {
			$rawPower += $this->ReadPropertyInteger('maxDischargePower');
		}	
		// we subtract the standby baseload to be more accurate
		// it's the power that is consumed by the house when no particular
		// consumers are active (routers, refrigerators, etc.)
		$rawPower -= $this->ReadPropertyInteger('standbyBaseload');
		$availablePower = max(0, $rawPower);
		
		// calculate the charger current from the charger power
		$chargerCurrent = round($availablePower / 3 / 230 * 1000, 0);

		// for daytime modes, check if min sun power is reached
		if ($daytimeOnly && $production < $this->ReadPropertyInteger('minSunPower')) {
			$availablePower = 0;
				$minChargerCurrent = 0;
				$maxChargerCurrent = 0;
		}

		// check if charger current is within allowed range from charger settings
		$chargerCurrent = max($chargerCurrent, $minChargerCurrent);
		$chargerCurrent = min($chargerCurrent, $maxChargerCurrent);
		
		// check if charger current is within allowed range of charger capabilities
		$minAllowedCurrent = $this->ReadPropertyInteger('minChargerCurrent');
		if ($chargerCurrent < $minAllowedCurrent) {
			$chargerCurrent = 0;
		}
		$maxAllowedCurrent = $this->ReadPropertyInteger('maxChargerCurrent');
		if ($chargerCurrent > $maxAllowedCurrent) {
			$chargerCurrent = $maxAllowedCurrent;
		}
		// recalculate charger power from the final charger current (for display)
		$chargerPower = $chargerCurrent * 3 * 230 / 1000;
		
		return [
			'chargerCurrent' => $chargerCurrent,
			'chargerPower' => $chargerPower,
			'availablePower' => $availablePower / 1000,
		];
	}

	protected function getProductionData($data) {
		// get production, consumption and import from data
		// Enphase API returns an array of arrays containing
		// the values we need.
		$result['production'] = $data['production'][1]['wNow'];
		$result['consumption'] = $data['consumption'][0]['wNow'];
		$result['import'] = $data['consumption'][1]['wNow'];
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
		$stopThreshold = 4.2; // kW
		$startDelay = 90; // seconds
		$stopDelay = 180; // seconds
		$minOnDuration = 600; // seconds
		$importDeadband = 0.25; // kW
		$minSocForDischargeDuringMinOn = 40.0; // percent

		$now = time();
		$chargingActive = (@$this->GetBuffer('chargingActive')) === '1';
		$startCandidate = (int)@$this->GetBuffer('chargingStartCandidate');
		$stopCandidate = (int)@$this->GetBuffer('chargingStopCandidate');
		$minOnUntil = (int)@$this->GetBuffer('chargingMinOnUntil');
		$minOnStartSoc = (float)@$this->GetBuffer('chargingMinOnStartSoc');
		$importPowerKw = max(0.0, $importPowerKw);

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
		$scale = 1000;
		$epsilon = 0.1;
		// scale value to kW, round to 1 decimal place
		$result = round($value / $scale, 1);
		return abs($result) < $epsilon ? 0 : $result;
	}

	public function SetVariables($data)
	{
		// write back all values to variables, scale if necessary
		$values = [];
		$values['batteryLevel']			= $data['battery']['level'];
		$values['batteryStatus'] 		= $data['battery']['status'];
		if (isset($data['battery']['powerKw'])) {
			$values['batteryPower']		= (float)$data['battery']['powerKw'];
		}
		$values['import'] 			 	= $this->scaleToKw($data['production']['import']);
		$values['production']  			= $this->scaleToKw($data['production']['production']);
		$values['consumption']  	 	= $this->scaleToKw($data['production']['consumption']);
		$values['houseConsumption'] 	= $this->scaleToKw($data['house']['consumption']);
		$values['chargerPower'] 	 	= $this->scaleToKw($data['charger']['chargerPower']);
		$values['chargerCurrent'] 	 	= $data['charger']['chargerCurrent'];

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
			'production'   => isset($e['pv']['pv_power_w']) ? (float)$e['pv']['pv_power_w'] : 0.0,
			'consumption'  => isset($e['site']['total_consumption_w']) ? (float)$e['site']['total_consumption_w'] : 0.0,
			'import'       => isset($e['site']['grid_power_w']) ? (float)$e['site']['grid_power_w'] : 0.0,
		];
		$houseConsumption = isset($e['site']['house_power_w']) ? (float)$e['site']['house_power_w'] : null;
		$batteryData = $this->getBatteryDataFromEnergy($e['battery'] ?? []);
		$batteryFlowW = isset($e['battery']['battery_power_w']) && is_numeric($e['battery']['battery_power_w']) ? (float)$e['battery']['battery_power_w'] : null;
		$chargerMode = $this->GetValue('chargerMode');
		$chargerData = $this->getChargerData( $productionData['production'], $batteryData['level']);
		$availablePowerKw = isset($chargerData['availablePower']) ? (float)$chargerData['availablePower'] : 0.0;
		$importPowerKw = isset($productionData['import']) ? ((float)$productionData['import'] / 1000.0) : 0.0;
	$batteryFlowKw = is_numeric($batteryFlowW) ? ($batteryFlowW / 1000.0) : 0.0;
		$batteryLevel = isset($batteryData['level']) ? (float)$batteryData['level'] : 0.0;
		$allowCharging = $this->manageChargingWindow($chargerMode, $availablePowerKw, $importPowerKw, $batteryFlowKw, $batteryLevel);
		$data = [
			'battery' => $batteryData + ['powerKw' => round($batteryFlowKw, 1)],
			'production' => $productionData,
			'charger' => $chargerData,
			'house' => [
				'consumption' => $houseConsumption,
			],
		];
		// write charger current to charger module if mode allows charging
		if ($allowCharging) {
			$this->SetChargerCurrent($chargerData['chargerCurrent']);
		} else {
			$chargerData['chargerCurrent'] = 0;
			$chargerData['chargerPower'] = 0;
			$data['charger'] = $chargerData;
			$this->SetChargerCurrent(0);
		}

		// write all values to variables
		$this->SetVariables($data);
	}

	public function SetChargerCurrent(int $current): int
	{
		// return if module is disabled or OFF mode
		if (!$this->ReadPropertyBoolean('enabled')) {
			return 0;
		}
		$chargerMode = $this->GetValue('chargerMode');
		if ($chargerMode === 4) {
			return 0;
		}

		// Forward to Warp2Gateway if configured
		$gatewayId = (int)$this->ReadPropertyInteger('warp2Gateway');
		if ($gatewayId <= 0) {
			$this->LogMessage('SetChargerCurrent: No Warp2Gateway configured.', KL_WARNING);
			return 0;
		}
		try {
			@IPS_RequestAction($gatewayId, 'target_current', (int)$current);
			return $current;
		} catch (Exception $e) {
			$this->LogMessage('SetChargerCurrent failed: ' . $e->getMessage(), KL_ERROR);
			return 0;
		}
	}

	protected function GetWarpConfig()
	{
		// Prefer settings from selected Warp2Gateway instance if provided
		$gatewayId = (int)$this->ReadPropertyInteger('warp2Gateway');
		if ($gatewayId > 0) {
			try {
				$props = IPS_GetProperty($gatewayId, 'host'); // probe to ensure instance exists
				$host = IPS_GetProperty($gatewayId, 'host');
				$user = IPS_GetProperty($gatewayId, 'user');
				$pass = IPS_GetProperty($gatewayId, 'password');
				return [ 'host' => $host, 'user' => $user, 'password' => $pass ];
			} catch (Exception $e) {
				// fall back to local properties
			}
		}
		return [
			"host"     => $this->ReadPropertyString('host'),
			"user"     => $this->ReadPropertyString('user'),
			"password" => $this->ReadPropertyString('password'),
		];
	}

}