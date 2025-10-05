<?php

return [
    'properties' => [
        'energyGateway' => [
            'type' => 'Integer',
            'default' => 0,
        ],
        'standbyBaseload' => [
            'type' => 'Integer',
            'default' => 300,
        ],
        'minSunPower' => [
            'type' => 'Integer',
            'default' => 300,
        ],
        'daytimeSunDelayMinutes' => [
            'type' => 'Integer',
            'default' => 0,
        ],
        'maxDischargePower' => [
            'type' => 'Integer',
            'default' => 3800,
        ],
        'minChargerCurrent' => [
            'type' => 'Integer',
            'default' => 6000,
        ],      
        'maxChargerCurrent' => [
            'type' => 'Integer',
            'default' => 16000,
        ],
        'minBatteryLevel' => [
            'type' => 'Integer',
            'default' => 15,
        ],
        'targetCharger' => [
            'type' => 'Integer',
            'default' => 0,
        ],
        'enabled' => [
            'type' => 'Boolean',
            'default' => true,
        ],

    ],
    'variables' => [
        'batteryLevel' => [
            'type' => 'Integer',
            'name' => 'Battery Level',
            'profile' => '~Intensity.100',
            'position' => 1,
            'enableAction' => false,
        ],
        'batteryStatus' => [
            'type' => 'String',
            'name' => 'Battery Status',
            'profile' => 'SOLAR.BatteryStatus',
            'position' => 2,
            'enableAction' => false,
        ],
        'batteryPower' => [
            'type' => 'Float',
            'name' => 'Battery Power',
            'profile' => 'SOLAR.BatteryPower',
            'position' => 3,
            'enableAction' => false,
        ],
        'production' => [
            'type' => 'Float',
            'name' => 'Production',
            'profile' => 'SOLAR.Production',
            'position' => 4,
            'enableAction' => false,
        ],
        'consumption' => [
            'type' => 'Float',
            'name' => 'Consumption',
            'profile' => 'SOLAR.Consumption',
            'position' => 5,
            'enableAction' => false,
        ],
        'houseConsumption' => [
            'type' => 'Float',
            'name' => 'House Consumption',
            'profile' => 'SOLAR.Consumption',
            'position' => 7,
            'enableAction' => false,
        ],
        'import' => [
            'type' => 'Float',
            'name' => 'Import / Export',
            'profile' => 'SOLAR.Import',
            'position' => 6,
            'enableAction' => false,
        ],
        'availablePower' => [
            'type' => 'Float',
            'name' => 'Verfügbare Leistung',
            'profile' => 'SOLAR.ChargerPower',
            'position' => 8,
            'translations' => [
                'en' => 'Available Power',
            ],
            'enableAction' => false,
        ],
        'chargerMode' => [
            'type' => 'Integer',
            'name' => 'Charger Mode',
            'profile' => 'SOLAR.ChargerMode',
            'position' => 10,
            'enableAction' => true,
        ],
        'maxChargerCurrent' => [
            'type' => 'Integer',
            'name' => 'Charger Power (max)',
            'profile' => 'SOLAR.CurrentMax',
            'position' => 13,
            'default' => 16000,
            'enableAction' => true,
        ],
        'chargerCurrent' => [
            'type' => 'Integer',
            'name' => 'Charger Current',
            'profile' => 'SOLAR.ChargerCurrent',
            'position' => 15,
            'enableAction' => false,
        ],
        'chargerUpdate' => [
            'type' => 'Boolean',
            'name' => 'Update Charger now',
            'profile' => '~Switch',
            'position' => 18,
            'enableAction' => true,
        ],
        'chargerReboot' => [
            'type' => 'Boolean',
            'name' => 'Reboot Charger',
            'profile' => '~Switch',
            'position' => 19,
            'enableAction' => true,
        ],
        'chargerPower' => [
            'type' => 'Float',
            'name' => 'Charger Power',
            'profile' => 'SOLAR.ChargerPower',
            'position' => 16,
            'enableAction' => false,
        ],
        
        
    ],
    'profiles' => [
        'SOLAR.BatteryStatus' => [
            'type' => 'String',
            'icon' => 'Graph',
            'suffix' => '',
        ],
        'SOLAR.BatteryPower' => [
            'type' => 'Float',
            'icon' => 'EnergyStorage',
            'suffix' => ' kW',
            'digits' => 1,
        ],
        'SOLAR.ChargerStatus' => [
            'type' => 'String',
            'icon' => 'Garage',
            'suffix' => '',
        ],
        'SOLAR.Production' => [
            'type' => 'Float',
            'icon' => 'Sun',
            'suffix' => ' kW',
            'digits' => 1,
        ],
        'SOLAR.Import' => [
            'type' => 'Float',
            'icon' => 'EnergySolar',
            'suffix' => ' kW',
            'digits' => 1,
        ],
        'SOLAR.Consumption' => [
            'type' => 'Float',
            'icon' => 'EnergyProduction',
            'suffix' => ' kW',
            'digits' => 1,
        ],
        'SOLAR.ChargerPower' => [
            'type' => 'Float',
            'icon' => 'Graph',
            'suffix' => ' kW',
            'digits' => 1,
        ],
        'SOLAR.ChargerCurrent' => [
            'type' => 'Integer',
            'icon' => 'Electricity',
            'suffix' => ' mA',
            'minimum' => 0,
            'maximum' => 32000,
        ],
        'SOLAR.Electricity' => [
            'type' => 'Float',
            'icon' => 'Electricity',
            'suffix' => ' kW',
            'digits' => 1,
        ],
        'SOLAR.Baseload' => [
            'type' => 'Integer',
            'icon' => 'Intensity',
            'suffix' => ' W',
            'minimum' => 0,
            'maximum' => 500,
        ],
        'SOLAR.LEDStatus' => [
            'type' => 'Integer',
            'icon' => 'Eyes',
            'suffix' => '',
        ],
        'SOLAR.Capacity' => [
            'type' => 'Integer',
            'icon' => 'EnergyStorage',
            'suffix' => ' kWh',
        ],
        'SOLAR.Information' => [
            'type' => 'String',
            'icon' => 'Information',
            'suffix' => '',
        ],
        'SOLAR.YesNo' => [
            'type' => 'Boolean',
            'icon' => '',
            'associations' => [
                [ 
                    'value' => false, 
                    'text' => 'no', 
                    'icon' => 'Cross',
                    'color' => -1,
                ],
                [ 
                    'value' => true, 
                    'text' => 'yes', 
                    'icon' => 'Ok',
                    'color' => -1,
                ],
            ],
        ],
        'SOLAR.CurrentMax' => [
            'type' => 'Integer',
            'icon' => '',
            'associations' => [
                [ 
                    'value' => 6000, 
                    'text' => '4,1 kW', 
                    'icon' => 'HollowArrowDown',
                    'color' => -1,
                ],
                [ 
                    'value' => 10870, 
                    'text' => '7,5 kW', 
                    'icon' => 'HollowArrowDown',
                    'color' => -1,
                ],
                [ 
                    'value' => 16000, 
                    'text' => '11,0 kW', 
                    'icon' => 'HollowDoubleArrowDown',
                    'color' => -1,
                ],
            ],
        ],
        'SOLAR.ChargerMode' => [
            'type' => 'Integer',
            'icon' => 'Intensity',
            'associations' => [
                [ 
                    'value' => 0, 
                    'text' => 'Sonne', 
                    'icon' => 'Sun',
                    'color' => -1,
                ],
                [ 
                    'value' => 1, 
                    'text' => 'Sonne + Batterie', 
                    'icon' => 'Battery',
                    'color' => -1,
                ],
                [ 
                    'value' => 2, 
                    'text' => 'nur am Tag', 
                    'icon' => 'EnergySolar',
                    'color' => -1,
                ],
                [
                    'value' => 3,
                    'text' => 'fest',
                    'icon' => 'Electricity',
                    'color' => -1,
                ],
                [
                    'value' => 4,
                    'text' => 'aus',
                    'icon' => 'Power',
                    'color' => -1,
                ],
            ],
        ],
        
    ],
];
